<?php
session_start();
require 'db_connection.php';
require 'log_activity.php';

// Configuration constants
const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB
const UPLOAD_DIR = __DIR__ . '/uploads/';
const VALID_FILE_TYPES = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'png', 'txt', 'zip', 'other'];

/**
 * Generates a JSON response.
 *
 * @param bool $success
 * @param string $message
 * @param array $data
 * @param int $statusCode
 * @return void
 */
function generateResponse(bool $success, string $message = '', array $data = [], int $statusCode = 200): void
{
    header('Content-Type: application/json');
    http_response_code($statusCode);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}

/**
 * Validates and sanitizes file input.
 *
 * @param array $file
 * @return array Sanitized file details
 * @throws Exception If validation fails
 */
function validateFile(array $file): array
{
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No file uploaded or upload error.');
    }

    if (!is_int($file['size']) || $file['size'] <= 0 || $file['size'] > MAX_FILE_SIZE) {
        throw new Exception('Invalid file size or exceeds 10MB limit.');
    }

    $fileName = trim(filter_var($file['name'], FILTER_SANITIZE_SPECIAL_CHARS));
    $safeFileName = preg_replace('/[^A-Za-z0-9\-\_\.]/', '', $fileName);
    $fileType = strtolower(pathinfo($safeFileName, PATHINFO_EXTENSION));
    if (!in_array($fileType, VALID_FILE_TYPES)) {
        $fileType = 'other';
    }

    return [
        'name' => $fileName,
        'safe_name' => $safeFileName,
        'size' => $file['size'],
        'type' => $fileType,
        'tmp_name' => $file['tmp_name']
    ];
}

/**
 * Ensures the upload directory exists.
 *
 * @return string Upload directory path
 * @throws Exception If directory creation fails
 */
function ensureUploadDirectory(): string
{
    $uploadDir = rtrim(UPLOAD_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        throw new Exception('Failed to create upload directory.');
    }
    return $uploadDir;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method.');
    }

    if (!isset($_SESSION['user_id'])) {
        generateResponse(false, 'User not authenticated.', [], 401);
    }

    $fileDetails = validateFile($_FILES['file'] ?? []);

    $uploadDir = ensureUploadDirectory();
    $fileNamePrefix = bin2hex(random_bytes(8)) . '_' . $fileDetails['safe_name'];
    $destinationPath = $uploadDir . $fileNamePrefix;

    if (!move_uploaded_file($fileDetails['tmp_name'], $destinationPath)) {
        throw new Exception('Failed to move uploaded file.');
    }

    // Insert file record into database (simplified)
    global $pdo;
    $stmt = $pdo->prepare("
        INSERT INTO files (File_name, File_path, User_id, File_size, File_type, File_status)
        VALUES (?, ?, ?, ?, ?, 'active')
    ");
    $relativeFilePath = 'uploads/' . $fileNamePrefix;
    $stmt->execute([
        $fileDetails['name'],
        $relativeFilePath,
        $_SESSION['user_id'],
        $fileDetails['size'],
        $fileDetails['type']
    ]);
    $fileId = $pdo->lastInsertId();

    generateResponse(true, 'File uploaded successfully.', ['file_id' => $fileId], 200);
} catch (Exception $e) {
    error_log('Upload error: ' . $e->getMessage());
    generateResponse(false, 'Upload failed: ' . $e->getMessage(), [], 500);
}
