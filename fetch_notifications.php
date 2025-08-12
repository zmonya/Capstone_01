<?php
session_start();
require 'db_connection.php';

header('Content-Type: application/json');

$userId = filter_var($_SESSION['user_id'], FILTER_SANITIZE_NUMBER_INT);

try {
    $stmt = $pdo->prepare("
        SELECT t.Transaction_id AS id, t.File_id, t.Transaction_status AS status, t.Time AS timestamp, t.Massage AS message
        FROM transaction t
        LEFT JOIN files f ON t.File_id = f.File_id
        WHERE t.User_id = ? AND t.Transaction_type = 12
        AND (f.File_status != 'deleted' OR f.File_id IS NULL)
        ORDER BY t.Time DESC
        LIMIT 5
    ");
    $stmt->execute([$userId]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'notifications' => $notifications]);
} catch (Exception $e) {
    error_log("Fetch notifications error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to fetch notifications.']);
}