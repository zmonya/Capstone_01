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
        throw new Exception('User not logged in.');
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
        sendResponse(false, 'Invalid request method.', [], 405);
    }
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        sendResponse(false, 'Invalid CSRF token.', [], 403);
    }

    $userId = validateUserSession();
    $formData = $_POST;
    $departmentId = filter_var($formData['department_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$departmentId || $departmentId <= 0) {
        error_log('Department ID missing');
        sendResponse(false, 'Department ID missing.', [], 400);
    }

    $fileName = filter_var($formData['file_name'] ?? 'Untitled', FILTER_SANITIZE_STRING);
    $documentType = filter_var($formData['document_type'] ?? null, FILTER_SANITIZE_STRING);
    if (!$documentType) {
        error_log('Document type missing');
        sendResponse(false, 'Document type is required.', [], 400);
    }

    $storageMetadata = json_decode($formData['storage_metadata'] ?? '{}', true);
    if (!$storageMetadata || !isset($storageMetadata['location_id']) || !filter_var($storageMetadata['location_id'], FILTER_VALIDATE_INT)) {
        error_log('Invalid or missing storage metadata: ' . print_r($formData['storage_metadata'], true));
        sendResponse(false, 'Invalid storage metadata.', [], 400);
    }

    global $pdo;
    $pdo->beginTransaction();

    // Get document type ID
    $stmt = $pdo->prepare("SELECT Document_type_id FROM documents_type_fields WHERE Field_name = ?");
    $stmt->execute([$documentType]);
    $documentTypeId = $stmt->fetchColumn();
    if ($documentTypeId === false) {
        $pdo->rollBack();
        throw new Exception("Invalid document type: $documentType");
    }

    // Insert into files table
    $finalFilePath = "physical/pending_" . time();
    $stmt = $pdo->prepare("
        INSERT INTO files (
            File_name, File_path, User_id, File_type, Document_type_id, File_status
        ) VALUES (?, ?, ?, 'pdf', ?, 'pending')
    ");
    $success = $stmt->execute([$fileName, $finalFilePath, $userId, $documentTypeId]);
    if (!$success) {
        $pdo->rollBack();
        throw new Exception('Failed to insert file into files table');
    }

    $fileId = $pdo->lastInsertId();

    // Update file_path with file ID
    $finalFilePath = "physical/{$fileId}";
    $stmt = $pdo->prepare("UPDATE files SET File_path = ? WHERE File_id = ?");
    $stmt->execute([$finalFilePath, $fileId]);

    // Store storage metadata in transaction table
    $stmt = $pdo->prepare("
        INSERT INTO transaction (User_id, File_id, Transaction_status, Transaction_type, Time, Massage)
        VALUES (?, ?, 'completed', 13, NOW(), ?)
    ");
    $stmt->execute([$userId, $fileId, "Storage location ID: {$storageMetadata['location_id']}"]);

    // Store hard copy status in transaction table
    $stmt = $pdo->prepare("
        INSERT INTO transaction (User_id, File_id, Transaction_status, Transaction_type, Time, Massage)
        VALUES (?, ?, 'completed', 8, NOW(), ?)
    ");
    $stmt->execute([$userId, $fileId, "Hard copy available for file: $fileName"]);

    // Fetch dynamic fields for the document type
    $stmt = $pdo->prepare("
        SELECT Field_name 
        FROM documents_type_fields 
        WHERE Document_type_id = ?
    ");
    $stmt->execute([$documentTypeId]);
    $fields = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Store document-specific metadata in transaction table
    if (!empty($fields)) {
        foreach ($fields as $fieldName) {
            if (isset($formData[$fieldName]) && $formData[$fieldName] !== '') {
                $metaValue = filter_var($formData[$fieldName], FILTER_SANITIZE_STRING);
                $stmt = $pdo->prepare("
                    INSERT INTO transaction (User_id, File_id, Transaction_status, Transaction_type, Time, Massage)
                    VALUES (?, ?, 'completed', 9, NOW(), ?)
                ");
                $stmt->execute([$userId, $fileId, "$fieldName=$metaValue"]);
            }
        }
    }

    // Insert ownership record
    $stmt = $pdo->prepare("
        INSERT INTO transaction (User_id, File_id, Transaction_status, Transaction_type, Time, Massage)
        VALUES (?, ?, 'accepted', 4, NOW(), ?)
    ");
    $stmt->execute([$userId, $fileId, "Original ownership for file: $fileName"]);

    // Log activity
    logActivity($userId, "Saved file details for '$fileName'", $fileId);

    $pdo->commit();
    sendResponse(true, 'File details saved successfully', ['file_id' => $fileId], 200);
} catch (Exception $e) {
    $pdo->rollBack();
    error_log('Error saving file details: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
    sendResponse(false, 'Error saving file: ' . $e->getMessage(), [], 500);
}
