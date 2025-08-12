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
        SELECT f.File_id AS id, f.File_name AS file_name, f.meta_data
        FROM files f
        JOIN users_department ud ON f.User_id = ud.User_id
        WHERE ud.Department_id = ? AND f.Copy_type = 'hard' 
        AND f.Document_type_id = (SELECT Document_type_id FROM documents_type_fields WHERE Field_name = ?)
        AND f.File_status != 'deleted'
    ");
    $stmt->execute([$departmentId, $documentType]);
    $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'files' => $files]);
} catch (Exception $e) {
    error_log("Fetch hardcopy files error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to fetch hardcopy files.']);
}