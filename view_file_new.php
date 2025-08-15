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
    SELECT f.*, u.username as owner, dtf.type_name as document_type,
           d.Department_name as department_name
    FROM files f
    LEFT JOIN users u ON f.user_id = u.user_id
    LEFT JOIN document_types dtf ON f.document_type_id = dtf.document_type_id
    LEFT JOIN users_department ud ON f.user_id = ud.User_id
    LEFT JOIN departments d ON ud.Department_id = d.Department_id
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

$filePath = 'uploads/' . $file['file_path']; // Ensure this path is correct
if (!file_exists($filePath)) {
    die('File not found on server.');
}
if (!file_exists($filePath)) {
    die('File not found on server.');
}
if (!file_exists($filePath)) {
    die('File not found on server.');
}
if (!file_exists($filePath)) {
    die('File not found on server.');
}
if (!file_exists($filePath)) {
    die('File not found on server.');
}
if (!file_exists($filePath)) {
    die('File not found on server.');
}
$fileExt = strtolower(pathinfo($file['file_name'], PATHINFO_EXTENSION));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View File - <?php echo htmlspecialchars($file['file_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="style/dashboard.css">
    <style>
        .pdf-container {
            height: 70vh;
            border: 1px solid #ddd;
            border-radius: 8px;
            overflow: hidden;
        }
        .file-info-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 20px;
            margin-bottom: 20px;
        }
        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .history-timeline {
            max-height: 300px;
            overflow-y: auto;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 bg-dark text-white p-3" style="min-height: 100vh;">
                <h4>File Management</h4>
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link text-white" href="dashboard_files.php">
                            <i class="fas fa-arrow-left"></i> Back to Files
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link text-white" href="#" onclick="downloadFile()">
                            <i class="fas fa-download"></i> Download
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link text-white" href="#" onclick="sendFile()">
                            <i class="fas fa-share"></i> Send File
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link text-white" href="#" onclick="viewHistory()">
                            <i class="fas fa-history"></i> View History
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Main Content -->
            <div class="col-md-9 p-4">
                <div class="row">
                    <!-- File Viewer -->
                    <div class="col-md-8">
                        <h2><?php echo htmlspecialchars($file['file_name']); ?></h2>
                        
                        <?php if ($fileExt === 'pdf'): ?>
                            <div class="pdf-container">
                                <iframe src="<?php echo $filePath; ?>" width="100%" height="100%" frameborder="0"></iframe>
                            </div>
                        <?php elseif (in_array($fileExt, ['jpg', 'jpeg', 'png', 'gif'])): ?>
                            <div class="text-center">
                                <img src="<?php echo $filePath; ?>" alt="<?php echo htmlspecialchars($file['file_name']); ?>" 
                                     class="img-fluid" style="max-height: 500px;">
                            </div>
                        <?php else: ?>
                            <div class="text-center p-4">
                                <i class="fas fa-file fa-5x text-primary"></i>
                                <h3>File Preview</h3>
                                <p>File: <?php echo htmlspecialchars($file['file_name']); ?></p>
                                <p>Type: <?php echo strtoupper($fileExt); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- File Info -->
                    <div class="col-md-4">
                        <div class="file-info-card">
                            <h4>File Information</h4>
                            <table class="table table-sm">
                                <tr>
                                    <td><strong>Name:</strong></td>
                                    <td><?php echo htmlspecialchars($file['file_name']); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Type:</strong></td>
                                    <td><?php echo htmlspecialchars($file['document_type'] ?? 'Unknown'); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Size:</strong></td>
                                    <td><?php echo number_format($file['file_size'] / 1024, 2); ?> KB</td>
                                </tr>
                                <tr>
                                    <td><strong>Uploaded:</strong></td>
                                    <td><?php echo date('M d, Y H:i', strtotime($file['upload_date'])); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Owner:</strong></td>
                                    <td><?php echo htmlspecialchars($file['owner']); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Department:</strong></td>
                                    <td><?php echo htmlspecialchars($file['department_name'] ?? 'N/A'); ?></td>
                                </tr>
                            </table>
                        </div>

                        <div class="action-buttons">
                            <button class="I have gathered sufficient information about the existing files and their functionalities. Here’s a comprehensive plan to implement the new page for viewing files in PDF format, along with the additional features you requested:

### Information Gathered:
1. **Current View File (`view_file_new.php`)**:
   - Fetches file details based on `file_id`.
   - Checks user access to the file.
   - Displays file previews for images and PDFs.

2. **Dashboard Files (`dashboard_files.php`)**:
   - Displays all files, user files, and shared files.
   - Provides functionality to view and download files.

3. **File Preview (`get_file_preview.php`)**:
   - Generates a preview for the requested file.
   - Currently only displays basic information.

4. **Download Functionality (`download.php`)**:
   - Handles file downloads with access checks.

5. **File Info (`get_file_info.php`)**:
   - Fetches detailed information about a file, including metadata.

### Plan:
1. **Create a New View File Page**:
   - Create a new file `view_file.php` that will serve as the main page for viewing files.
   - This page will include:
     - A PDF viewer for displaying the file.
     - Sections for file info, download button, history, and send file options.

2. **Update `view_file_new.php`**:
   - Modify the existing `view_file_new.php` to redirect to the new `view_file.php` page.
   - Ensure that the new page is called with the correct `file_id`.

3. **Implement File Info Section**:
   - Use the existing `get_file_info.php` to fetch and display detailed file information on the new page.

4. **Implement Download Functionality**:
   - Add a download button that links to the existing `download.php` functionality.

5. **Implement History Section**:
   - Create a new function to fetch and display the file's transaction history (e.g., uploads, downloads, shares).
   - This may require creating a new SQL query to fetch relevant transaction records.

6. **Implement Send File Functionality**:
   - Add a form or button to send the file to other users or departments.
   - This will likely involve creating a new handler for sending files.

### Dependent Files to be Edited:
- **New File**: `view_file.php` (to be created)
- **Existing File**: `view_file_new.php` (to be modified)
- **Existing File**: `get_file_info.php` (to be utilized for file info)
- **Existing File**: `download.php` (to be utilized for download functionality)

### Follow-up Steps:
- Implement the changes as per the plan.
- Test the new functionality to ensure everything works as expected.

<ask_followup_question>
<question>Please confirm if I can proceed with this plan or if you have any modifications or additional requirements.</question>
</ask_followup_question>
