<?php
session_start();
require 'db_connection.php';

header('Content-Type: text/html');

$fileId = filter_var($_GET['file_id'], FILTER_SANITIZE_NUMBER_INT);

try {
    $stmt = $pdo->prepare("SELECT File_name, File_type FROM files WHERE File_id = ? AND File_status != 'deleted' AND User_id = ?");
    $stmt->execute([$fileId, $_SESSION['user_id']]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($file) {
        echo "<p>Preview for: <strong>{$file['File_name']}</strong> (Type: {$file['File_type']})</p>";
        // Add actual preview logic (e.g., image tag for images, PDF embed for PDFs) based on File_type
    } else {
        echo "<p>File not found or access denied.</p>";
    }
} catch (Exception $e) {
    error_log("File preview error: " . $e->getMessage());
    echo "<p>Unable to load preview.</p>";
}