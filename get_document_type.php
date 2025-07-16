<?php
require 'db_connection.php';
require 'vendor/autoload.php'; // Load Composer autoloader for phpdotenv

use Dotenv\Dotenv;

// Load environment variables for consistency
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

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
    echo json_encode($success ? ['success' => true, 'data' => $data] : ['success' => false, 'message' => $data]);
    exit;
}

try {
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$id || $id <= 0) {
        sendResponse(false, 'Invalid document type ID', 400);
    }

    global $pdo;

    // Fetch document type
    $stmt = $pdo->prepare("
        SELECT Document_type_id AS id, Field_name AS name, Field_label, Field_type, Is_required
        FROM documents_type_fields 
        WHERE Document_type_id = ?
    ");
    $stmt->execute([$id]);
    $document_type = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$document_type) {
        sendResponse(false, 'Document type not found', 404);
    }

    // Fetch fields (same table, as document_type_fields is merged into documents_type_fields)
    $fieldStmt = $pdo->prepare("
        SELECT Field_name AS field_name, Field_label AS field_label, Field_type AS field_type, Is_required AS is_required
        FROM documents_type_fields 
        WHERE Document_type_id = ?
    ");
    $fieldStmt->execute([$id]);
    $fields = $fieldStmt->fetchAll(PDO::FETCH_ASSOC);

    // Log request in transaction table
    $logStmt = $pdo->prepare("
        INSERT INTO transaction (Transaction_status, Transaction_type, Time, Massage)
        VALUES ('completed', 7, NOW(), 'Fetched document type info')
    ");
    $logStmt->execute();

    sendResponse(true, ['document_type' => $document_type, 'fields' => $fields], 200);
} catch (Exception $e) {
    error_log("Error in get_document_type.php: " . $e->getMessage());
    sendResponse(false, 'Database error: ' . $e->getMessage(), 500);
}
