<?php
session_start();
require 'db_connection.php';

header('Content-Type: application/json');

$userId = filter_var($_SESSION['user_id'], FILTER_SANITIZE_NUMBER_INT);

try {
    $stmt = $pdo->prepare("
        SELECT t.transaction_id AS id, t.file_id, t.description AS status, t.transaction_time AS timestamp, t.description AS message
        FROM transactions t
        LEFT JOIN files f ON t.file_id = f.file_id
        WHERE t.user_id = ? AND t.transaction_type = 'notification'
        AND (f.file_status != 'deleted' OR f.file_id IS NULL)
        ORDER BY t.transaction_time DESC
        LIMIT 5
    ");
    $stmt->execute([$userId]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'notifications' => $notifications]);
} catch (Exception $e) {
    error_log("Fetch notifications error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to fetch notifications.']);
}
