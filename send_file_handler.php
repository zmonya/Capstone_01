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

error_log("Send file handler called with POST data: " . json_encode($_POST));

$fileId = filter_var($_POST['file_id'], FILTER_SANITIZE_NUMBER_INT);
$recipientsRaw = $_POST['recipients'] ?? [];
$recipients = [];

foreach ($recipientsRaw as $recipient) {
    $recipient = filter_var($recipient, FILTER_SANITIZE_STRING);
    if (strpos($recipient, ':') === false) {
        error_log("Skipping malformed recipient: $recipient");
        continue; // skip malformed recipient
    }
    list($type, $id) = explode(':', $recipient, 2);
    if (!in_array($type, ['user', 'department']) || !ctype_digit($id)) {
        error_log("Skipping invalid recipient type or id: $recipient");
        continue; // skip invalid recipient
    }
    $recipients[] = ['type' => $type, 'id' => (int)$id];
}

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT User_id FROM files WHERE File_id = ? AND User_id = ? AND File_status != 'deleted'");
    $stmt->execute([$fileId, $_SESSION['user_id']]);
    if (!$stmt->fetch()) {
        throw new Exception('Invalid file or access denied.');
    }

    $insertStmt = $pdo->prepare("INSERT INTO transaction (File_id, User_id, Users_Department_id, Transaction_type, Transaction_status, Time, Message) VALUES (?, ?, ?, 2, 'pending', NOW(), 'File sent for review')");

    foreach ($recipients as $recipient) {
        $userId = ($recipient['type'] === 'user') ? $recipient['id'] : null;
        $deptId = ($recipient['type'] === 'department') ? $recipient['id'] : null;
        error_log("Inserting transaction for file $fileId to user $userId or department $deptId");
        $insertStmt->execute([$fileId, $userId, $deptId]);
    }

    logActivity($_SESSION['user_id'], 2, "Sent file $fileId to " . count($recipients) . " recipients");
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'File sent successfully.']);
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Send file error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to send file: ' . $e->getMessage()]);
}
