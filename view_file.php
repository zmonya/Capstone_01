<?php
session_start();
require 'db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$fileId = filter_var($_GET['file_id'] ?? null, FILTER_VALIDATE_INT);
if (!$fileId) {
    echo "Invalid file ID.";
    exit;
}

$userId = $_SESSION['user_id'];

try {
    // Check user permission and fetch file info
    $stmt = $pdo->prepare("
        SELECT 
            f.File_id, f.File_name, f.File_path, f.Upload_date, f.File_type,
            u.User_id AS uploader_id, u.Username AS uploader_name,
            dt.Field_name AS document_type,
            d.Department_name
        FROM files f
        JOIN users u ON f.User_id = u.User_id
        LEFT JOIN documents_type_fields dt ON f.Document_type_id = dt.Document_type_id
        LEFT JOIN users_department ud ON f.User_id = ud.User_id
        LEFT JOIN departments d ON ud.Department_id = d.Department_id
        WHERE f.File_id = ? AND f.File_status != 'deleted'
        AND (f.User_id = ? OR ud.Department_id IN (
            SELECT Department_id FROM users_department WHERE User_id = ?
        ))
        LIMIT 1
    ");
    $stmt->execute([$fileId, $userId, $userId]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$file) {
        echo "Access denied or file not found.";
        exit;
    }

    // Fetch transaction history for the file
    $transStmt = $pdo->prepare("
        SELECT Transaction_id, Transaction_type, Transaction_status, Time, Massage
        FROM transaction
        WHERE File_id = ?
        ORDER BY Time DESC
    ");
    $transStmt->execute([$fileId]);
    $transactions = $transStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    error_log("Error fetching file details: " . $e->getMessage());
    echo "An error occurred while fetching file details.";
    exit;
}

function formatTransactionType($type) {
    $types = [
        1 => 'Upload',
        2 => 'Sent',
        9 => 'Metadata Update',
        10 => 'Info Fetch',
        11 => 'Access Request',
        12 => 'Notification',
        13 => 'File Acceptance',
        14 => 'File Denial',
        15 => 'Other'
    ];
    return $types[$type] ?? 'Unknown';
}

function formatTransactionStatus($status) {
    return ucfirst($status);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>View File - <?= htmlspecialchars($file['File_name']) ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .file-info, .transaction-history { margin-bottom: 30px; }
        .file-info h2, .transaction-history h2 { border-bottom: 1px solid #ccc; padding-bottom: 5px; }
        .file-preview { margin-top: 15px; }
        iframe, img { max-width: 100%; height: auto; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
    </style>
</head>
<body>
    <h1>File Details</h1>
    <div class="file-info">
        <h2><?= htmlspecialchars($file['File_name']) ?></h2>
        <p><strong>Uploader:</strong> <?= htmlspecialchars($file['uploader_name']) ?></p>
        <p><strong>Upload Date:</strong> <?= htmlspecialchars(date('F j, Y, g:i a', strtotime($file['Upload_date']))) ?></p>
        <p><strong>Document Type:</strong> <?= htmlspecialchars($file['document_type'] ?? 'N/A') ?></p>
        <p><strong>Department:</strong> <?= htmlspecialchars($file['Department_name'] ?? 'N/A') ?></p>
        <div class="file-preview">
            <?php
            $filePath = $file['File_path'] ?? null;
            if ($filePath && file_exists($filePath)) {
                $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
                if (in_array($ext, ['pdf'])) {
                    echo '<iframe src="' . htmlspecialchars($filePath) . '" width="100%" height="600px"></iframe>';
                } elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
                    echo '<img src="' . htmlspecialchars($filePath) . '" alt="File preview" />';
                } else {
                    echo '<p><a href="' . htmlspecialchars($filePath) . '" download>Download File</a></p>';
                }
            } else {
                echo '<p>File preview not available.</p>';
            }
            ?>
        </div>
    </div>

    <div class="transaction-history">
        <h2>File Transaction History</h2>
        <?php if (!empty($transactions)): ?>
            <table>
                <thead>
                    <tr>
                        <th>Transaction ID</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Time</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $trans): ?>
                        <tr>
                            <td><?= htmlspecialchars($trans['Transaction_id']) ?></td>
                            <td><?= htmlspecialchars(formatTransactionType($trans['Transaction_type'])) ?></td>
                            <td><?= htmlspecialchars(formatTransactionStatus($trans['Transaction_status'])) ?></td>
                            <td><?= htmlspecialchars(date('F j, Y, g:i a', strtotime($trans['Time']))) ?></td>
                            <td><?= htmlspecialchars($trans['Massage']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No transaction history available for this file.</p>
        <?php endif; ?>
    </div>

    <p><a href="dashboard.php">Back to Dashboard</a></p>
</body>
</html>
