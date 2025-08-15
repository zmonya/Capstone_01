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
    // Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        sendResponse(false, 'Invalid request method.', [], 405);
    }

    $userId = validateUserSession();
    $notificationId = filter_input(INPUT_GET, 'notification_id', FILTER_VALIDATE_INT);
    if (!$notificationId || $notificationId <= 0) {
        sendResponse(false, 'Notification ID not provided.', [], 400);
    }

    global $pdo;

    // Fetch notification status from transactions table
    $stmt = $pdo->prepare("
        SELECT description AS status 
        FROM transactions 
        WHERE transaction_id = ? AND user_id = ? AND transaction_type = 'notification'
    ");
    $stmt->execute([$notificationId, $userId]);
    $notification = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$notification) {
        sendResponse(false, 'Notification not found or unauthorized.', [], 404);
    }

    // Log request in transactions table
    $stmt = $pdo->prepare("
        INSERT INTO transactions (user_id, transaction_type, transaction_time, description)
        VALUES (?, 'fetch_status', NOW(), ?)
    ");
    $stmt->execute([$userId, "Fetched notification status for ID $notificationId"]);

    sendResponse(true, 'Notification status retrieved successfully.', ['status' => $notification['status']], 200);
} catch (Exception $e) {
    error_log("Error in get_notification_status.php: " . $e->getMessage());
    sendResponse(false, 'Server error: ' . $e->getMessage(), [], 500);
}
