<?php
session_start();
require 'db_connection.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User not authenticated']);
    exit;
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$documentTypeName = filter_var($_POST['document_type_name'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS);

if (!$documentTypeName) {
    echo json_encode(['success' => false, 'message' => 'Document type is required']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT Field_name AS field_name, Field_name AS field_label, Field_type AS field_type, Is_required AS is_required
        FROM documents_type_fields
        WHERE Document_type_id = (SELECT Document_type_id FROM documents_type_fields WHERE Field_name = ? LIMIT 1)
        AND Field_type != 'document_type'
    ");
    $stmt->execute([$documentTypeName]);
    $fields = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => ['fields' => $fields]]);
} catch (Exception $e) {
    error_log("Error fetching document type fields: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error fetching metadata fields']);
}
?>