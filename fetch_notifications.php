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
    $typeFilter = filter_input(INPUT_GET, 'type', FILTER_SANITIZE_STRING) ?? null;

    global $pdo;

    // Build query to fetch notifications from transaction table
    $query = "
        SELECT 
            t.Transaction_id AS id,
            t.User_id AS user_id,
            t.File_id AS file_id,
            t.Transaction_status AS status,
            t.Time AS timestamp,
            t.Massage AS message,
            COALESCE(f.File_name, 'Unknown File') AS file_name
        FROM transaction t
        LEFT JOIN files f ON t.File_id = f.File_id
        WHERE t.User_id = ? AND t.Transaction_type = 12
        AND f.File_status != 'deleted'
    ";
    $params = [$userId];

    if ($typeFilter) {
        // Map type filter to transaction status or message content
        $query .= " AND t.Massage LIKE ?";
        $params[] = "%$typeFilter%";
    }

    $query .= " ORDER BY t.Time DESC LIMIT 5";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Log request in transaction table
    $stmt = $pdo->prepare("
        INSERT INTO transaction (User_id, Transaction_status, Transaction_type, Time, Massage)
        VALUES (?, 'completed', 20, NOW(), ?)
    ");
    $stmt->execute([$userId, "Fetched notifications with type filter: " . ($typeFilter ?? 'none')]);

    if ($notifications) {
        sendResponse(true, 'Notifications retrieved successfully.', ['notifications' => $notifications], 200);
    } else {
        sendResponse(true, 'No notifications available.', ['notifications' => []], 200);
    }
} catch (Exception $e) {
    error_log("Error in fetch_notifications.php: " . $e->getMessage());
    sendResponse(false, 'Server error: ' . $e->getMessage(), [], 500);
}
