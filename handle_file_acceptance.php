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
    $redirect = filter_var($_POST['redirect'] ?? '', FILTER_SANITIZE_URL);

    if (!$notificationId || !$fileId || !$action || !in_array($action, ['accept', 'deny'])) {
        sendResponse(false, 'Missing or invalid parameters', null, true, 400);
    }

    $userId = validateUserSession();

    global $pdo;
    $pdo->beginTransaction();

    // Get file and sender details
    $stmt = $pdo->prepare("
        SELECT f.file_name, f.document_type_id, dt.type_name AS document_type, t.user_id AS sender_id, u.username
        FROM transactions t
        JOIN files f ON t.file_id = f.file_id
        LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
        JOIN users u ON t.user_id = u.user_id
        WHERE t.transaction_id = ? AND t.transaction_type = 'file_sent' AND t.description = 'pending'
    ");
    $stmt->execute([$notificationId]);
    $fileDetails = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$fileDetails) {
        $pdo->rollBack();
        sendResponse(false, 'Notification or file not found', null, true, 404);
    }

    $fileName = $fileDetails['file_name'];
    $documentType = $fileDetails['document_type'] ?? 'File';
    $senderId = $fileDetails['sender_id'];
    $username = $fileDetails['username'];

    // Get user's department
    $stmt = $pdo->prepare("SELECT users_department_id FROM users_department WHERE user_id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $usersDepartmentId = $stmt->fetchColumn() ?: null;

    if ($action === 'accept') {
        // Update transfer status
        $stmt = $pdo->prepare("
            UPDATE transactions 
            SET description = 'accepted', transaction_time = NOW()
            WHERE file_id = ? AND (user_id = ? OR users_department_id = ?)
            AND transaction_type = 'file_sent' AND description = 'pending'
        ");
        $stmt->execute([$fileId, $userId, $usersDepartmentId]);

        // Update notification status
        $stmt = $pdo->prepare("
            UPDATE transactions 
            SET description = 'accepted'
            WHERE transaction_id = ? AND user_id = ? AND transaction_type = 'notification'
        ");
        $stmt->execute([$notificationId, $userId]);

        $logMessage = $usersDepartmentId
            ? "Accepted $documentType: $fileName for department"
            : "Accepted $documentType: $fileName";
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
            UPDATE transactions 
            SET description = 'denied', transaction_time = NOW()
            WHERE file_id = ? AND (user_id = ? OR users_department_id = ?)
            AND transaction_type = 'file_sent' AND description = 'pending'
        ");
        $stmt->execute([$fileId, $userId, $usersDepartmentId]);

        // Update notification status
        $stmt = $pdo->prepare("
            UPDATE transactions 
            SET description = 'denied'
            WHERE transaction_id = ? AND user_id = ? AND transaction_type = 'notification'
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
