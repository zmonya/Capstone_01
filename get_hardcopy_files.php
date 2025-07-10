<?php
session_start();
require 'db_connection.php';
require 'vendor/autoload.php'; // Load Composer autoloader for phpdotenv

use Dotenv\Dotenv;

// Load environment variables for consistency
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

/**
 * Generates a JSON response with appropriate HTTP status.
 *
 * @param bool $success
 * @param array|string $data
 * @param int $statusCode
 * @return void
 */
function sendResponse(bool $success, $data, int $statusCode): void
{
    header('Content-Type: application/json');
    http_response_code($statusCode);
    echo json_encode($success ? ['success' => true, 'data' => $data] : ['success' => false, 'message' => $data]);
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
        throw new Exception('User not authenticated.');
    }
    return (int)$_SESSION['user_id'];
}

try {
    $userId = validateUserSession();
    $departmentId = filter_input(INPUT_GET, 'department_id', FILTER_VALIDATE_INT);
    if (!$departmentId || $departmentId <= 0) {
        sendResponse(false, 'Department ID not provided or invalid.', 400);
    }

    global $pdo;

    // Verify user belongs to the department
    $authStmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM users_department 
        WHERE User_id = ? AND Department_id = ?
    ");
    $authStmt->execute([$userId, $departmentId]);
    if ($authStmt->fetchColumn() == 0) {
        sendResponse(false, 'You do not have permission to view files in this department.', 403);
    }

    // Fetch files with hard copy status (simulated via transaction with type 8)
    $stmt = $pdo->prepare("
        SELECT DISTINCT f.File_id AS id, f.File_name AS file_name, dt.Field_name AS document_type
        FROM files f
        LEFT JOIN documents_type_fields dt ON f.Document_type_id = dt.Document_type_id
        JOIN transaction t ON f.File_id = t.File_id
        WHERE t.Users_Department_id IN (
            SELECT Users_Department_id 
            FROM users_department 
            WHERE Department_id = ?
        )
        AND t.Transaction_type = 8
        AND f.File_status != 'deleted'
        ORDER BY f.Upload_date DESC
    ");
    $stmt->execute([$departmentId]);
    $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Log request in transaction table
    $logStmt = $pdo->prepare("
        INSERT INTO transaction (User_id, Users_Department_id, Transaction_status, Transaction_type, Time, Massage)
        VALUES (?, ?, 'completed', 8, NOW(), 'Fetched hardcopy files for department')
    ");
    $usersDepartmentId = $pdo->query("SELECT Users_Department_id FROM users_department WHERE User_id = $userId AND Department_id = $departmentId LIMIT 1")->fetchColumn();
    $logStmt->execute([$userId, $usersDepartmentId]);

    sendResponse(true, ['files' => $files], 200);
} catch (Exception $e) {
    error_log("Error in get_hardcopy_files.php: " . $e->getMessage());
    sendResponse(false, 'Server error: ' . $e->getMessage(), 500);
}
