<?php
session_start();
require 'db_connection.php';
require 'log_activity.php';
require 'vendor/autoload.php'; // Load Composer autoloader for phpdotenv

use Dotenv\Dotenv;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Disable error display in production
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('error_log', __DIR__ . '/logs/error_log.log');
error_reporting(E_ALL);

/**
 * Generates a JSON response with appropriate HTTP status.
 *
 * @param bool $success
 * @param string $message
 * @param array $data
 * @param int $statusCode
 * @return void
 */
function sendJsonResponse(bool $success, string $message, array $data, int $statusCode): void
{
    header('Content-Type: application/json');
    http_response_code($statusCode);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}

/**
 * Validates user session.
 *
 * @return array{user_id: int, role: string}
 * @throws Exception If user is not authenticated
 */
function validateUserSession(): array
{
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        header('Location: login.php');
        exit;
    }
    return ['user_id' => (int)$_SESSION['user_id'], 'role' => (string)$_SESSION['role']];
}

/**
 * Sanitizes SQL ORDER BY clause to prevent injection.
 *
 * @param string $sortBy
 * @return string
 */
function sanitizeSortBy(string $sortBy): string
{
    $sortMap = [
        'file_name_asc' => 'f.File_name ASC',
        'file_name_desc' => 'f.File_name DESC',
        'document_type_asc' => 'dtf.Field_name ASC',
        'document_type_desc' => 'dtf.Field_name DESC',
        'upload_date_asc' => 'f.Upload_date ASC',
        'upload_date_desc' => 'f.Upload_date DESC'
    ];
    return $sortMap[$sortBy] ?? 'f.File_name ASC';
}

/**
 * Defines file icon based on extension.
 *
 * @param string $fileName
 * @return string
 */
function getFileIcon(string $fileName): string
{
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $iconMap = [
        'pdf' => 'fas fa-file-pdf',
        'doc' => 'fas fa-file-word',
        'docx' => 'fas fa-file-word',
        'xls' => 'fas fa-file-excel',
        'xlsx' => 'fas fa-file-excel',
        'jpg' => 'fas fa-file-image obbligato
System: -image',
        'png' => 'fas fa-file-image',
        'jpeg' => 'fas fa-file-image',
        'gif' => 'fas fa-file-image',
        'txt' => 'fas fa-file-alt',
        'zip' => 'fas fa-file-archive',
        'other' => 'fas fa-file'
    ];
    return $iconMap[$extension] ?? $iconMap['other'];
}

/**
 * Sanitizes file path to prevent path traversal.
 *
 * @param string $filePath
 * @return string
 */
function sanitizeFilePath(string $filePath): string
{
    // Remove any path traversal attempts
    $filePath = str_replace(['..', '//', '\\'], '', $filePath);
    // Ensure path starts with expected directory
    $baseDir = realpath(__DIR__ . '/Uploads/');
    $fullPath = realpath(__DIR__ . '/' . $filePath);
    if ($fullPath === false || strpos($fullPath, $baseDir) !== 0) {
        throw new Exception('Invalid file path.');
    }
    return $filePath;
}

try {
    // Validate session and get user details
    $user = validateUserSession();
    $userId = $user['user_id'];
    $userRole = $user['role'];

    global $pdo;

    // Generate CSRF token
    $csrfToken = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $csrfToken;

    // Fetch user departments
    $stmt = $pdo->prepare("
        SELECT d.Department_id, d.Department_name 
        FROM departments d 
        JOIN users_department ud ON d.Department_id = ud.Department_id 
        WHERE ud.User_id = ?
    ");
    $stmt->execute([$userId]);
    $userDepartments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get query parameters with sanitization and validation
    $searchQuery = filter_input(INPUT_GET, 'q', FILTER_SANITIZE_SPECIAL_CHARS) ?? '';
    $documentType = filter_input(INPUT_GET, 'type', FILTER_SANITIZE_SPECIAL_CHARS) ?? '';
    $folderFilter = filter_input(INPUT_GET, 'folder', FILTER_SANITIZE_SPECIAL_CHARS) ?? '';
    $hardCopyFilter = filter_input(INPUT_GET, 'hardcopy', FILTER_SANITIZE_SPECIAL_CHARS) ?? '';
    $sortBy = sanitizeSortBy(filter_input(INPUT_GET, 'sort', FILTER_SANITIZE_SPECIAL_CHARS) ?? 'file_name_asc');
    $page = max(1, filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]));
    $limit = max(1, filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT, ['options' => ['default' => 10, 'min_range' => 1]]));
    $offset = ($page - 1) * $limit;

    // Base query for fetching files
    $sql = "
        SELECT f.File_id AS id, f.File_name AS file_name, f.Upload_date AS upload_date, f.File_type AS file_type,
               f.File_status AS file_status, f.File_path AS file_path, f.File_size AS file_size,
               COALESCE(dtf.Field_name, 'Unknown Type') AS document_type,
               COALESCE(u.Username, 'Unknown User') AS uploaded_by,
               COALESCE(d.Department_name, 'No Department') AS department_name
        FROM files f
        LEFT JOIN documents_type_fields dtf ON f.Document_type_id = dtf.Document_type_id
        LEFT JOIN users u ON f.User_id = u.User_id
        LEFT JOIN users_department ud ON u.User_id = ud.User_id
        LEFT JOIN departments d ON ud.Department_id = d.Department_id
        WHERE f.File_status != 'deleted'
    ";

    $departmentIds = array_column($userDepartments, 'Department_id');
    $params = [$userId];
    $sql .= " AND (f.User_id = ?";
    if (!empty($departmentIds)) {
        $sql .= " OR ud.Department_id IN (" . implode(',', array_fill(0, count($departmentIds), '?')) . ")";
        $params = array_merge($params, $departmentIds);
    }
    $sql .= ")";

    if (!empty($searchQuery)) {
        $searchFields = ['f.File_name', 'f.File_path', 'u.Username', 'd.Department_name', 'dtf.Field_name'];
        $sql .= " AND (" . implode(" LIKE ? OR ", $searchFields) . " LIKE ?)";
        $params = array_merge($params, array_fill(0, count($searchFields), "%$searchQuery%"));
    }

    if (!empty($documentType)) {
        $sql .= " AND dtf.Field_name = ?";
        $params[] = $documentType;
    }

    if (!empty($folderFilter)) {
        if ($folderFilter === 'my-folder') {
            $sql .= " AND f.User_id = ?";
            $params[] = $userId;
        } elseif (strpos($folderFilter, 'department-') === 0) {
            $departmentId = substr($folderFilter, 10);
            if (is_numeric($departmentId) && in_array((int)$departmentId, $departmentIds)) {
                $sql .= " AND ud.Department_id = ?";
                $params[] = (int)$departmentId;
            }
        }
    }

    if ($hardCopyFilter === 'hardcopy') {
        $sql .= " AND f.Copy_type = 'hard'";
    } elseif ($hardCopyFilter === 'softcopy') {
        $sql .= " AND f.Copy_type = 'soft'";
    }

    $sql .= " ORDER BY $sortBy LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Count query
    $countSql = "
        SELECT COUNT(*) as total
        FROM files f
        LEFT JOIN documents_type_fields dtf ON f.Document_type_id = dtf.Document_type_id
        LEFT JOIN users u ON f.User_id = u.User_id
        LEFT JOIN users_department ud ON u.User_id = ud.User_id
        LEFT JOIN departments d ON ud.Department_id = d.Department_id
        WHERE f.File_status != 'deleted'
        AND (f.User_id = ?";
    $countParams = [$userId];
    if (!empty($departmentIds)) {
        $countSql .= " OR ud.Department_id IN (" . implode(',', array_fill(0, count($departmentIds), '?')) . ")";
        $countParams = array_merge($countParams, $departmentIds);
    }
    $countSql .= ")";

    if (!empty($searchQuery)) {
        $countSql .= " AND (" . implode(" LIKE ? OR ", $searchFields) . " LIKE ?)";
        $countParams = array_merge($countParams, array_fill(0, count($searchFields), "%$searchQuery%"));
    }

    if (!empty($documentType)) {
        $countSql .= " AND dtf.Field_name = ?";
        $countParams[] = $documentType;
    }

    if (!empty($folderFilter)) {
        if ($folderFilter === 'my-folder') {
            $countSql .= " AND f.User_id = ?";
            $countParams[] = $userId;
        } elseif (strpos($folderFilter, 'department-') === 0) {
            $departmentId = substr($folderFilter, 10);
            if (is_numeric($departmentId) && in_array((int)$departmentId, $departmentIds)) {
                $countSql .= " AND ud.Department_id = ?";
                $countParams[] = (int)$departmentId;
            }
        }
    }

    if ($hardCopyFilter === 'hardcopy') {
        $countSql .= " AND f.Copy_type = 'hard'";
    } elseif ($hardCopyFilter === 'softcopy') {
        $countSql .= " AND f.Copy_type = 'soft'";
    }

    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($countParams);
    $totalResults = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPages = ceil($totalResults / $limit);

    // Log search in transaction table
    $stmt = $pdo->prepare("
        INSERT INTO transaction (User_id, Transaction_status, Transaction_type, Time, Massage)
        VALUES (?, 'completed', 24, NOW(), ?)
    ");
    $stmt->execute([$userId, "Searched files with query: $searchQuery, type: $documentType, folder: $folderFilter, hardcopy: $hardCopyFilter"]);
} catch (Exception $e) {
    error_log("Error in search.php: " . $e->getMessage());
    sendJsonResponse(false, 'Server error: Unable to process search.', [], 500);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search Results</title>
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.css">
    <script src="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.js"></script>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="folders.css">
    <link rel="stylesheet" href="client-sidebar.css">
    <style>
        body {
            display: flex;
            margin: 0;
            font-family: 'Montserrat', sans-serif;
            min-height: 100vh;
        }

        .container {
            display: flex;
            width: 100%;
        }

        .logout-btn,
        .admin-dashboard-btn {
            padding: 12px;
            color: #ffffff;
            border-radius: 8px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            text-decoration: none;
        }

        .admin-dashboard-btn {
            background-color: rgba(80, 200, 120, 0.2);
            border: 2px solid #50c878;
        }

        .admin-dashboard-btn i {
            margin-right: 12px;
            color: #00ff55;
        }

        .admin-dashboard-btn:hover {
            background-color: rgba(80, 200, 120, 0.3);
            border-color: #40a867;
            transform: translateX(5px);
        }

        .admin-dashboard-btn:hover i {
            color: #ffffff;
        }

        .sidebar.minimized .admin-dashboard-btn {
            justify-content: center;
        }

        .sidebar.minimized .admin-dashboard-btn .link-text,
        .sidebar.minimized .admin-dashboard-btn i {
            margin-right: 0;
        }

        .main-content {
            margin-left: 10px;
            margin-top: 70px;
            flex-grow: 1;
            padding: 20px;
            background-color: #f5f5f5;
            min-height: 100vh;
        }

        .top-nav {
            background-color: #ffffff;
            padding: 15px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }

        .search-container {
            display: flex;
            gap: 10px;
        }

        .search-container input,
        .search-container select,
        .search-container button {
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }

        .filter-buttons {
            margin-top: 20px;
        }

        .filter-button {
            padding: 8px 16px;
            margin-right: 10px;
            background-color: #34495e;
            color: white;
            border-radius: 4px;
            text-decoration: none;
            transition: background-color 0.3s ease;
        }

        .filter-button.active {
            background-color: #40a867;
        }

        .filter-button:hover {
            background-color: rgb(64, 161, 168);
        }

        .search-results {
            background-color: #ffffff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            margin-top: 10px;
        }

        #fileDisplay {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
        }

        .file-item {
            background-color: #f9f9f9;
            border: 1px solid #ccc;
            border-radius: 5px;
            padding: 15px;
            text-align: center;
            cursor: pointer;
            transition: background-color 0.3s;
            width: 200px;
            position: relative;
        }

        .file-item:hover {
            background-color: #e0e0e0;
        }

        .file-icon {
            font-size: 40px;
            margin-bottom: 10px;
        }

        .pagination {
            margin-top: 20px;
            text-align: center;
        }

        .pagination a {
            padding: 8px 16px;
            margin-right: 5px;
            background-color: #34495e;
            color: white;
            border-radius: 4px;
            text-decoration: none;
            transition: background-color 0.3s ease;
        }

        .pagination a.active {
            background-color: #50c878;
        }

        .pagination a:hover {
            background-color: rgb(64, 161, 168);
        }

        .options-menu {
            display: none;
            position: absolute;
            top: 30px;
            right: 10px;
            background-color: white;
            border: 1px solid #ccc;
            border-radius: 3px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
            z-index: 10;
        }

        .options-menu div {
            padding: 5px 10px;
            cursor: pointer;
        }

        .options-menu div:hover {
            background-color: #f0f0f0;
        }

        .options-menu div.request-access-btn {
            background-color: #50c878;
            color: white;
            padding: 5px;
            border-radius: 3px;
            cursor: pointer;
        }

        .options-menu div.request-access-btn:hover {
            background-color: #40a867;
        }

        .file-info-sidebar {
            position: fixed;
            top: 0;
            right: -400px;
            width: 400px;
            height: 100%;
            background: #fff;
            box-shadow: -2px 0 10px rgba(0, 0, 0, 0.1);
            transition: right 0.3s ease;
            z-index: 1100;
            overflow-y: auto;
            box-sizing: border-box;
        }

        .file-info-sidebar.active {
            right: 0;
        }

        .file-name-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px 20px;
            background: #f5f6f5;
            border-bottom: 1px solid #e0e0e0;
        }

        .file-name-title {
            font-size: 18px;
            font-weight: 700;
            color: #2c3e50;
            flex-grow: 1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .close-sidebar-btn {
            background: none;
            border: none;
            font-size: 24px;
            color: #7f8c8d;
            cursor: pointer;
            transition: color 0.3s ease;
        }

        .close-sidebar-btn:hover {
            color: #e74c3c;
        }

        .file-info-header {
            display: flex;
            border-bottom: 1px solid #e0e0e0;
        }

        .file-info-header div {
            flex: 1;
            padding: 10px 0;
            text-align: center;
            cursor: pointer;
            font-weight: 500;
            color: #7f8c8d;
            transition: all 0.3s ease;
        }

        .file-info-header div.active {
            color: #50c878;
            border-bottom: 2px solid #50c878;
        }

        .file-info-header div:hover {
            color: #50c878;
        }

        .info-section {
            display: none;
            padding: 20px;
        }

        .info-section.active {
            display: block;
        }

        .info-item {
            display: flex;
            margin-bottom: 15px;
            font-size: 14px;
        }

        .info-label {
            font-weight: 600;
            color: #34495e;
            min-width: 120px;
        }

        .info-value {
            color: #7f8c8d;
            flex-grow: 1;
            word-break: break-word;
        }

        .access-log h3,
        .file-details h3 {
            font-size: 16px;
            color: #2c3e50;
            margin: 0 0 15px 0;
            border-bottom: 1px solid #e0e0e0;
            padding-bottom: 5px;
        }

        .access-users {
            margin-bottom: 10px;
        }

        .access-info {
            font-size: 12px;
            color: #95a5a6;
        }

        .file-preview {
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid #e0e0e0;
        }

        .file-preview img,
        .file-preview iframe {
            max-width: 100%;
            max-height: 200px;
            cursor: pointer;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            transition: transform 0.3s ease;
        }

        .file-preview img:hover,
        .file-preview iframe:hover {
            transform: scale(1.05);
        }

        .file-preview p {
            margin: 10px 0 0;
            font-size: 12px;
            color: #7f8c8d;
        }

        .full-preview-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.9);
            z-index: 1200;
            justify-content: center;
            align-items: center;
            overflow: auto;
        }

        .full-preview-content {
            background: #fff;
            padding: 20px;
            border-radius: 10px;
            width: 90%;
            max-width: 1200px;
            max-height: 90vh;
            overflow: auto;
            position: relative;
            box-sizing: border-box;
        }

        .full-preview-content iframe {
            width: 100%;
            height: 80vh;
            border: none;
        }

        .full-preview-content img {
            max-width: 100%;
            max-height: 80vh;
        }

        .close-full-preview {
            position: absolute;
            top: 10px;
            right: 10px;
            background: #e74c3c;
            color: white;
            border: none;
            border-radius: 50%;
            width: 28px;
            height: 28px;
            cursor: pointer;
            font-size: 16px;
            line-height: 28px;
            transition: background-color 0.3s ease;
        }

        .close-full-preview:hover {
            background-color: #c0392b;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="sidebar">
            <button class="toggle-btn" title="Toggle Sidebar">
                <i class="fas fa-bars"></i>
            </button>
            <h2 class="sidebar-title">Document Archival</h2>
            <?php if ($userRole === 'admin'): ?>
                <a href="admin_dashboard.php" class="admin-dashboard-btn">
                    <i class="fas fa-user-shield"></i>
                    <span class="link-text">Admin Dashboard</span>
                </a>
            <?php endif; ?>
            <a href="index.php" class="<?= basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : '' ?>">
                <i class="fas fa-home"></i><span class="link-text"> Dashboard</span>
            </a>
            <a href="my-report.php" class="<?= basename($_SERVER['PHP_SELF']) == 'my-report.php' ? 'active' : '' ?>">
                <i class="fas fa-chart-bar"></i><span class="link-text"> My Report</span>
            </a>
            <a href="my-folder.php" class="<?= basename($_SERVER['PHP_SELF']) == 'my-folder.php' ? 'active' : '' ?>">
                <i class="fas fa-folder"></i><span class="link-text"> My Folder</span>
            </a>
            <?php if (!empty($userDepartments)): ?>
                <?php foreach ($userDepartments as $dept): ?>
                    <a href="department_folder.php?department_id=<?= htmlspecialchars($dept['Department_id'], ENT_QUOTES, 'UTF-8') ?>"
                        class="<?= isset($_GET['department_id']) && $_GET['department_id'] == $dept['Department_id'] ? 'active' : '' ?>">
                        <i class="fas fa-folder"></i>
                        <span class="link-text"> <?= htmlspecialchars($dept['Department_name'], ENT_QUOTES, 'UTF-8') ?></span>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
            <a href="logout.php" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i>
                <span class="link-text">Logout</span>
            </a>
        </div>

        <div class="main-content">
            <div class="file-info-sidebar">
                <div class="file-name-container">
                    <div class="file-name-title" id="sidebarFileName">File Name</div>
                    <button class="close-sidebar-btn" onclick="closeSidebar()">×</button>
                </div>
                <div class="file-preview" id="filePreview"></div>
                <div class="file-info-header">
                    <div class="file-info-location active" onclick="showSection('locationSection')">
                        <h4>Location</h4>
                    </div>
                    <div class="file-info-details" onclick="showSection('detailsSection')">
                        <h4>Details</h4>
                    </div>
                </div>
                <div class="info-section active" id="locationSection">
                    <div class="info-item"><span class="info-label">Department:</span><span class="info-value" id="departmentCollege">N/A</span></div>
                    <div class="info-item"><span class="info-label">Physical Location:</span><span class="info-value" id="physicalLocation">Digital only</span></div>
                </div>
                <div class="info-section" id="detailsSection">
                    <div class="access-log">
                        <h3>Who Has Access</h3>
                        <div class="access-users" id="accessUsers"></div>
                        <p class="access-info" id="accessInfo"></p>
                    </div>
                    <div class="file-details">
                        <h3>File Details</h3>
                        <div class="info-item"><span class="info-label">Uploader:</span><span class="info-value" id="uploader">N/A</span></div>
                        <div class="info-item"><span class="info-label">File Type:</span><span class="info-value" id="fileType">N/A</span></div>
                        <div class="info-item"><span class="info-label">File Size:</span><span class="info-value" id="fileSize">N/A</span></div>
                        <div class="info-item"><span class="info-label">Category:</span><span class="info-value" id="fileCategory">N/A</span></div>
                        <div class="info-item"><span class="info-label">Date Uploaded:</span><span class="info-value" id="dateUpload">N/A</span></div>
                    </div>
                </div>
            </div>

            <div class="full-preview-modal" id="fullPreviewModal">
                <div class="full-preview-content">
                    <button class="close-full-preview" onclick="closeFullPreview()">✕</button>
                    <div id="fullPreviewContent"></div>
                </div>
            </div>
            <div class="top-nav">
                <h2>Search Results</h2>
                <form action="search.php" method="GET" class="search-container" id="search-form">
                    <input type="text" id="search" name="q" placeholder="Search documents..." value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?>">
                    <select name="type" id="document-type">
                        <option value="">All Document Types</option>
                        <?php
                        $stmt = $pdo->query("SELECT DISTINCT Field_name AS name FROM documents_type_fields ORDER BY Field_name");
                        while ($type = $stmt->fetch(PDO::FETCH_ASSOC)) {
                            $selected = $documentType === $type['name'] ? 'selected' : '';
                            echo "<option value=\"" . htmlspecialchars($type['name'], ENT_QUOTES, 'UTF-8') . "\" $selected>" . htmlspecialchars($type['name'], ENT_QUOTES, 'UTF-8') . "</option>";
                        }
                        ?>
                    </select>
                    <select name="folder" id="folder">
                        <option value="">All Folders</option>
                        <option value="my-folder" <?= $folderFilter === 'my-folder' ? 'selected' : '' ?>>My Folder</option>
                        <?php foreach ($userDepartments as $dept): ?>
                            <option value="department-<?= htmlspecialchars($dept['Department_id'], ENT_QUOTES, 'UTF-8') ?>" <?= $folderFilter === 'department-' . $dept['Department_id'] ? 'selected' : '' ?>><?= htmlspecialchars($dept['Department_name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="hardcopy" id="hardcopy">
                        <option value="" <?= empty($hardCopyFilter) ? 'selected' : '' ?>>All Copies</option>
                        <option value="hardcopy" <?= $hardCopyFilter === 'hardcopy' ? 'selected' : '' ?>>Hardcopy</option>
                        <option value="softcopy" <?= $hardCopyFilter === 'softcopy' ? 'selected' : '' ?>>Softcopy</option>
                    </select>
                    <button type="submit">Search</button>
                </form>
            </div>

            <div class="filter-buttons">
                <a href="search.php?<?= http_build_query(array_merge($_GET, ['hardcopy' => 'hardcopy'])) ?>" class="filter-button <?= $hardCopyFilter === 'hardcopy' ? 'active' : '' ?>">Hardcopy</a>
                <a href="search.php?<?= http_build_query(array_merge($_GET, ['hardcopy' => 'softcopy'])) ?>" class="filter-button <?= $hardCopyFilter === 'softcopy' ? 'active' : '' ?>">Softcopy</a>
                <a href="search.php?<?= http_build_query(array_diff_key($_GET, ['hardcopy' => ''])) ?>" class="filter-button <?= empty($hardCopyFilter) ? 'active' : '' ?>">Clear Filter</a>
            </div>

            <div class="search-results">
                <?php if (empty($files)): ?>
                    <p>No files found.</p>
                <?php else: ?>
                    <div id="fileDisplay" class="masonry-grid">
                        <?php foreach ($files as $file): ?>
                            <div class="file-item" data-file-id="<?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>">
                                <div class="file-options" onclick="toggleOptions(this)">
                                    <i class="fas fa-ellipsis-v"></i>
                                    <div class="options-menu">
                                        <?php if ($file['User_id'] == $userId || in_array($file['Department_id'] ?? '', $departmentIds)): ?>
                                            <div onclick="handleOption('Rename', <?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>, '<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>')">Rename</div>
                                            <?php if ($file['User_id'] == $userId): ?>
                                                <div onclick="handleOption('Delete', <?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>, '<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>')">Delete</div>
                                            <?php endif; ?>
                                            <div onclick="handleOption('Make Copy', <?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>, '<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>')">Make Copy</div>
                                            <div onclick="handleOption('File Information', <?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>)">File Information</div>
                                        <?php else: ?>
                                            <div class="request-access-btn" onclick="requestAccess(<?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>, '<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>')">Request Document</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="file-icon">
                                    <i class="<?= getFileIcon($file['file_name']) ?>"></i>
                                </div>
                                <div class="file-name">
                                    <?= htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="pagination">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="search.php?page=<?= $i ?>&q=<?= urlencode($searchQuery) ?>&type=<?= urlencode($documentType) ?>&folder=<?= urlencode($folderFilter) ?>&hardcopy=<?= urlencode($hardCopyFilter) ?>&sort=<?= urlencode($sortBy) ?>"
                        class="<?= $page == $i ? 'active' : '' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
            </div>
        </div>
    </div>

    <script>
        const notyf = new Notyf();
        let openMenu = null;

        function toggleOptions(element) {
            const optionsMenu = element.querySelector('.options-menu');
            if (openMenu && openMenu !== optionsMenu) {
                openMenu.style.display = 'none';
            }
            optionsMenu.style.display = optionsMenu.style.display === 'block' ? 'none' : 'block';
            openMenu = optionsMenu;
        }

        document.addEventListener('click', (event) => {
            if (!event.target.closest('.file-options')) {
                if (openMenu) {
                    openMenu.style.display = 'none';
                    openMenu = null;
                }
            }
        });

        function handleOption(action, fileId, csrfToken) {
            switch (action) {
                case 'Rename':
                    renameFile(fileId, csrfToken);
                    break;
                case 'Delete':
                    deleteFile(fileId, csrfToken);
                    break;
                case 'Make Copy':
                    makeCopy(fileId, csrfToken);
                    break;
                case 'File Information':
                    openSidebar(fileId);
                    break;
                default:
                    console.log('Unknown action:', action);
            }
        }

        function renameFile(fileId, csrfToken) {
            const newName = prompt('Enter the new file name:');
            if (newName) {
                $.ajax({
                    url: 'rename_file.php',
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({
                        file_id: fileId,
                        new_name: newName,
                        csrf_token: csrfToken
                    }),
                    success: function(data) {
                        if (data.success) {
                            notyf.success('File renamed successfully.');
                            location.reload();
                        } else {
                            notyf.error('Failed to rename file: ' + (data.message || 'Unknown error'));
                        }
                    },
                    error: function(xhr, status, error) {
                        notyf.error('Server error: ' + error);
                    }
                });
            }
        }

        function deleteFile(fileId, csrfToken) {
            if (confirm('Are you sure you want to delete this file?')) {
                $.ajax({
                    url: 'delete_file.php',
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({
                        file_id: fileId,
                        csrf_token: csrfToken
                    }),
                    success: function(data) {
                        if (data.success) {
                            notyf.success('File deleted successfully.');
                            location.reload();
                        } else {
                            notyf.error('Failed to delete file: ' + (data.message || 'Unknown error'));
                        }
                    },
                    error: function(xhr, status, error) {
                        notyf.error('Server error: ' + error);
                    }
                });
            }
        }

        function makeCopy(fileId, csrfToken) {
            $.ajax({
                url: 'make_copy.php',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    file_id: fileId,
                    csrf_token: csrfToken
                }),
                success: function(data) {
                    if (data.success) {
                        notyf.success('File copy created successfully.');
                        location.reload();
                    } else {
                        notyf.error('Failed to create copy: ' + (data.message || 'Unknown error'));
                    }
                },
                error: function(xhr, status, error) {
                    notyf.error('Server error: ' + error);
                }
            });
        }

        function requestAccess(fileId, csrfToken) {
            $.ajax({
                url: 'handle_access_request.php',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    file_id: fileId,
                    action: 'request',
                    csrf_token: csrfToken
                }),
                success: function(data) {
                    if (data.success) {
                        notyf.success('Access request sent successfully.');
                        $(`.file-item[data-file-id="${fileId}"] .request-access-btn`)
                            .text('Request Sent')
                            .css('opacity', '0.6')
                            .off('click');
                    } else {
                        notyf.error('Failed to send access request: ' + (data.message || 'Unknown error'));
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    console.error('AJAX Error:', textStatus, errorThrown, jqXHR.responseText);
                    notyf.error('An error occurred while sending the access request: ' + textStatus);
                }
            });
        }

        function openSidebar(fileId) {
            fetch(`get_file_info.php?file_id=${fileId}`, {
                    method: 'GET',
                    credentials: 'same-origin'
                })
                .then(response => {
                    if (!response.ok) throw new Error(`HTTP error! Status: ${response.status}`);
                    return response.json();
                })
                .then(data => {
                    if (data.error) throw new Error(data.error);
                    document.getElementById('sidebarFileName').textContent = data.file_name || 'Unnamed File';
                    document.getElementById('departmentCollege').textContent = data.department_name || 'N/A';
                    document.getElementById('physicalLocation').textContent = data.copy_type === 'hard' ? 'Physical copy' : 'Digital only';
                    document.getElementById('uploader').textContent = data.uploaded_by || 'N/A';
                    document.getElementById('fileType').textContent = data.file_type || 'N/A';
                    document.getElementById('fileSize').textContent = formatFileSize(data.file_size) || 'N/A';
                    document.getElementById('fileCategory').textContent = data.document_type || 'N/A';
                    document.getElementById('dateUpload').textContent = data.upload_date || 'N/A';

                    const fileDetails = document.querySelector('.file-details');
                    fileDetails.innerHTML = `
                        <h3>File Details</h3>
                        <div class="info-item"><span class="info-label">Uploader:</span><span class="info-value">${data.uploaded_by || 'N/A'}</span></div>
                        <div class="info-item"><span class="info-label">File Type:</span><span class="info-value">${data.file_type || 'N/A'}</span></div>
                        <div class="info-item"><span class="info-label">File Size:</span><span class="info-value">${formatFileSize(data.file_size) || 'N/A'}</span></div>
                        <div class="info-item"><span class="info-label">Category:</span><span class="info-value">${data.document_type || 'N/A'}</span></div>
                        <div class="info-item"><span class="info-label">Date Uploaded:</span><span class="info-value">${data.upload_date || 'N/A'}</span></div>
                    `;

                    fetch(`get_document_type_field.php?document_type_name=${encodeURIComponent(data.document_type)}`)
                        .then(response => response.json())
                        .then(fieldsData => {
                            if (fieldsData.success) {
                                fieldsData.fields.forEach(field => {
                                    const value = data[field.field_name] || 'N/A';
                                    fileDetails.innerHTML += `
                                        <div class="info-item">
                                            <span class="info-label">${field.field_label}:</span>
                                            <span class="info-value">${value}</span>
                                        </div>`;
                                });
                            }
                        })
                        .catch(error => console.error('Error fetching document type fields:', error));

                    const preview = document.getElementById('filePreview');
                    preview.innerHTML = '';
                    if (data.copy_type !== 'hard' && data.file_path) {
                        try {
                            const filePath = sanitizeFilePath(data.file_path);
                            const ext = data.file_type.toLowerCase();
                            if (ext === 'pdf') {
                                preview.innerHTML = `<iframe src="${filePath}" title="File Preview"></iframe><p>Click to view full file</p>`;
                                preview.querySelector('iframe').addEventListener('click', () => openFullPreview(filePath));
                            } else if (['jpg', 'png', 'jpeg', 'gif'].includes(ext)) {
                                preview.innerHTML = `<img src="${filePath}" alt="File Preview"><p>Click to view full image</p>`;
                                preview.querySelector('img').addEventListener('click', () => openFullPreview(filePath));
                            } else {
                                preview.innerHTML = '<p>Preview not available for this file type</p>';
                            }
                        } catch (e) {
                            preview.innerHTML = '<p>Invalid file path</p>';
                        }
                    } else if (data.copy_type === 'hard') {
                        preview.innerHTML = '<p>Hardcopy - No digital preview available</p>';
                    } else {
                        preview.innerHTML = '<p>No preview available</p>';
                    }

                    fetchAccessInfo(fileId);
                    document.querySelector('.file-info-sidebar').classList.add('active');
                })
                .catch(error => {
                    console.error('Error:', error);
                    notyf.error('Failed to load file information: ' + error.message);
                });
        }

        function fetchAccessInfo(fileId) {
            fetch(`get_access_info.php?file_id=${fileId}`, {
                    method: 'GET',
                    credentials: 'same-origin'
                })
                .then(response => {
                    if (!response.ok) throw new Error('Network response was not ok');
                    return response.json();
                })
                .then(data => {
                    const accessUsers = document.getElementById('accessUsers');
                    const accessInfo = document.getElementById('accessInfo');
                    accessUsers.innerHTML = '';
                    if (data.users && data.users.length > 0) {
                        data.users.forEach(user => {
                            accessUsers.innerHTML += `<div>${user.username} (${user.role})</div>`;
                        });
                        accessInfo.textContent = `${data.users.length} user(s) have access`;
                    } else {
                        accessUsers.innerHTML = '<div>No additional users have access</div>';
                        accessInfo.textContent = 'Only you have access';
                    }
                })
                .catch(error => {
                    console.error('Error fetching access info:', error);
                    document.getElementById('accessUsers').innerHTML = 'Error loading access info';
                    document.getElementById('accessInfo').textContent = '';
                });
        }

        function openFullPreview(filePath) {
            const modal = document.getElementById('fullPreviewModal');
            const content = document.getElementById('fullPreviewContent');
            content.innerHTML = '';
            try {
                const sanitizedPath = sanitizeFilePath(filePath);
                const ext = sanitizedPath.split('.').pop().toLowerCase();
                if (ext === 'pdf') {
                    content.innerHTML = `<iframe src="${sanitizedPath}" title="File Preview"></iframe>`;
                } else if (['jpg', 'png', 'jpeg', 'gif'].includes(ext)) {
                    content.innerHTML = `<img src="${sanitizedPath}" alt="File Preview">`;
                } else {
                    content.innerHTML = '<p>Full preview not available for this file type</p>';
                }
                modal.style.display = 'flex';
                modal.onclick = function(event) {
                    if (event.target === modal) {
                        closeFullPreview();
                    }
                };
            } catch (e) {
                content.innerHTML = '<p>Invalid file path</p>';
                modal.style.display = 'flex';
            }
        }

        function closeFullPreview() {
            const modal = document.getElementById('fullPreviewModal');
            const content = document.getElementById('fullPreviewContent');
            modal.style.display = 'none';
            content.innerHTML = '';
        }

        function closeSidebar() {
            document.querySelector('.file-info-sidebar').classList.remove('active');
        }

        function showSection(sectionId) {
            document.querySelectorAll('.info-section').forEach(section => section.classList.remove('active'));
            document.getElementById(sectionId).classList.add('active');
            document.querySelectorAll('.file-info-header div').forEach(div => div.classList.remove('active'));
            document.querySelector(`.file-info-${sectionId === 'locationSection' ? 'location' : 'details'}`).classList.add('active');
        }

        function formatFileSize(bytes) {
            if (!bytes) return '0 bytes';
            if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
            if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
            if (bytes >= 1024) return (bytes / 1024).toFixed(2) + ' KB';
            if (bytes > 1) return bytes + ' bytes';
            return '1 byte';
        }

        $(document).ready(function() {
            $("#search").autocomplete({
                source: function(request, response) {
                    $.ajax({
                        url: "autocomplete.php",
                        dataType: "json",
                        data: {
                            term: request.term
                        },
                        success: function(data) {
                            response(data);
                        },
                        error: function(xhr, status, error) {
                            console.error('Autocomplete error:', status, error);
                        }
                    });
                },
                minLength: 2,
                select: function(event, ui) {
                    $("#search").val(ui.item.label);
                    if (ui.item.document_type) {
                        $("#document-type").val(ui.item.document_type.toLowerCase());
                    }
                    if (ui.item.department_id) {
                        $("#folder").val("department-" + ui.item.department_id);
                    }
                    $("#search-form").submit();
                }
            }).autocomplete("instance")._renderItem = function(ul, item) {
                return $("<li>").append(`<div>${item.label}</div>`).appendTo(ul);
            };

            $('.toggle-btn').click(function() {
                $('.sidebar').toggleClass('minimized');
                $('.main-content').toggleClass('expanded');
            });
        });
    </script>
</body>

</html>