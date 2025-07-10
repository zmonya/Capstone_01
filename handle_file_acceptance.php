<?php
session_start();
require 'db_connection.php';
require 'log_activity.php';
require 'notification.php';
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
 * @param string|null $redirect
 * @param bool $popup
 * @param int $statusCode
 * @return void
 */
function sendResponse(bool $success, string $message, ?string $redirect, bool $popup, int $statusCode): void
{
    header('Content-Type: application/json');
    http_response_code($statusCode);
    echo json_encode(['success' => $success, 'message' => $message, 'redirect' => $redirect, 'popup' => $popup]);
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
        sendResponse(false, 'Invalid request method', null, true, 405);
    }
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        sendResponse(false, 'Invalid CSRF token', null, true, 403);
    }

    $notificationId = filter_var($_POST['notification_id'] ?? null, FILTER_VALIDATE_INT);
    $action = $_POST['action'] ?? null;
    $fileId = filter_var($_POST['file_id'] ?? null, FILTER_VALIDATE_INT);
    $userId = validateUserSession();

    if (!$notificationId || !$action || !$fileId || !in_array($action, ['accept', 'deny'])) {
        sendResponse(false, 'Invalid request parameters', null, true, 400);
    }

    global $pdo;

    // Fetch transfer details
    $stmt = $pdo->prepare("
        SELECT t.*, f.File_name AS file_name, dt.Field_name AS document_type, u.Username AS sender_username, ud.Users_Department_id
        FROM transaction t
        JOIN files f ON t.File_id = f.File_id
        LEFT JOIN documents_type_fields dt ON f.Document_type_id = dt.Document_type_id
        LEFT JOIN users u ON t.User_id = u.User_id
        LEFT JOIN users_department ud ON t.Users_Department_id = ud.Users_Department_id
        WHERE t.File_id = ? AND t.Transaction_type = 2 AND t.Transaction_status = 'pending'
        AND (t.User_id = ? OR ud.Users_Department_id IN (
            SELECT Users_Department_id FROM users_department WHERE User_id = ?
        ))
    ");
    $stmt->execute([$fileId, $userId, $userId]);
    $transfer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$transfer) {
        sendResponse(false, 'Transfer not found or already processed', null, true, 404);
    }

    $fileName = $transfer['file_name'];
    $documentType = $transfer['document_type'] ?? 'Unknown Type';
    $senderId = $transfer['User_id'];
    $senderUsername = $transfer['sender_username'] ?? 'Unknown User';
    $usersDepartmentId = $transfer['Users_Department_id'];
    $grantsOwnership = true; // Simulated via transaction type 4
    $redirect = $usersDepartmentId ? 'department_folder.php' : 'my-folder.php';

    // Fetch recipient username
    $stmt = $pdo->prepare("SELECT Username FROM users WHERE User_id = ?");
    $stmt->execute([$userId]);
    $username = $stmt->fetchColumn() ?: 'Unknown User';

    $pdo->beginTransaction();
    try {
        if ($action === 'accept') {
            // Update transfer status
            $stmt = $pdo->prepare("
                UPDATE transaction 
                SET Transaction_status = 'accepted', Time = NOW()
                WHERE File_id = ? AND (User_id = ? OR Users_Department_id = ?)
                AND Transaction_type = 2 AND Transaction_status = 'pending'
            ");
            $stmt->execute([$fileId, $userId, $usersDepartmentId]);

            // Update notification status
            $stmt = $pdo->prepare("
                UPDATE transaction 
                SET Transaction_status = 'accepted'
                WHERE Transaction_id = ? AND User_id = ? AND Transaction_type = 12
            ");
            $stmt->execute([$notificationId, $userId]);

            // Grant co-ownership if applicable
            if ($grantsOwnership && !$usersDepartmentId) {
                $stmt = $pdo->prepare("
                    INSERT INTO transaction (File_id, User_id, Transaction_status, Transaction_type, Time, Massage)
                    VALUES (?, ?, 'accepted', 4, NOW(), ?)
                ");
                $stmt->execute([$fileId, $userId, "Co-ownership granted to $username"]);
            }

            $logMessage = $usersDepartmentId
                ? "Accepted $documentType: $fileName for department"
                : "Accepted $documentType: $fileName and became co-owner";
            logActivity($userId, $logMessage, $fileId);

            sendNotification(
                $senderId,
                "Your $documentType '$fileName' was accepted by $username",
                $fileId,
                'info'
            );

            sendResponse(true, 'File accepted successfully', $redirect, true, 200);
        } elseif ($action === 'deny') {
            // Update transfer status
            $stmt = $pdo->prepare("
                UPDATE transaction 
                SET Transaction_status = 'denied', Time = NOW()
                WHERE File_id = ? AND (User_id = ? OR Users_Department_id = ?)
                AND Transaction_type = 2 AND Transaction_status = 'pending'
            ");
            $stmt->execute([$fileId, $userId, $usersDepartmentId]);

            // Update notification status
            $stmt = $pdo->prepare("
                UPDATE transaction 
                SET Transaction_status = 'denied'
                WHERE Transaction_id = ? AND User_id = ? AND Transaction_type = 12
            ");
            $stmt->execute([$notificationId, $userId]);

            $logMessage = $usersDepartmentId
                ? "Denied $documentType: $fileName for department"
                : "Denied $documentType: $fileName";
            logActivity($userId, $logMessage, $fileId);

            sendNotification(
                $senderId,
                "Your $documentType '$fileName' was denied by $username",
                $fileId,
                'info'
            );

            sendResponse(true, 'File denied successfully', $redirect, true, 200);
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Transaction error in handle_file_acceptance.php: " . $e->getMessage());
        sendResponse(false, 'Error: ' . $e->getMessage(), null, true, 500);
    }
} catch (Exception $e) {
    error_log("Error in handle_file_acceptance.php: " . $e->getMessage());
    sendResponse(false, 'Error: ' . $e->getMessage(), null, true, 500);
}
