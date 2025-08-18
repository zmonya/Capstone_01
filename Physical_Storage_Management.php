<?php
session_start();
require 'db_connection.php';

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// CSRF token generation and validation
function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Redirect to login if not authenticated or not an admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

$userId = filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT);
if ($userId === false) {
    session_destroy();
    header('Location: login.php');
    exit();
}

// Function to execute prepared queries safely
function executeQuery($pdo, $query, $params = []) {
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        return false;
    }
}

// Function to log transactions
function logTransaction($pdo, $userId, $status, $type, $message) {
    $stmt = executeQuery($pdo, "
        INSERT INTO transaction (User_id, Transaction_status, Transaction_type, Time, Massage)
        VALUES (?, ?, ?, NOW(), ?)", 
        [$userId, $status, $type, $message]
    );
    return $stmt !== false;
}

$error = "";
$success = "";

// Handle form submissions
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && validateCsrfToken($_POST['csrf_token'])) {
    $action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    
    if ($action === 'add' || $action === 'edit') {
        $file_id = isset($_POST['file_id']) ? filter_var($_POST['file_id'], FILTER_VALIDATE_INT) : null;
        $department_id = filter_var($_POST['department_id'], FILTER_VALIDATE_INT);
        $sub_department_id = filter_var($_POST['sub_department_id'], FILTER_VALIDATE_INT) ?: null;
        $building = trim(filter_input(INPUT_POST, 'building', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
        $room = trim(filter_input(INPUT_POST, 'room', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
        $layer = filter_var($_POST['layer'], FILTER_VALIDATE_INT);
        $box = filter_var($_POST['box'], FILTER_VALIDATE_INT);
        $folder = filter_var($_POST['folder'], FILTER_VALIDATE_INT);
        $folder_capacity = filter_var($_POST['folder_capacity'], FILTER_VALIDATE_INT);
        $location = "$building, $room, Layer $layer-Box $box-Folder $folder";

        if (empty($building) || empty($room) || !$department_id || !$layer || !$box || !$folder || !$folder_capacity) {
            $error = "All required fields must be filled.";
            logTransaction($pdo, $userId, 'Failure', 12, $error);
        } else {
            // Validate folder capacity
            if ($folder_capacity <= 0) {
                $error = "Folder capacity must be greater than 0.";
                logTransaction($pdo, $userId, 'Failure', 12, $error);
            } else {
                // Check file count for the location
                $locationKey = "$department_id-$sub_department_id-$location";
                $countStmt = executeQuery($pdo, "
                    SELECT COUNT(*) as file_count 
                    FROM files 
                    WHERE File_path = ? AND Copy_type = 'hard_copy'", 
                    [$location]
                );
                $file_count = $countStmt ? $countStmt->fetch(PDO::FETCH_ASSOC)['file_count'] : 0;

                if ($action === 'add' && $file_count >= $folder_capacity) {
                    $error = "Folder capacity exceeded for this location.";
                    logTransaction($pdo, $userId, 'Failure', 12, $error);
                } else {
                    if ($action === 'add') {
                        // Check if file exists
                        $fileStmt = executeQuery($pdo, "SELECT File_id FROM files WHERE File_id = ?", [$file_id]);
                        if (!$fileStmt || $fileStmt->rowCount() === 0) {
                            $error = "Invalid file ID.";
                            logTransaction($pdo, $userId, 'Failure', 12, $error);
                        } else {
                            $stmt = executeQuery($pdo, "
                                UPDATE files 
                                SET Department_id = ?, File_path = ?, Copy_type = 'hard_copy', Folder_capacity = ? 
                                WHERE File_id = ?", 
                                [$department_id, $location, $folder_capacity, $file_id]
                            );
                            if ($stmt) {
                                $success = "File assigned to storage location successfully.";
                                logTransaction($pdo, $userId, 'Success', 12, $success);
                                header("Location: physical_storage_management.php");
                                exit();
                            } else {
                                $error = "Failed to assign file to storage location.";
                                logTransaction($pdo, $userId, 'Failure', 12, $error);
                            }
                        }
                    } elseif ($action === 'edit' && $file_id) {
                        $stmt = executeQuery($pdo, "
                            UPDATE files 
                            SET Department_id = ?, File_path = ?, Folder_capacity = ? 
                            WHERE File_id = ?", 
                            [$department_id, $location, $folder_capacity, $file_id]
                        );
                        if ($stmt) {
                            $success = "Storage assignment updated successfully.";
                            logTransaction($pdo, $userId, 'Success', 13, $success);
                            header("Location: physical_storage_management.php");
                            exit();
                        } else {
                            $error = "Failed to update storage assignment.";
                            logTransaction($pdo, $userId, 'Failure', 13, $error);
                        }
                    }
                }
            }
        }
    } elseif ($action === 'remove_file') {
        $file_id = filter_var($_POST['file_id'], FILTER_VALIDATE_INT);
        if ($file_id) {
            $stmt = executeQuery($pdo, "
                UPDATE files 
                SET File_path = NULL, Copy_type = NULL, Folder_capacity = NULL 
                WHERE File_id = ?", 
                [$file_id]
            );
            if ($stmt) {
                $success = "File removed from storage successfully.";
                logTransaction($pdo, $userId, 'Success', 14, $success);
                header("Location: physical_storage_management.php");
                exit();
            } else {
                $error = "Failed to remove file from storage.";
                logTransaction($pdo, $userId, 'Failure', 14, $error);
            }
        } else {
            $error = "Invalid file ID.";
            logTransaction($pdo, $userId, 'Failure', 14, $error);
        }
    }
}

// Handle deletion of storage assignment
if (isset($_GET['delete']) && validateCsrfToken($_GET['csrf_token'])) {
    $file_id = filter_var($_GET['delete'], FILTER_VALIDATE_INT);
    if ($file_id) {
        $stmt = executeQuery($pdo, "
            UPDATE files 
            SET File_path = NULL, Copy_type = NULL, Folder_capacity = NULL 
            WHERE File_id = ?", 
            [$file_id]
        );
        if ($stmt) {
            $success = "Storage assignment deleted successfully.";
            logTransaction($pdo, $userId, 'Success', 15, $success);
            header("Location: physical_storage_management.php");
            exit();
        } else {
            $error = "Failed to delete storage assignment.";
            logTransaction($pdo, $userId, 'Failure', 15, $error);
        }
    } else {
        $error = "Invalid file ID.";
        logTransaction($pdo, $userId, 'Failure', 15, $error);
    }
}

// Fetch all departments
$departmentsStmt = executeQuery($pdo, "
    SELECT Department_id, Department_name, Department_type 
    FROM departments 
    WHERE Department_type IN ('college', 'office') 
    ORDER BY Department_name ASC");
$departments = $departmentsStmt ? $departmentsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

// Fetch all subdepartments
$subdepartmentsStmt = executeQuery($pdo, "
    SELECT Department_id, Department_name 
    FROM departments 
    WHERE Department_type = 'sub_department' 
    ORDER BY Department_name ASC");
$subdepartments = $subdepartmentsStmt ? $subdepartmentsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

// Fetch all physical files
$filesStmt = executeQuery($pdo, "
    SELECT f.File_id, f.File_name, f.File_path, f.Department_id, f.Copy_type, f.Folder_capacity, 
           d.Department_name, u.Username, dt.Field_label AS Document_type
    FROM files f
    LEFT JOIN departments d ON f.Department_id = d.Department_id
    LEFT JOIN users u ON f.User_id = u.User_id
    LEFT JOIN documents_type_fields dt ON f.Document_type_id = dt.Document_type_id
    WHERE f.Copy_type = 'hard_copy'
    ORDER BY d.Department_name, f.File_name ASC");
$files = $filesStmt ? $filesStmt->fetchAll(PDO::FETCH_ASSOC) : [];

// Organize files by department
$departments_with_files = [];
foreach ($files as $file) {
    $dept_id = $file['Department_id'];
    if (!isset($departments_with_files[$dept_id])) {
        $departments_with_files[$dept_id] = [
            'name' => $file['Department_name'] ?? 'Unknown',
            'files' => []
        ];
    }
    $departments_with_files[$dept_id]['files'][] = $file;
}
usort($departments_with_files, fn($a, $b) => strcmp($a['name'], $b['name']));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Physical Storage Management - Arc-Hive</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" crossorigin="anonymous">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.css">
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="style/admin-sidebar.css">
    <link rel="stylesheet" href="style/admin-interface.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.js"></script>
    <style>
        .main-content {
            padding: 20px;
            transition: margin-left 0.3s ease;
        }
        .error-message, .success-message {
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 4px;
            font-size: 14px;
        }
        .error-message { background-color: #ffe6e6; color: #d32f2f; }
        .success-message { background-color: #e6ffe6; color: #2e7d32; }
        .file-search-form {
            margin-top: 20px;
            padding: 15px;
            background: #f9f9f9;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        .file-search-form h3 {
            margin: 0 0 15px;
            font-size: 18px;
            color: #333;
        }
        .search-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }
        .search-input, .search-select {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            flex: 1;
            min-width: 150px;
        }
        .search-btn {
            padding: 8px 15px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            transition: background 0.3s;
        }
        .search-btn:hover {
            background: #0056b3;
        }
        .search-results {
            margin-top: 20px;
            max-height: 300px;
            overflow-y: auto;
        }
        .results-table {
            width: 100%;
            border-collapse: collapse;
        }
        .results-table th, .results-table td {
            padding: 10px;
            border: 1px solid #ddd;
            text-align: left;
        }
        .results-table th {
            background: #007bff;
            color: white;
        }
        .results-table tr:nth-child(even) {
            background: #f2f2f2;
        }
        .no-results {
            color: #7f8c8d;
            text-align: center;
            padding: 10px;
        }
        .department-section {
            margin-bottom: 20px;
            border: 1px solid #ddd;
            border-radius: 5px;
            background: #fff;
        }
        .dept-header {
            background: #f5f5f5;
            padding: 10px 15px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: 5px 5px 0 0;
        }
        .dept-header h2 {
            margin: 0;
            font-size: 18px;
            color: #333;
        }
        .file-table {
            display: none;
            margin: 10px;
        }
        .file-table table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        .file-table th, .file-table td {
            padding: 12px;
            border: 1px solid #ddd;
            text-align: left;
        }
        .file-table th {
            background-color: #f8f8f8;
            font-weight: bold;
        }
        .action-buttons {
            display: flex;
            gap: 10px;
        }
        .edit-btn, .delete-btn, .remove-btn {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: background-color 0.2s;
        }
        .edit-btn {
            background-color: #50c878;
            color: white;
        }
        .edit-btn:hover {
            background-color: #45a049;
        }
        .delete-btn, .remove-btn {
            background-color: #d32f2f;
            color: white;
        }
        .delete-btn:hover, .remove-btn:hover {
            background-color: #b71c1c;
        }
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }
        .modal-content {
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        .modal-content select, .modal-content input {
            width: 100%;
            padding: 10px;
            margin-bottom: 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        .modal-content button {
            width: 100%;
            padding: 10px;
            background-color: #50c878;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        .modal-content button:hover {
            background-color: #45a049;
        }
        .close {
            float: right;
            font-size: 24px;
            cursor: pointer;
            color: #333;
        }
        .open-modal-btn {
            padding: 10px 20px;
            margin-right: 10px;
            margin-bottom: 15px;
            background-color: #50c878;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: background-color 0.2s;
        }
        .open-modal-btn:hover {
            background-color: #45a049;
        }
        .warning-modal-content {
            text-align: center;
        }
        .warning-modal-content .buttons {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 20px;
        }
        .confirm-btn, .cancel-btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .confirm-btn {
            background-color: #d32f2f;
            color: white;
        }
        .confirm-btn:hover {
            background-color: #b71c1c;
        }
        .cancel-btn {
            background-color: #ccc;
            color: #333;
        }
        .cancel-btn:hover {
            background-color: #bbb;
        }
    </style>
</head>
<body>
    <!-- Admin Sidebar -->
    <?php
        include 'admin_menu.php';
    ?>

    <!-- Main Content -->
    <div class="main-content sidebar-expanded">
        <!-- CSRF Token -->
        <input type="hidden" id="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">

        <!-- Messages -->
        <?php if (!empty($error)): ?>
            <div class="error-message"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="success-message"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <!-- Add Storage Assignment Button -->
        <button id="open-modal-btn" class="open-modal-btn">Assign File to Storage</button>

        <!-- Storage Assignment Modal -->
        <div class="modal" id="storage-modal">
            <div class="modal-content">
                <span class="close">×</span>
                <h2><?= isset($_GET['edit']) ? 'Edit Storage Assignment' : 'Assign File to Storage' ?></h2>
                <form method="POST" action="physical_storage_management.php">
                    <input type="hidden" name="action" value="<?= isset($_GET['edit']) ? 'edit' : 'add' ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                    <?php if (isset($_GET['edit'])): 
                        $editFileId = filter_var($_GET['edit'], FILTER_VALIDATE_INT);
                        $editStmt = executeQuery($pdo, "
                            SELECT File_id, File_name, File_path, Department_id, Folder_capacity 
                            FROM files 
                            WHERE File_id = ? AND Copy_type = 'hard_copy'", [$editFileId]);
                        $editFile = $editStmt ? $editStmt->fetch(PDO::FETCH_ASSOC) : null;
                        $locationParts = $editFile ? explode(', ', $editFile['File_path'] . ', Layer 0-Box 0-Folder 0') : ['', '', 'Layer 0-Box 0-Folder 0'];
                        $layerBoxFolder = explode('-', $locationParts[2] ?? 'Layer 0-Box 0-Folder 0');
                    ?>
                        <input type="hidden" name="file_id" value="<?= htmlspecialchars($editFileId, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="text" name="file_name" value="<?= htmlspecialchars($editFile['File_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" disabled>
                    <?php else: ?>
                        <select name="file_id" required>
                            <option value="">Select File</option>
                            <?php
                            $fileListStmt = executeQuery($pdo, "
                                SELECT File_id, File_name 
                                FROM files 
                                WHERE Copy_type IS NULL OR Copy_type != 'hard_copy'
                                ORDER BY File_name ASC");
                            $availableFiles = $fileListStmt ? $fileListStmt->fetchAll(PDO::FETCH_ASSOC) : [];
                            foreach ($availableFiles as $file): ?>
                                <option value="<?= htmlspecialchars($file['File_id'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($file['File_name'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <select name="department_id" id="department_id" required onchange="loadSubDepartments()">
                        <option value="">Select Department</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= htmlspecialchars($dept['Department_id'], ENT_QUOTES, 'UTF-8') ?>" 
                                    <?= isset($editFile) && $editFile['Department_id'] == $dept['Department_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($dept['Department_name'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="sub_department_id" id="sub_department_id">
                        <option value="">Select Sub-Department (Optional)</option>
                    </select>
                    <input type="text" name="building" placeholder="Building" value="<?= htmlspecialchars($locationParts[0] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                    <input type="text" name="room" placeholder="Room" value="<?= htmlspecialchars($locationParts[1] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                    <input type="number" name="layer" placeholder="Layer" min="1" value="<?= htmlspecialchars(explode(' ', $layerBoxFolder[0])[1] ?? 1, ENT_QUOTES, 'UTF-8') ?>" required>
                    <input type="number" name="box" placeholder="Box" min="1" value="<?= htmlspecialchars(explode(' ', $layerBoxFolder[1])[1] ?? 1, ENT_QUOTES, 'UTF-8') ?>" required>
                    <input type="number" name="folder" placeholder="Folder" min="1" value="<?= htmlspecialchars(explode(' ', $layerBoxFolder[2])[1] ?? 1, ENT_QUOTES, 'UTF-8') ?>" required>
                    <input type="number" name="folder_capacity" placeholder="Folder Capacity (Max Files)" min="1" value="<?= htmlspecialchars($editFile['Folder_capacity'] ?? 10, ENT_QUOTES, 'UTF-8') ?>" required>
                    <button type="submit"><?= isset($_GET['edit']) ? 'Update Storage Assignment' : 'Assign to Storage' ?></button>
                </form>
            </div>
        </div>

        <!-- File Search Form -->
        <div class="file-search-form">
            <h3>Search Physical Files</h3>
            <form method="POST" action="physical_storage_management.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <div class="search-filters">
                    <input type="text" name="file_name" placeholder="File Name (optional)" class="search-input">
                    <select name="department_id" class="search-select">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= htmlspecialchars($dept['Department_id'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($dept['Department_name'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="document_type_id" class="search-select">
                        <option value="">All Document Types</option>
                        <?php
                        $docTypesStmt = executeQuery($pdo, "SELECT Document_type_id, Field_label FROM documents_type_fields ORDER BY Field_label ASC");
                        $docTypes = $docTypesStmt ? $docTypesStmt->fetchAll(PDO::FETCH_ASSOC) : [];
                        foreach ($docTypes as $doc_type): ?>
                            <option value="<?= htmlspecialchars($doc_type['Document_type_id'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($doc_type['Field_label'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="date" name="date_from" placeholder="From Date" class="search-input" title="Start date of upload">
                    <input type="date" name="date_to" placeholder="To Date" class="search-input" title="End date of upload">
                    <select name="uploader_id" class="search-select">
                        <option value="">All Uploaders</option>
                        <?php
                        $usersStmt = executeQuery($pdo, "SELECT User_id, Username FROM users ORDER BY Username ASC");
                        $users = $usersStmt ? $usersStmt->fetchAll(PDO::FETCH_ASSOC) : [];
                        foreach ($users as $user): ?>
                            <option value="<?= htmlspecialchars($user['User_id'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($user['Username'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" name="search_files" class="search-btn"><i class="fas fa-search"></i> Search</button>
                </div>
            </form>
            <?php
            if (isset($_POST['search_files']) && validateCsrfToken($_POST['csrf_token'])) {
                $query = "
                    SELECT f.File_id, f.File_name, f.File_path, f.Upload_date, d.Department_name, u.Username, dt.Field_label AS Document_type
                    FROM files f
                    LEFT JOIN departments d ON f.Department_id = d.Department_id
                    LEFT JOIN users u ON f.User_id = u.User_id
                    LEFT JOIN documents_type_fields dt ON f.Document_type_id = dt.Document_type_id
                    WHERE f.Copy_type = 'hard_copy'";
                $params = [];

                if (!empty($_POST['file_name'])) {
                    $query .= " AND f.File_name LIKE ?";
                    $params[] = '%' . filter_input(INPUT_POST, 'file_name', FILTER_SANITIZE_FULL_SPECIAL_CHARS) . '%';
                }
                if (!empty($_POST['department_id'])) {
                    $query .= " AND f.Department_id = ?";
                    $params[] = filter_var($_POST['department_id'], FILTER_VALIDATE_INT);
                }
                if (!empty($_POST['document_type_id'])) {
                    $query .= " AND f.Document_type_id = ?";
                    $params[] = filter_var($_POST['document_type_id'], FILTER_VALIDATE_INT);
                }
                if (!empty($_POST['date_from'])) {
                    $query .= " AND f.Upload_date >= ?";
                    $params[] = filter_input(INPUT_POST, 'date_from', FILTER_SANITIZE_FULL_SPECIAL_CHARS) . ' 00:00:00';
                }
                if (!empty($_POST['date_to'])) {
                    $query .= " AND f.Upload_date <= ?";
                    $params[] = filter_input(INPUT_POST, 'date_to', FILTER_SANITIZE_FULL_SPECIAL_CHARS) . ' 23:59:59';
                }
                if (!empty($_POST['uploader_id'])) {
                    $query .= " AND f.User_id = ?";
                    $params[] = filter_var($_POST['uploader_id'], FILTER_VALIDATE_INT);
                }

                $query .= " ORDER BY f.Upload_date DESC";
                $stmt = executeQuery($pdo, $query, $params);
                $results = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

                echo '<div class="search-results">';
                if ($results) {
                    echo '<table class="results-table">';
                    echo '<thead><tr><th>File Name</th><th>Department</th><th>Document Type</th><th>Uploader</th><th>Upload Date</th><th>Location</th></tr></thead>';
                    echo '<tbody>';
                    foreach ($results as $result) {
                        echo '<tr>';
                        echo '<td>' . htmlspecialchars($result['File_name'], ENT_QUOTES, 'UTF-8') . '</td>';
                        echo '<td>' . htmlspecialchars($result['Department_name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') . '</td>';
                        echo '<td>' . htmlspecialchars($result['Document_type'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . '</td>';
                        echo '<td>' . htmlspecialchars($result['Username'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') . '</td>';
                        echo '<td>' . htmlspecialchars($result['Upload_date'], ENT_QUOTES, 'UTF-8') . '</td>';
                        echo '<td>' . htmlspecialchars($result['File_path'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . '</td>';
                        echo '</tr>';
                    }
                    echo '</tbody></table>';
                } else {
                    echo '<p class="no-results">No files found matching your criteria.</p>';
                }
                echo '</div>';
            }
            ?>
        </div>

        <!-- Files by Department -->
        <div class="table-container">
            <h3>Physical Files by Department</h3>
            <?php if (empty($departments_with_files)): ?>
                <p>No physical files found.</p>
            <?php else: ?>
                <?php foreach ($departments_with_files as $dept_id => $dept): ?>
                    <div class="department-section">
                        <div class="dept-header">
                            <h2><?= htmlspecialchars($dept['name'], ENT_QUOTES, 'UTF-8') ?></h2>
                            <div class="action-buttons">
                                <i class="fas fa-chevron-down toggle-files"></i>
                            </div>
                        </div>
                        <div class="file-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>File ID</th>
                                        <th>File Name</th>
                                        <th>Location</th>
                                        <th>Uploader</th>
                                        <th>Document Type</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($dept['files'] as $file): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($file['File_id'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($file['File_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($file['File_path'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($file['Username'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($file['Document_type'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></td>
                                            <td class="action-buttons">
                                                <a href="physical_storage_management.php?edit=<?= htmlspecialchars($file['File_id'], ENT_QUOTES, 'UTF-8') ?>&csrf_token=<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                                    <button class="edit-btn">Edit</button>
                                                </a>
                                                <button class="remove-btn" onclick="confirmRemoveFile(<?= htmlspecialchars($file['File_id'], ENT_QUOTES, 'UTF-8') ?>)">Remove</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Warning Modal for File Removal -->
        <div class="modal warning-modal" id="warning-remove-file-modal">
            <div class="warning-modal-content">
                <span class="close">×</span>
                <h2>Warning</h2>
                <p>Are you sure you want to remove this file from storage? This action cannot be undone.</p>
                <div class="buttons">
                    <button class="confirm-btn" id="confirm-remove-file">Yes</button>
                    <button class="cancel-btn">No</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Initialize Notyf for notifications
        const notyf = new Notyf({
            duration: 5000,
            position: { x: 'right', y: 'top' },
            ripple: true
        });

        document.addEventListener('DOMContentLoaded', () => {
            // Sidebar toggle
            const sidebar = document.querySelector('.sidebar');
            const mainContent = document.querySelector('.main-content');
            const toggleBtn = document.querySelector('.toggle-btn');

            toggleBtn.addEventListener('click', () => {
                sidebar.classList.toggle('minimized');
                mainContent.classList.toggle('sidebar-expanded');
                mainContent.classList.toggle('sidebar-minimized');
            });

            // Storage Modal
            const storageModal = document.getElementById("storage-modal");
            const openModalBtn = document.getElementById("open-modal-btn");
            const closeModalBtn = storageModal.querySelector(".close");

            openModalBtn.onclick = () => storageModal.style.display = "flex";
            closeModalBtn.onclick = () => storageModal.style.display = "none";

            // Warning Modal
            const warningModal = document.getElementById("warning-remove-file-modal");
            const closeWarningModalBtn = warningModal.querySelector(".close");
            const cancelWarningBtn = warningModal.querySelector(".cancel-btn");

            closeWarningModalBtn.onclick = () => warningModal.style.display = "none";
            cancelWarningBtn.onclick = () => warningModal.style.display = "none";

            // Close modals when clicking outside
            window.onclick = (event) => {
                if (event.target === storageModal) storageModal.style.display = "none";
                if (event.target === warningModal) warningModal.style.display = "none";
            };

            // Auto-open modal for editing
            <?php if (isset($_GET['edit'])): ?>
                storageModal.style.display = "flex";
                loadSubDepartments();
            <?php endif; ?>

            // Toggle file tables
            document.querySelectorAll('.toggle-files').forEach(toggle => {
                toggle.addEventListener('click', () => {
                    const fileTable = toggle.closest('.department-section').querySelector('.file-table');
                    fileTable.style.display = fileTable.style.display === 'block' ? 'none' : 'block';
                    toggle.classList.toggle('fa-chevron-down');
                    toggle.classList.toggle('fa-chevron-up');
                });
            });

            // Form validation
            document.querySelectorAll('form').forEach(form => {
                form.addEventListener('submit', (e) => {
                    const csrfToken = document.getElementById('csrf_token').value;
                    if (!csrfToken) {
                        e.preventDefault();
                        notyf.error('CSRF token missing');
                    }
                });
            });
        });

        // Load sub-departments dynamically
        function loadSubDepartments() {
            const deptId = document.getElementById('department_id').value;
            const subDeptSelect = document.getElementById('sub_department_id');
            subDeptSelect.innerHTML = '<option value="">Select Sub-Department (Optional)</option>';
            if (deptId) {
                fetch(`get_sub_departments.php?department_id=${encodeURIComponent(deptId)}`)
                    .then(response => response.json())
                    .then(data => {
                        if (Array.isArray(data)) {
                            data.forEach(sub => {
                                const option = document.createElement('option');
                                option.value = sub.id;
                                option.textContent = sub.name;
                                subDeptSelect.appendChild(option);
                            });
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching sub-departments:', error);
                        notyf.error('Failed to load sub-departments');
                    });
            }
        }

        // File removal confirmation
        let pendingFileId = null;
        function confirmRemoveFile(fileId) {
            pendingFileId = fileId;
            document.getElementById('warning-remove-file-modal').style.display = 'flex';
        }

        document.getElementById('confirm-remove-file').addEventListener('click', () => {
            if (pendingFileId !== null) {
                const formData = new FormData();
                formData.append('action', 'remove_file');
                formData.append('file_id', pendingFileId);
                formData.append('csrf_token', document.getElementById('csrf_token').value);

                fetch('physical_storage_management.php', {
                    method: 'POST',
                    body: formData
                }).then(response => {
                    if (!response.ok) throw new Error('Network response was not ok');
                    location.reload();
                }).catch(error => {
                    notyf.error('Failed to remove file: ' + error.message);
                });
                document.getElementById('warning-remove-file-modal').style.display = 'none';
                pendingFileId = null;
            }
        });
    </script>
</body>
</html>