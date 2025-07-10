<?php
session_start();
require 'db_connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$stmt = $pdo->query("SELECT id, name FROM document_types ORDER BY name ASC");
$documentTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document Type Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.css">
    <script src="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link rel="stylesheet" href="admin-interface.css">
    <link rel="stylesheet" href="admin-sidebar.css">
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
        <a href="index.php" class="client-btn">
            <i class="fas fa-exchange-alt"></i><span class="link-text">Switch to Client View</span>
        </a>
        <a href="admin_dashboard.php">
            <i class="fas fa-home"></i><span class="link-text">Dashboard</span>
        </a>
        <a href="admin_search.php">
            <i class="fas fa-search"></i><span class="link-text">View All Files</span>
        </a>
        <a href="user_management.php">
            <i class="fas fa-users"></i><span class="link-text">User Management</span>
        </a>
        <a href="department_management.php">
            <i class="fas fa-building"></i><span class="link-text">Department Management</span>
        </a>
        <a href="physical_storage_management.php">
            <i class="fas fa-archive"></i><span class="link-text">Physical Storage</span>
        </a>
        <a href="document_type_management.php" class="active">
            <i class="fas fa-file-alt"></i><span class="link-text">Document Type Management</span>
        </a>
        <a href="logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i><span class="link-text">Logout</span>
        </a>
    </div>

    <div class="main-content sidebar-expanded">
        <h2>Document Type Management</h2>
        <button class="open-modal-btn" onclick="openModal('add')"><i class="fas fa-plus"></i> Add Document Type</button>

        <div class="document-type-section">
            <div class="document-grid">
                <?php foreach ($documentTypes as $type): ?>
                    <div class="document-card" data-id="<?= $type['id'] ?>" onclick="toggleFields(<?= $type['id'] ?>)">
                        <h3><i class="fas fa-chevron-down"></i> <?= htmlspecialchars($type['name']) ?></h3>
                        <div class="fields-container" id="fields-container-<?= $type['id'] ?>">
                            <table class="fields-table" id="fields-<?= $type['id'] ?>">
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
                            <button class="edit-btn" onclick="addFieldModal(<?= $type['id'] ?>, event)"><i class="fas fa-plus"></i> Add Field</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Modal for Adding/Editing Document Type -->
    <div class="modal" id="documentTypeModal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h2 id="modalTitle">Add Document Type</h2>
            <form id="documentTypeForm">
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
            <form id="fieldForm">
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
                    <label><input type="checkbox" id="fieldRequired" name="is_required" checked> Required</label>
                    <button type="submit"><i class="fas fa-save"></i> Save</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const notyf = new Notyf();

        $(document).ready(function() {
            $('.toggle-btn').on('click', function() {
                $('.sidebar').toggleClass('minimized');
                $('.main-content').toggleClass('sidebar-expanded', !$('.sidebar').hasClass('minimized'));
                $('.main-content').toggleClass('sidebar-minimized', $('.sidebar').hasClass('minimized'));
            });

            $('#documentTypeForm').on('submit', function(e) {
                e.preventDefault();
                const id = $('#documentTypeId').val();
                const name = $('#typeName').val().trim();

                if (!name) {
                    notyf.error('Document type name is required.');
                    return;
                }

                $.ajax({
                    url: id ? 'update_document_type.php' : 'add_document_type.php',
                    method: 'POST',
                    data: {
                        id,
                        name
                    },
                    dataType: 'json',
                    success: function(data) {
                        if (data.success) {
                            notyf.success(data.message);
                            setTimeout(() => location.reload(), 1000);
                        } else {
                            notyf.error(data.message);
                        }
                    },
                    error: function() {
                        notyf.error('An error occurred while saving.');
                    }
                });
            });

            $('#fieldForm').on('submit', function(e) {
                e.preventDefault();
                const documentTypeId = $('#fieldDocumentTypeId').val();
                const fieldId = $('#fieldId').val();
                const fieldName = $('#fieldName').val().trim();
                const fieldLabel = $('#fieldLabel').val().trim();
                const fieldType = $('#fieldType').val();
                const isRequired = $('#fieldRequired').is(':checked') ? 1 : 0;

                if (!fieldName || !fieldLabel) {
                    notyf.error('Field name and label are required.');
                    return;
                }

                $.ajax({
                    url: fieldId ? 'update_field.php' : 'add_field.php',
                    method: 'POST',
                    data: {
                        document_type_id: documentTypeId,
                        id: fieldId,
                        field_name: fieldName,
                        field_label: fieldLabel,
                        field_type: fieldType,
                        is_required: isRequired
                    },
                    dataType: 'json',
                    success: function(data) {
                        if (data.success) {
                            notyf.success(data.message);
                            loadFields(documentTypeId);
                            closeFieldModal();
                        } else {
                            notyf.error(data.message);
                        }
                    },
                    error: function() {
                        notyf.error('An error occurred while saving field.');
                    }
                });
            });
        });

        function toggleFields(id) {
            const card = $(`.document-card[data-id="${id}"]`);
            const container = $(`#fields-container-${id}`);
            if (card.hasClass('expanded')) {
                container.slideUp(300);
                card.removeClass('expanded');
            } else {
                loadFields(id);
                container.slideDown(300);
                card.addClass('expanded');
            }
        }

        function loadFields(id) {
            $.ajax({
                url: 'get_document_type.php',
                method: 'GET',
                data: {
                    id: id
                },
                dataType: 'json',
                success: function(data) {
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
                        $(`#fields-${id} tbody`).html(fieldsHtml || '<tr><td colspan="5" style="text-align: center; color: #7f8c8d;">No fields defined.</td></tr>');
                    } else {
                        notyf.error(data.message);
                    }
                },
                error: function() {
                    notyf.error('Failed to load fields.');
                }
            });
        }

        function openModal(mode, id = null) {
            $('#documentTypeModal').fadeIn(300);
            $('#modalTitle').text(mode === 'add' ? 'Add Document Type' : 'Edit Document Type');
            $('#documentTypeId').val('');
            $('#typeName').val('');
            if (mode === 'edit' && id) {
                $.ajax({
                    url: 'get_document_type.php',
                    method: 'GET',
                    data: {
                        id: id
                    },
                    dataType: 'json',
                    success: function(data) {
                        if (data.success) {
                            $('#documentTypeId').val(data.document_type.id);
                            $('#typeName').val(data.document_type.name);
                        }
                    }
                });
            }
        }

        function closeModal() {
            $('#documentTypeModal').fadeOut(300);
        }

        function addFieldModal(documentTypeId, event) {
            event.stopPropagation();
            $('#fieldModal').fadeIn(300);
            $('#fieldModalTitle').text('Add Field');
            $('#fieldDocumentTypeId').val(documentTypeId);
            $('#fieldId').val('');
            $('#fieldName').val('');
            $('#fieldLabel').val('');
            $('#fieldType').val('text');
            $('#fieldRequired').prop('checked', true);
        }

        function editField(documentTypeId, fieldId, event) {
            event.stopPropagation();
            $.ajax({
                url: 'get_field.php',
                method: 'GET',
                data: {
                    id: fieldId
                },
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        $('#fieldModal').fadeIn(300);
                        $('#fieldModalTitle').text('Edit Field');
                        $('#fieldDocumentTypeId').val(documentTypeId);
                        $('#fieldId').val(data.field.id);
                        $('#fieldName').val(data.field.field_name);
                        $('#fieldLabel').val(data.field.field_label);
                        $('#fieldType').val(data.field.field_type);
                        $('#fieldRequired').prop('checked', data.field.is_required == 1);
                    } else {
                        notyf.error(data.message);
                    }
                }
            });
        }

        function deleteField(documentTypeId, fieldId) {
            if (confirm('Are you sure you want to delete this field?')) {
                $.ajax({
                    url: 'delete_field.php',
                    method: 'POST',
                    data: {
                        id: fieldId
                    },
                    dataType: 'json',
                    success: function(data) {
                        if (data.success) {
                            notyf.success(data.message);
                            loadFields(documentTypeId);
                        } else {
                            notyf.error(data.message);
                        }
                    }
                });
            }
        }

        function closeFieldModal() {
            $('#fieldModal').fadeOut(300);
        }
    </script>
</body>

</html>