<?php

declare(strict_types=1);
session_start();
require 'db_connection.php';

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// CSRF token generation and validation
function generateCsrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken(string $token): bool
{
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
function executeQuery(PDO $pdo, string $query, array $params = []): PDOStatement|false
{
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
function logTransaction(PDO $pdo, int $userId, string $status, int $type, string $message): bool
{
    $stmt = executeQuery(
        $pdo,
        "INSERT INTO transaction (User_id, Transaction_status, Transaction_type, Time, Massage) VALUES (?, ?, ?, NOW(), ?)",
        [$userId, $status, $type, $message]
    );
    return $stmt !== false;
}

// Handle AJAX requests
$error = '';
$success = '';
$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && validateCsrfToken($_POST['csrf_token'] ?? '')) {
    $action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

    if ($action === 'add_document_type' || $action === 'edit_document_type') {
        $id = isset($_POST['id']) ? filter_var($_POST['id'], FILTER_VALIDATE_INT) : null;
        $name = trim(filter_input(INPUT_POST, 'name', FILTER_SANITIZE_FULL_SPECIAL_CHARS));

        if (empty($name)) {
            $error = 'Document type name is required.';
            logTransaction($pdo, $userId, 'Failure', $action === 'add_document_type' ? 16 : 17, $error);
            $response = ['success' => false, 'message' => $error];
        } else {
            // Check for duplicate name
            $checkStmt = executeQuery(
                $pdo,
                "SELECT id FROM document_types WHERE name = ? AND id != ?",
                [$name, $id ?? 0]
            );
            if ($checkStmt && $checkStmt->rowCount() > 0) {
                $error = 'Document type name already exists.';
                logTransaction($pdo, $userId, 'Failure', $action === 'add_document_type' ? 16 : 17, $error);
                $response = ['success' => false, 'message' => $error];
            } else {
                if ($action === 'add_document_type') {
                    $stmt = executeQuery(
                        $pdo,
                        "INSERT INTO document_types (name) VALUES (?)",
                        [$name]
                    );
                    $message = "Added document type: $name";
                    $transType = 16;
                } elseif ($action === 'edit_document_type' && $id) {
                    $stmt = executeQuery(
                        $pdo,
                        "UPDATE document_types SET name = ? WHERE id = ?",
                        [$name, $id]
                    );
                    $message = "Updated document type: $name";
                    $transType = 17;
                }

                if ($stmt) {
                    $success = $message;
                    logTransaction($pdo, $userId, 'Success', $transType, $message);
                    $response = ['success' => true, 'message' => $success];
                } else {
                    $error = 'Failed to ' . ($action === 'add_document_type' ? 'add' : 'update') . ' document type.';
                    logTransaction($pdo, $userId, 'Failure', $transType, $error);
                    $response = ['success' => false, 'message' => $error];
                }
            }
        }
    } elseif ($action === 'add_field' || $action === 'edit_field') {
        $document_type_id = filter_var($_POST['document_type_id'], FILTER_VALIDATE_INT);
        $field_id = isset($_POST['field_id']) ? filter_var($_POST['field_id'], FILTER_VALIDATE_INT) : null;
        $field_name = trim(filter_input(INPUT_POST, 'field_name', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
        $field_label = trim(filter_input(INPUT_POST, 'field_label', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
        $field_type = filter_input(INPUT_POST, 'field_type', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $is_required = filter_var($_POST['is_required'], FILTER_VALIDATE_INT, ['options' => ['default' => 0, 'min_range' => 0, 'max_range' => 1]]);

        if (!$document_type_id || empty($field_name) || empty($field_label) || !in_array($field_type, ['text', 'textarea', 'date'])) {
            $error = 'All field inputs must be valid.';
            logTransaction($pdo, $userId, 'Failure', $action === 'add_field' ? 18 : 19, $error);
            $response = ['success' => false, 'message' => $error];
        } else {
            // Validate document type
            $docTypeStmt = executeQuery($pdo, "SELECT id FROM document_types WHERE id = ?", [$document_type_id]);
            if (!$docTypeStmt || $docTypeStmt->rowCount() === 0) {
                $error = 'Invalid document type selected.';
                logTransaction($pdo, $userId, 'Failure', $action === 'add_field' ? 18 : 19, $error);
                $response = ['success' => false, 'message' => $error];
            } else {
                // Check for duplicate field name
                $checkStmt = executeQuery(
                    $pdo,
                    "SELECT id FROM documents_type_fields WHERE field_name = ? AND document_type_id = ? AND id != ?",
                    [$field_name, $document_type_id, $field_id ?? 0]
                );
                if ($checkStmt && $checkStmt->rowCount() > 0) {
                    $error = 'Field name already exists for this document type.';
                    logTransaction($pdo, $userId, 'Failure', $action === 'add_field' ? 18 : 19, $error);
                    $response = ['success' => false, 'message' => $error];
                } else {
                    if ($action === 'add_field') {
                        $stmt = executeQuery(
                            $pdo,
                            "INSERT INTO documents_type_fields (document_type_id, field_name, field_label, field_type, is_required) VALUES (?, ?, ?, ?, ?)",
                            [$document_type_id, $field_name, $field_label, $field_type, $is_required]
                        );
                        $message = "Added field: $field_label to document type ID: $document_type_id";
                        $transType = 18;
                    } elseif ($action === 'edit_field' && $field_id) {
                        $stmt = executeQuery(
                            $pdo,
                            "UPDATE documents_type_fields SET field_name = ?, field_label = ?, field_type = ?, is_required = ? WHERE id = ? AND document_type_id = ?",
                            [$field_name, $field_label, $field_type, $is_required, $field_id, $document_type_id]
                        );
                        $message = "Updated field: $field_label for document type ID: $document_type_id";
                        $transType = 19;
                    }

                    if ($stmt) {
                        $success = $message;
                        logTransaction($pdo, $userId, 'Success', $transType, $message);
                        $response = ['success' => true, 'message' => $success];
                    } else {
                        $error = 'Failed to ' . ($action === 'add_field' ? 'add' : 'update') . ' field.';
                        logTransaction($pdo, $userId, 'Failure', $transType, $error);
                        $response = ['success' => false, 'message' => $error];
                    }
                }
            }
        }
    } elseif ($action === 'delete_field') {
        $field_id = filter_var($_POST['field_id'], FILTER_VALIDATE_INT);
        $document_type_id = filter_var($_POST['document_type_id'], FILTER_VALIDATE_INT);

        if (!$field_id || !$document_type_id) {
            $error = 'Invalid field or document type ID.';
            logTransaction($pdo, $userId, 'Failure', 20, $error);
            $response = ['success' => false, 'message' => $error];
        } else {
            $stmt = executeQuery(
                $pdo,
                "DELETE FROM documents_type_fields WHERE id = ? AND document_type_id = ?",
                [$field_id, $document_type_id]
            );
            if ($stmt) {
                $message = "Deleted field ID: $field_id from document type ID: $document_type_id";
                logTransaction($pdo, $userId, 'Success', 20, $message);
                $response = ['success' => true, 'message' => $message];
            } else {
                $error = 'Failed to delete field.';
                logTransaction($pdo, $userId, 'Failure', 20, $error);
                $response = ['success' => false, 'message' => $error];
            }
        }
    } elseif ($action === 'delete_document_type') {
        $id = filter_var($_POST['id'], FILTER_VALIDATE_INT);
        if (!$id) {
            $error = 'Invalid document type ID.';
            logTransaction($pdo, $userId, 'Failure', 21, $error);
            $response = ['success' => false, 'message' => $error];
        } else {
            // Check if document type is used in files
            $checkStmt = executeQuery($pdo, "SELECT File_id FROM files WHERE Document_type_id = ?", [$id]);
            if ($checkStmt && $checkStmt->rowCount() > 0) {
                $error = 'Cannot delete document type with associated files.';
                logTransaction($pdo, $userId, 'Failure', 21, $error);
                $response = ['success' => false, 'message' => $error];
            } else {
                // Delete fields first
                $fieldStmt = executeQuery($pdo, "DELETE FROM documents_type_fields WHERE document_type_id = ?", [$id]);
                if ($fieldStmt) {
                    $stmt = executeQuery($pdo, "DELETE FROM document_types WHERE id = ?", [$id]);
                    if ($stmt) {
                        $message = "Deleted document type ID: $id";
                        logTransaction($pdo, $userId, 'Success', 21, $message);
                        $response = ['success' => true, 'message' => $message];
                    } else {
                        $error = 'Failed to delete document type.';
                        logTransaction($pdo, $userId, 'Failure', 21, $error);
                        $response = ['success' => false, 'message' => $error];
                    }
                } else {
                    $error = 'Failed to delete associated fields.';
                    logTransaction($pdo, $userId, 'Failure', 21, $error);
                    $response = ['success' => false, 'message' => $error];
                }
            }
        }
    }

    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
}

// Fetch all document types
$documentTypesStmt = executeQuery($pdo, "SELECT id, name FROM document_types ORDER BY name ASC");
$documentTypes = $documentTypesStmt ? $documentTypesStmt->fetchAll(PDO::FETCH_ASSOC) : [];

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Document Type Management - Arc-Hive</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" integrity="sha512-Kc323vGBEqzTmouAECnVceyQqyqdsSiqLQISBL29aUW4U/M7pSPA/gEUZQqv1cwx4OnYxTxve5UMg5GT6L4JJg==" crossorigin="anonymous">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.css">
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="admin-sidebar.css">
    <link rel="stylesheet" href="admin-interface.css">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.js"></script>
    <style>
        body.document-type-management {
            font-family: 'Montserrat', sans-serif;
            background: linear-gradient(135deg, #e0e7ff, #f4f4f9);
            color: #34495e;
        }

        .main-content.document-type-management {
            padding: 25px;
            max-width: 1400px;
            margin: 0 auto;
        }

        h2 {
            font-size: 26px;
            color: #2c3e50;
            margin: 0 0 20px;
            padding-bottom: 6px;
            border-bottom: 2px solid #50c878;
            text-align: left;
        }

        .open-modal-btn {
            background: linear-gradient(45deg, #50c878, #2ecc71);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .open-modal-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);
        }

        .document-type-section {
            margin: 30px 0;
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.05);
        }

        .document-grid {
            column-count: 3;
            column-gap: 20px;
        }

        .document-card {
            background: linear-gradient(135deg, #ffffff, #f9fbfc);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            transition: transform 0.2s, box-shadow 0.2s;
            cursor: pointer;
            border: 1px solid #ecf0f1;
            break-inside: avoid;
        }

        .document-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.08);
        }

        .document-card h3 {
            margin: 0 0 10px;
            font-size: 18px;
            color: #2c3e50;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .document-card h3 i {
            transition: transform 0.3s;
        }

        .document-card.expanded h3 i {
            transform: rotate(180deg);
        }

        .fields-container {
            display: none;
            padding: 15px;
            background: #f9fbfc;
            border-radius: 6px;
            margin-top: 10px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
        }

        .fields-table {
            width: 100%;
            border-collapse: collapse;
            background: #ffffff;
            border-radius: 6px;
            overflow: hidden;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.05);
        }

        .fields-table th,
        .fields-table td {
            padding: 8px 10px;
            text-align: left;
            border-bottom: 1px solid #ecf0f1;
            font-size: 13px;
            color: #34495e;
        }

        .fields-table th {
            background: #eef2f6;
            font-weight: 600;
            color: #2c3e50;
        }

        .fields-table tr:hover {
            background: #f1f5f9;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .action-buttons button {
            padding: 6px 12px;
            border: none;
            border-radius: 5px;
            font-size: 13px;
            cursor: pointer;
            transition: background 0.2s;
        }

        .action-buttons .edit-btn {
            background: #50c878;
            color: white;
        }

        .action-buttons .edit-btn:hover {
            background: #2ecc71;
        }

        .action-buttons .delete-btn {
            background: #e74c3c;
            color: white;
        }

        .action-buttons .delete-btn:hover {
            background: #c0392b;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.15);
            width: 90%;
            max-width: 550px;
            max-height: 80vh;
            overflow-y: auto;
            animation: slideIn 0.3s ease-out;
        }

        @keyframes slideIn {
            from {
                transform: translateY(-30px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-content h2 {
            font-size: 24px;
            color: #2c3e50;
            margin-bottom: 15px;
        }

        .form-container {
            display: grid;
            gap: 15px;
        }

        .form-container input,
        .form-container select {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 13px;
            transition: border-color 0.3s, box-shadow 0.3s;
        }

        .form-container input:focus,
        .form-container select:focus {
            border-color: #50c878;
            box-shadow: 0 0 6px rgba(80, 200, 120, 0.2);
            outline: none;
        }

        .form-container label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
        }

        .form-container button {
            background: linear-gradient(45deg, #50c878, #2ecc71);
            color: white;
            border: none;
            padding: 10px;
            border-radius: 5px;
            font-size: 14px;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .form-container button:hover {
            transform: translateY(-1px);
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.15);
        }

        .close {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 24px;
            color: #aaa;
            cursor: pointer;
            transition: color 0.3s;
        }

        .close:hover {
            color: #333;
        }
    </style>
</head>

<body class="document-type-management">
    <!-- Admin Sidebar -->
    <div class="sidebar">
        <button class="toggle-btn" title="Toggle Sidebar"><i class="fas fa-bars"></i></button>
        <h2 class="sidebar-title">Admin Panel</h2>
        <a href="dashboard.php" class="client-btn"><i class="fas fa-exchange-alt"></i><span class="link-text">Switch to Client View</span></a>
        <a href="admin_dashboard.php"><i class="fas fa-home"></i><span class="link-text">Dashboard</span></a>
        <a href="admin_search.php"><i class="fas fa-search"></i><span class="link-text">View All Files</span></a>
        <a href="user_management.php"><i class="fas fa-users"></i><span class="link-text">User Management</span></a>
        <a href="department_management.php"><i class="fas fa-building"></i><span class="link-text">Department Management</span></a>
        <a href="physical_storage_management.php"><i class="fas fa-archive"></i><span class="link-text">Physical Storage</span></a>
        <a href="document_type_management.php" class="active"><i class="fas fa-file-alt"></i><span class="link-text">Document Type Management</span></a>
        <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i><span class="link-text">Logout</span></a>
    </div>

    <!-- Main Content -->
    <div class="main-content document-type-management">
        <!-- CSRF Token -->
        <input type="hidden" id="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">

        <!-- Messages -->
        <?php if (!empty($error)) { ?>
            <div class="error-message"><?php echo htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
        <?php } ?>
        <?php if (!empty($success)) { ?>
            <div class="success-message"><?php echo htmlspecialchars($success, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
        <?php } ?>

        <h2>Document Type Management</h2>
        <button class="open-modal-btn" onclick="openModal('add')"><i class="fas fa-plus"></i> Add Document Type</button>

        <div class="document-type-section">
            <div class="document-grid">
                <?php foreach ($documentTypes as $type) { ?>
                    <div class="document-card" data-id="<?php echo htmlspecialchars((string)$type['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" onclick="toggleFields(<?php echo htmlspecialchars((string)$type['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>)">
                        <h3>
                            <i class="fas fa-chevron-down"></i>
                            <?php echo htmlspecialchars($type['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                            <div class="action-buttons" style="margin-left: auto;">
                                <button class="edit-btn" onclick="openModal('edit', <?php echo htmlspecialchars((string)$type['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>, event)"><i class="fas fa-edit"></i></button>
                                <button class="delete-btn" onclick="deleteDocumentType(<?php echo htmlspecialchars((string)$type['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>, event)"><i class="fas fa-trash"></i></button>
                            </div>
                        </h3>
                        <div class="fields-container" id="fields-container-<?php echo htmlspecialchars((string)$type['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                            <table class="fields-table" id="fields-<?php echo htmlspecialchars((string)$type['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                                <thead>
                                    <tr>
                                        <th>Field Name</th>
                                        <th>Label</th>
                                        <th>Type</th>
                                        <th>Required</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                            <button class="edit-btn" onclick="addFieldModal(<?php echo htmlspecialchars((string)$type['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>, event)"><i class="fas fa-plus"></i> Add Field</button>
                        </div>
                    </div>
                <?php } ?>
            </div>
        </div>
    </div>

    <!-- Modal for Adding/Editing Document Type -->
    <div class="modal" id="documentTypeModal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h2 id="modalTitle">Add Document Type</h2>
            <form id="documentTypeForm" method="POST" action="document_type_management.php">
                <input type="hidden" name="action" id="documentTypeAction">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                <input type="hidden" id="documentTypeId" name="id">
                <div class="form-container">
                    <input type="text" id="typeName" name="name" placeholder="e.g., Memo" required>
                    <button type="submit"><i class="fas fa-save"></i> Save</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal for Adding/Editing Fields -->
    <div class="modal" id="fieldModal">
        <div class="modal-content">
            <span class="close" onclick="closeFieldModal()">&times;</span>
            <h2 id="fieldModalTitle">Add Field</h2>
            <form id="fieldForm" method="POST" action="document_type_management.php">
                <input type="hidden" name="action" id="fieldAction">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                <input type="hidden" id="fieldDocumentTypeId" name="document_type_id">
                <input type="hidden" id="fieldId" name="field_id">
                <div class="form-container">
                    <input type="text" id="fieldName" name="field_name" placeholder="e.g., subject" required>
                    <input type="text" id="fieldLabel" name="field_label" placeholder="e.g., Subject" required>
                    <select name="field_type" id="fieldType">
                        <option value="text">Text</option>
                        <option value="textarea">Textarea</option>
                        <option value="date">Date</option>
                    </select>
                    <label><input type="checkbox" id="fieldRequired" name="is_required" value="1" checked> Required</label>
                    <button type="submit"><i class="fas fa-save"></i> Save</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const notyf = new Notyf({
            duration: 5000,
            position: {
                x: 'right',
                y: 'top'
            },
            ripple: true
        });

        document.addEventListener('DOMContentLoaded', () => {
            // Sidebar toggle
            const sidebar = document.querySelector('.sidebar');
            const mainContent = document.querySelector('.main-content');
            const toggleBtn = document.querySelector('.toggle-btn');

            toggleBtn.addEventListener('click', () => {
                sidebar.classList.toggle('minimized');
                mainContent.classList.toggle('sidebar-expanded', !sidebar.classList.contains('minimized'));
                mainContent.classList.toggle('sidebar-minimized', sidebar.classList.contains('minimized'));
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

            // Handle form submissions via AJAX
            const handleFormSubmit = async (form, url, data) => {
                try {
                    const response = await fetch(url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: new URLSearchParams(data)
                    });
                    const result = await response.json();
                    if (result.success) {
                        notyf.success(result.message);
                        if (form.id === 'documentTypeForm') {
                            setTimeout(() => location.reload(), 1000);
                        } else {
                            loadFields(data.document_type_id);
                            closeFieldModal();
                        }
                    } else {
                        notyf.error(result.message);
                    }
                } catch (error) {
                    notyf.error(`An error occurred: ${error.message}`);
                }
            };

            // Document Type Form
            document.getElementById('documentTypeForm').addEventListener('submit', async (e) => {
                e.preventDefault();
                const id = document.getElementById('documentTypeId').value;
                const name = document.getElementById('typeName').value.trim();
                const action = id ? 'edit_document_type' : 'add_document_type';
                document.getElementById('documentTypeAction').value = action;

                if (!name) {
                    notyf.error('Document type name is required.');
                    return;
                }

                await handleFormSubmit(e.target, 'document_type_management.php', {
                    action,
                    id,
                    name,
                    csrf_token: document.getElementById('csrf_token').value
                });
            });

            // Field Form
            document.getElementById('fieldForm').addEventListener('submit', async (e) => {
                e.preventDefault();
                const documentTypeId = document.getElementById('fieldDocumentTypeId').value;
                const fieldId = document.getElementById('fieldId').value;
                const fieldName = document.getElementById('fieldName').value.trim();
                const fieldLabel = document.getElementById('fieldLabel').value.trim();
                const fieldType = document.getElementById('fieldType').value;
                const isRequired = document.getElementById('fieldRequired').checked ? 1 : 0;
                const action = fieldId ? 'edit_field' : 'add_field';
                document.getElementById('fieldAction').value = action;

                if (!fieldName || !fieldLabel) {
                    notyf.error('Field name and label are required.');
                    return;
                }

                await handleFormSubmit(e.target, 'document_type_management.php', {
                    action,
                    document_type_id: documentTypeId,
                    field_id: fieldId,
                    field_name: fieldName,
                    field_label: fieldLabel,
                    field_type: fieldType,
                    is_required: isRequired,
                    csrf_token: document.getElementById('csrf_token').value
                });
            });
        });

        async function loadFields(id) {
            try {
                const response = await fetch(`document_type_management.php?action=get_fields&document_type_id=${encodeURIComponent(id)}&csrf_token=${encodeURIComponent(document.getElementById('csrf_token').value)}`);
                const data = await response.json();
                if (data.success) {
                    const fieldsHtml = data.fields.map(field => `
                        <tr>
                            <td>${field.field_name}</td>
                            <td>${field.field_label}</td>
                            <td>${field.field_type}</td>
                            <td>${field.is_required ? 'Yes' : 'No'}</td>
                            <td class="action-buttons">
                                <button class="edit-btn" onclick="editField(${id}, ${field.id}, event)"><i class="fas fa-edit"></i></button>
                                <button class="delete-btn" onclick="deleteField(${id}, ${field.id})"><i class="fas fa-trash"></i></button>
                            </td>
                        </tr>
                    `).join('');
                    document.querySelector(`#fields-${id} tbody`).innerHTML = fieldsHtml || '<tr><td colspan="5" style="text-align: center; color: #7f8c8d;">No fields defined.</td></tr>';
                } else {
                    notyf.error(data.message);
                }
            } catch (error) {
                notyf.error(`Failed to load fields: ${error.message}`);
            }
        }

        function openModal(mode, id = null, event = null) {
            if (event) event.stopPropagation();
            const modal = document.getElementById('documentTypeModal');
            const title = document.getElementById('modalTitle');
            const form = document.getElementById('documentTypeForm');
            const idInput = document.getElementById('documentTypeId');
            const nameInput = document.getElementById('typeName');

            title.textContent = mode === 'add' ? 'Add Document Type' : 'Edit Document Type';
            idInput.value = '';
            nameInput.value = '';
            form.querySelector('[name="action"]').value = mode === 'add' ? 'add_document_type' : 'edit_document_type';

            if (mode === 'edit' && id) {
                fetch(`document_type_management.php?action=get_document_type&id=${encodeURIComponent(id)}&csrf_token=${encodeURIComponent(document.getElementById('csrf_token').value)}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            idInput.value = data.document_type.id;
                            nameInput.value = data.document_type.name;
                        } else {
                            notyf.error(data.message);
                        }
                    })
                    .catch(error => notyf.error(`Failed to load document type: ${error.message}`));
            }
            modal.style.display = 'flex';
        }

        function closeModal() {
            document.getElementById('documentTypeModal').style.display = 'none';
        }

        function addFieldModal(documentTypeId, event) {
            event.stopPropagation();
            const modal = document.getElementById('fieldModal');
            document.getElementById('fieldModalTitle').textContent = 'Add Field';
            document.getElementById('fieldDocumentTypeId').value = documentTypeId;
            document.getElementById('fieldId').value = '';
            document.getElementById('fieldName').value = '';
            document.getElementById('fieldLabel').value = '';
            document.getElementById('fieldType').value = 'text';
            document.getElementById('fieldRequired').checked = true;
            document.getElementById('fieldAction').value = 'add_field';
            modal.style.display = 'flex';
        }

        async function editField(documentTypeId, fieldId, event) {
            event.stopPropagation();
            try {
                const response = await fetch(`document_type_management.php?action=get_field&id=${encodeURIComponent(fieldId)}&csrf_token=${encodeURIComponent(document.getElementById('csrf_token').value)}`);
                const data = await response.json();
                if (data.success) {
                    const modal = document.getElementById('fieldModal');
                    document.getElementById('fieldModalTitle').textContent = 'Edit Field';
                    document.getElementById('fieldDocumentTypeId').value = documentTypeId;
                    document.getElementById('fieldId').value = data.field.id;
                    document.getElementById('fieldName').value = data.field.field_name;
                    document.getElementById('fieldLabel').value = data.field.field_label;
                    document.getElementById('fieldType').value = data.field.field_type;
                    document.getElementById('fieldRequired').checked = data.field.is_required == 1;
                    document.getElementById('fieldAction').value = 'edit_field';
                    modal.style.display = 'flex';
                } else {
                    notyf.error(data.message);
                }
            } catch (error) {
                notyf.error(`Failed to load field: ${error.message}`);
            }
        }

        async function deleteField(documentTypeId, fieldId) {
            if (!confirm('Are you sure you want to delete this field?')) return;
            try {
                const response = await fetch('document_type_management.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: new URLSearchParams({
                        action: 'delete_field',
                        field_id: fieldId,
                        document_type_id: documentTypeId,
                        csrf_token: document.getElementById('csrf_token').value
                    })
                });
                const data = await response.json();
                if (data.success) {
                    notyf.success(data.message);
                    loadFields(documentTypeId);
                } else {
                    notyf.error(data.message);
                }
            } catch (error) {
                notyf.error(`Failed to delete field: ${error.message}`);
            }
        }

        async function deleteDocumentType(id, event) {
            event.stopPropagation();
            if (!confirm('Are you sure you want to delete this document type and its fields?')) return;
            try {
                const response = await fetch('document_type_management.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: new URLSearchParams({
                        action: 'delete_document_type',
                        id,
                        csrf_token: document.getElementById('csrf_token').value
                    })
                });
                const data = await response.json();
                if (data.success) {
                    notyf.success(data.message);
                    setTimeout(() => location.reload(), 1000);
                } else {
                    notyf.error(data.message);
                }
            } catch (error) {
                notyf.error(`Failed to delete document type: ${error.message}`);
            }
        }

        function closeFieldModal() {
            document.getElementById('fieldModal').style.display = 'none';
        }

        function toggleFields(id) {
            const card = document.querySelector(`.document-card[data-id="${id}"]`);
            const container = document.getElementById(`fields-container-${id}`);
            if (card.classList.contains('expanded')) {
                container.style.display = 'none';
                card.classList.remove('expanded');
            } else {
                loadFields(id);
                container.style.display = 'block';
                card.classList.add('expanded');
            }
        }

        // Handle AJAX GET for document type and fields
        <?php
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && validateCsrfToken($_GET['csrf_token'] ?? '')) {
            $action = filter_input(INPUT_GET, 'action', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $response = ['success' => false, 'message' => ''];

            if ($action === 'get_document_type') {
                $id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
                if ($id) {
                    $stmt = executeQuery($pdo, "SELECT id, name FROM document_types WHERE id = ?", [$id]);
                    if ($stmt && $data = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $response = ['success' => true, 'document_type' => $data];
                    } else {
                        $response['message'] = 'Document type not found.';
                    }
                } else {
                    $response['message'] = 'Invalid document type ID.';
                }
            } elseif ($action === 'get_field') {
                $id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
                if ($id) {
                    $stmt = executeQuery($pdo, "SELECT id, document_type_id, field_name, field_label, field_type, is_required FROM documents_type_fields WHERE id = ?", [$id]);
                    if ($stmt && $data = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $response = ['success' => true, 'field' => $data];
                    } else {
                        $response['message'] = 'Field not found.';
                    }
                } else {
                    $response['message'] = 'Invalid field ID.';
                }
            } elseif ($action === 'get_fields') {
                $document_type_id = filter_var($_GET['document_type_id'], FILTER_VALIDATE_INT);
                if ($document_type_id) {
                    $stmt = executeQuery($pdo, "SELECT id, field_name, field_label, field_type, is_required FROM documents_type_fields WHERE document_type_id = ? ORDER BY field_name ASC", [$document_type_id]);
                    if ($stmt) {
                        $response = ['success' => true, 'fields' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
                    } else {
                        $response['message'] = 'Failed to load fields.';
                    }
                } else {
                    $response['message'] = 'Invalid document type ID.';
                }
            }

            header('Content-Type: application/json');
            echo json_encode($response);
            exit;
        }
        ?>
    </script>
</body>

</html>