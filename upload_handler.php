<?php
session_start();
require 'db_connection.php';
require 'log_activity.php';

const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB
const UPLOAD_DIR = __DIR__ . '/Uploads/';
const VALID_FILE_TYPES = ['pdf', 'docx', 'txt', 'png', 'jpg', 'jpeg', 'csv', 'xlsx'];

function generateResponse(bool $success, string $message = '', array $data = [], int $statusCode = 200): void
{
    header('Content-Type: application/json');
    http_response_code($statusCode);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}

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
        throw new Exception('Unsupported file type.');
    }
    return [
        'name' => $fileName,
        'safe_name' => $safeFileName,
        'size' => $file['size'],
        'type' => $fileType,
        'tmp_name' => $file['tmp_name']
    ];
}

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
        throw new Exception('Invalid request method.', 405);
    }
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('User not authenticated.', 401);
    }
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        throw new Exception('Invalid CSRF token.', 403);
    }

    $fileDetails = validateFile($_FILES['file'] ?? []);
    $documentTypeId = filter_var($_POST['document_type_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
    $parentFileId = filter_var($_POST['parent_file_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
    $metaData = filter_var($_POST['meta_data'] ?? '', FILTER_SANITIZE_STRING) ?: null;
    $physicalStorage = filter_var($_POST['physical_storage'] ?? '', FILTER_SANITIZE_STRING) ?: null;
    $copyType = filter_var($_POST['copy_type'] ?? '', FILTER_SANITIZE_STRING) ?: null;

    if ($physicalStorage && !preg_match('/^[A-Z][0-9]+(\/[A-Z][0-9]+){3}$/', $physicalStorage)) {
        throw new Exception('Invalid physical storage path format.');
    }

    $uploadDir = ensureUploadDirectory();
    $fileNamePrefix = bin2hex(random_bytes(8)) . '_' . $fileDetails['safe_name'];
    $destinationPath = $uploadDir . $fileNamePrefix;

    if (!move_uploaded_file($fileDetails['tmp_name'], $destinationPath)) {
        throw new Exception('Failed to move uploaded file.');
    }

    global $pdo;
    $stmt = $pdo->prepare("
        INSERT INTO files (
            file_name, file_path, user_id, file_size, file_type, file_status,
            parent_file_id, meta_data, upload_date, document_type_id, physical_storage_path, copy_type
        )
        VALUES (?, ?, ?, ?, ?, 'pending_ocr', ?, ?, NOW(), ?, ?, ?)
    ");
    $relativeFilePath = 'Uploads/' . $fileNamePrefix;
    $stmt->execute([
        $fileDetails['name'],
        $relativeFilePath,
        $_SESSION['user_id'],
        $fileDetails['size'],
        $fileDetails['type'],
        $parentFileId,
        $metaData ?: null,
        $documentTypeId,
        $physicalStorage ?: null,
        $copyType ?: null
    ]);
    $fileId = $pdo->lastInsertId();

    // Insert into text_repository with null extracted_text
    if (in_array($fileDetails['type'], ['pdf', 'docx', 'txt', 'png', 'jpg', 'jpeg', 'csv', 'xlsx'])) {
        $stmt = $pdo->prepare("
            INSERT INTO text_repository (file_id, extracted_text)
            VALUES (?, NULL)
        ");
        $stmt->execute([$fileId]);
    }

    // Log the upload transaction
    $stmt = $pdo->prepare("
        INSERT INTO transactions (user_id, file_id, transaction_type, transaction_status, transaction_time, description)
        VALUES (?, ?, 'upload', 'completed', NOW(), ?)
    ");
    $stmt->execute([
        $_SESSION['user_id'],
        $fileId,
        'Uploaded ' . $fileDetails['name']
    ]);

    // Trigger background OCR processing
    $logFile = __DIR__ . '/logs/ocr_processor.log';
    $ocrScript = __DIR__ . '/ocr_processor.php';
    if (!file_exists($ocrScript) || !is_readable($ocrScript)) {
        error_log("OCR processor script not found or not readable: $ocrScript", 3, $logFile);
        generateResponse(true, 'File uploaded successfully, but OCR processing could not be scheduled.', ['file_id' => $fileId], 200);
    } else {
        $command = escapeshellcmd("php $ocrScript $fileId >> $logFile 2>&1");
        $output = [];
        $returnCode = 0;
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            exec("start /B $command 2>&1", $output, $returnCode);
        } else {
            exec("$command &", $output, $returnCode);
        }
        if ($returnCode !== 0) {
            error_log("Failed to start OCR processing for file ID $fileId: " . implode("\n", $output), 3, $logFile);
            generateResponse(true, 'File uploaded successfully, but OCR processing could not be scheduled.', ['file_id' => $fileId], 200);
        } else {
            generateResponse(true, 'File uploaded successfully. OCR processing scheduled.', ['file_id' => $fileId], 200);
        }
    }
} catch (Exception $e) {
    error_log('Upload error: ' . $e->getMessage() . ' | Line: ' . $e->getLine());
    generateResponse(false, 'Upload failed: ' . $e->getMessage(), [], $e->getCode() ?: 500);
}
