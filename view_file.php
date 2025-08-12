<?php
session_start();
require 'db_connection.php';

$fileId = filter_var($_GET['file_id'], FILTER_SANITIZE_NUMBER_INT);

try {
    $stmt = $pdo->prepare("SELECT File_name FROM files WHERE File_id = ? AND User_id = ? AND File_status != 'deleted'");
    $stmt->execute([$fileId, $_SESSION['user_id']]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($file) {
        header('Location: Uploads/' . $file['File_name']);
    } else {
        header('Location: dashboard.php?error=access_denied');
    }
} catch (Exception $e) {
    error_log("View file error: " . $e->getMessage());
    header('Location: dashboard.php?error=access_denied');
}
exit;