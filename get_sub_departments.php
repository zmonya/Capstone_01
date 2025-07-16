<?php
session_start();
require 'db_connection.php';
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
 * Validates CSRF token.
 *
 * @param string $csrfToken
 * @return bool
 */
function validateCsrfToken(string $csrfToken): bool
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $csrfToken);
}

try {
    // Validate request method and CSRF token
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendResponse(false, 'Invalid request method.', 405);
    }
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        sendResponse(false, 'Invalid CSRF token.', 403);
    }

    // Validate department_id
    $departmentId = filter_input(INPUT_POST, 'department_id', FILTER_VALIDATE_INT);
    if (!$departmentId || $departmentId <= 0) {
        sendResponse(false, 'Invalid department ID.', 400);
    }

    // Since sub_departments table does not exist, return empty array
    // Log request in transaction table
    global $pdo;
    $stmt = $pdo->prepare("
        INSERT INTO transaction (User_id, Transaction_status, Transaction_type, Time, Massage)
        VALUES (?, 'completed', 14, NOW(), ?)
    ");
    $stmt->execute([$_SESSION['user_id'] ?? 0, "Fetched sub-departments for department ID $departmentId"]);

    sendResponse(true, ['sub_departments' => []], 200);
} catch (PDOException $e) {
    error_log("Sub-department fetch error: " . $e->getMessage());
    sendResponse(false, 'Database error occurred.', 500);
}
