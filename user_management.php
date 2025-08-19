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

// Handle form submissions
$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCsrfToken($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_user') {
        $username = trim($_POST['username'] ?? '');
        $email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
        $password = password_hash($_POST['password'] ?? '', PASSWORD_BCRYPT);
        $role = in_array($_POST['role'] ?? '', ['admin', 'user', 'client']) ? $_POST['role'] : 'user';
        $position = filter_var($_POST['position'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['default' => 0]]);
        $department_ids = isset($_POST['departments']) ? array_map('intval', $_POST['departments']) : [];

        // Handle profile picture
        $profile_pic = null;
        if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg', 'image/png'];
            $file_type = mime_content_type($_FILES['profile_pic']['tmp_name']);
            if (in_array($file_type, $allowed_types)) {
                $profile_pic = file_get_contents($_FILES['profile_pic']['tmp_name']);
            }
        }

        if (!empty($username) && !empty($email) && !empty($_POST['password'])) {
            $insertUserStmt = executeQuery($pdo, "
                INSERT INTO users (username, password, email, role, position, profile_pic, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())",
                [$username, $password, $email, $role, $position, $profile_pic]);
            if ($insertUserStmt) {
                $new_user_id = $pdo->lastInsertId();
                foreach ($department_ids as $department_id) {
                    executeQuery($pdo, "
                        INSERT INTO users_department (user_id, department_id)
                        VALUES (?, ?)",
                        [$new_user_id, $department_id]);
                }
                executeQuery($pdo, "
                    INSERT INTO transactions (user_id, transaction_type, transaction_status, transaction_time, description)
                    VALUES (?, 'edit_user', 'completed', NOW(), ?)",
                    [$userId, "Added user: $username"]);
                $successMessage = 'User added successfully.';
            } else {
                $errorMessage = 'Failed to add user.';
            }
        } else {
            $errorMessage = 'All required fields must be filled.';
        }
    } elseif ($action === 'edit_user') {
        $edit_user_id = (int)($_POST['user_id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
        $role = in_array($_POST['role'] ?? '', ['admin', 'user', 'client']) ? $_POST['role'] : 'user';
        $position = filter_var($_POST['position'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['default' => 0]]);
        $department_ids = isset($_POST['departments']) ? array_map('intval', $_POST['departments']) : [];

        $password_sql = '';
        $params = [$username, $email, $role, $position];
        if (!empty($_POST['password'])) {
            $password = password_hash($_POST['password'], PASSWORD_BCRYPT);
            $password_sql = ', password = ?';
            $params[] = $password;
        }

        $profile_pic_sql = '';
        if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg', 'image/png'];
            $file_type = mime_content_type($_FILES['profile_pic']['tmp_name']);
            if (in_array($file_type, $allowed_types)) {
                $profile_pic = file_get_contents($_FILES['profile_pic']['tmp_name']);
                $profile_pic_sql = ', profile_pic = ?';
                $params[] = $profile_pic;
            }
        }

        $params[] = $edit_user_id;
        if ($edit_user_id > 0 && !empty($username) && !empty($email)) {
            $updateUserStmt = executeQuery($pdo, "
                UPDATE users 
                SET username = ?, email = ?, role = ?, position = ? $password_sql $profile_pic_sql
                WHERE user_id = ?",
                $params);
            if ($updateUserStmt) {
                executeQuery($pdo, "DELETE FROM users_department WHERE user_id = ?", [$edit_user_id]);
                foreach ($department_ids as $department_id) {
                    executeQuery($pdo, "
                        INSERT INTO users_department (user_id, department_id)
                        VALUES (?, ?)",
                        [$edit_user_id, $department_id]);
                }
                executeQuery($pdo, "
                    INSERT INTO transactions (user_id, transaction_type, transaction_status, transaction_time, description)
                    VALUES (?, 'edit_user', 'completed', NOW(), ?)",
                    [$userId, "Edited user: $username"]);
                $successMessage = 'User updated successfully.';
            } else {
                $errorMessage = 'Failed to update user.';
            }
        } else {
            $errorMessage = 'Invalid data for update.';
        }
    } elseif ($action === 'delete_user') {
        $delete_user_id = (int)($_POST['user_id'] ?? 0);
        if ($delete_user_id > 0 && $delete_user_id != $userId) {
            $deleteStmt = executeQuery($pdo, "DELETE FROM users WHERE user_id = ?", [$delete_user_id]);
            if ($deleteStmt) {
                executeQuery($pdo, "
                    INSERT INTO transactions (user_id, transaction_type, transaction_status, transaction_time, description)
                    VALUES (?, 'edit_user', 'completed', NOW(), ?)",
                    [$userId, "Deleted user ID: $delete_user_id"]);
                $successMessage = 'User deleted successfully.';
            } else {
                $errorMessage = 'Failed to delete user.';
            }
        } else {
            $errorMessage = 'Cannot delete your own account or invalid user ID.';
        }
    }
}

// Fetch all departments for the add/edit form
$departmentsStmt = executeQuery($pdo, "
    SELECT department_id, department_name, parent_department_id
    FROM departments
    ORDER BY department_name");
$departments = $departmentsStmt ? $departmentsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

// Fetch users with filtering and pagination
$roleFilter = isset($_GET['role_filter']) ? trim($_GET['role_filter']) : '';
$roleFilter = in_array($roleFilter, ['admin', 'user', 'client']) ? $roleFilter : '';

$itemsPerPage = isset($_GET['items_per_page']) ? (int)$_GET['items_per_page'] : 5;
if (!in_array($itemsPerPage, [5, 10, 20, -1])) {
    $itemsPerPage = 5;
}
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$currentPage = max(1, $currentPage);
$offset = ($currentPage - 1) * $itemsPerPage;

$whereClause = $roleFilter ? "WHERE u.role = ?" : "";
$params = $roleFilter ? [$roleFilter] : [];

$usersStmt = executeQuery($pdo, "
    SELECT 
        u.user_id, 
        u.username, 
        u.email, 
        u.position, 
        u.role, 
        u.profile_pic,
        GROUP_CONCAT(COALESCE(d2.department_name, d.department_name)) AS departments
    FROM users u
    LEFT JOIN users_department ud ON u.user_id = ud.user_id
    LEFT JOIN departments d ON ud.department_id = d.department_id
    LEFT JOIN departments d2 ON d.parent_department_id = d2.department_id
    $whereClause
    GROUP BY u.user_id
    ORDER BY u.username", $params);
$users = $usersStmt ? $usersStmt->fetchAll(PDO::FETCH_ASSOC) : [];

$totalItemsStmt = executeQuery($pdo, "SELECT COUNT(*) FROM users $whereClause", $params);
$totalItems = $totalItemsStmt ? $totalItemsStmt->fetchColumn() : 0;

$paginatedUsers = $users;
if ($itemsPerPage !== -1) {
    $paginatedUsers = array_slice($users, $offset, $itemsPerPage);
}
$totalPages = $itemsPerPage === -1 ? 1 : max(1, ceil($totalItems / $itemsPerPage));

$csrfToken = generateCsrfToken();
?>

<!DOCTYPE html>
<html lang="en">
<?php include 'admin_head.php'; ?>
<body class="admin-dashboard">
    <?php include 'admin_menu.php'; ?>

    <div class="main-content">
        <h2>User Management</h2>
        <?php if ($successMessage): ?>
            <p class="success"><?php echo sanitizeHTML($successMessage); ?></p>
        <?php endif; ?>
        <?php if ($errorMessage): ?>
            <p class="error"><?php echo sanitizeHTML($errorMessage); ?></p>
        <?php endif; ?>

        <div class="filter-container">
            <label for="role_filter">Filter by Role:</label>
            <select id="role_filter" onchange="updateRoleFilter()">
                <option value="" <?php echo $roleFilter === '' ? 'selected' : ''; ?>>All</option>
                <option value="admin" <?php echo $roleFilter === 'admin' ? 'selected' : ''; ?>>Admin</option>
                <option value="user" <?php echo $roleFilter === 'user' ? 'selected' : ''; ?>>User</option>
                <option value="client" <?php echo $roleFilter === 'client' ? 'selected' : ''; ?>>Client</option>
            </select>
        </div>

        <button class="add-department-btn" onclick="openModal('add')">Add New User</button>

        <div class="table-container">
            <table class="department-table">
                <thead>
                    <tr>
                        <th>Profile</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Position</th>
                        <th>Departments</th>
                        <th>Role</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($paginatedUsers)): ?>
                        <tr><td colspan="7">No users found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($paginatedUsers as $user): ?>
                            <tr>
                                <td>
                                    <?php if (!empty($user['profile_pic'])): ?>
                                        <img src="data:image/jpeg;base64,<?php echo base64_encode($user['profile_pic']); ?>" alt="Profile" style="width: 50px; height: 50px; border-radius: 50%;">
                                    <?php else: ?>
                                        No Profile Picture
                                    <?php endif; ?>
                                </td>
                                <td><?php echo sanitizeHTML($user['username']); ?></td>
                                <td><?php echo sanitizeHTML($user['email']); ?></td>
                                <td><?php echo sanitizeHTML($user['position']); ?></td>
                                <td><?php echo sanitizeHTML($user['departments'] ?? 'None'); ?></td>
                                <td><?php echo sanitizeHTML($user['role']); ?></td>
                                <td class="action-buttons">
                                    <button class="edit-btn" onclick='openModal("edit", <?php echo json_encode($user); ?>)'>Edit</button>
                                    <button class="delete-btn" onclick='openModal("delete", <?php echo json_encode(['user_id' => $user['user_id'], 'username' => $user['username']]); ?>)'>Delete</button>
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
        <div class="modal" id="user-modal">
            <div class="modal-content">
                <span class="close" onclick="closeModal()">&times;</span>
                <h3 id="modal-title">User Action</h3>
                <form id="user-form" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" id="form-action">
                    <input type="hidden" name="user_id" id="user_id">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    <div id="form-content">
                        <!-- Dynamic form content will be injected here -->
                    </div>
                    <button type="submit" id="form-submit" data-action="">Submit</button>
                </form>
            </div>
        </div>
    </div>

    <style>
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
        .modal-content select[multiple] {
            height: 100px;
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
        .filter-container {
            margin-bottom: 20px;
        }
        .filter-container select {
            padding: 8px;
            border-radius: 4px;
            border: 1px solid #ddd;
            cursor: pointer;
        }
        .profile-pic-preview {
            max-width: 200px;
            max-height: 200px;
            margin-top: 10px;
            display: none;
        }
        .cropper-container {
            max-width: 100%;
            max-height: 300px;
            margin-top: 10px;
        }
        .highlight-option {
            animation: highlight 0.3s ease;
        }
        @keyframes highlight {
            0% { background-color: #d4edda; }
            100% { background-color: transparent; }
        }
    </style>

    <script>
        let cropper = null;

        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            const mainContent = document.querySelector('.main-content');
            sidebar.classList.toggle('minimized');
            mainContent.classList.toggle('sidebar-minimized');
        }

        function openModal(action, data = {}) {
            const modal = document.getElementById('user-modal');
            const modalTitle = document.getElementById('modal-title');
            const formAction = document.getElementById('form-action');
            const formContent = document.getElementById('form-content');
            const formSubmit = document.getElementById('form-submit');
            const userId = document.getElementById('user_id');

            modal.style.display = 'flex';
            formAction.value = action === 'add' ? 'add_user' : action === 'edit' ? 'edit_user' : 'delete_user';
            formSubmit.setAttribute('data-action', action);

            if (action === 'delete') {
                userId.value = data.user_id || '';
                modalTitle.textContent = 'Delete User';
                formContent.innerHTML = `
                    <p>Are you sure you want to delete the user "${sanitizeHTML(data.username)}"?</p>
                `;
                formSubmit.textContent = 'Delete';
                formSubmit.style.backgroundColor = '#dc3545';
            } else {
                userId.value = action === 'edit' ? data.user_id || '' : '';
                modalTitle.textContent = action === 'add' ? 'Add New User' : 'Edit User';
                const departments = action === 'edit' && data.departments ? data.departments.split(',') : [];
                formContent.innerHTML = `
                    <label for="username">Username:</label>
                    <input type="text" id="username" name="username" value="${action === 'edit' ? sanitizeHTML(data.username || '') : ''}" required>
                    <label for="email">Email:</label>
                    <input type="email" id="email" name="email" value="${action === 'edit' ? sanitizeHTML(data.email || '') : ''}" required>
                    <label for="password">Password${action === 'edit' ? ' (leave blank to keep unchanged)' : ''}:</label>
                    <input type="password" id="password" name="password" ${action === 'add' ? 'required' : ''}>
                    <label for="role">Role:</label>
                    <select id="role" name="role" required>
                        <option value="admin" ${action === 'edit' && data.role === 'admin' ? 'selected' : ''}>Admin</option>
                        <option value="user" ${action === 'edit' && data.role === 'user' ? 'selected' : ''}>User</option>
                        <option value="client" ${action === 'edit' && data.role === 'client' ? 'selected' : ''}>Client</option>
                    </select>
                    <label for="position">Position:</label>
                    <input type="number" id="position" name="position" value="${action === 'edit' ? sanitizeHTML(data.position || '0') : '0'}" min="0" required>
                    <label for="departments">Departments (Hold Ctrl/Cmd to select multiple):</label>
                    <select id="departments" name="departments[]" multiple>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['department_id']; ?>"
                                ${action === 'edit' && departments.some(dept => dept.includes(<?php echo json_encode($dept['department_name']); ?>)) ? 'selected' : ''}>
                                <?php echo sanitizeHTML($dept['department_name']); ?>
                                <?php if ($dept['parent_department_id']): ?>
                                    (Parent: <?php echo sanitizeHTML($departments[array_search($dept['parent_department_id'], array_column($departments, 'department_id'))]['department_name']); ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <label for="profile_pic">Profile Picture:</label>
                    <input type="file" id="profile_pic" name="profile_pic" accept="image/jpeg,image/png">
                    <div class="cropper-container" id="cropperContainer" style="display: none;">
                        <img id="profilePicPreview" class="profile-pic-preview">
                    </div>
                `;
                formSubmit.textContent = action === 'add' ? 'Add User' : 'Update User';
                formSubmit.style.backgroundColor = '#28a745';
                initializeCropper();
                initializeDepartmentHighlight();
            }
        }

        function initializeDepartmentHighlight() {
            const departmentSelect = document.getElementById('departments');
            departmentSelect.addEventListener('click', function(e) {
                if (e.target.tagName === 'OPTION') {
                    const option = e.target;
                    option.classList.add('highlight-option');
                    setTimeout(() => {
                        option.classList.remove('highlight-option');
                    }, 300); // Match animation duration
                }
            });
        }

        function closeModal() {
            const modal = document.getElementById('user-modal');
            modal.style.display = 'none';
            if (cropper) {
                cropper.destroy();
                cropper = null;
                document.getElementById('profilePicPreview').src = '';
                document.getElementById('cropperContainer').style.display = 'none';
            }
        }

        function initializeCropper() {
            const input = document.getElementById('profile_pic');
            const preview = document.getElementById('profilePicPreview');
            const container = document.getElementById('cropperContainer');

            input.addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(event) {
                        preview.src = event.target.result;
                        container.style.display = 'block';
                        preview.style.display = 'block';

                        if (cropper) {
                            cropper.destroy();
                        }

                        cropper = new Cropper(preview, {
                            aspectRatio: 1,
                            viewMode: 1,
                            autoCropArea: 0.8,
                            responsive: true,
                            crop(event) {
                                cropper.getCroppedCanvas({
                                    width: 200,
                                    height: 200,
                                }).toBlob(function(blob) {
                                    const file = new File([blob], input.files[0].name, { type: blob.type });
                                    const dataTransfer = new DataTransfer();
                                    dataTransfer.items.add(file);
                                    input.files = dataTransfer.files;
                                });
                            }
                        });
                    };
                    reader.readAsDataURL(file);
                }
            });
        }

        function sanitizeHTML(str) {
            const div = document.createElement('div');
            div.textContent = str ?? '';
            return div.innerHTML;
        }

        function updateItemsPerPage() {
            const itemsPerPage = document.getElementById('items_per_page').value;
            const roleFilter = document.getElementById('role_filter').value;
            window.location.href = `user_management.php?items_per_page=${itemsPerPage}&page=1&role_filter=${roleFilter}`;
        }

        function goToPage(page) {
            const itemsPerPage = document.getElementById('items_per_page').value;
            const roleFilter = document.getElementById('role_filter').value;
            window.location.href = `user_management.php?items_per_page=${itemsPerPage}&page=${page}&role_filter=${roleFilter}`;
        }

        function updateRoleFilter() {
            const roleFilter = document.getElementById('role_filter').value;
            const itemsPerPage = document.getElementById('items_per_page').value;
            window.location.href = `user_management.php?items_per_page=${itemsPerPage}&page=1&role_filter=${roleFilter}`;
        }

        document.addEventListener('DOMContentLoaded', () => {
            const sidebar = document.querySelector('.sidebar');
            const mainContent = document.querySelector('.main-content');
            mainContent.classList.add(sidebar.classList.contains('minimized') ? 'sidebar-minimized' : '');

            window.addEventListener('click', (event) => {
                const modal = document.getElementById('user-modal');
                if (event.target === modal) {
                    closeModal();
                }
            });
        });
    </script>
</body>
</html>