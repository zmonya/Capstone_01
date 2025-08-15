<?php
session_start();
require 'db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user_id'];

// Fetch all files with complete information
$stmt = $pdo->prepare("
    SELECT f.file_id, f.file_name, f.file_type, f.upload_date, f.file_size, 
           f.copy_type, dtf.type_name AS document_type, u.username as owner,
           f.file_path, f.user_id as owner_id
    FROM files f
    LEFT JOIN document_types dtf ON f.document_type_id = dtf.document_type_id
    LEFT JOIN users u ON f.user_id = u.user_id
    WHERE f.file_status != 'deleted'
    ORDER BY f.upload_date DESC
");
$stmt->execute();
$allFiles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get user's files
$stmt = $pdo->prepare("
    SELECT f.file_id, f.file_name, f.file_type, f.upload_date, f.file_size, 
           f.copy_type, dtf.type_name AS document_type, f.file_path
    FROM files f
    LEFT JOIN document_types dtf ON f.document_type_id = dtf.document_type_id
    WHERE f.user_id = ? AND f.file_status != 'deleted'
    ORDER BY f.upload_date DESC
");
$stmt->execute([$userId]);
$userFiles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get shared files
$stmt = $pdo->prepare("
    SELECT DISTINCT f.file_id, f.file_name, f.file_type, f.upload_date, f.file_size, 
           f.copy_type, dtf.type_name AS document_type, u.username as owner,
           f.file_path
    FROM files f
    JOIN transactions t ON f.file_id = t.file_id
    LEFT JOIN document_types dtf ON f.document_type_id = dtf.document_type_id
    LEFT JOIN users u ON f.user_id = u.user_id
    WHERE t.user_id = ? AND t.transaction_type = 'file_sent' 
    AND t.description IN ('pending', 'accepted')
    AND f.file_status != 'deleted'
    ORDER BY f.upload_date DESC
");
$stmt->execute([$userId]);
$sharedFiles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get file icon
function getFileIcon($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $icons = [
        'pdf' => 'fas fa-file-pdf text-danger',
        'doc' => 'fas fa-file-word text-primary',
        'docx' => 'fas fa-file-word text-primary',
        'xls' => 'fas fa-file-excel text-success',
        'xlsx' => 'fas fa-file-excel text-success',
        'jpg' => 'fas fa-file-image text-warning',
        'jpeg' => 'fas fa-file-image text-warning',
        'png' => 'fas fa-file-image text-warning',
        'gif' => 'fas fa-file-image text-warning',
        'txt' => 'fas fa-file-alt text-secondary',
        'zip' => 'fas fa-file-archive text-info',
        'rar' => 'fas fa-file-archive text-info'
    ];
    return $icons[$ext] ?? 'fas fa-file text-muted';
}

function formatFileSize($bytes) {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' bytes';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>File Dashboard - Document Archival</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        :root {
            --primary-color: #007bff;
            --secondary-color: #6c757d;
            --success-color: #28a745;
            --danger-color: #dc3545;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
            --light-color: #f8f9fa;
            --dark-color: #343a40;
        }

        body {
            background-color: #f8f9fa;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: 250px;
            background: var(--dark-color);
            padding-top: 20px;
            z-index: 1000;
            transition: all 0.3s;
        }

        .sidebar .nav-link {
            color: #fff;
            padding: 15px 20px;
            border-radius: 0;
            transition: all 0.3s;
            display: flex;
            align-items: center;
        }

        .sidebar .nav-link:hover {
            background-color: rgba(255,255,255,0.1);
            color: #fff;
        }

        .sidebar .nav-link i {
            margin-right: 10px;
            width: 20px;
        }

        .main-content {
            margin-left: 250px;
            padding: 20px;
            transition: all 0.3s;
        }

        .file-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: transform 0.3s, box-shadow 0.3s;
            overflow: hidden;
        }

        .file-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
        }

        .file-icon {
            font-size: 2.5rem;
            padding: 20px;
            text-align: center;
        }

        .file-info {
            padding: 20px;
            border-top: 1px solid #eee;
        }

        .file-name {
            font-weight: 600;
            margin-bottom: 5px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .file-meta {
            font-size: 0.9rem;
            color: #666;
        }

        .file-actions {
            padding: 15px 20px;
            background: #f8f9fa;
            border-top: 1px solid #eee;
        }

        .search-section {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .filter-section {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 20px;
            color: var(--dark-color);
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: #666;
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            color: #ccc;
        }

        .badge-custom {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }

        .modal-content {
            border-radius: 10px;
        }

        .modal-header {
            background: var(--primary-color);
            color: white;
            border-radius: 10px 10px 0 0;
        }

        .preview-container {
            max-height: 500px;
            overflow: auto;
            text-align: center;
        }

        .preview-container img {
            max-width: 100%;
            height: auto;
            border-radius: 5px;
        }

        .preview-container iframe {
            width: 100%;
            height: 400px;
            border: none;
            border-radius: 5px;
        }
    </style>
</head>
    <body>
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="px-3 mb-4">
                <h4 class="text-white">Document Archival</h4>
            </div>
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link" href="dashboard.php">
                        <i class="fas fa-home"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="dashboard_files.php">
                        <i class="fas fa-folder"></i> My Files
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="my-report.php">
                        <i class="fas fa-chart-bar"></i> Reports
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="logout.php">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="section-title">My Files</h1>
                <button class="btn btn-primary" onclick="openUploadModal()">
                    <i class="fas fa-plus"></i> Upload File
                </button>
            </div>

            <!-- Search and Filter Section -->
            <div class="search-section">
                <div class="row">
                    <div class="col-md-6">
                        <div class="input-group mb-3">
                            <span class="input-group-text"><i class="fas fa-search"></i></span>
                            <input type="text" class="form-control" id="searchInput" placeholder="Search files...">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <select class="form-select" id="typeFilter">
                            <option value="">All Types</option>
                            <option value="pdf">PDF</option>
                            <option value="doc">Word</option>
                            <option value="xls">Excel</option>
                            <option value="jpg">Image</option>
                            <option value="png">Image</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select class="form-select" id="sortBy">
                            <option value="date">Sort by Date</option>
                            <option value="name">Sort by Name</option>
                            <option value="size">Sort by Size</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- File Sections -->
            <div class="row">
                <!-- My Files Section -->
                <div class="col-12 mb-4">
                    <h2 class="section-title">My Files</h2>
                    <div class="row" id="myFilesContainer">
                        <?php if (empty($userFiles)): ?>
                            <div class="col-12">
                                <div class="empty-state">
                                    <i class="fas fa-folder-open"></i>
                                    <h4>No files uploaded yet</h4>
                                    <p>Upload your first file to get started</p>
                                </div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($userFiles as $file): ?>
                                <div class="col-md-4 col-lg-3 mb-4">
                                    <div class="file-card">
                                        <div class="file-icon">
                                            <i class="<?= getFileIcon($file['file_name']) ?>"></i>
                                        </div>
                                        <div class="file-info">
                                            <div class="file-name" title="<?= htmlspecialchars($file['file_name']) ?>">
                                                <?= htmlspecialchars(substr($file['file_name'], 0, 25)) . (strlen($file['file_name']) > 25 ? '...' : '') ?>
                                            </div>
                                            <div class="file-meta">
                                                <small><?= htmlspecialchars($file['document_type'] ?? 'Unknown') ?></small><br>
                                                <small><?= formatFileSize($file['file_size']) ?></small><br>
                                                <small><?= date('M d, Y', strtotime($file['upload_date'])) ?></small>
                                            </div>
                                        </div>
                                        <div class="file-actions">
                                            <button class="btn btn-sm btn-primary" onclick="viewFile(<?= $file['file_id'] ?>)">
                                                <i class="fas fa-eye"></i> View
                                            </button>
                                            <button class="btn btn-sm btn-success" onclick="downloadFile(<?= $file['file_id'] ?>)">
                                                <i class="fas fa-download"></i> Download
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Shared Files Section -->
                <div class="col-12 mb-4">
                    <h2 class="section-title">Shared With Me</h2>
                    <div class="row" id="sharedFilesContainer">
                        <?php if (empty($sharedFiles)): ?>
                            <div class="col-12">
                                <div class="empty-state">
                                    <i class="fas fa-share-alt"></i>
                                    <h4>No shared files</h4>
                                    <p>Files shared with you will appear here</p>
                                </div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($sharedFiles as $file): ?>
                                <div class="col-md-4 col-lg-3 mb-4">
                                    <div class="file-card">
                                        <div class="file-icon">
                                            <i class="<?= getFileIcon($file['file_name']) ?>"></i>
                                        </div>
                                        <div class="file-info">
                                            <div class="file-name" title="<?= htmlspecialchars($file['file_name']) ?>">
                                                <?= htmlspecialchars(substr($file['file_name'], 0, 25)) . (strlen($file['file_name']) > 25 ? '...' : '') ?>
                                            </div>
                                            <div class="file-meta">
                                                <small>From: <?= htmlspecialchars($file['owner']) ?></small><br>
                                                <small><?= htmlspecialchars($file['document_type'] ?? 'Unknown') ?></small><br>
                                                <small><?= formatFileSize($file['file_size']) ?></small>
                                            </div>
                                        </div>
                                        <div class="file-actions">
                                            <button class="btn btn-sm btn-primary" onclick="viewFile(<?= $file['file_id'] ?>)">
                                                <i class="fas fa-eye"></i> View
                                            </button>
                                            <button class="btn btn-sm btn-success" onclick="downloadFile(<?= $file['file_id'] ?>)">
                                                <i class="fas fa-download"></i> Download
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- All Files Section -->
                <div class="col-12 mb-4">
                    <h2 class="section-title">All Files</h2>
                    <div class="row" id="allFilesContainer">
                        <?php if (empty($allFiles)): ?>
                            <div class="col-12">
                                <div class="empty-state">
                                    <i class="fas fa-folder-open"></i>
                                    <h4>No files available</h4>
                                    <p>Files will appear here when uploaded</p>
                                </div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($allFiles as $file): ?>
                                <div class="col-md-4 col-lg-3 mb-4">
                                    <div class="file-card">
                                        <div class="file-icon">
                                            <i class="<?= getFileIcon($file['file_name']) ?>"></i>
                                        </div>
                                        <div class="file-info">
                                            <div class="file-name" title="<?= htmlspecialchars($file['file_name']) ?>">
                                                <?= htmlspecialchars(substr($file['file_name'], 0, 25)) . (strlen($file['file_name']) > 25 ? '...' : '') ?>
                                            </div>
                                            <div class="file-meta">
                                                <small>Owner: <?= htmlspecialchars($file['owner']) ?></small><br>
                                                <small><?= htmlspecialchars($file['document_type'] ?? 'Unknown') ?></small><br>
                                                <small><?= formatFileSize($file['file_size']) ?></small>
                                            </div>
                                        </div>
                                        <div class="file-actions">
                                            <button class="btn btn-sm btn-primary" onclick="viewFile(<?= $file['file_id'] ?>)">
                                                <i class="fas fa-eye"></i> View
                                            </button>
                                            <button class="btn btn-sm btn-success" onclick="downloadFile(<?= $file['file_id'] ?>)">
                                                <i class="fas fa-download"></i> Download
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- File Preview Modal -->
        <div class="modal fade" id="filePreviewModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">File Preview</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div id="previewContent">
                            <div class="text-center">
                                <i class="fas fa-spinner fa-spin fa-3x"></i>
                                <p>Loading preview...</p>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="button" class="btn btn-primary" id="downloadBtn">Download</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- JavaScript -->
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        <script>
            // Search functionality
            document.getElementById('searchInput').addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                const fileCards = document.querySelectorAll('.file-card');
                
                fileCards.forEach(card => {
                    const fileName = card.querySelector('.file-name').textContent.toLowerCase();
                    if (fileName.includes(searchTerm)) {
                        card.style.display = 'block';
                    } else {
                        card.style.display = 'none';
                    }
                });
            });

            // Filter by type
            document.getElementById('typeFilter').addEventListener('change', function() {
                const typeFilter = this.value.toLowerCase();
                const fileCards = document.querySelectorAll('.file-card');
                
                fileCards.forEach(card => {
                    const fileName = card.querySelector('.file-name').textContent.toLowerCase();
                    if (!typeFilter || fileName.includes(typeFilter)) {
                        card.style.display = 'block';
                    } else {
                        card.style.display = 'none';
                    }
                });
            });

            // View file function
            function viewFile(fileId) {
                const modal = new bootstrap.Modal(document.getElementById('filePreviewModal'));
                const previewContent = document.getElementById('previewContent');
                
                previewContent.innerHTML = '<div class="text-center"><i class="fas fa-spinner fa-spin fa-3x"></i><p>Loading preview...</p></div>';
                
                fetch(`get_file_preview.php?file_id=${fileId}`)
                    .then(response => response.text())
                    .then(data => {
                        previewContent.innerHTML = data;
                        document.getElementById('downloadBtn').onclick = () => downloadFile(fileId);
                    })
                    .catch(error => {
                        previewContent.innerHTML = '<div class="text-center text-danger"><i class="fas fa-exclamation-triangle fa-3x"></i><p>Error loading preview</p></div>';
                    });
                
                modal.show();
            }

            // Download file function
            function downloadFile(fileId) {
                window.location.href = `download.php?file_id=${fileId}`;
            }

            // Upload modal
            function openUploadModal() {
                window.location.href = 'dashboard.php';
            }
        </script>
    </body>
</html>
