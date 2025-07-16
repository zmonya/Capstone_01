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
function sendResponse(bool $success, string $message, array $data, int $statusCode): void
{
    header('Content-Type: application/json');
    http_response_code($statusCode);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}

/**
 * Validates user session.
 *
 * @return array{user_id: int, role: string}
 * @throws Exception If user is not authenticated
 */
function validateUserSession(): array
{
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        throw new Exception('User not authenticated.');
    }
    return ['user_id' => (int)$_SESSION['user_id'], 'role' => (string)$_SESSION['role']];
}

/**
 * Sanitizes SQL ORDER BY clause to prevent injection.
 *
 * @param string $sortBy
 * @return string
 */
function sanitizeSortBy(string $sortBy): string
{
    $allowedSorts = [
        'File_name ASC',
        'File_name DESC',
        'Upload_date ASC',
        'Upload_date DESC',
        'File_type ASC',
        'File_type DESC'
    ];
    return in_array($sortBy, $allowedSorts) ? $sortBy : 'Upload_date DESC';
}

try {
    $user = validateUserSession();
    $userId = $user['user_id'];
    $userRole = $user['role'];

    $limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT, ['options' => ['default' => 10, 'min_range' => 1]]) ?? 10;
    $offset = filter_input(INPUT_GET, 'offset', FILTER_VALIDATE_INT, ['options' => ['default' => 0, 'min_range' => 0]]) ?? 0;
    $search = filter_input(INPUT_GET, 'search', FILTER_SANITIZE_STRING) ?? '';
    $sortBy = sanitizeSortBy(filter_input(INPUT_GET, 'sort', FILTER_SANITIZE_STRING) ?? 'Upload_date DESC');
    $table = filter_input(INPUT_GET, 'table', FILTER_SANITIZE_STRING) ?? 'files';

    global $pdo;

    // Fetch user departments for authorization
    $stmt = $pdo->prepare("SELECT Department_id FROM users_department WHERE User_id = ?");
    $stmt->execute([$userId]);
    $userDepartments = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if ($table === 'files') {
        $sql = "
            SELECT 
                f.File_id AS id,
                f.File_name,
                f.Upload_date,
                f.File_type,
                f.File_status,
                f.File_path,
                COALESCE(dtf.Field_name, 'Unknown Type') AS document_type,
                COALESCE(u.Username, 'Unknown User') AS uploaded_by,
                COALESCE(d.Department_name, 'No Department') AS department_name
            FROM files f
            LEFT JOIN documents_type_fields dtf ON f.Document_type_id = dtf.Document_type_id
            LEFT JOIN users u ON f.User_id = u.User_id
            LEFT JOIN users_department ud ON ud.User_id = f.User_id
            LEFT JOIN departments d ON ud.Department_id = d.Department_id
            WHERE f.File_status != 'deleted'
        ";
        $params = [];

        if (!empty($search)) {
            $sql .= " AND (f.File_name LIKE ? OR dtf.Field_name LIKE ? OR u.Username LIKE ?)";
            $params = ["%$search%", "%$search%", "%$search%"];
        }

        // Authorization check
        $sql .= " AND (f.User_id = ? OR ud.Department_id IN (" . implode(',', array_fill(0, count($userDepartments), '?')) . "))";
        $params[] = $userId;
        $params = array_merge($params, $userDepartments);

        $sql .= " ORDER BY $sortBy LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Count total for pagination
        $countSql = str_replace(
            ['SELECT f.File_id AS id, f.File_name, f.Upload_date, f.File_type, f.File_status, f.File_path, COALESCE(dtf.Field_name, \'Unknown Type\') AS document_type, COALESCE(u.Username, \'Unknown User\') AS uploaded_by, COALESCE(d.Department_name, \'No Department\') AS department_name', "ORDER BY $sortBy LIMIT ? OFFSET ?"],
            'SELECT COUNT(*) as total',
            $sql
        );
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute(array_slice($params, 0, -2));
        $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

        // Log request in transaction table
        $stmt = $pdo->prepare("
            INSERT INTO transaction (User_id, Transaction_status, Transaction_type, Time, Massage)
            VALUES (?, 'completed', 21, NOW(), ?)
        ");
        $stmt->execute([$userId, "Fetched table data for files with search: $search, sort: $sortBy"]);

        sendResponse(true, 'File data retrieved successfully.', ['data' => $data, 'total' => $total], 200);
    } else {
        // Cabinets table is not available
        sendResponse(false, 'Invalid table specified. Cabinets are not supported.', [], 400);
    }
} catch (Exception $e) {
    error_log("Error in fetch_table_data.php: " . $e->getMessage());
    sendResponse(false, 'Server error: ' . $e->getMessage(), [], 500);
}
