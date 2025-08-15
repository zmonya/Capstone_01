<?php
session_start();
require 'db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$fileId = isset($_GET['file_id']) ? intval($_GET['file_id']) : 0;
$userId = $_SESSION['user_id'];

// Fetch file information
$stmt = $pdo->prepare("
    SELECT f.*, u.username as owner_name
    FROM files f
    LEFT JOIN users u ON f.user_id = u.user_id
    WHERE f.file_id = ? AND f.file_status != 'deleted'
");
$stmt->execute([$fileId]);
$file = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$file) {
    die("File not found or access denied.");
}

// Check if user has access to this file
$hasAccess = false;
if ($file['user_id'] == $userId) {
    $hasAccess = true;
} else {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM transactions 
        WHERE file_id = ? AND user_id = ? AND transaction_type = 'file_sent' AND description IN ('pending', 'accepted')
    ");
    $stmt->execute([$fileId, $userId]);
    $hasAccess = $stmt->fetchColumn() > 0;
}

if (!$hasAccess) {
    die("You don't have permission to download this file.");
}

$filePath = 'uploads/' . $file['file_path'];
$fileName = $file['file_name'];

if (!file_exists($filePath)) {
    die("File not found on server.");
}

// Log the download action
logActivity($userId, $fileId, 'file_download', 'File downloaded');

// Set headers for file download
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

// Clear output buffer
ob_clean();
flush();

// Read and output the file
readfile($filePath);
exit;
?>
