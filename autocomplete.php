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
 * @param bool $success
 * @param string $message
 * @param array $data
 * @param int $statusCode
 * @return void
 */
function sendJsonResponse(bool $success, string $message, array $data, int $statusCode): void
{
    header('Content-Type: application/json');
    http_response_code($statusCode);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}

try {
    // Validate session
    if (!isset($_SESSION['user_id'])) {
        sendJsonResponse(false, 'Unauthorized access.', [], 401);
    }
    $userId = (int)$_SESSION['user_id'];

    // Sanitize input
    $term = filter_input(INPUT_GET, 'term', FILTER_SANITIZE_SPECIAL_CHARS);
    if ($term === false || $term === '') {
        sendJsonResponse(true, 'No search term provided.', ['results' => []], 200);
    }

    // Fetch user departments
    $stmt = $pdo->prepare("
        SELECT Department_id 
        FROM users_department 
        WHERE User_id = ?
    ");
    $stmt->execute([$userId]);
    $departmentIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Department_id');

    // Base query
    $sql = "
        SELECT DISTINCT 
            f.File_name AS label, 
            f.File_name AS value,
            COALESCE(dtf.Field_name, 'Unknown Type') AS document_type,
            COALESCE(u.Username, 'Unknown User') AS uploaded_by,
            COALESCE(d.Department_name, 'No Department') AS department_name,
            d.Department_id AS department_id,
            f.Meta_data
        FROM files f
        LEFT JOIN documents_type_fields dtf ON f.Document_type_id = dtf.Document_type_id
        LEFT JOIN users u ON f.User_id = u.User_id
        LEFT JOIN users_department ud ON u.User_id = ud.User_id
        LEFT JOIN departments d ON ud.Department_id = d.Department_id
        WHERE f.File_status != 'deleted'
        AND (f.User_id = ? OR ud.Department_id IN (" . (empty($departmentIds) ? '0' : implode(',', array_fill(0, count($departmentIds), '?'))) . "))
        AND (f.File_name LIKE ? OR dtf.Field_name LIKE ? OR u.Username LIKE ? OR d.Department_name LIKE ? OR f.Meta_data LIKE ?)
        LIMIT 10
    ";

    $params = [$userId];
    if (!empty($departmentIds)) {
        $params = array_merge($params, $departmentIds);
    }
    $searchParam = "%$term%";
    $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam, $searchParam]);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format results
    $formattedResults = array_map(function ($result) {
        $meta = json_decode($result['Meta_data'] ?? '{}', true);
        $cabinet = $meta['cabinet'] ?? 'N/A';
        return [
            'label' => $result['label'] . ' (' . $result['document_type'] . ') - Uploaded By: ' . $result['uploaded_by'] . ' - Department: ' . ($result['department_name'] ?? 'N/A') . ' - Cabinet: ' . $cabinet,
            'value' => $result['value'],
            'document_type' => $result['document_type'],
            'uploaded_by' => $result['uploaded_by'],
            'department_name' => $result['department_name'],
            'department_id' => $result['department_id'],
            'cabinet' => $cabinet
        ];
    }, $results);

    // Log search in transaction table
    $stmt = $pdo->prepare("
        INSERT INTO transaction (User_id, Transaction_status, Transaction_type, Time, Massage)
        VALUES (?, 'completed', 24, NOW(), ?)
    ");
    $stmt->execute([$userId, "Autocomplete search for term: $term"]);

    sendJsonResponse(true, 'Success', ['results' => $formattedResults], 200);
} catch (Exception $e) {
    error_log("Error in autocomplete.php: " . $e->getMessage());
    sendJsonResponse(false, 'Server error: Unable to process autocomplete request.', [], 500);
}
