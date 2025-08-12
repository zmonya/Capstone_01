<?php
session_start();
require 'db_connection.php';

header('Content-Type: application/json');

// CSRF Validation
if (empty($_GET['csrf_token']) || $_GET['csrf_token'] !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

$term = filter_var($_GET['term'], FILTER_SANITIZE_STRING);

try {
    $stmt = $pdo->prepare("
        SELECT f.File_name AS value, dtf.Field_name AS document_type, d.Department_id
        FROM files f
        LEFT JOIN documents_type_fields dtf ON f.Document_type_id = dtf.Document_type_id
        LEFT JOIN users_department ud ON f.User_id = ud.User_id
        LEFT JOIN departments d ON ud.Department_id = d.Department_id
        WHERE f.File_name LIKE ? AND f.File_status != 'deleted'
        LIMIT 10
    ");
    $stmt->execute(['%' . $term . '%']);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'results' => $results]);
} catch (Exception $e) {
    error_log("Autocomplete error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error fetching autocomplete suggestions.']);
}