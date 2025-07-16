<?php
session_start();
require 'db_connection.php';
require 'vendor/autoload.php'; // Load Composer autoloader for phpdotenv

use Dotenv\Dotenv;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Disable error display in production
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

/**
 * Generates a JSON response with appropriate HTTP status.
 *
 * @param bool $success
 * @param array|string $data
 * @param int $statusCode
 * @return void
 */
function sendResponse(bool $success, $data, int $statusCode): void
{
    header('Content-Type: application/json');
    http_response_code($statusCode);
    echo json_encode($success ? ['success' => true, 'data' => $data] : ['success' => false, 'error' => $data]);
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
        throw new Exception('Unauthorized');
    }
    return (int)$_SESSION['user_id'];
}

try {
    $userId = validateUserSession();
    $fileId = filter_input(INPUT_GET, 'file_id', FILTER_VALIDATE_INT);
    if (!$fileId || $fileId <= 0) {
        sendResponse(false, 'Invalid file ID', 400);
    }

    global $pdo;

    // Authorization check: user must be uploader or in same department
    $authQuery = "
        SELECT COUNT(*) 
        FROM files f
        LEFT JOIN users_department ud ON f.User_id = ud.User_id
        WHERE f.File_id = ? 
        AND f.File_status != 'deleted'
        AND (f.User_id = ? OR ud.Department_id IN (
            SELECT Department_id 
            FROM users_department 
            WHERE User_id = ?
        ))
    ";
    $authStmt = $pdo->prepare($authQuery);
    $authStmt->execute([$fileId, $userId, $userId]);
    if ($authStmt->fetchColumn() == 0) {
        sendResponse(false, 'You do not have permission to view this file', 403);
    }

    // Fetch file details
    $query = "
        SELECT 
            f.File_id AS id,
            f.File_name AS file_name,
            f.File_path AS file_path,
            f.File_size AS file_size,
            f.Upload_date AS upload_date,
            f.Document_type_id AS document_type_id,
            f.User_id AS user_id,
            u.Username AS uploader_name,
            dt.Field_name AS document_type,
            d.Department_name AS department_name
        FROM files f
        JOIN users u ON f.User_id = u.User_id
        LEFT JOIN documents_type_fields dt ON f.Document_type_id = dt.Document_type_id
        LEFT JOIN users_department ud ON f.User_id = ud.User_id
        LEFT JOIN departments d ON ud.Department_id = d.Department_id
        WHERE f.File_id = ? AND f.File_status != 'deleted'
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$fileId]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$file) {
        sendResponse(false, 'File not found or deleted', 404);
    }

    // Handle NULL file_size
    if ($file['file_size'] === null && $file['file_path']) {
        $filePath = realpath(__DIR__ . '/' . $file['file_path']);
        if ($filePath && file_exists($filePath)) {
            $fileSize = filesize($filePath);
            if ($fileSize > 0) {
                $updateStmt = $pdo->prepare("UPDATE files SET File_size = ? WHERE File_id = ?");
                $updateStmt->execute([$fileSize, $file['id']]);
                $file['file_size'] = $fileSize;
                error_log("Updated file_size for file ID {$file['id']} to $fileSize bytes");
            } else {
                error_log("Invalid file size (0 bytes) for file ID {$file['id']}: {$file['file_path']}");
            }
        } else {
            error_log("File not found for file ID {$file['id']}: {$file['file_path']}");
        }
    }

    // Fetch document type fields
    $fieldStmt = $pdo->prepare("
        SELECT Field_name AS field_name, Field_label AS field_label, Field_type AS field_type, Is_required AS is_required 
        FROM documents_type_fields 
        WHERE Document_type_id = ?
    ");
    $fieldStmt->execute([$file['document_type_id']]);
    $fields = $fieldStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch metadata (simulated via transaction with type 9)
    $metaStmt = $pdo->prepare("
        SELECT Massage AS meta_value
        FROM transaction
        WHERE File_id = ? AND Transaction_type = 9
    ");
    $metaStmt->execute([$fileId]);
    $metadata = [];
    foreach ($metaStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        parse_str($row['meta_value'], $metaPairs);
        $metadata = array_merge($metadata, $metaPairs);
    }

    // Prepare response
    $response = [
        'id' => $file['id'],
        'file_name' => $file['file_name'] ?? 'Unnamed File',
        'file_path' => $file['file_path'] ?? null,
        'file_size' => $file['file_size'],
        'upload_date' => $file['upload_date'] ? date('Y-m-d H:i:s', strtotime($file['upload_date'])) : 'N/A',
        'hard_copy_available' => false, // Simulated via transaction
        'uploader_name' => $file['uploader_name'] ?? 'Unknown User',
        'document_type' => $file['document_type'] ?? 'Uncategorized',
        'file_type' => $file['file_path'] ? pathinfo($file['file_path'], PATHINFO_EXTENSION) : 'N/A',
        'cabinet_name' => 'N/A', // No storage_locations table
        'layer' => 'N/A',
        'box' => 'N/A',
        'folder' => 'N/A',
        'department_name' => $file['department_name'] ?? 'No Department',
        'sub_department_name' => 'N/A', // No sub_departments table
        'pages' => $metadata['pages'] ?? 'N/A',
        'purpose' => $metadata['purpose'] ?? 'Not specified',
        'metadata' => $metadata ?: []
    ];

    // Dynamically add metadata fields
    foreach ($fields as $field) {
        $key = $field['field_name'];
        $response[$key] = $metadata[$key] ?? 'N/A';
    }

    // Log request in transaction table
    $logStmt = $pdo->prepare("
        INSERT INTO transaction (User_id, File_id, Transaction_status, Transaction_type, Time, Massage)
        VALUES (?, ?, 'completed', 10, NOW(), 'Fetched file info')
    ");
    $logStmt->execute([$userId, $fileId]);

    sendResponse(true, $response, 200);
} catch (Exception $e) {
    error_log("Error in get_file_info.php: " . $e->getMessage());
    sendResponse(false, 'Server error: ' . $e->getMessage(), 500);
}
