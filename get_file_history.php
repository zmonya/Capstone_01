<?php
session_start();
require 'db_connection.php';

header('Content-Type: application/json');

if (!isset($_GET['file_id'])) {
    echo json_encode(['error' => 'File ID required']);
    exit;
}

$fileId = intval($_GET['file_id']);
$userId = $_SESSION['user_id'];

try {
    // Check access
    $stmt = $pdo->prepare("
        SELECT f.*, u.username as owner
        FROM files f
        LEFT JOIN users u ON f.user_id = u.user_id
        WHERE f.file_id = ? AND f.file_status != 'deleted'
    ");
    $stmt->execute([$fileId]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$file) {
        echo json_encode(['error' => 'File not found']);
        exit;
    }

    // Check access
    $hasAccess = false;
    if ($file['user_id'] == $userId) {
        $hasAccess = true;
    } else {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM transactions 
            WHERE file_id = ? AND user_id = ? AND transaction_type = 'file_sent' 
            AND description IN ('pending', 'accepted')
        ");
        $stmt->execute([$fileId, $userId]);
        $hasAccess = $stmt->fetchColumn() > 0;
    }

    if (!$hasAccess) {
        echo json_encode(['error' => 'Access denied']);
        exit;
    }

    // Get file history
    $stmt = $pdo->prepare("
        SELECT 
            t.*,
            u.username as user_name,
            CASE 
                WHEN t.transaction_type = 'file_upload' THEN 'File Uploaded'
                WHEN t.transaction_type = 'file_sent' THEN 'File Shared'
                WHEN t.transaction_type = 'file_download' THEN 'File Downloaded'
                WHEN t.transaction_type = 'file_edit' THEN 'File Edited'
                ELSE t.transaction_type
            END as action_type
        FROM transactions t
        LEFT JOIN users u ON t.user_id = u.user_id
        WHERE t.file_id = ?
        ORDER BY t.transaction_time DESC
    ");
    $stmt->execute([$fileId]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'history' => $history]);

} catch (Exception $e) {
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
?>
