<?php
ob_start();
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
 * @param int $statusCode
 * @return void
 */
function sendResponse(bool $success, string $message, int $statusCode): void
{
    ob_end_clean();
    header('Content-Type: application/json');
    http_response_code($statusCode);
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}

/**
 * Validates user session.
 *
 * @return array{user_id: int, username: string}
 * @throws Exception If user is not authenticated
 */
function validateUserSession(): array
{
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
        throw new Exception('User not authenticated.');
    }
    return ['user_id' => (int)$_SESSION['user_id'], 'username' => (string)$_SESSION['username']];
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

/**
 * Sends an access notification using the transactions table.
 *
 * @param int $userId
 * @param string $message
 * @param int|null $fileId
 * @param string $type
 * @return bool
 */
function sendAccessNotification(int $userId, string $message, ?int $fileId, string $type): bool
{
    global $pdo;
    try {
        $description = ($type === 'access_request') ? 'pending' : 'completed';
        $stmt = $pdo->prepare("
            INSERT INTO transactions (user_id, file_id, transaction_type, transaction_time, description)
            VALUES (?, ?, 'notification', NOW(), ?)
        ");
        return $stmt->execute([$userId, $fileId, $message]);
    } catch (PDOException $e) {
        error_log("Database error in sendAccessNotification: " . $e->getMessage());
        return false;
    }
}

try {
    // Validate request method and CSRF token
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendResponse(false, 'Invalid request method.', 405);
    }
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        sendResponse(false, 'Invalid CSRF token.', 403);
    }

    $user = validateUserSession();
    $userId = $user['user_id'];
    $username = $user['username'];

    $fileId = filter_var($_POST['file_id'] ?? null, FILTER_VALIDATE_INT);
    $action = filter_var($_POST['action'] ?? null, FILTER_SANITIZE_STRING);

    if (!$fileId || !$action || !in_array($action, ['approve', 'deny'])) {
        sendResponse(false, 'Missing or invalid parameters.', 400);
    }

    $pdo->beginTransaction();

    // Verify file ownership
    $stmt = $pdo->prepare("
        SELECT file_name, user_id AS owner_id
        FROM files
        WHERE file_id = ? AND user_id = ?
    ");
    $stmt->execute([$fileId, $userId]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$file) {
        $pdo->rollBack();
        sendResponse(false, 'File not found or unauthorized.', 404);
    }

    $fileName = $file['file_name'];
    $ownerId = $file['owner_id'];

    // Get requester details
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.username
        FROM transactions t
        JOIN users u ON t.user_id = u.user_id
        WHERE t.file_id = ? AND t.transaction_type = 'file_request' AND t.description = 'pending'
    ");
    $stmt->execute([$fileId]);
    $requester = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$requester) {
        $pdo->rollBack();
        sendResponse(false, 'No pending access request found for this file.', 404);
    }

    $requesterId = $requester['user_id'];
    $requesterUsername = $requester['username'];

    $newStatus = ($action === 'approve') ? 'accepted' : 'denied';

    // Update notification status
    $stmt = $pdo->prepare("
        UPDATE transactions 
        SET description = ? 
        WHERE file_id = ? AND user_id = ? AND transaction_type = 'notification' AND description = 'pending'
    ");
    if (!$stmt->execute([$newStatus, $fileId, $userId])) {
        error_log("Failed to update notification status for file ID: $fileId, user ID: $userId");
    }

    // Notify requester
    $requesterMessage = "Your access request for file '$fileName' has been $newStatus by $username.";
    if (!sendAccessNotification($requesterId, $requesterMessage, $fileId, 'access_result')) {
        error_log("Failed to notify requester ID: $requesterId, Message: $requesterMessage");
    }

    logActivity($userId, "You have $newStatus the access request from $requesterUsername for your file '$fileName'", $fileId);

    if ($action === 'approve') {
        // Grant access via file transfer
        $stmt = $pdo->prepare("
            INSERT INTO transactions (file_id, user_id, transaction_type, transaction_time, description)
            VALUES (?, ?, 'file_sent', NOW(), ?)
        ");
        if (!$stmt->execute([$fileId, $requesterId, "File access granted to $requesterUsername"])) {
            sendResponse(false, 'Failed to grant file access to requester.', 500);
        }

        // Grant co-ownership
        $stmt = $pdo->prepare("
            INSERT INTO transactions (file_id, user_id, transaction_type, transaction_time, description)
            VALUES (?, ?, 'co-ownership', NOW(), ?)
        ");
        $stmt->execute([$fileId, $requesterId, "Co-ownership granted to $requesterUsername"]);
    }

    $pdo->commit();
    sendResponse(true, "Access request $newStatus successfully.", 200);
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Error in handle_access_request.php: " . $e->getMessage());
    sendResponse(false, 'Server error: ' . $e->getMessage(), 500);
}
