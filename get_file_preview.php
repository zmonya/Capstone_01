<?php
session_start();
require 'db_connection.php';
require 'vendor/autoload.php'; // Load Composer autoloader for phpdotenv

use Dotenv\Dotenv;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

const ENCRYPTION_METHOD = 'AES-256-CBC';
$ENCRYPTION_KEY = hex2bin(getenv('ENCRYPTION_KEY') ?: throw new Exception('Encryption key not found.'));

/**
 * Generates an HTML response with appropriate HTTP status.
 *
 * @param string $content
 * @param int $statusCode
 * @return void
 */
function sendHtmlResponse(string $content, int $statusCode): void
{
    header('Content-Type: text/html; charset=utf-8');
    http_response_code($statusCode);
    echo $content;
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
        throw new Exception('User not authenticated. Please log in.');
    }
    return (int)$_SESSION['user_id'];
}

/**
 * Decrypts file content using OpenSSL.
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

try {
    $userId = validateUserSession();
    $fileId = filter_input(INPUT_GET, 'file_id', FILTER_VALIDATE_INT);
    if (!$fileId || $fileId <= 0) {
        sendHtmlResponse('<p>No file ID provided.</p>', 400);
    }

    global $pdo;

    // Check permissions: owner (Transaction_type = 4), transfer recipient (Transaction_type = 2), or department recipient
    $authStmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM transaction t
        WHERE t.File_id = ? 
        AND t.User_id = ? 
        AND t.Transaction_type IN (2, 4) 
        AND t.Transaction_status IN ('pending', 'accepted')
        UNION
        SELECT COUNT(*) 
        FROM transaction t
        JOIN users_department ud ON t.Users_Department_id = ud.Users_Department_id
        WHERE t.File_id = ? 
        AND ud.User_id = ? 
        AND t.Transaction_type = 2 
        AND t.Transaction_status = 'pending'
    ");
    $authStmt->execute([$fileId, $userId, $fileId, $userId]);
    $hasPermission = false;
    while ($row = $authStmt->fetchColumn()) {
        if ($row > 0) {
            $hasPermission = true;
            break;
        }
    }

    if (!$hasPermission) {
        sendHtmlResponse('<p>You do not have permission to preview this file.</p>', 403);
    }

    // Fetch file details
    $stmt = $pdo->prepare("
        SELECT File_name AS file_name, File_path AS file_path, File_type AS file_type 
        FROM files 
        WHERE File_id = ? AND File_status != 'deleted'
    ");
    $stmt->execute([$fileId]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$file) {
        sendHtmlResponse('<p>File not found or has been deleted.</p>', 404);
    }

    $filePath = realpath(__DIR__ . '/' . $file['file_path']);
    $fileName = htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8');
    $fileType = strtolower($file['file_type'] ?? 'unknown');

    // Verify file exists
    if (!$filePath || !file_exists($filePath)) {
        sendHtmlResponse('<p>File not found on server.</p>', 404);
    }

    // Decrypt file content
    $encryptedContent = file_get_contents($filePath);
    $decryptedContent = decryptFile($encryptedContent);

    // Generate temporary file for preview
    $tempFile = tempnam(sys_get_temp_dir(), 'preview_');
    file_put_contents($tempFile, $decryptedContent);

    // Generate preview based on file type
    $output = '';
    switch ($fileType) {
        case 'pdf':
            $output = "<iframe src='data:application/pdf;base64," . base64_encode($decryptedContent) . "' style='width: 100%; height: 500px; border: none;'></iframe>";
            break;
        case 'jpg':
        case 'png':
            $output = "<img src='data:image/$fileType;base64," . base64_encode($decryptedContent) . "' alt='$fileName' style='max-width: 100%; height: auto;'>";
            break;
        case 'doc':
        case 'docx':
            $output = "<p>Preview not available for Word documents.</p>";
            break;
        case 'xls':
        case 'xlsx':
            $output = "<p>Preview not available for Excel files.</p>";
            break;
        case 'txt':
            $content = htmlspecialchars($decryptedContent, ENT_QUOTES, 'UTF-8');
            $output = "<pre style='background: #f4f4f4; padding: 10px; max-height: 500px; overflow-y: auto;'>$content</pre>";
            break;
        default:
            $output = "<p>Preview not available for this file type ($fileType).</p>";
    }
    $output .= "<p>File: $fileName</p>";

    // Clean up temporary file
    unlink($tempFile);

    // Log preview request in transaction table
    $logStmt = $pdo->prepare("
        INSERT INTO transaction (User_id, File_id, Transaction_status, Transaction_type, Time, Massage)
        VALUES (?, ?, 'completed', 9, NOW(), 'Previewed file')
    ");
    $logStmt->execute([$userId, $fileId]);

    sendHtmlResponse($output, 200);
} catch (Exception $e) {
    error_log("Error in get_file_preview.php: " . $e->getMessage());
    sendHtmlResponse('<p>Server error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>', 500);
}
