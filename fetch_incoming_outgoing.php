<?php
session_start();
require 'db_connection.php';
require 'log_activity.php';
require 'vendor/autoload.php'; // Load Composer autoloader for phpdotenv

use Dotenv\Dotenv;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Disable error display in production
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('error_log', __DIR__ . '/logs/error_log.log');
error_reporting(E_ALL);

/**
 * Generates a JSON response with appropriate HTTP status.
 *
 * @param array $data
 * @param int $statusCode
 * @return void
 */
function sendResponse(array $data, int $statusCode): void
{
    header('Content-Type: application/json');
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

/**
 * Validates user session.
 *
 * @return int User ID
 * @throws Exception If user is not authenticated
 */
function validateUserSession(): int
{
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('User not logged in.');
    }
    return (int)$_SESSION['user_id'];
}

try {
    $userId = validateUserSession();
    $interval = filter_input(INPUT_GET, 'interval', FILTER_SANITIZE_STRING) ?? 'day';
    $startDate = filter_input(INPUT_GET, 'startDate', FILTER_SANITIZE_STRING) ?? null;
    $endDate = filter_input(INPUT_GET, 'endDate', FILTER_SANITIZE_STRING) ?? null;

    // Validate interval
    $validIntervals = ['day', 'week', 'month'];
    if (!in_array($interval, $validIntervals)) {
        sendResponse(['error' => 'Invalid interval specified.'], 400);
    }

    // Determine the GROUP BY clause based on interval
    $groupBy = match ($interval) {
        'week' => "DATE_FORMAT(Time, '%Y-%u')",
        'month' => "DATE_FORMAT(Time, '%Y-%m')",
        default => "DATE(Time)"
    };

    global $pdo;

    // Fetch user departments
    $stmt = $pdo->prepare("SELECT Department_id FROM users_department WHERE User_id = ?");
    $stmt->execute([$userId]);
    $userDepartments = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Chart data query: Aggregate files sent, received, requested, and received from requests
    $chartQuery = "
        SELECT 
            $groupBy AS period,
            COUNT(DISTINCT CASE 
                WHEN t.Transaction_type = 2 AND t.User_id = :userId AND t.Transaction_status IN ('pending', 'accepted') 
                THEN t.File_id 
            END) AS files_sent,
            COUNT(DISTINCT CASE 
                WHEN t.Transaction_type = 2 AND t.Transaction_status = 'accepted' 
                    AND (t.User_id = :userId OR t.Users_Department_id IN (
                        SELECT Users_Department_id FROM users_department WHERE Department_id IN (" . implode(',', array_fill(0, count($userDepartments), '?')) . ")
                    ))
                THEN t.File_id 
            END) AS files_received,
            COUNT(DISTINCT CASE 
                WHEN t.Transaction_type = 11 AND t.User_id = :userId AND t.Transaction_status = 'pending' 
                THEN t.File_id 
            END) AS files_requested,
            COUNT(DISTINCT CASE 
                WHEN t.Transaction_type = 11 AND t.User_id = :userId AND t.Transaction_status = 'approved' 
                    AND EXISTS (
                        SELECT 1 FROM transaction t2 
                        WHERE t2.File_id = t.File_id AND t2.User_id = :userId 
                        AND t2.Transaction_type = 2 AND t2.Transaction_status = 'accepted'
                    )
                THEN t.File_id 
            END) AS files_received_from_request
        FROM transaction t
        LEFT JOIN files f ON t.File_id = f.File_id
        WHERE t.Transaction_type IN (2, 11)
            AND f.File_status != 'deleted'
            AND (t.User_id = :userId 
                OR t.Users_Department_id IN (
                    SELECT Users_Department_id FROM users_department WHERE Department_id IN (" . implode(',', array_fill(0, count($userDepartments), '?')) . ")
                ))
    ";

    $params = array_merge([':userId' => $userId], $userDepartments, $userDepartments);
    if ($startDate && $endDate) {
        $chartQuery .= " AND t.Time BETWEEN :startDate AND :endDate";
        $params[':startDate'] = $startDate;
        $params[':endDate'] = $endDate;
    }

    $chartQuery .= " GROUP BY period ORDER BY period ASC";
    $stmt = $pdo->prepare($chartQuery);
    $stmt->execute($params);
    $chartResults = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Table data query: File details with event-specific dates
    $tableQuery = "
        SELECT DISTINCT
            f.File_id AS file_id,
            COALESCE(f.File_name, 'Unnamed File') AS file_name,
            COALESCE(dtf.Field_name, 'Unknown Type') AS document_type,
            CASE 
                WHEN t.Transaction_type = 2 AND t.User_id = :userId THEN t.Time
                WHEN t.Transaction_type = 2 AND t.Transaction_status = 'accepted' 
                    AND (t.User_id = :userId OR t.Users_Department_id IN (
                        SELECT Users_Department_id FROM users_department WHERE Department_id IN (" . implode(',', array_fill(0, count($userDepartments), '?')) . ")
                    )) THEN t.Time
                WHEN t.Transaction_type = 11 AND t.User_id = :userId THEN t.Time
                ELSE NULL
            END AS event_date,
            COALESCE(d.Department_name, 'No Department') AS department_name,
            COALESCE(u.Username, 'Unknown User') AS uploader,
            CASE 
                WHEN t.Transaction_type = 2 AND t.User_id = :userId THEN 'Sent'
                WHEN t.Transaction_type = 2 AND t.Transaction_status = 'accepted' 
                    AND t.User_id = :userId THEN 'Received'
                WHEN t.Transaction_type = 2 AND t.Transaction_status = 'accepted' 
                    AND t.Users_Department_id IN (
                        SELECT Users_Department_id FROM users_department WHERE Department_id IN (" . implode(',', array_fill(0, count($userDepartments), '?')) . ")
                    ) THEN 'Received (Department)'
                WHEN t.Transaction_type = 11 AND t.User_id = :userId AND t.Transaction_status = 'pending' THEN 'Requested'
                WHEN t.Transaction_type = 11 AND t.User_id = :userId AND t.Transaction_status = 'approved' THEN 'Request Approved'
                WHEN t.Transaction_type = 11 AND t.User_id = :userId AND t.Transaction_status = 'rejected' THEN 'Request Denied'
                ELSE 'Unknown'
            END AS direction
        FROM files f
        LEFT JOIN transaction t ON f.File_id = t.File_id
        LEFT JOIN documents_type_fields dtf ON f.Document_type_id = dtf.Document_type_id
        LEFT JOIN users_department ud ON t.Users_Department_id = ud.Users_Department_id
        LEFT JOIN departments d ON ud.Department_id = d.Department_id
        LEFT JOIN users u ON f.User_id = u.User_id
        WHERE t.Transaction_type IN (2, 11)
            AND f.File_status != 'deleted'
            AND (t.User_id = :userId 
                OR t.Users_Department_id IN (
                    SELECT Users_Department_id FROM users_department WHERE Department_id IN (" . implode(',', array_fill(0, count($userDepartments), '?')) . ")
                ))
    ";

    $tableParams = array_merge([':userId' => $userId], $userDepartments, $userDepartments);
    if ($startDate && $endDate) {
        $tableQuery .= " AND t.Time BETWEEN :startDate AND :endDate";
        $tableParams[':startDate'] = $startDate;
        $tableParams[':endDate'] = $endDate;
    }

    $tableQuery .= " ORDER BY event_date ASC";
    $stmt = $pdo->prepare($tableQuery);
    $stmt->execute($tableParams);
    $filesResults = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Log request in transaction table
    $stmt = $pdo->prepare("
        INSERT INTO transaction (User_id, Transaction_status, Transaction_type, Time, Massage)
        VALUES (?, 'completed', 19, NOW(), ?)
    ");
    $stmt->execute([$userId, "Fetched incoming/outgoing data for interval: $interval"]);

    // Prepare chart data
    $labels = array_column($chartResults, 'period');
    $datasets = [
        'files_sent' => array_column($chartResults, 'files_sent'),
        'files_received' => array_column($chartResults, 'files_received'),
        'files_requested' => array_column($chartResults, 'files_requested'),
        'files_received_from_request' => array_column($chartResults, 'files_received_from_request')
    ];

    // Output JSON with renamed key
    sendResponse([
        'labels' => $labels,
        'datasets' => $datasets,
        'tableData' => array_map(function ($row) {
            $row['upload_date'] = $row['event_date'];
            unset($row['event_date']);
            return $row;
        }, $filesResults)
    ], 200);
} catch (Exception $e) {
    error_log("Error in fetch_incoming_outgoing.php: " . $e->getMessage());
    sendResponse(['error' => 'Server error: ' . $e->getMessage()], 500);
}
