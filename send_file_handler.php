<?php
session_start();
require 'db_connection.php';
require 'log_activity.php';

header('Content-Type: application/json');

// CSRF Validation
if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

$fileId = filter_var($_POST['file_id'], FILTER_SANITIZE_NUMBER_INT);
$recipients = $_POST['recipients'] ? array_map('filter_var', $_POST['recipients'], array_fill(0, count($_POST['recipients']), FILTER_SANITIZE_STRING)) : [];

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT User_id FROM files WHERE File_id = ? AND User_id = ? AND File_status != 'deleted'");
    $stmt->execute([$fileId, $_SESSION['user_id']]);
    if (!$stmt->fetch()) {
        throw new Exception('Invalid file or access denied.');
    }

    foreach ($recipients as $recipient) {
        list($type, $id) = explode(':', $recipient);
        $userId = ($type === 'user') ? $id : null;
        $deptId = ($type === 'department') ? $id : null;

        $stmt = $pdo->prepare("INSERT INTO transaction (File_id, User_id, Users_Department_id, Transaction_type, Transaction_status, Time, Massage) VALUES (?, ?, ?, 2, 'pending', NOW(), 'File sent for review')");
        $stmt->execute([$fileId, $userId, $deptId]);
    }

    logActivity($_SESSION['user_id'], 2, "Sent file $fileId to " . count($recipients) . " recipients");
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'File sent successfully.']);
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Send file error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to send file.']);
}