<?php
session_start();
require 'db_connection.php';

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// CSRF token generation and validation
function generateCsrfToken()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Validate CSRF token
function validateCsrfToken($token)
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Redirect to login if not authenticated or not an admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: login.php');
    exit();
}

if ($_SESSION['role'] !== 'admin') {
    header('Location: unauthorized.php');
    exit();
}

$userId = filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT);
if ($userId === false) {
    session_destroy();
    header('Location: login.php');
    exit();
}

// Function to execute prepared queries safely
function executeQuery($pdo, $query, $params = [])
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

// Function to sanitize HTML output
function sanitizeHTML($data)
{
    return htmlspecialchars($data ?? '', ENT_QUOTES, 'UTF-8');
}

// Handle form submissions for add/edit/delete departments
$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCsrfToken($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_department') {
        $name = trim($_POST['department_name'] ?? '');
        $type = trim($_POST['department_type'] ?? '');
        $nameType = trim($_POST['name_type'] ?? '');
        $parentId = !empty($_POST['parent_department_id']) ? (int)$_POST['parent_department_id'] : null;

        if (!empty($name) && !empty($type) && !empty($nameType)) {
            $insertStmt = executeQuery($pdo, "INSERT INTO departments (department_name, department_type, name_type, parent_department_id) VALUES (?, ?, ?, ?)", [$name, $type, $nameType, $parentId]);
            if ($insertStmt) {
                $successMessage = 'Department added successfully.';
                // Log transaction
                executeQuery($pdo, "INSERT INTO transactions (user_id, transaction_type, transaction_status, transaction_time, description) VALUES (?, 'add_department', 'completed', NOW(), ?)", [$userId, "Added department: $name"]);
            } else {
                $errorMessage = 'Failed to add department.';
            }
        } else {
            $errorMessage = 'All fields are required.';
        }
    } elseif ($action === 'edit_department') {
        $id = (int)($_POST['department_id'] ?? 0);
        $name = trim($_POST['department_name'] ?? '');
        $type = trim($_POST['department_type'] ?? '');
        $nameType = trim($_POST['name_type'] ?? '');
        $parentId = !empty($_POST['parent_department_id']) ? (int)$_POST['parent_department_id'] : null;

        if ($id > 0 && !empty($name) && !empty($type) && !empty($nameType)) {
            $updateStmt = executeQuery($pdo, "UPDATE departments SET department_name = ?, department_type = ?, name_type = ?, parent_department_id = ? WHERE department_id = ?", [$name, $type, $nameType, $parentId, $id]);
            if ($updateStmt) {
                $successMessage = 'Department updated successfully.';
                // Log transaction
                executeQuery($pdo, "INSERT INTO transactions (user_id, transaction_type, transaction_status, transaction_time, description) VALUES (?, 'edit_department', 'completed', NOW(), ?)", [$userId, "Edited department ID: $id"]);
            } else {
                $errorMessage = 'Failed to update department.';
            }
        } else {
            $errorMessage = 'Invalid data for update.';
        }
    } elseif ($action === 'delete_department') {
        $id = (int)($_POST['department_id'] ?? 0);
        if ($id > 0) {
            // Check for children or dependencies (e.g., users_department)
            $childrenStmt = executeQuery($pdo, "SELECT COUNT(*) FROM departments WHERE parent_department_id = ?", [$id]);
            $childrenCount = $childrenStmt ? $childrenStmt->fetchColumn() : 0;

            $usersStmt = executeQuery($pdo, "SELECT COUNT(*) FROM users_department WHERE department_id = ?", [$id]);
            $usersCount = $usersStmt ? $usersStmt->fetchColumn() : 0;

            if ($childrenCount === 0 && $usersCount === 0) {
                $deleteStmt = executeQuery($pdo, "DELETE FROM departments WHERE department_id = ?", [$id]);
                if ($deleteStmt) {
                    $successMessage = 'Department deleted successfully.';
                    // Log transaction
                    executeQuery($pdo, "INSERT INTO transactions (user_id, transaction_type, transaction_status, transaction_time, description) VALUES (?, 'delete_department', 'completed', NOW(), ?)", [$userId, "Deleted department ID: $id"]);
                } else {
                    $errorMessage = 'Failed to delete department.';
                }
            } else {
                $errorMessage = 'Cannot delete department with children or assigned users.';
            }
        } else {
            $errorMessage = 'Invalid department ID.';
        }
    }
}

// Fetch all departments with hierarchy
$departmentsStmt = executeQuery($pdo, "
    SELECT d.department_id, d.department_name, d.department_type, d.name_type, d.parent_department_id, 
           COALESCE(p.department_name, 'None') AS parent_name
    FROM departments d
    LEFT JOIN departments p ON d.parent_department_id = p.department_id
    ORDER BY d.department_id ASC");
$departments = $departmentsStmt ? $departmentsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

// Fetch parent departments for select options (top-level only, to avoid cycles)
$parentDepartmentsStmt = executeQuery($pdo, "SELECT department_id, department_name FROM departments WHERE parent_department_id IS NULL ORDER BY department_name ASC");
$parentDepartments = $parentDepartmentsStmt ? $parentDepartmentsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

$csrfToken = generateCsrfToken();

// Pagination and table display settings
$itemsPerPage = isset($_GET['items_per_page']) ? (int)$_GET['items_per_page'] : 5;
if (!in_array($itemsPerPage, [5, 10, 20, -1])) {
    $itemsPerPage = 5;
}
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$currentPage = max(1, $currentPage); // Ensure page is at least 1
$offset = ($currentPage - 1) * $itemsPerPage;

$totalItemsStmt = executeQuery($pdo, "SELECT COUNT(*) FROM departments");
$totalItems = $totalItemsStmt ? $totalItemsStmt->fetchColumn() : 0;

$paginatedDepartments = $departments;
if ($itemsPerPage !== -1) {
    $paginatedDepartments = array_slice($departments, $offset, $itemsPerPage);
}
$totalPages = $itemsPerPage === -1 ? 1 : max(1, ceil($totalItems / $itemsPerPage));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-Content-Type-Options" content="nosniff">
    <meta http-equiv="X-Frame-Options" content="DENY">
    <meta http-equiv="X-XSS-Protection" content="1; mode=block">
    <title>Department Management - ArcHive</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style/admin-interface.css">
    <link rel="stylesheet" href="style/admin-sidebar.css">
    <style>
/*         body {
            margin: 0;
            font-family: 'Montserrat', sans-serif;
            display: flex;
            height: 100vh;
            overflow: hidden; 
        }
        .sidebar {
            position: fixed; 
            top: 0;
            left: 0;
            height: 100%;
            width: 250px; 
            transition: width 0.3s ease;
            z-index: 1000;
        }
        .sidebar.minimized {
            width: 60px;
        } */
        .main-content {
            margin-left: 290px; /* Align with expanded sidebar */
            padding: 20px;
            flex: 1; /* Take remaining space */
            overflow-y: auto; /* Allow scrolling in main content */
            transition: margin-left 0.3s ease;
        }
        .main-content.sidebar-minimized {
            margin-left: 60px; /* Align with minimized sidebar */
        }
        .error, .success {
            font-weight: bold;
            padding: 10px;
            margin-bottom: 10px;
            border-radius: 4px;
            width: fit-content;
        }
        .error {
            color: #dc3545;
            background-color: #f8d7da;
        }
        .success {
            color: #28a745;
            background-color: #d4edda;
            animation: fadeOut 5s forwards;
        }
        @keyframes fadeOut {
            0% { opacity: 1; }
            80% { opacity: 1; }
            100% { opacity: 0; display: none; }
        }
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal-content {
            background-color: #fff;
            padding: 20px;
            width: 90%;
            max-width: 500px;
            border-radius: 8px;
            position: relative;
        }
        .modal-content h3 {
            margin-top: 0;
        }
        .close {
            position: absolute;
            top: 10px;
            right: 10px;
            font-size: 1.5em;
            cursor: pointer;
        }
        .table-container {
            position: relative;
        }
        .department-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .department-table th, .department-table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        .department-table th {
            background-color: #3d3d3dff;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .action-buttons button {
            margin-right: 5px;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
            border: none;
        }
        .edit-btn {
            background-color: #007bff;
            color: white;
        }
        .delete-btn {
            background-color: #dc3545;
            color: white;
        }
        .add-department-btn {
            padding: 8px 16px;
            background-color: #28a745;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin-bottom: 20px;
        }
        .pagination {
            position: sticky;
            bottom: 0;
            background-color: #fff;
            padding: 10px;
            border-top: 1px solid #ddd;
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 10;
        }
        .pagination select, .pagination button {
            padding: 8px;
            border-radius: 4px;
            border: 1px solid #ddd;
            cursor: pointer;
        }
        .pagination button:disabled {
            background-color: #ccc;
            cursor: not-allowed;
        }
        .pagination select:focus, .pagination button:focus {
            outline: none;
            border-color: #007bff;
        }
        .modal-content input, .modal-content select {
            width: 100%;
            padding: 8px;
            margin-bottom: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .modal-content button {
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            border: none;
        }
        .modal-content button[type="submit"] {
            background-color: #28a745;
            color: white;
        }
        .modal-content button[type="submit"][data-action="delete"] {
            background-color: #dc3545;
        }
    </style>
</head>
<body class="admin-dashboard">
    <!-- Admin Sidebar -->
    <?php
        include 'admin_menu.php';
    ?>

    <div class="main-content">
        <h2>Department Management</h2>
        <?php if ($successMessage): ?>
            <p class="success"><?php echo sanitizeHTML($successMessage); ?></p>
        <?php endif; ?>
        <?php if ($errorMessage): ?>
            <p class="error"><?php echo sanitizeHTML($errorMessage); ?></p>
        <?php endif; ?>

        <button class="add-department-btn" onclick="openModal('add')">Add New Department</button>

        <div class="table-container">
            <table class="department-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Name Type</th>
                        <th>Parent</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($paginatedDepartments)): ?>
                        <tr><td colspan="6">No departments found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($paginatedDepartments as $dept): ?>
                            <tr>
                                <td><?php echo sanitizeHTML($dept['department_id']); ?></td>
                                <td><?php echo sanitizeHTML($dept['department_name']); ?></td>
                                <td><?php echo sanitizeHTML($dept['department_type']); ?></td>
                                <td><?php echo sanitizeHTML($dept['name_type']); ?></td>
                                <td><?php echo sanitizeHTML($dept['parent_name']); ?></td>
                                <td class="action-buttons">
                                    <button class="edit-btn" onclick='openModal("edit", <?php echo json_encode($dept); ?>)'>Edit</button>
                                    <button class="delete-btn" onclick='openModal("delete", <?php echo json_encode(['department_id' => $dept['department_id'], 'department_name' => $dept['department_name']]); ?>)'>Delete</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Pagination Controls -->
            <div class="pagination">
                <div>
                    <label for="items_per_page">Items per page:</label>
                    <select id="items_per_page" onchange="updateItemsPerPage()">
                        <option value="5" <?php echo $itemsPerPage === 5 ? 'selected' : ''; ?>>5</option>
                        <option value="10" <?php echo $itemsPerPage === 10 ? 'selected' : ''; ?>>10</option>
                        <option value="20" <?php echo $itemsPerPage === 20 ? 'selected' : ''; ?>>20</option>
                        <option value="-1" <?php echo $itemsPerPage === -1 ? 'selected' : ''; ?>>All</option>
                    </select>
                </div>
                <div>
                    <button onclick="goToPage(<?php echo $currentPage - 1; ?>)" <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>>Previous</button>
                    <span>Page <?php echo $currentPage; ?> of <?php echo $totalPages; ?></span>
                    <button onclick="goToPage(<?php echo $currentPage + 1; ?>)" <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>>Next</button>
                </div>
            </div>
        </div>

        <!-- Modal for Add/Edit/Delete -->
        <div class="modal" id="department-modal">
            <div class="modal-content">
                <span class="close" onclick="closeModal()">&times;</span>
                <h3 id="modal-title">Department Action</h3>
                <form id="department-form" method="POST">
                    <input type="hidden" name="action" id="form-action">
                    <input type="hidden" name="department_id" id="department_id">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    <div id="form-content">
                        <!-- Dynamic form content will be injected here -->
                    </div>
                    <button type="submit" id="form-submit" data-action="">Submit</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            const mainContent = document.querySelector('.main-content');
            sidebar.classList.toggle('minimized');
            mainContent.classList.toggle('sidebar-minimized');
        }

        function openModal(action, data = {}) {
            const modal = document.getElementById('department-modal');
            const modalTitle = document.getElementById('modal-title');
            const formAction = document.getElementById('form-action');
            const formContent = document.getElementById('form-content');
            const formSubmit = document.getElementById('form-submit');
            const departmentId = document.getElementById('department_id');

            modal.style.display = 'flex';
            formAction.value = action === 'add' ? 'add_department' : action === 'edit' ? 'edit_department' : 'delete_department';
            formSubmit.setAttribute('data-action', action);

            if (action === 'delete') {
                departmentId.value = data.department_id || '';
                modalTitle.textContent = 'Delete Department';
                formContent.innerHTML = `
                    <p>Are you sure you want to delete the department "${sanitizeHTML(data.department_name)}"?</p>
                `;
                formSubmit.textContent = 'Delete';
                formSubmit.style.backgroundColor = '#dc3545';
            } else {
                departmentId.value = action === 'edit' ? data.department_id || '' : '';
                modalTitle.textContent = action === 'add' ? 'Add New Department' : 'Edit Department';
                formContent.innerHTML = `
                    <label for="department_name">Department Name:</label>
                    <input type="text" id="department_name" name="department_name" value="${action === 'edit' ? sanitizeHTML(data.department_name || '') : ''}" required><br>
                    <label for="department_type">Department Type:</label>
                    <select id="department_type" name="department_type" required>
                        <option value="college" ${action === 'edit' && data.department_type === 'college' ? 'selected' : ''}>College</option>
                        <option value="office" ${action === 'edit' && data.department_type === 'office' ? 'selected' : ''}>Office</option>
                        <option value="sub_department" ${action === 'edit' && data.department_type === 'sub_department' ? 'selected' : ''}>Sub-Department</option>
                    </select><br>
                    <label for="name_type">Name Type:</label>
                    <select id="name_type" name="name_type" required>
                        <option value="Academic" ${action === 'edit' && data.name_type === 'Academic' ? 'selected' : ''}>Academic</option>
                        <option value="Administrative" ${action === 'edit' && data.name_type === 'Administrative' ? 'selected' : ''}>Administrative</option>
                        <option value="Program" ${action === 'edit' && data.name_type === 'Program' ? 'selected' : ''}>Program</option>
                    </select><br>
                    <label for="parent_department_id">Parent Department (Optional):</label>
                    <select id="parent_department_id" name="parent_department_id">
                        <option value="">None</option>
                        <?php foreach ($parentDepartments as $parent): ?>
                            <option value="<?php echo $parent['department_id']; ?>" ${action === 'edit' && data.parent_department_id == <?php echo $parent['department_id']; ?> ? 'selected' : ''}>
                                <?php echo sanitizeHTML($parent['department_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                `;
                formSubmit.textContent = action === 'add' ? 'Add Department' : 'Update Department';
                formSubmit.style.backgroundColor = '#28a745';
            }
        }

        function closeModal() {
            const modal = document.getElementById('department-modal');
            modal.style.display = 'none';
        }

        function sanitizeHTML(str) {
            const div = document.createElement('div');
            div.textContent = str ?? '';
            return div.innerHTML;
        }

        function updateItemsPerPage() {
            const itemsPerPage = document.getElementById('items_per_page').value;
            window.location.href = `department_management.php?items_per_page=${itemsPerPage}&page=1`;
        }

        function goToPage(page) {
            const itemsPerPage = document.getElementById('items_per_page').value;
            window.location.href = `department_management.php?items_per_page=${itemsPerPage}&page=${page}`;
        }

        // Set initial sidebar state and handle modal close
        document.addEventListener('DOMContentLoaded', () => {
            const sidebar = document.querySelector('.sidebar');
            const mainContent = document.querySelector('.main-content');
            mainContent.classList.add(sidebar.classList.contains('minimized') ? 'sidebar-minimized' : '');

            // Handle modal close on click outside
            window.addEventListener('click', (event) => {
                const modal = document.getElementById('department-modal');
                if (event.target === modal) {
                    closeModal();
                }
            });
        });
    </script>
</body>
</html>