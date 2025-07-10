<?php
session_start();
require 'db_connection.php';
require 'log_activity.php';
require 'vendor/autoload.php';

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
 * Sends a JSON response with appropriate HTTP status.
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
 * Validates user session and department access.
 *
 * @param int $departmentId
 * @return int User ID
 * @throws Exception If user is not authenticated or lacks access
 */
function validateUserSessionAndDepartment(int $departmentId): int
{
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('User not authenticated.');
    }
    $userId = (int)$_SESSION['user_id'];

    global $pdo;
    $stmt = $pdo->prepare("SELECT Users_Department_id FROM users_department WHERE User_id = ? AND Department_id = ?");
    $stmt->execute([$userId, $departmentId]);
    if (!$stmt->fetch()) {
        throw new Exception('User does not have access to this department.');
    }
    return $userId;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendResponse(false, 'Invalid request method.', [], 405);
    }
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        sendResponse(false, 'Invalid CSRF token.', [], 403);
    }

    $departmentId = filter_input(INPUT_POST, 'department_id', FILTER_VALIDATE_INT);
    if (!$departmentId || $departmentId <= 0) {
        sendResponse(false, 'Invalid department ID.', [], 400);
    }

    $userId = validateUserSessionAndDepartment($departmentId);

    global $pdo;

    // Fetch hardcopy files for the department
    $stmt = $pdo->prepare("
        SELECT f.File_id AS id, f.File_name AS file_name, f.Meta_data
        FROM files f
        JOIN transaction t ON f.File_id = t.File_id
        JOIN users_department ud ON t.Users_Department_id = ud.Users_Department_id
        WHERE ud.Department_id = ? 
        AND f.Copy_type = 'hard' 
        AND f.File_status != 'deleted'
        ORDER BY f.Upload_date DESC
    ");
    $stmt->execute([$departmentId]);
    $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Log the fetch request
    $stmt = $pdo->prepare("
        INSERT INTO transaction (User_id, Transaction_status, Transaction_type, Time, Massage)
        VALUES (?, 'completed', 8, NOW(), ?)
    ");
    $stmt->execute([$userId, "Fetched hardcopy files for department ID: $departmentId"]);

    sendResponse(true, 'Hardcopy files retrieved successfully.', ['files' => $files], 200);
} catch (Exception $e) {
    error_log("Error in fetch_hardcopy_files.php: " . $e->getMessage());
    sendResponse(false, 'Server error: ' . $e->getMessage(), [], 500);
}

/**
 * Validates CSRF token.
 *
 * @param string $csrfToken
 * @return bool
 */
function validateCsrfToken(string $csrfToken): bool
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $csrfToken);
}
