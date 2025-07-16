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

// Configuration
const ENCRYPTION_METHOD = 'AES-256-CBC';
$ENCRYPTION_KEY = hex2bin(getenv('ENCRYPTION_KEY') ?: throw new Exception('Encryption key not found.'));

/**
 * Decrypts file content using OpenSSL (for verification, not used in send).
 *
 * @param string $encryptedData
 * @return string
 * @throws Exception If decryption fails
 */
function decryptFile(string $encryptedData): string
{
    global $ENCRYPTION_KEY, $ENCRYPTION_METHOD;
    $data = base64_decode($encryptedData);
    $ivLength = openssl_cipher_iv_length($ENCRYPTION_METHOD);
    $iv = substr($data, 0, $ivLength);
    $encrypted = substr($data, $ivLength);
    $decrypted = openssl_decrypt($encrypted, $ENCRYPTION_METHOD, $ENCRYPTION_KEY, 0, $iv);
    if ($decrypted === false) {
        throw new Exception('File decryption failed.');
    }
    return $decrypted;
}

/**
 * Generates a JSON response.
 *
 * @param bool $success
 * @param string $message
 * @param string|null $redirect
 * @return string JSON-encoded response
 */
function generateResponse(bool $success, string $message, ?string $redirect = null): string
{
    $response = ['success' => $success, 'message' => $message];
    if ($redirect) {
        $response['redirect'] = $redirect;
    }
    return json_encode($response);
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
    // Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Content-Type: application/json');
        echo generateResponse(false, 'Invalid request method');
        exit;
    }

    // Validate CSRF token
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($csrfToken)) {
        error_log("CSRF token validation failed for user: " . ($_SESSION['user_id'] ?? 'unknown'));
        echo generateResponse(false, 'Invalid CSRF token.');
        exit;
    }

    // Prevent duplicate requests
    $requestId = hash('sha256', uniqid(mt_rand(), true));
    if (isset($_SESSION['last_request_id']) && $_SESSION['last_request_id'] === $requestId) {
        echo generateResponse(false, 'Duplicate request detected');
        exit;
    }
    $_SESSION['last_request_id'] = $requestId;

    // Validate user and input
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $fileId = filter_var($_POST['file_id'] ?? 0, FILTER_VALIDATE_INT);
    $recipients = $_POST['recipients'] ?? [];

    if (!$userId || !$fileId || empty($recipients) || !is_array($recipients)) {
        echo generateResponse(false, 'Missing or invalid parameters');
        exit;
    }

    global $pdo;

    // Check permissions
    $stmt = $pdo->prepare("
        SELECT 
            (SELECT COUNT(*) FROM file_owners WHERE file_id = ? AND user_id = ?) AS is_owner,
            (SELECT User_id FROM files WHERE File_id = ? AND User_id = ?) AS is_uploader
    ");
    $stmt->execute([$fileId, $userId, $fileId, $userId]);
    $permissions = $stmt->fetch();

    if (!$permissions['is_owner'] && !$permissions['is_uploader']) {
        echo generateResponse(false, 'You do not have permission to send this file');
        exit;
    }

    // Fetch file details
    $stmt = $pdo->prepare("
        SELECT f.*, dt.Field_name AS document_type 
        FROM files f 
        LEFT JOIN documents_type_fields dt ON f.Document_type_id = dt.Document_type_id 
        WHERE f.File_id = ?
    ");
    $stmt->execute([$fileId]);
    $file = $stmt->fetch();
    if (!$file) {
        echo generateResponse(false, 'File not found');
        exit;
    }

    $fileName = $file['File_name'];
    $documentType = $file['document_type'] ?? 'Unknown Type';

    // Process recipients
    $recipientUsernames = [];
    $recipientDepartmentNames = [];
    $departmentIds = [];

    $pdo->beginTransaction();

    foreach ($recipients as $recipient) {
        $recipientData = explode(':', $recipient);
        if (count($recipientData) !== 2) {
            continue;
        }

        $type = $recipientData[0];
        $id = (int)$recipientData[1];

        if ($type === 'user') {
            $stmt = $pdo->prepare("SELECT Username FROM users WHERE User_id = ?");
            $stmt->execute([$id]);
            $user = $stmt->fetch();
            if ($user) {
                $recipientUsernames[] = $user['Username'];
                // Log send as transaction
                $message = "Sent $documentType: $fileName to user: {$user['Username']}";
                $stmt = $pdo->prepare("
                    INSERT INTO transaction (User_id, File_id, Transaction_status, Transaction_type, Time, Massage)
                    VALUES (?, ?, 'pending', ?, NOW(), ?)
                ");
                $stmt->execute([$id, $fileId, getTransactionType('send'), $message]);

                // Send notification
                sendNotification(
                    $id,
                    "You have received a new $documentType: $fileName. Please review and accept or deny.",
                    $fileId,
                    'received'
                );
            }
        } elseif ($type === 'department') {
            $stmt = $pdo->prepare("SELECT Department_name FROM departments WHERE Department_id = ?");
            $stmt->execute([$id]);
            $department = $stmt->fetch();
            if ($department) {
                $departmentName = $department['Department_name'];
                $recipientDepartmentNames[] = $departmentName;
                $departmentIds[] = $id;

                $stmt = $pdo->prepare("
                    SELECT User_id FROM users_department 
                    WHERE Department_id = ?
                ");
                $stmt->execute([$id]);
                $deptUsers = $stmt->fetchAll(PDO::FETCH_COLUMN);

                foreach ($deptUsers as $deptUserId) {
                    $message = "Sent $documentType: $fileName to department: $departmentName";
                    $stmt = $pdo->prepare("
                        INSERT INTO transaction (User_id, File_id, Transaction_status, Transaction_type, Time, Massage)
                        VALUES (?, ?, 'pending', ?, NOW(), ?)
                    ");
                    $stmt->execute([$deptUserId, $fileId, getTransactionType('send'), $message]);

                    sendNotification(
                        $deptUserId,
                        "Your department ($departmentName) received a new $documentType: $fileName.",
                        $fileId,
                        'received'
                    );
                }
            }
        }
    }

    // Log the send activity
    $recipientsList = [];
    if (!empty($recipientUsernames)) {
        $recipientsList[] = implode(', ', $recipientUsernames);
    }
    if (!empty($recipientDepartmentNames)) {
        $recipientsList[] = implode(', ', $recipientDepartmentNames);
    }
    $toWhom = !empty($recipientsList) ? " to " . implode(' and ', $recipientsList) : "";
    logActivity($userId, "Sent $documentType: $fileName$toWhom", $fileId);
    logFileActivity($userId, $fileName, $fileId, $departmentIds);

    $pdo->commit();
    echo generateResponse(true, 'File sent successfully', 'my-folder.php');
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Send file error for user: " . ($_SESSION['user_id'] ?? 'unknown') . " - " . $e->getMessage());
    echo generateResponse(false, 'Error: ' . $e->getMessage());
}
exit;
