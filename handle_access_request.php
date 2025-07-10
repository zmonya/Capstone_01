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
 * Sends an access notification using the transaction table.
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
        $status = ($type === 'access_request') ? 'pending' : 'completed';
        $stmt = $pdo->prepare("
            INSERT INTO transaction (User_id, File_id, Transaction_status, Transaction_type, Time, Massage)
            VALUES (?, ?, ?, 12, NOW(), ?)
        ");
        return $stmt->execute([$userId, $fileId, $status, $message]);
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
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || !isset($data['csrf_token']) || !validateCsrfToken($data['csrf_token'])) {
        sendResponse(false, 'Invalid CSRF token.', 403);
    }

    $action = $data['action'] ?? null;
    $fileId = filter_var($data['file_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$action || !$fileId || $fileId <= 0) {
        sendResponse(false, 'Action and file ID are required.', 400);
    }

    $user = validateUserSession();
    $userId = $user['user_id'];
    $username = $user['username'];

    global $pdo;

    if ($action === 'request') {
        // Check file existence and owner
        $stmt = $pdo->prepare("SELECT User_id, File_name FROM files WHERE File_id = ? AND File_status != 'deleted'");
        $stmt->execute([$fileId]);
        $file = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$file) {
            sendResponse(false, 'File not found.', 404);
        }
        $fileOwner = (int)$file['User_id'];
        $fileName = $file['File_name'];

        // Check for existing request
        $stmt = $pdo->prepare("
            SELECT Transaction_id 
            FROM transaction 
            WHERE User_id = ? AND File_id = ? AND Transaction_type = 11 AND Transaction_status = 'pending'
        ");
        $stmt->execute([$userId, $fileId]);
        if ($stmt->fetchColumn()) {
            sendResponse(false, 'You have already requested access to this file.', 400);
        }

        // Insert access request
        $stmt = $pdo->prepare("
            INSERT INTO transaction (User_id, File_id, Transaction_status, Transaction_type, Time, Massage)
            VALUES (?, ?, 'pending', 11, NOW(), ?)
        ");
        if (!$stmt->execute([$userId, $fileId, "Access request for file: $fileName"])) {
            sendResponse(false, 'Failed to create access request.', 500);
        }

        // Notify owner
        $ownerMessage = "User $username has requested access to your file: $fileName.";
        if (!sendAccessNotification($fileOwner, $ownerMessage, $fileId, 'access_request')) {
            error_log("Failed to notify owner ID: $fileOwner for request of file: $fileName");
        }

        logActivity($userId, "Requested access to file '$fileName' owned by user ID $fileOwner", $fileId);
        sendResponse(true, 'Access request sent successfully.', 200);
    } elseif ($action === 'approve' || $action === 'reject') {
        // Check access request
        $stmt = $pdo->prepare("
            SELECT t.*, f.File_name, u.Username AS requester_username 
            FROM transaction t 
            JOIN files f ON t.File_id = f.File_id 
            JOIN users u ON t.User_id = u.User_id 
            WHERE t.File_id = ? AND f.User_id = ? AND t.Transaction_type = 11 AND t.Transaction_status = 'pending'
        ");
        $stmt->execute([$fileId, $userId]);
        $accessRequest = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$accessRequest) {
            sendResponse(false, 'Access request not found or already processed.', 404);
        }

        $accessRequestId = $accessRequest['Transaction_id'];
        $fileName = $accessRequest['File_name'];
        $requesterId = $accessRequest['User_id'];
        $requesterUsername = $accessRequest['requester_username'];
        $newStatus = $action === 'approve' ? 'approved' : 'rejected';

        // Update access request
        $stmt = $pdo->prepare("
            UPDATE transaction 
            SET Transaction_status = ?, Time = NOW()
            WHERE Transaction_id = ?
        ");
        if (!$stmt->execute([$newStatus, $accessRequestId])) {
            sendResponse(false, 'Failed to update access request status.', 500);
        }

        // Update notification
        $stmt = $pdo->prepare("
            UPDATE transaction 
            SET Transaction_status = ? 
            WHERE File_id = ? AND User_id = ? AND Transaction_type = 12 AND Transaction_status = 'pending'
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
                INSERT INTO transaction (File_id, User_id, Transaction_status, Transaction_type, Time, Massage)
                VALUES (?, ?, 'accepted', 2, NOW(), ?)
            ");
            if (!$stmt->execute([$fileId, $requesterId, "File access granted to $requesterUsername"])) {
                sendResponse(false, 'Failed to grant file access to requester.', 500);
            }

            // Grant co-ownership
            $stmt = $pdo->prepare("
                INSERT INTO transaction (File_id, User_id, Transaction_status, Transaction_type, Time, Massage)
                VALUES (?, ?, 'accepted', 4, NOW(), ?)
            ");
            $stmt->execute([$fileId, $requesterId, "Co-ownership granted to $requesterUsername"]);
        }

        sendResponse(true, "Access request $newStatus successfully.", 200);
    } else {
        sendResponse(false, 'Invalid action.', 400);
    }
} catch (Exception $e) {
    error_log("Error in handle_access_request.php: " . $e->getMessage());
    sendResponse(false, 'Server error: ' . $e->getMessage(), 500);
}
