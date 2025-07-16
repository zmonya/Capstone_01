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
        throw new Exception('User not authenticated.');
    }
    return (int)$_SESSION['user_id'];
}

try {
    $userId = validateUserSession();
    $query = filter_input(INPUT_GET, 'q', FILTER_SANITIZE_STRING) ?? '';

    global $pdo;

    // Fetch users
    $stmt = $pdo->prepare("SELECT User_id AS id, Username AS username, 'user' AS type FROM users WHERE Username LIKE ?");
    $stmt->execute(["%$query%"]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch departments
    $stmt = $pdo->prepare("SELECT Department_id AS id, Department_name AS name, 'department' AS type FROM departments WHERE Department_name LIKE ?");
    $stmt->execute(["%$query%"]);
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Combine results
    $results = array_merge($users, $departments);

    // Log request in transaction table
    $stmt = $pdo->prepare("
        INSERT INTO transaction (User_id, Transaction_status, Transaction_type, Time, Massage)
        VALUES (?, 'completed', 17, NOW(), ?)
    ");
    $stmt->execute([$userId, "Fetched recipients with query: $query"]);

    sendResponse($results, 200);
} catch (Exception $e) {
    error_log("Error in get_recipients.php: " . $e->getMessage());
    sendResponse(['success' => false, 'error' => 'Server error: ' . $e->getMessage()], 500);
}
