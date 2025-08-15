<?php
session_start();
require 'db_connection.php';

if (!isset($_GET['file_id'])) {
    die('Invalid file ID');
}

$fileId = intval($_GET['file_id']);
$userId = $_SESSION['user_id'];

// Fetch file details
$stmt = $pdo->prepare("
    SELECT f.*, u.username as owner, dtf.type_name as document_type
    FROM files f
    LEFT JOIN users u ON f.user_id = u.user_id
    LEFT JOIN document_types dtf ON f.document_type_id = dtf.document_type_id
    WHERE f.file_id = ? AND f.file_status != 'deleted'
");
$stmt->execute([$fileId]);
$file = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$file) {
    die('File not found');
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
    die('Access denied');
}

$filePath = 'uploads/' . $file['file_path'];
$fileExt = strtolower(pathinfo($file['file_name'], PATHINFO_EXTENSION));

// Generate preview based on file type
if (!file_exists($filePath)) {
    echo '<div class="text-center text-danger"><i class="fas fa-exclamation-triangle fa-3x"></i><p>File not found</p></div>';
    exit;
}

if (in_array($fileExt, ['jpg', 'jpeg', 'png', 'gif'])) {
    echo '<img src="' . $filePath . '" alt="' . htmlspecialchars($file['file_name']) . '" class="img-fluid" style="max-width: 100%; max-height: 500px;">';
} elseif ($fileExt === 'pdf') {
    echo '<iframe src="' . $filePath . '" width="100%" height="500px" frameborder="0"></iframe>';
} else {
    echo '<div class="text-center">';
    echo '<i class="fas fa-file fa-5x text-primary"></i>';
    echo '<h3 class="mt-3">File Preview</h3>';
    echo '<p>File: ' . htmlspecialchars($file['file_name']) . '</p>';
    echo '</div>';
}
?>
