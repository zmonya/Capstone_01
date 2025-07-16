<?php
session_start();
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
        sendResponse(false, 'Invalid request method', 405);
    }

    // Validate CSRF token
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($csrfToken)) {
        error_log("CSRF token validation failed for user: " . ($_SESSION['user_id'] ?? 'unknown'));
        sendResponse(false, 'Invalid CSRF token', 403);
    }

    // Validate document type name
    $documentTypeName = filter_input(INPUT_POST, 'document_type_name', FILTER_SANITIZE_STRING);
    $documentTypeName = trim(strtolower($documentTypeName ?? ''));
    if (empty($documentTypeName)) {
        sendResponse(false, 'No document type specified', 400);
    }

    global $pdo;

    // Fetch document type ID
    $stmt = $pdo->prepare("
        SELECT Document_type_id AS id 
        FROM documents_type_fields 
        WHERE LOWER(Field_name) = ? 
        LIMIT 1
    ");
    $stmt->execute([$documentTypeName]);
    $docType = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$docType) {
        sendResponse(false, "Document type '$documentTypeName' not found", 404);
    }

    // Fetch fields for the document type
    $stmt = $pdo->prepare("
        SELECT Field_name AS field_name, Field_label AS field_label, Field_type AS field_type, Is_required AS is_required
        FROM documents_type_fields
        WHERE Document_type_id = ?
        ORDER BY Document_type_id ASC
    ");
    $stmt->execute([$docType['id']]);
    $fields = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Log request in transaction table
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $logStmt = $pdo->prepare("
        INSERT INTO transaction (User_id, Transaction_status, Transaction_type, Time, Massage)
        VALUES (?, 'completed', 7, NOW(), 'Fetched document type fields')
    ");
    $logStmt->execute([$userId]);

    sendResponse(true, ['fields' => $fields], 200);
} catch (PDOException $e) {
    error_log("Database error in get_document_type_field.php: " . $e->getMessage());
    sendResponse(false, 'Database error occurred', 500);
} catch (Exception $e) {
    error_log("Unexpected error in get_document_type_field.php: " . $e->getMessage());
    sendResponse(false, 'An unexpected error occurred', 500);
}
