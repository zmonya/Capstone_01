<?php
session_start();
require 'db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];

// Fetch user details including department and sub-department
$stmt = $pdo->prepare("
    SELECT users.*, 
           d.id AS department_id, 
           d.name AS department_name, 
           sd.id AS sub_department_id, 
           sd.name AS sub_department_name 
    FROM users 
    LEFT JOIN user_department_affiliations uda ON users.id = uda.user_id 
    LEFT JOIN departments d ON uda.department_id = d.id 
    LEFT JOIN sub_departments sd ON uda.sub_department_id = sd.id 
    WHERE users.id = ? 
    LIMIT 1
");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch all user department and sub-department affiliations
$stmt = $pdo->prepare("
    SELECT d.id AS department_id, 
           d.name AS department_name, 
           sd.id AS sub_department_id, 
           sd.name AS sub_department_name 
    FROM departments d 
    JOIN user_department_affiliations uda ON d.id = uda.department_id 
    LEFT JOIN sub_departments sd ON uda.sub_department_id = sd.id 
    WHERE uda.user_id = ?
");
$stmt->execute([$userId]);
$userDepartments = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($userDepartments) && !empty($user['department_id'])) {
    $userDepartments = [[
        'department_id' => $user['department_id'],
        'department_name' => $user['department_name'],
        'sub_department_id' => $user['sub_department_id'],
        'sub_department_name' => $user['sub_department_name']
    ]];
}

// Fetch recent files, notifications, activity logs, and all files
$stmt = $pdo->prepare("SELECT * FROM files WHERE user_id = ? ORDER BY upload_date DESC LIMIT 5");
$stmt->execute([$userId]);
$recentFiles = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY timestamp DESC LIMIT 5");
$stmt->execute([$userId]);
$notificationLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT * FROM activity_log WHERE user_id = ? ORDER BY timestamp DESC LIMIT 5");
$stmt->execute([$userId]);
$activityLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT files.*, document_types.name AS document_type 
    FROM files 
    LEFT JOIN document_types ON files.document_type_id = document_types.id 
    WHERE files.user_id = ? 
    ORDER BY files.upload_date DESC
");
$stmt->execute([$userId]);
$files = $stmt->fetchAll(PDO::FETCH_ASSOC);

function getFileIcon($fileName)
{
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    switch ($extension) {
        case 'pdf':
            return 'fas fa-file-pdf';
        case 'doc':
        case 'docx':
            return 'fas fa-file-word';
        case 'xls':
        case 'xlsx':
            return 'fas fa-file-excel';
        case 'jpg':
        case 'png':
            return 'fas fa-file-image';
        default:
            return 'fas fa-file';
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.css">
    <script src="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="client.css">
    <link rel="stylesheet" href="style/client-sidebar.css">
    <style>
        /* Existing styles remain unchanged */
        body {
            display: flex;
            margin-left: 330px;
            font-family: 'Montserrat', sans-serif;
            min-height: 100vh;
        }

        .processed-access {
            background-color: #e0e0e0;
            cursor: default;
            opacity: 0.6;
        }

        .access-result {
            background-color: #e0f7fa;
            cursor: default;
        }

        .notification-item {
            cursor: pointer;
        }

        .notification-item:hover {
            background-color: #f0f0f0;
            transition: background-color 0.3s ease;
        }

        .notification-item.pending-access {
            background-color: #fff3cd;
        }

        .notification-item.pending-access:hover {
            background-color: #ffeeba;
        }

        .notification-item.received-pending {
            background-color: #cce5ff;
        }

        .notification-item.received-pending:hover {
            background-color: #b3d4fc;
        }

        .popup-file-selection {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 80%;
            max-width: 600px;
            background-color: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            z-index: 1000;
            display: none;
        }

        .popup-file-selection .exit-button {
            position: absolute;
            top: 10px;
            right: 10px;
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
        }

        .popup-file-selection h3 {
            margin-top: 0;
        }

        .popup-file-selection .search-container {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .popup-file-selection .search-container input,
        .popup-file-selection .search-container select {
            flex: 1;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
        }

        .popup-file-selection .view-toggle {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .popup-file-selection .view-toggle button {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            background-color: #f0f0f0;
        }

        .popup-file-selection .view-toggle button.active {
            background-color: #50c878;
            color: white;
        }

        .popup-file-selection .file-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
            margin-bottom: 10px;
        }

        .popup-file-selection .file-item .file-icon {
            font-size: 24px;
        }

        .popup-file-selection .file-item .select-file-button {
            margin-left: auto;
            padding: 5px 10px;
            border: none;
            border-radius: 5px;
            background-color: #50c878;
            color: white;
            cursor: pointer;
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
            margin-top: 100px;
        }

        .filter-button {
            display: inline-block;
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

        .popup-questionnaire {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background-color: #fff;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
            z-index: 1000;
            width: 450px;
            display: none;
            transition: all 0.3s ease;
        }

        .popup-questionnaire h3 {
            margin: 0 0 10px;
            font-size: 24px;
            color: #2c3e50;
        }

        .popup-questionnaire .subtitle {
            margin: 0 0 20px;
            font-size: 14px;
            color: #7f8c8d;
        }

        .popup-questionnaire .exit-button {
            position: absolute;
            top: 15px;
            right: 15px;
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #7f8c8d;
        }

        .popup-questionnaire .exit-button:hover {
            color: #e74c3c;
        }

        .hardcopy-options {
            display: grid;
            gap: 15px;
        }

        .checkbox-container {
            display: flex;
            align-items: center;
            position: relative;
            padding-left: 35px;
            cursor: pointer;
            font-size: 14px;
            color: #34495e;
            user-select: none;
        }

        .checkbox-container input {
            position: absolute;
            opacity: 0;
            cursor: pointer;
            height: 0;
            width: 0;
        }

        .checkmark {
            position: absolute;
            top: 0;
            left: 0;
            height: 20px;
            width: 20px;
            background-color: #eee;
            border-radius: 4px;
            transition: background-color 0.3s ease;
        }

        .checkbox-container:hover input~.checkmark {
            background-color: #ddd;
        }

        .checkbox-container input:checked~.checkmark {
            background-color: #50c878;
        }

        .checkmark:after {
            content: "";
            position: absolute;
            display: none;
        }

        .checkbox-container input:checked~.checkmark:after {
            display: block;
        }

        .checkbox-container .checkmark:after {
            left: 7px;
            top: 3px;
            width: 5px;
            height: 10px;
            border: solid white;
            border-width: 0 2px 2px 0;
            transform: rotate(45deg);
        }

        .radio-group {
            padding: 15px;
            background-color: #f9f9f9;
            border-radius: 8px;
            display: grid;
            gap: 10px;
        }

        .radio-container {
            display: flex;
            align-items: center;
            position: relative;
            padding-left: 35px;
            cursor: pointer;
            font-size: 14px;
            color: #34495e;
            user-select: none;
        }

        .radio-container input {
            position: absolute;
            opacity: 0;
            cursor: pointer;
        }

        .radio-checkmark {
            position: absolute;
            top: 0;
            left: 0;
            height: 20px;
            width: 20px;
            background-color: #eee;
            border-radius: 50%;
            transition: background-color 0.3s ease;
        }

        .radio-container:hover input~.radio-checkmark {
            background-color: #ddd;
        }

        .radio-container input:checked~.radio-checkmark {
            background-color: #50c878;
        }

        .radio-checkmark:after {
            content: "";
            position: absolute;
            display: none;
        }

        .radio-container input:checked~.radio-checkmark:after {
            display: block;
        }

        .radio-container .radio-checkmark:after {
            top: 6px;
            left: 6px;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: white;
        }

        .storage-suggestion {
            margin-top: 15px;
            padding: 15px;
            background-color: #e8f6ea;
            border-left: 4px solid #50c878;
            border-radius: 6px;
            font-size: 14px;
            color: #2c3e50;
            display: none;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .storage-suggestion p {
            margin: 0;
            font-weight: 500;
        }

        .storage-suggestion span {
            display: block;
            font-size: 12px;
            color: #50c878;
            margin-top: 5px;
        }

        .button-group {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .button-group button {
            padding: 12px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            transition: background-color 0.3s ease, transform 0.1s ease;
            flex: 1;
        }

        .button-group button:hover {
            transform: translateY(-2px);
        }

        .btn-back {
            background-color: #ecf0f1;
            color: #7f8c8d;
        }

        .btn-back:hover {
            background-color: #d5dbdc;
        }

        .btn-next {
            background-color: #50c878;
            color: white;
        }

        .btn-next:hover {
            background-color: #45b069;
        }

        #acceptFileButton {
            background-color: #50c878;
            color: white;
        }

        #acceptFileButton:hover {
            background-color: #45b069;
        }

        #denyFileButton {
            background-color: #e74c3c;
            color: white;
        }

        #denyFileButton:hover {
            background-color: #c0392b;
        }

        .popup-questionnaire .btn-back {
            background-color: #ecf0f1;
            color: #7f8c8d;
        }

        .popup-questionnaire .btn-back:hover {
            background-color: #d5dbdc;
        }

        .popup-questionnaire .btn-next {
            background-color: #50c878;
            color: white;
        }

        .popup-questionnaire .btn-next:hover {
            background-color: #45b069;
        }

        .file-preview {
            margin: 15px 0;
            text-align: center;
        }

        .file-preview iframe,
        .file-preview img {
            max-width: 100%;
            max-height: 150px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }

        .popup-questionnaire .file-preview p {
            margin: 5px 0 0;
            font-size: 12px;
            color: #7f8c8d;
        }

        #hardcopyStoragePopup label {
            display: block;
            margin: 10px 0;
        }

        #hardcopyStoragePopup input[type="radio"] {
            margin-right: 10px;
        }

        #hardcopyStoragePopup .radio-group {
            margin: 15px 0;
            padding: 10px;
            background-color: #f9f9f9;
            border-radius: 5px;
        }

        #hardcopyStoragePopup .storage-suggestion {
            margin-top: 10px;
            padding: 10px;
            background-color: #e8f4f8;
            border-radius: 5px;
            display: none;
        }

        #hardcopyStoragePopup .button-group {
            display: flex;
            justify-content: space-between;
        }

        #hardcopyStoragePopup .button-group button {
            flex: 1;
            margin: 0 5px;
        }

        #linkHardcopyPopup {
            width: 500px;
        }

        #linkHardcopyPopup .search-container {
            margin: 15px 0;
        }

        #linkHardcopyPopup .file-list {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #ccc;
            border-radius: 5px;
            padding: 10px;
        }

        #linkHardcopyPopup .file-list .file-item {
            display: flex;
            align-items: center;
            padding: 8px;
            border-bottom: 1px solid #eee;
            cursor: pointer;
        }

        #linkHardcopyPopup .file-list .file-item:hover {
            background-color: #f0f0f0;
        }

        #linkHardcopyPopup .file-list .file-item .file-icon {
            margin-right: 10px;
        }
    </style>
</head>

<body>
    <div class="sidebar">
        <button class="toggle-btn" title="Toggle Sidebar"><i class="fas fa-bars"></i></button>
        <h2 class="sidebar-title">Document Archival</h2>
        <?php if ($userRole === 'admin'): ?>
            <a href="admin_dashboard.php" class="admin-dashboard-btn"><i class="fas fa-user-shield"></i><span class="link-text">Admin Dashboard</span></a>
        <?php endif; ?>
        <a href="index.php" class="<?= basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : '' ?>"><i class="fas fa-home"></i><span class="link-text"> Dashboard</span></a>
        <a href="my-report.php"><i class="fas fa-chart-bar"></i><span class="link-text"> My Report</span></a>
        <a href="my-folder.php" class="<?= basename($_SERVER['PHP_SELF']) == 'my-folder.php' ? 'active' : '' ?>"><i class="fas fa-folder"></i><span class="link-text"> My Folder</span></a>
        <?php if (!empty($userDepartments)): ?>
            <?php foreach ($userDepartments as $dept): ?>
                <a href="department_folder.php?department_id=<?= htmlspecialchars($dept['department_id']) ?>"
                    class="<?= isset($_GET['department_id']) && $_GET['department_id'] == $dept['department_id'] ? 'active' : '' ?>">
                    <i class="fas fa-folder"></i>
                    <span class="link-text"> <?= htmlspecialchars($dept['department_name'] ?? 'Unnamed Department') ?></span>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
        <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i><span class="link-text">Logout</span></a>
    </div>

    <div class="popup-file-selection" id="fileSelectionPopup">
        <button class="exit-button" onclick="closePopup('fileSelectionPopup')">x</button>
        <h3>Select a Document</h3>
        <div class="search-container">
            <input type="text" id="fileSearch" placeholder="Search files..." oninput="filterFiles()">
            <select id="documentTypeFilter" onchange="filterFilesByType()">
                <option value="">All Document Types</option>
                <?php
                $stmt = $pdo->prepare("SELECT name FROM document_types ORDER BY name ASC");
                $stmt->execute();
                $docTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($docTypes as $type): ?>
                    <option value="<?= htmlspecialchars($type['name']) ?>"><?= htmlspecialchars(ucfirst($type['name'])) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="view-toggle">
            <button id="thumbnailViewButton" class="active" onclick="switchView('thumbnail')"><i class="fas fa-th-large"></i> Thumbnails</button>
            <button id="listViewButton" onclick="switchView('list')"><i class="fas fa-list"></i> List</button>
        </div>
        <div id="fileDisplay" class="thumbnail-view masonry-grid">
            <?php foreach ($files as $file): ?>
                <div class="file-item" data-file-id="<?= $file['id'] ?>" data-file-name="<?= htmlspecialchars($file['file_name']) ?>" data-document-type="<?= htmlspecialchars($file['document_type']) ?>">
                    <div class="file-icon"><i class="<?= getFileIcon($file['file_name']) ?>"></i></div>
                    <p><?= htmlspecialchars($file['file_name']) ?></p>
                    <button class="select-file-button">Select</button>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="popup-questionnaire" id="fileDetailsPopup">
        <button class="exit-button" onclick="closePopup('fileDetailsPopup')">x</button>
        <h3>Upload File Details</h3>
        <p class="subtitle">Provide details for the document you're uploading.</p>
        <form id="fileDetailsForm" enctype="multipart/form-data">
            <label for="departmentId">Department:</label>
            <select id="departmentId" name="department_id">
                <option value="">No Department</option>
                <?php foreach ($userDepartments as $dept): ?>
                    <option value="<?= $dept['department_id'] ?>" <?= $dept['department_id'] == $user['department_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($dept['department_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <label for="subDepartmentId">Sub-Department (Optional):</label>
            <select id="subDepartmentId" name="sub_department_id">
                <option value="">No Sub-Department</option>
                <?php if (!empty($user['sub_department_id'])): ?>
                    <option value="<?= $user['sub_department_id'] ?>" selected>
                        <?= htmlspecialchars($user['sub_department_name']) ?>
                    </option>
                <?php endif; ?>
            </select>
            <label for="documentType">Document Type:</label>
            <select id="documentType" name="document_type" required>
                <option value="">Select Document Type</option>
                <?php foreach ($docTypes as $type): ?>
                    <option value="<?= htmlspecialchars($type['name']) ?>"><?= htmlspecialchars(ucfirst($type['name'])) ?></option>
                <?php endforeach; ?>
            </select>
            <div id="dynamicFields"></div>
            <div class="button-group">
                <button type="button" class="btn-back" onclick="closePopup('fileDetailsPopup')">Cancel</button>
                <button type="button" class="btn-next" onclick="proceedToHardcopy()">Next</button>
            </div>
        </form>
    </div>

    <div class="popup-questionnaire" id="sendFilePopup">
        <button class="exit-button" onclick="closePopup('sendFilePopup')">x</button>
        <h3>Send File</h3>
        <form id="sendFileForm">
            <label for="recipients">Recipients (Users or Departments):</label>
            <select id="recipientSelect" name="recipients[]" multiple style="width: 100%;">
                <optgroup label="Users">
                    <?php
                    $stmt = $pdo->prepare("SELECT id, username FROM users WHERE id != ?");
                    $stmt->execute([$userId]);
                    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($users as $userOption): ?>
                        <option value="user:<?= $userOption['id'] ?>"><?= htmlspecialchars($userOption['username']) ?></option>
                    <?php endforeach; ?>
                </optgroup>
                <optgroup label="Departments">
                    <?php
                    $stmt = $pdo->prepare("SELECT id, name FROM departments");
                    $stmt->execute();
                    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($departments as $dept): ?>
                        <option value="department:<?= $dept['id'] ?>"><?= htmlspecialchars($dept['name']) ?></option>
                    <?php endforeach; ?>
                </optgroup>
            </select>
            <div class="button-group">
                <button type="button" class="btn-back" onclick="closePopup('sendFilePopup')">Cancel</button>
                <button type="button" class="btn-next" onclick="sendFile()">Send</button>
            </div>
        </form>
    </div>

    <div class="popup-questionnaire" id="hardcopyStoragePopup">
        <button class="exit-button" onclick="closePopup('hardcopyStoragePopup')">×</button>
        <h3>Hardcopy Storage</h3>
        <p class="subtitle">Specify if this document has a physical copy and how to manage it.</p>
        <div class="hardcopy-options">
            <label class="checkbox-container">
                <input type="checkbox" id="hardcopyCheckbox" name="hard_copy_available">
                <span class="checkmark"></span>
                This file has a hardcopy
            </label>
            <div class="radio-group" id="hardcopyOptions" style="display: none;">
                <label class="radio-container">
                    <input type="radio" name="hardcopyOption" value="link" checked>
                    <span class="radio-checkmark"></span>
                    Link to existing hardcopy
                </label>
                <label class="radio-container">
                    <input type="radio" name="hardcopyOption" value="new">
                    <span class="radio-checkmark"></span>
                    Suggest new storage location
                </label>
                <div class="storage-suggestion" id="storageSuggestion"></div>
            </div>
        </div>
        <div class="button-group">
            <button class="btn-back" onclick="handleHardcopyBack()">Back</button>
            <button class="btn-next" onclick="handleHardcopyNext()">Next</button>
        </div>
    </div>

    <div class="popup-questionnaire" id="linkHardcopyPopup">
        <button class="exit-button" onclick="closePopup('linkHardcopyPopup')">x</button>
        <h3>Link to Existing Hardcopy</h3>
        <div class="search-container">
            <input type="text" id="hardcopySearch" placeholder="Search hardcopy files..." oninput="filterHardcopies()">
        </div>
        <div class="file-list" id="hardcopyList"></div>
        <div class="button-group">
            <button class="btn-back" onclick="closePopup('linkHardcopyPopup')">Cancel</button>
            <button id="linkHardcopyButton" class="btn-next" disabled onclick="linkHardcopy()">Link</button>
        </div>
    </div>

    <div class="top-nav">
        <h2>Dashboard</h2>
        <form action="search.php" method="GET" class="search-container" id="search-form">
            <input type="text" id="searchInput" name="q" placeholder="Search documents..." value="<?= htmlspecialchars($searchQuery ?? '') ?>">
            <select name="type" id="document-type">
                <option value="">All Document Types</option>
                <?php foreach ($docTypes as $type): ?>
                    <option value="<?= htmlspecialchars($type['name']) ?>" <?= ($documentType ?? '') === $type['name'] ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($type['name'])) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="folder" id="folder">
                <option value="">All Folders</option>
                <option value="my-folder" <?= ($folderFilter ?? '') === 'my-folder' ? 'selected' : '' ?>>My Folder</option>
                <?php if (!empty($userDepartments)): ?>
                    <?php foreach ($userDepartments as $dept): ?>
                        <option value="department-<?= $dept['department_id'] ?>" <?= ($folderFilter ?? '') === 'department-' . $dept['department_id'] ? 'selected' : '' ?>><?= htmlspecialchars($dept['department_name']) ?></option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
            <button type="submit">Search</button>
        </form>
        <i class="fas fa-history activity-log-icon" onclick="toggleActivityLog()"></i>
    </div>

    <div class="activity-log" id="activityLog" style="display: none;">
        <h3>Activity Log</h3>
        <div class="log-entries">
            <?php if (!empty($activityLogs)): ?>
                <?php foreach ($activityLogs as $log): ?>
                    <div class="log-entry"><i class="fas fa-history"></i>
                        <p><?= htmlspecialchars($log['action']) ?></p><span><?= date('h:i A', strtotime($log['timestamp'])) ?></span>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="log-entry">
                    <p>No recent activity.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="main-content">
        <div class="user-id-calendar-container">
            <div class="user-id">
                <img src="<?php echo $user['profile_pic'] ?? 'user.jpg'; ?>" alt="User Picture" class="user-picture">
                <div class="user-info">
                    <p class="user-name"><?= htmlspecialchars($user['full_name']) ?></p>
                    <p class="user-position"><?= htmlspecialchars($user['position']) ?></p>
                    <p class="user-department"><?= htmlspecialchars($user['department_name'] ?? 'No Department') ?></p>
                </div>
            </div>
            <div class="digital-calendar-clock">
                <p id="currentDate"></p>
                <p id="currentTime"></p>
            </div>
        </div>
        <div class="upload-activity-container">
            <div class="upload-file" id="upload">
                <h3>Send a Document</h3><button id="selectDocumentButton">Select Document</button>
            </div>
            <div class="upload-file" id="fileUpload">
                <h3>Upload File</h3><button id="uploadFileButton">Upload File</button>
            </div>
            <div class="notification-log">
                <h3>Notifications</h3>
                <div class="log-entries">
                    <?php if (!empty($notificationLogs)): ?>
                        <?php foreach ($notificationLogs as $notification): ?>
                            <div class="log-entry notification-item <?= $notification['type'] === 'access_request' && $notification['status'] === 'pending' ? 'pending-access' : ($notification['type'] === 'received' && $notification['status'] === 'pending' ? 'received-pending' : '') ?>"
                                data-notification-id="<?= $notification['id'] ?>"
                                data-file-id="<?= $notification['file_id'] ?>"
                                data-message="<?= htmlspecialchars($notification['message']) ?>"
                                data-type="<?= $notification['type'] ?>"
                                data-status="<?= $notification['status'] ?>">
                                <i class="fas fa-bell"></i>
                                <p><?= htmlspecialchars($notification['message']) ?></p>
                                <span><?= date('h:i A', strtotime($notification['timestamp'])) ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="log-entry">
                            <p>No new notifications.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="popup-questionnaire" id="fileAcceptancePopup">
            <button class="exit-button" onclick="closePopup('fileAcceptancePopup')">x</button>
            <h3 id="fileAcceptanceTitle">Review File</h3>
            <p id="fileAcceptanceMessage"></p>
            <div class="file-preview" id="filePreview"></div>
            <div class="button-group">
                <button id="acceptFileButton">Accept</button>
                <button id="denyFileButton">Deny</button>
            </div>
        </div>
        <div class="popup-questionnaire" id="alreadyProcessedPopup">
            <button class="exit-button" onclick="closePopup('alreadyProcessedPopup')">x</button>
            <h3>Request Status</h3>
            <p id="alreadyProcessedMessage"></p>
        </div>
    </div>

    <script>
        const notyf = new Notyf();
        let selectedFile = null;
        let selectedHardcopyId = null;

        $(document).ready(function() {
            $('#recipientSelect').select2({
                placeholder: "Select users or departments",
                allowClear: true
            });

            $('.toggle-btn').on('click', function() {
                $('.sidebar').toggleClass('minimized');
                $('.top-nav').toggleClass('resized', $('.sidebar').hasClass('minimized'));
            });

            function updateDateTime() {
                const now = new Date();
                const options = {
                    weekday: 'long',
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric'
                };
                $('#currentDate').text(now.toLocaleDateString('en-US', options));
                $('#currentTime').text(now.toLocaleTimeString('en-US'));
            }
            setInterval(updateDateTime, 1000);
            updateDateTime();

            $('#selectDocumentButton').on('click', function() {
                $('#fileSelectionPopup').show();
            });

            $('#uploadFileButton').on('click', function() {
                const fileInput = $('<input type="file" id="fileInput" style="display: none;">');
                $('body').append(fileInput);
                fileInput.trigger('click');
                fileInput.on('change', function() {
                    const file = this.files[0];
                    if (file) {
                        selectedFile = file;
                        $('#fileDetailsPopup').show();
                    }
                });
            });

            $("#searchInput").autocomplete({
                source: function(request, response) {
                    $.ajax({
                        url: "autocomplete.php",
                        dataType: "json",
                        data: {
                            term: request.term
                        },
                        success: function(data) {
                            response(data);
                        }
                    });
                },
                minLength: 2,
                select: function(event, ui) {
                    $("#searchInput").val(ui.item.value);
                    if (ui.item.document_type) $("#document-type").val(ui.item.document_type.toLowerCase());
                    if (ui.item.department_id) $("#folder").val("department-" + ui.item.department_id);
                    $("#search-form").submit();
                }
            });

            fetchNotifications();
            setInterval(fetchNotifications, 5000);
            setInterval(fetchAccessNotifications, 5000);

            $('#hardcopyCheckbox').on('change', function() {
                $('#hardcopyOptions').toggle(this.checked);
                if (!this.checked) {
                    $('#storageSuggestion').hide().empty();
                } else if ($('input[name="hardcopyOption"]:checked').val() === 'new') {
                    fetchStorageSuggestion();
                }
            });

            $('input[name="hardcopyOption"]').on('change', function() {
                if (this.value === 'new') {
                    fetchStorageSuggestion();
                } else {
                    $('#storageSuggestion').hide().empty();
                }
            });

            // Function to load sub-departments
            function loadSubDepartments(departmentId, selectedSubDeptId = null) {
                const subDeptSelect = $('#subDepartmentId');
                subDeptSelect.empty().append('<option value="">No Sub-Department</option>');
                if (departmentId) {
                    $.ajax({
                        url: 'get_sub_departments.php',
                        method: 'GET',
                        data: {
                            department_id: departmentId
                        },
                        dataType: 'json',
                        success: function(data) {
                            data.forEach(subDept => {
                                const isSelected = subDept.id == selectedSubDeptId ? 'selected' : '';
                                subDeptSelect.append(`<option value="${subDept.id}" ${isSelected}>${subDept.name}</option>`);
                            });
                        },
                        error: function() {
                            notyf.error('Failed to load sub-departments.');
                        }
                    });
                }
            }

            // Load sub-departments for the initially selected department
            const initialDeptId = $('#departmentId').val();
            const initialSubDeptId = <?= json_encode($user['sub_department_id'] ?? null) ?>;
            if (initialDeptId) {
                loadSubDepartments(initialDeptId, initialSubDeptId);
            }

            // Dynamically load sub-departments when department changes
            $('#departmentId').on('change', function() {
                const departmentId = $(this).val();
                loadSubDepartments(departmentId);
            });

            // Dynamically load document type fields
            $('#documentType').on('change', function() {
                const docTypeName = $(this).val();
                const dynamicFields = $('#dynamicFields');
                dynamicFields.empty();

                if (docTypeName) {
                    $.ajax({
                        url: 'get_document_type_field.php',
                        method: 'GET',
                        data: {
                            document_type_name: docTypeName
                        },
                        dataType: 'json',
                        success: function(data) {
                            if (data.success && data.fields.length > 0) {
                                data.fields.forEach(field => {
                                    const requiredAttr = field.is_required ? 'required' : '';
                                    let inputField = '';
                                    switch (field.field_type) {
                                        case 'text':
                                            inputField = `<input type="text" id="${field.field_name}" name="${field.field_name}" ${requiredAttr}>`;
                                            break;
                                        case 'textarea':
                                            inputField = `<textarea id="${field.field_name}" name="${field.field_name}" ${requiredAttr}></textarea>`;
                                            break;
                                        case 'date':
                                            inputField = `<input type="date" id="${field.field_name}" name="${field.field_name}" ${requiredAttr}>`;
                                            break;
                                    }
                                    dynamicFields.append(`
                                    <label for="${field.field_name}">${field.field_label}${field.is_required ? ' *' : ''}:</label>
                                    ${inputField}
                                `);
                                });
                            } else {
                                dynamicFields.append('<p>No additional fields required for this document type.</p>');
                            }
                        },
                        error: function() {
                            notyf.error('Failed to load document type fields.');
                            dynamicFields.append('<p>Error loading fields.</p>');
                        }
                    });
                }
            });

            // File selection handler
            $(document).on('click', '.select-file-button', function() {
                $('.file-item').removeClass('selected'); // Clear previous selection
                const $fileItem = $(this).closest('.file-item');
                $fileItem.addClass('selected'); // Mark current selection
                const fileId = $fileItem.data('file-id');
                $('#sendFilePopup').data('selected-file-id', fileId); // Store file ID
                $('#fileSelectionPopup').hide();
                $('#sendFilePopup').show();
            });
        });

        function fetchNotifications() {
            $.ajax({
                url: 'fetch_notifications.php',
                method: 'GET',
                dataType: 'json',
                success: function(data) {
                    const notificationContainer = $('.notification-log .log-entries');
                    if (Array.isArray(data) && data.length > 0) {
                        const currentNotifications = notificationContainer.find('.notification-item').map(function() {
                            return $(this).data('notification-id');
                        }).get();
                        const newNotifications = data.map(n => n.id);

                        if (JSON.stringify(currentNotifications) !== JSON.stringify(newNotifications)) {
                            notificationContainer.empty();
                            data.forEach(notification => {
                                const notificationClass = notification.type === 'access_request' && notification.status === 'pending' ?
                                    'pending-access' :
                                    (notification.type === 'received' && notification.status === 'pending' ? 'received-pending' : 'processed-access');
                                notificationContainer.append(`
                            <div class="log-entry notification-item ${notificationClass}"
                                data-notification-id="${notification.id}"
                                data-file-id="${notification.file_id}"
                                data-message="${notification.message}"
                                data-type="${notification.type}"
                                data-status="${notification.status}">
                                <i class="fas fa-bell"></i>
                                <p>${notification.message}</p>
                                <span>${new Date(notification.timestamp).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })}</span>
                            </div>
                        `);
                            });
                        }
                    } else if (Array.isArray(data) && data.length === 0 && notificationContainer.find('.notification-item').length === 0) {
                        notificationContainer.empty().append('<div class="log-entry"><p>No new notifications.</p></div>');
                    }
                },
                error: function() {
                    notyf.error('Failed to fetch notifications.');
                }
            });
        }

        function fetchAccessNotifications() {
            // Placeholder for fetching access notifications (implement as needed)
        }
        $(document).on('click', '.notification-item', function() {
            const type = $(this).data('type');
            const status = $(this).data('status');
            const fileId = $(this).data('file-id');
            const notificationId = $(this).data('notification-id');
            const message = $(this).data('message');

            // Only proceed if the notification is still pending
            if (status !== 'pending') {
                $('#alreadyProcessedMessage').text('This request has already been processed.');
                $('#alreadyProcessedPopup').show();
                return;
            }

            if (type === 'received' || type === 'access_request') {
                $('#fileAcceptanceTitle').text('Review ' + (type === 'received' ? 'Received File' : 'Access Request'));
                $('#fileAcceptanceMessage').text(message);
                $('#fileAcceptancePopup').data('notification-id', notificationId).data('file-id', fileId).show();
                showFilePreview(fileId);
            } else {
                $('#alreadyProcessedMessage').text('This request has already been processed.');
                $('#alreadyProcessedPopup').show();
            }
        });

        function showFilePreview(fileId) {
            $.ajax({
                url: 'get_file_preview.php',
                method: 'GET',
                data: {
                    file_id: fileId
                },
                success: function(data) {
                    $('#filePreview').html(data);
                },
                error: function() {
                    $('#filePreview').html('<p>Unable to load preview.</p>');
                }
            });
        }

        $('#acceptFileButton').on('click', function() {
            const notificationId = $('#fileAcceptancePopup').data('notification-id');
            const fileId = $('#fileAcceptancePopup').data('file-id');
            handleFileAction(notificationId, fileId, 'accept');
        });

        $('#denyFileButton').on('click', function() {
            const notificationId = $('#fileAcceptancePopup').data('notification-id');
            const fileId = $('#fileAcceptancePopup').data('file-id');
            handleFileAction(notificationId, fileId, 'deny');
        });

        function handleFileAction(notificationId, fileId, action) {
            $.ajax({
                url: 'handle_file_acceptance.php',
                method: 'POST',
                data: {
                    notification_id: notificationId,
                    file_id: fileId,
                    action: action
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        notyf.success(response.message);
                        $('#fileAcceptancePopup').hide();

                        // Immediately remove or update the notification in the UI
                        $('.notification-item[data-notification-id="' + notificationId + '"]').removeClass('pending-access received-pending')
                            .addClass('processed-access')
                            .off('click') // Remove click handler to prevent further actions
                            .find('p').text(response.message + ' (Processed)'); // Update text to indicate processed

                        // Refresh notifications to ensure consistency
                        fetchNotifications();
                    } else {
                        notyf.error(response.message);
                    }
                },
                error: function() {
                    notyf.error('Error processing file action.');
                }
            });
        }

        function closePopup(popupId) {
            $(`#${popupId}`).hide();
            if (popupId === 'sendFilePopup') {
                $('.file-item').removeClass('selected');
                $('#sendFilePopup').removeData('selected-file-id');
            }
            if (popupId === 'fileDetailsPopup') selectedFile = null;
            if (popupId === 'hardcopyStoragePopup') $('#storageSuggestion').empty();
        }

        function toggleActivityLog() {
            $('#activityLog').toggle();
        }

        $(document).on('click', function(event) {
            if (!$(event.target).closest('.activity-log, .activity-log-icon').length) {
                $('#activityLog').hide();
            }
        });

        function proceedToHardcopy() {
            const documentType = $('#documentType').val();
            if (!documentType) {
                notyf.error('Please select a document type.');
                return;
            }
            const departmentId = $('#departmentId').val();
            $('#fileDetailsPopup').hide();
            if (departmentId) {
                $('#hardcopyStoragePopup').show();
                if ($('#hardcopyCheckbox').is(':checked') && $('input[name="hardcopyOption"]:checked').val() === 'new') {
                    fetchStorageSuggestion();
                }
            } else {
                uploadFile();
            }
        }

        function handleHardcopyBack() {
            $('#hardcopyStoragePopup').hide();
            $('#fileDetailsPopup').show();
        }

        function handleHardcopyNext() {
            const hardcopyAvailable = $('#hardcopyCheckbox').is(':checked');
            if (hardcopyAvailable && $('input[name="hardcopyOption"]:checked').val() === 'link') {
                $('#hardcopyStoragePopup').hide();
                $('#linkHardcopyPopup').show();
                fetchHardcopyFiles();
            } else {
                uploadFile();
            }
        }

        function fetchHardcopyFiles() {
            $.ajax({
                url: 'fetch_hardcopy_files.php',
                method: 'GET',
                dataType: 'json',
                success: function(data) {
                    const hardcopyList = $('#hardcopyList');
                    hardcopyList.empty();
                    data.forEach(file => {
                        hardcopyList.append(`
                        <div class="file-item" data-file-id="${file.id}">
                            <input type="radio" name="hardcopyFile" value="${file.id}">
                            <span>${file.file_name}</span>
                        </div>
                    `);
                    });
                    hardcopyList.find('input').on('change', function() {
                        selectedHardcopyId = $(this).val();
                        $('#linkHardcopyButton').prop('disabled', false);
                    });
                },
                error: function() {
                    notyf.error('Failed to fetch hardcopy files.');
                }
            });
        }

        function filterHardcopies() {
            const searchTerm = $('#hardcopySearch').val().toLowerCase();
            $('#hardcopyList .file-item').each(function() {
                const fileName = $(this).find('span').text().toLowerCase();
                $(this).toggle(fileName.includes(searchTerm));
            });
        }

        function linkHardcopy() {
            if (!selectedHardcopyId) {
                notyf.error('Please select a hardcopy to link.');
                return;
            }
            uploadFile();
        }

        function fetchStorageSuggestion() {
            const departmentId = $('#departmentId').val();
            const subDepartmentId = $('#subDepartmentId').val() || null;
            if (!departmentId) {
                $('#storageSuggestion').html('<p>No department selected.</p>').show();
                return;
            }
            $.ajax({
                url: 'get_storage_suggestions.php',
                method: 'POST',
                data: {
                    department_id: departmentId,
                    sub_department_id: subDepartmentId
                },
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        $('#storageSuggestion').html(`<p>Suggested Location: ${data.suggestion}</p><span>Based on department/sub-department selection</span>`).show();
                    } else {
                        $('#storageSuggestion').html(`<p>${data.suggestion || 'No suggestion available'}</p>`).show();
                    }
                },
                error: function() {
                    $('#storageSuggestion').html('<p>Failed to fetch suggestion.</p>').show();
                }
            });
        }

        function uploadFile() {
            const documentType = $('#documentType').val();
            const departmentId = $('#departmentId').val() || null;
            const subDepartmentId = $('#subDepartmentId').val() || null;
            const hardcopyAvailable = $('#hardcopyCheckbox').is(':checked');
            const hardcopyOption = hardcopyAvailable ? $('input[name="hardcopyOption"]:checked').val() : null;

            if (!selectedFile) {
                notyf.error('No file selected.');
                return;
            }

            const formData = new FormData();
            formData.append('file', selectedFile);
            formData.append('document_type', documentType);
            if (departmentId) formData.append('department_id', departmentId);
            if (subDepartmentId) formData.append('sub_department_id', subDepartmentId);
            formData.append('hard_copy_available', hardcopyAvailable ? 1 : 0);
            if (hardcopyAvailable && hardcopyOption === 'link' && selectedHardcopyId) {
                formData.append('link_hardcopy_id', selectedHardcopyId);
            } else if (hardcopyAvailable && hardcopyOption === 'new') {
                formData.append('new_storage', 1);
                if (window.storageMetadata) {
                    formData.append('storage_metadata', JSON.stringify(window.storageMetadata));
                }
            }

            $('#fileDetailsForm').find('input, textarea, select').each(function() {
                const name = $(this).attr('name');
                const value = $(this).val();
                if (name && value && name !== 'department_id' && name !== 'document_type' && name !== 'sub_department_id') {
                    formData.append(name, value);
                }
            });

            $.ajax({
                url: 'upload_handler.php',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(data) {
                    try {
                        const response = typeof data === 'string' ? JSON.parse(data) : data;
                        if (response.success) {
                            notyf.success(response.message);
                            $('#hardcopyStoragePopup').hide();
                            $('#linkHardcopyPopup').hide();
                            selectedFile = null;
                            selectedHardcopyId = null;
                            window.storageMetadata = null;
                            window.location.href = response.redirect || 'my-folder.php';
                        } else {
                            notyf.error(response.message || 'Failed to upload file.');
                        }
                    } catch (e) {
                        console.error('Error parsing response:', e, data);
                        notyf.error('Invalid server response.');
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    console.error("Upload error:", textStatus, errorThrown, jqXHR.responseText);
                    notyf.error('An error occurred while uploading the file.');
                }
            });
        }

        function sendFile() {
            const recipients = $('#recipientSelect').val();
            if (!recipients || recipients.length === 0) {
                notyf.error('Please select at least one recipient.');
                return;
            }

            const fileId = $('.file-item.selected').data('file-id') || $('#sendFilePopup').data('selected-file-id');
            if (!fileId) {
                notyf.error('No file selected to send.');
                return;
            }

            $.ajax({
                url: 'send_file_handler.php',
                method: 'POST',
                data: {
                    file_id: fileId,
                    recipients: recipients
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        notyf.success(response.message);
                        $('#sendFilePopup').hide();
                        $('.file-item').removeClass('selected');
                        $('#sendFilePopup').removeData('selected-file-id');
                    } else {
                        notyf.error(response.message || 'Error sending file.');
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    notyf.error('Error sending file: ' + textStatus);
                }
            });
        }

        window.switchView = function(view) {
            const fileDisplay = $('#fileDisplay');
            if (view === 'thumbnail') {
                fileDisplay.removeClass('list-view').addClass('thumbnail-view masonry-grid');
                $('#thumbnailViewButton').addClass('active');
                $('#listViewButton').removeClass('active');
            } else {
                fileDisplay.removeClass('thumbnail-view masonry-grid').addClass('list-view');
                $('#listViewButton').addClass('active');
                $('#thumbnailViewButton').removeClass('active');
            }
        };

        function filterFilesByType() {
            const typeFilter = $('#documentTypeFilter').val().toLowerCase();
            $('#fileDisplay .file-item').each(function() {
                const docType = $(this).data('document-type').toLowerCase();
                $(this).toggle(typeFilter === '' || docType === typeFilter);
            });
        }

        function filterFiles() {
            const searchTerm = $('#fileSearch').val().toLowerCase();
            const typeFilter = $('#documentTypeFilter').val().toLowerCase();
            $('#fileDisplay .file-item').each(function() {
                const fileName = $(this).data('file-name').toLowerCase();
                const docType = $(this).data('document-type').toLowerCase();
                const matchesSearch = fileName.includes(searchTerm);
                const matchesType = typeFilter === '' || docType === typeFilter;
                $(this).toggle(matchesSearch && matchesType);
            });
        }

        function getFileIcon(fileName) {
            const extension = fileName.split('.').pop().toLowerCase();
            switch (extension) {
                case 'pdf':
                    return 'fas fa-file-pdf';
                case 'doc':
                case 'docx':
                    return 'fas fa-file-word';
                case 'xls':
                case 'xlsx':
                    return 'fas fa-file-excel';
                case 'jpg':
                case 'png':
                    return 'fas fa-file-image';
                default:
                    return 'fas fa-file';
            }
        }
    </script>
</body>

</html>