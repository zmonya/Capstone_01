<?php
session_start();
require 'db_connection.php';

header('Content-Type: application/json');

// CSRF Validation
if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

$departmentId = filter_var($_POST['department_id'], FILTER_SANITIZE_NUMBER_INT);
$documentType = filter_var($_POST['document_type'], FILTER_SANITIZE_STRING);

try {
    $stmt = $pdo->prepare("
        SELECT meta_data
        FROM files
        WHERE Department_id = ? AND Document_type_id = (SELECT Document_type_id FROM documents_type_fields WHERE Field_name = ?)
        AND Copy_type = 'hard' AND File_status != 'deleted'
        ORDER BY Upload_date DESC LIMIT 1
    ");
    $stmt->execute([$departmentId, $documentType]);
    $lastMeta = $stmt->fetchColumn();

    $metadata = json_decode($lastMeta, true) ?: ['cabinet' => 'A', 'layer' => 1, 'box' => 1, 'folder' => 1];
    $metadata['folder'] = isset($metadata['folder']) ? $metadata['folder'] + 1 : 1;

    echo json_encode(['success' => true, 'metadata' => $metadata]);
} catch (Exception $e) {
    error_log("Storage suggestion error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to fetch storage suggestion.']);
}