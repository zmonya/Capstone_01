<?php
session_start();
require 'db_connection.php'; // Assumes $pdo is initialized with PDO connection
require 'log_activity.php'; // Assumes logging function for transaction table
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

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

/**
 * Sends a JSON response with appropriate HTTP status for AJAX requests.
 *
 * @param bool $success
 * @param string $message
 * @param array $data
 * @param int $statusCode
 * @return void
 */
function sendJsonResponse(bool $success, string $message, array $data, int $statusCode): void
{
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code($statusCode);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}

/**
 * Validates user session and admin role, redirects if invalid.
 *
 * @param PDO $pdo Database connection
 * @return int User ID
 */
function validateAdminSession(PDO $pdo): int
{
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        // Log unauthorized access attempt
        $message = 'Unauthorized access attempt to user_management.php';
        try {
            $stmt = $pdo->prepare("
                INSERT INTO transaction (User_id, Transaction_status, Transaction_type, Time, Massage)
                VALUES (?, 'failed', 22, NOW(), ?)
            ");
            $stmt->execute([$_SESSION['user_id'] ?? null, $message]);
        } catch (PDOException $e) {
            error_log("Failed to log unauthorized access: " . $e->getMessage(), 3, __DIR__ . '/logs/error_log.log');
        }

        // Store error message in session and redirect
        $_SESSION['error'] = 'Access denied: Admin privileges required.';
        header('Location: login.php');
        exit;
    }
    session_regenerate_id(true); // Regenerate session ID for security
    return (int)$_SESSION['user_id'];
}

/**
 * Validates input data for user management.
 *
 * @param array $data
 * @param bool $isAdd
 * @return string Error message or empty string if valid
 */
function validateInput(array $data, bool $isAdd): string
{
    if (empty($data['username']) || strlen($data['username']) > 255) {
        return 'Username is required and must be 255 characters or less.';
    }
    if ($isAdd && (empty($data['password']) || strlen($data['password']) < 8)) {
        return 'Password is required and must be at least 8 characters for new users.';
    }
    if (!is_numeric($data['position']) || $data['position'] < 0) {
        return 'Position must be a non-negative integer.';
    }
    if (!in_array($data['role'], ['admin', 'client'])) {
        return 'Role must be either "admin" or "client".';
    }
    if (empty($data['department_ids'])) {
        return 'At least one department must be selected.';
    }
    return '';
}

/**
 * Processes and saves profile picture as BLOB.
 *
 * @param string $croppedImage Base64-encoded image
 * @return string|null Base64-encoded image or null
 * @throws Exception If image processing fails
 */
function processProfilePicture(string $croppedImage): ?string
{
    if (empty($croppedImage)) {
        return null;
    }
    $imageData = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $croppedImage));
    if ($imageData === false) {
        throw new Exception('Invalid image data.', 400);
    }
    // Validate image size (e.g., max 1MB)
    if (strlen($imageData) > 1024 * 1024) {
        throw new Exception('Profile picture size exceeds 1MB.', 400);
    }
    return $imageData;
}

/**
 * Fetches all users with their department affiliations.
 *
 * @param PDO $pdo
 * @return array
 */
function fetchAllUsers(PDO $pdo): array
{
    $stmt = $pdo->prepare("
        SELECT u.User_id AS id, u.Username AS username, u.Position AS position, u.Role AS role, u.Profile_pic AS profile_pic,
               GROUP_CONCAT(DISTINCT d.Department_name ORDER BY d.Department_name SEPARATOR ', ') AS department_names
        FROM users u
        LEFT JOIN users_department ud ON u.User_id = ud.User_id
        LEFT JOIN departments d ON ud.Department_id = d.Department_id
        GROUP BY u.User_id
        ORDER BY u.Username
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fetches all departments.
 *
 * @param PDO $pdo
 * @return array
 */
function fetchAllDepartments(PDO $pdo): array
{
    $stmt = $pdo->prepare("SELECT Department_id AS id, Department_name AS name FROM departments ORDER BY Department_name");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Validates department IDs against the database.
 *
 * @param PDO $pdo
 * @param array $departmentIds
 * @return bool
 */
function validateDepartmentIds(PDO $pdo, array $departmentIds): bool
{
    if (empty($departmentIds)) {
        return false;
    }
    $placeholders = implode(',', array_fill(0, count($departmentIds), '?'));
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE Department_id IN ($placeholders)");
    $stmt->execute($departmentIds);
    $count = $stmt->fetchColumn();
    return $count === count($departmentIds);
}

try {
    // Validate admin session at the start
    $adminId = validateAdminSession($pdo);
    $error = '';

    // Handle CSRF token
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $csrfToken = $_SESSION['csrf_token'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Verify CSRF token
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrfToken) {
            $message = 'Invalid CSRF token during POST request';
            $stmt = $pdo->prepare("
                INSERT INTO transaction (User_id, Transaction_status, Transaction_type, Time, Massage)
                VALUES (?, 'failed', 22, NOW(), ?)
            ");
            $stmt->execute([$adminId, $message]);
            sendJsonResponse(false, 'Invalid CSRF token.', [], 403);
        }

        $action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_STRING) ?? '';
        if (!in_array($action, ['add', 'edit'])) {
            throw new Exception('Invalid action.', 400);
        }

        $username = trim(filter_input(INPUT_POST, 'username', FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
        $password = trim(filter_input(INPUT_POST, 'password', FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
        $position = filter_input(INPUT_POST, 'position', FILTER_VALIDATE_INT, ['options' => ['default' => 0, 'min_range' => 0]]) ?? 0;
        $department_ids = array_filter(array_map('intval', explode(',', trim($_POST['departments'] ?? ''))));
        $cropped_image = trim($_POST['cropped_image'] ?? '');
        $role = trim(filter_input(INPUT_POST, 'role', FILTER_SANITIZE_STRING) ?? '');

        // Validate inputs
        $error = validateInput([
            'username' => $username,
            'password' => $password,
            'position' => $position,
            'role' => $role,
            'department_ids' => $department_ids
        ], $action === 'add');

        // Validate department IDs
        if (empty($error) && !validateDepartmentIds($pdo, $department_ids)) {
            $error = 'Invalid department IDs selected.';
        }

        if (empty($error)) {
            $stmt = $pdo->prepare("SELECT User_id FROM users WHERE Username = ?");
            $stmt->execute([$username]);
            $existingUser = $stmt->fetch();

            if ($action === 'add' && $existingUser) {
                $error = 'Username already exists.';
            } elseif ($action === 'edit' && $existingUser && $existingUser['User_id'] != ($_POST['user_id'] ?? 0)) {
                $error = 'Username is already taken by another user.';
            } else {
                $profile_pic = processProfilePicture($cropped_image);
                $pdo->beginTransaction();

                if ($action === 'add') {
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("
                        INSERT INTO users (Username, Password, Role, Profile_pic, Position, Created_at)
                        VALUES (?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([$username, $hashedPassword, $role, $profile_pic, $position]);
                    $user_id = $pdo->lastInsertId();
                    $logMessage = "Added user: $username";
                } else { // edit
                    $user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
                    if (!$user_id) {
                        throw new Exception('Invalid user ID.', 400);
                    }
                    $stmt = $pdo->prepare("
                        UPDATE users SET Username = ?, Position = ?, Role = ?, Profile_pic = COALESCE(?, Profile_pic)
                        WHERE User_id = ?
                    ");
                    $stmt->execute([$username, $position, $role, $profile_pic, $user_id]);
                    if (!empty($password)) {
                        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("UPDATE users SET Password = ? WHERE User_id = ?");
                        $stmt->execute([$hashedPassword, $user_id]);
                    }
                    $logMessage = "Edited user: $username";
                }

                // Update department affiliations
                $stmt = $pdo->prepare("DELETE FROM users_department WHERE User_id = ?");
                $stmt->execute([$user_id]);

                foreach ($department_ids as $dept_id) {
                    $stmt = $pdo->prepare("
                        INSERT INTO users_department (User_id, Department_id)
                        VALUES (?, ?)
                    ");
                    $stmt->execute([$user_id, $dept_id]);
                }

                // Log action in transaction table
                $stmt = $pdo->prepare("
                    INSERT INTO transaction (User_id, Transaction_status, Transaction_type, Time, Massage)
                    VALUES (?, 'completed', 22, NOW(), ?)
                ");
                $stmt->execute([$adminId, $logMessage]);

                $pdo->commit();
                sendJsonResponse(true, 'User ' . ($action === 'add' ? 'added' : 'updated') . ' successfully.', [], 200);
            }
        }
        if (!empty($error)) {
            sendJsonResponse(false, $error, [], 400);
        }
    }

    if (isset($_GET['delete'])) {
        // Verify CSRF token for delete action
        if (!isset($_GET['csrf_token']) || $_GET['csrf_token'] !== $csrfToken) {
            $message = 'Invalid CSRF token during DELETE request';
            $stmt = $pdo->prepare("
                INSERT INTO transaction (User_id, Transaction_status, Transaction_type, Time, Massage)
                VALUES (?, 'failed', 22, NOW(), ?)
            ");
            $stmt->execute([$adminId, $message]);
            $_SESSION['error'] = 'Invalid CSRF token.';
            header('Location: login.php');
            exit;
        }

        $user_id = filter_input(INPUT_GET, 'delete', FILTER_VALIDATE_INT);
        if (!$user_id) {
            throw new Exception('Invalid user ID for deletion.', 400);
        }
        if ($user_id === $adminId) {
            throw new Exception('Cannot delete your own account.', 403);
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT Username FROM users WHERE User_id = ?");
        $stmt->execute([$user_id]);
        $username = $stmt->fetchColumn();
        if (!$username) {
            throw new Exception('User not found.', 404);
        }

        $stmt = $pdo->prepare("DELETE FROM users_department WHERE User_id = ?");
        $stmt->execute([$user_id]);

        $stmt = $pdo->prepare("DELETE FROM users WHERE User_id = ?");
        $stmt->execute([$user_id]);

        $stmt = $pdo->prepare("
            INSERT INTO transaction (User_id, Transaction_status, Transaction_type, Time, Massage)
            VALUES (?, 'completed', 22, NOW(), ?)
        ");
        $stmt->execute([$adminId, "Deleted user: $username"]);

        $pdo->commit();
        header('Location: user_management.php');
        exit;
    }

    $users = fetchAllUsers($pdo);
    $departments = fetchAllDepartments($pdo);
} catch (Exception $e) {
    error_log("Error in user_management.php: " . $e->getMessage(), 3, __DIR__ . '/logs/error_log.log');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        sendJsonResponse(false, 'Server error: ' . $e->getMessage(), [], $e->getCode() ?: 500);
    } else {
        $_SESSION['error'] = 'Server error: ' . $e->getMessage();
        header('Location: login.php');
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-Content-Type-Options" content="nosniff">
    <meta http-equiv="X-Frame-Options" content="DENY">
    <meta http-equiv="X-XSS-Protection" content="1; mode=block">
    <title>User Management - Admin Panel</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.css" integrity="sha512-hCAg8D0Ji4sG8M4rKEAy7kSOd0pH2j+1vV5f2jVrOjpV+LP2qF+81Tr5QUvA0D2eV2XJC+9cW9k3G4U3V0y2eA==" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="admin-sidebar.css">
    <link rel="stylesheet" href="admin-interface.css">
    <style>
        .main-content {
            padding: 20px;
            transition: margin-left 0.3s ease;
        }

        .error {
            color: #dc3545;
            font-weight: bold;
            margin-bottom: 10px;
        }

        .department-item,
        .selected-department {
            cursor: pointer;
            padding: 5px 10px;
            margin: 2px;
            border-radius: 4px;
        }

        .department-item:hover {
            background-color: #f0f0f0;
        }

        .department-item.selected {
            background-color: #007bff;
            color: white;
        }

        .selected-department {
            background-color: #e0e0e0;
            display: inline-flex;
            align-items: center;
            margin: 5px;
        }

        .remove-department {
            color: #dc3545;
            margin-left: 5px;
            font-weight: bold;
            cursor: pointer;
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
        }

        .modal-content {
            background-color: #fff;
            margin: 10% auto;
            padding: 20px;
            width: 90%;
            max-width: 500px;
            border-radius: 8px;
        }

        .close {
            float: right;
            font-size: 1.5em;
            cursor: pointer;
        }

        .profile-pic-upload {
            text-align: center;
            margin-bottom: 15px;
        }

        .profile-pic-upload img {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
        }

        .cropper-popup {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1001;
            align-items: center;
            justify-content: center;
        }

        .cropper-container {
            background-color: #fff;
            padding: 20px;
            border-radius: 8px;
            max-width: 600px;
        }

        .cropper-container img {
            max-width: 100%;
        }

        .table-container {
            margin-top: 20px;
        }

        .toggle-buttons button {
            padding: 8px 16px;
            margin-right: 5px;
        }

        .toggle-buttons .active {
            background-color: #007bff;
            color: white;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 10px;
            border: 1px solid #ddd;
            text-align: left;
        }

        th {
            background-color: #f4f4f4;
        }

        td img {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
        }

        .action-buttons a {
            margin-right: 5px;
        }

        .edit-btn,
        .delete-btn {
            padding: 5px 10px;
            border-radius: 4px;
        }

        .edit-btn {
            background-color: #007bff;
            color: white;
        }

        .delete-btn {
            background-color: #dc3545;
            color: white;
        }
    </style>
</head>

<body>
    <div class="sidebar">
        <button class="toggle-btn" title="Toggle Sidebar" aria-label="Toggle Sidebar"><i class="fas fa-bars"></i></button>
        <h2 class="sidebar-title">Admin Panel</h2>
        <a href="dashboard.php" data-tooltip="Switch to Client View"><i class="fas fa-exchange-alt"></i><span class="link-text">Switch to Client View</span></a>
        <a href="admin_dashboard.php" data-tooltip="Dashboard"><i class="fas fa-home"></i><span class="link-text">Dashboard</span></a>
        <a href="admin_search.php" data-tooltip="View All Files"><i class="fas fa-search"></i><span class="link-text">View All Files</span></a>
        <a href="user_management.php" class="active" data-tooltip="User Management"><i class="fas fa-users"></i><span class="link-text">User Management</span></a>
        <a href="department_management.php" data-tooltip="Department Management"><i class="fas fa-building"></i><span class="link-text">Department Management</span></a>
        <a href="physical_storage_management.php" data-tooltip="Physical Storage"><i class="fas fa-archive"></i><span class="link-text">Physical Storage</span></a>
        <a href="logout.php" class="logout-btn" data-tooltip="Logout"><i class="fas fa-sign-out-alt"></i><span class="link-text">Logout</span></a>
    </div>

    <div class="main-content sidebar-expanded">
        <h2>User Management</h2>
        <button id="open-modal-btn" class="open-modal-btn">Add User</button>
        <?php if (!empty($error)): ?>
            <p class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
        <div class="modal" id="user-modal">
            <div class="modal-content">
                <span class="close" aria-label="Close Modal">&times;</span>
                <h2><?php echo isset($_GET['edit']) ? 'Edit User' : 'Add User'; ?></h2>
                <form id="user-form" method="POST" action="user_management.php">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="<?php echo isset($_GET['edit']) ? 'edit' : 'add'; ?>">
                    <?php if (isset($_GET['edit'])): ?>
                        <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($_GET['edit'], ENT_QUOTES, 'UTF-8'); ?>">
                    <?php endif; ?>
                    <div class="profile-pic-upload">
                        <img id="profile-pic-preview" src="placeholder.jpg" alt="Profile Picture">
                        <input type="file" name="profile_pic" id="profile-pic-input" accept="image/*">
                        <label for="profile-pic-input">Upload Profile Picture</label>
                    </div>
                    <label for="username">Username (Full Name):</label>
                    <input type="text" name="username" id="username" placeholder="Username" required maxlength="255">
                    <label for="password">Password:<?php echo isset($_GET['edit']) ? ' (Leave blank to keep unchanged)' : ''; ?></label>
                    <input type="password" name="password" id="password" placeholder="Password" <?php echo isset($_GET['edit']) ? '' : 'required'; ?> minlength="8">
                    <label for="position">Position (Numeric):</label>
                    <input type="number" name="position" id="position" placeholder="Position" required min="0">
                    <label for="role">Role:</label>
                    <select name="role" id="role" required>
                        <option value="">Select Role</option>
                        <option value="admin">Admin</option>
                        <option value="client">Client</option>
                    </select>
                    <div class="department-selection">
                        <label>Departments (Required):</label>
                        <input type="text" id="dept-search" placeholder="Search departments..." class="search-input">
                        <div class="department-list">
                            <?php foreach ($departments as $dept): ?>
                                <div class="department-item" data-id="<?php echo htmlspecialchars($dept['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <span><?php echo htmlspecialchars($dept['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div id="selected-departments" class="selected-departments"></div>
                    <input type="hidden" name="departments" id="selected-departments-input">
                    <input type="hidden" name="cropped_image" id="cropped-image-input">
                    <button type="submit"><?php echo isset($_GET['edit']) ? 'Update User' : 'Add User'; ?></button>
                </form>
            </div>
        </div>

        <div class="table-container">
            <div class="toggle-buttons">
                <button id="toggle-all" class="active">All Users</button>
                <button id="toggle-admins">Admins</button>
                <button id="toggle-clients">Clients</button>
            </div>
            <table id="user-table">
                <thead>
                    <tr>
                        <th>Profile</th>
                        <th>Username</th>
                        <th>Position</th>
                        <th>Departments</th>
                        <th>Role</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr data-role="<?php echo htmlspecialchars($user['role'], ENT_QUOTES, 'UTF-8'); ?>">
                            <td>
                                <img src="<?php echo htmlspecialchars($user['profile_pic'] ? 'data:image/png;base64,' . base64_encode($user['profile_pic']) : 'placeholder.jpg', ENT_QUOTES, 'UTF-8'); ?>" alt="Profile">
                            </td>
                            <td><?php echo htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($user['position'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($user['department_names'] ?? 'No departments', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($user['role'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="action-buttons">
                                <a href="user_management.php?edit=<?php echo htmlspecialchars($user['id'], ENT_QUOTES, 'UTF-8'); ?>&csrf_token=<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                    <button class="edit-btn">Edit</button>
                                </a>
                                <a href="user_management.php?delete=<?php echo htmlspecialchars($user['id'], ENT_QUOTES, 'UTF-8'); ?>&csrf_token=<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" onclick="return confirm('Are you sure you want to delete this user?')">
                                    <button class="delete-btn">Delete</button>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="cropper-popup">
            <div class="cropper-container">
                <img id="cropper-image" />
                <button id="crop-button">Crop Image</button>
                <button id="cancel-button">Cancel</button>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.js" integrity="sha512-9K6N7sNnxO2y6Cw59kQ1GpqCYQ4vfV4tP7qFuAq0A8tI3b9u2Z9tI3K0xU7l01W4JZaG8jG+Ox49CD91F+x3r/g==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script>
        // Initialize DOM elements
        const profilePicInput = document.getElementById('profile-pic-input');
        const profilePicPreview = document.getElementById('profile-pic-preview');
        const cropperPopup = document.querySelector('.cropper-popup');
        const cropperImage = document.getElementById('cropper-image');
        const cropButton = document.getElementById('crop-button');
        const cancelButton = document.getElementById('cancel-button');
        const croppedImageInput = document.getElementById('cropped-image-input');
        const toggleAll = document.getElementById('toggle-all');
        const toggleAdmins = document.getElementById('toggle-admins');
        const toggleClients = document.getElementById('toggle-clients');
        const userTableRows = document.querySelectorAll('#user-table tbody tr');
        const modal = document.getElementById('user-modal');
        const openModalBtn = document.getElementById('open-modal-btn');
        const closeModal = modal.querySelector('.close');
        const userForm = document.getElementById('user-form');
        const deptSearch = document.getElementById('dept-search');
        const departmentItems = document.querySelectorAll('.department-item');
        const selectedDepartmentsContainer = document.getElementById('selected-departments');
        const selectedDepartmentsInput = document.getElementById('selected-departments-input');
        let selectedDepartments = new Set();
        let cropper = null;

        // Handle profile picture upload and cropping
        function initializeProfilePictureCropper() {
            profilePicInput.addEventListener('change', function(event) {
                const file = event.target.files[0];
                if (file) {
                    if (file.size > 1024 * 1024) {
                        alert('Image size exceeds 1MB.');
                        return;
                    }
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        cropperPopup.style.display = 'flex';
                        cropperImage.src = e.target.result;
                        if (cropper) cropper.destroy();
                        cropper = new Cropper(cropperImage, {
                            aspectRatio: 1,
                            viewMode: 1,
                            autoCropArea: 0.8,
                        });
                    };
                    reader.readAsDataURL(file);
                }
            });

            cropButton.addEventListener('click', function() {
                if (cropper) {
                    const croppedCanvas = cropper.getCroppedCanvas({
                        width: 150,
                        height: 150
                    });
                    const croppedImage = croppedCanvas.toDataURL('image/png');
                    profilePicPreview.src = croppedImage;
                    croppedImageInput.value = croppedImage;
                    cropperPopup.style.display = 'none';
                    cropper.destroy();
                    cropper = null;
                }
            });

            cancelButton.addEventListener('click', function() {
                cropperPopup.style.display = 'none';
                if (cropper) {
                    cropper.destroy();
                    cropper = null;
                }
                profilePicInput.value = '';
            });
        }

        // Handle modal visibility
        function initializeModal() {
            openModalBtn.addEventListener('click', () => {
                modal.style.display = 'block';
                resetForm();
            });

            closeModal.addEventListener('click', () => {
                modal.style.display = 'none';
                resetForm();
            });

            window.addEventListener('click', (event) => {
                if (event.target === modal) {
                    modal.style.display = 'none';
                    resetForm();
                }
            });
        }

        // Handle department selection
        function initializeDepartmentSelection() {
            deptSearch.addEventListener('input', function() {
                const query = this.value.toLowerCase();
                departmentItems.forEach(item => {
                    const name = item.querySelector('span').textContent.toLowerCase();
                    item.style.display = name.includes(query) ? 'block' : 'none';
                });
            });

            departmentItems.forEach(item => {
                item.addEventListener('click', () => {
                    const deptId = item.getAttribute('data-id');
                    toggleDepartmentSelection(deptId, item);
                });
            });
        }

        function toggleDepartmentSelection(deptId, item) {
            if (selectedDepartments.has(deptId)) {
                selectedDepartments.delete(deptId);
                item.classList.remove('selected');
                selectedDepartmentsContainer.querySelector(`[data-department-id="${deptId}"]`)?.remove();
            } else {
                selectedDepartments.add(deptId);
                item.classList.add('selected');
                const selectedItem = document.createElement('div');
                selectedItem.className = 'selected-department';
                selectedItem.setAttribute('data-department-id', deptId);
                selectedItem.innerHTML = `${item.querySelector('span').textContent} <span class="remove-department">×</span>`;
                selectedDepartmentsContainer.appendChild(selectedItem);

                selectedItem.querySelector('.remove-department').addEventListener('click', () => {
                    selectedDepartments.delete(deptId);
                    item.classList.remove('selected');
                    selectedItem.remove();
                    updateSelectedDepartmentsInput();
                });
            }
            updateSelectedDepartmentsInput();
        }

        function updateSelectedDepartmentsInput() {
            selectedDepartmentsInput.value = Array.from(selectedDepartments).join(',');
        }

        function resetForm() {
            selectedDepartments.clear();
            departmentItems.forEach(item => item.classList.remove('selected'));
            selectedDepartmentsContainer.innerHTML = '';
            selectedDepartmentsInput.value = '';
            userForm.reset();
            profilePicPreview.src = 'placeholder.jpg';
            croppedImageInput.value = '';
        }

        // Handle table filtering
        function initializeTableFiltering() {
            function filterTable(role) {
                userTableRows.forEach(row => {
                    row.style.display = (role === 'all' || row.getAttribute('data-role') === role) ? '' : 'none';
                });
            }

            toggleAll.addEventListener('click', () => {
                filterTable('all');
                toggleAll.classList.add('active');
                toggleAdmins.classList.remove('active');
                toggleClients.classList.remove('active');
            });

            toggleAdmins.addEventListener('click', () => {
                filterTable('admin');
                toggleAll.classList.remove('active');
                toggleAdmins.classList.add('active');
                toggleClients.classList.remove('active');
            });

            toggleClients.addEventListener('click', () => {
                filterTable('client');
                toggleAll.classList.remove('active');
                toggleAdmins.classList.remove('active');
                toggleClients.classList.add('active');
            });
        }

        // Handle form submission via AJAX
        function initializeFormSubmission() {
            userForm.addEventListener('submit', function(event) {
                event.preventDefault();
                const formData = new FormData(this);
                fetch('user_management.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert(data.message);
                            window.location.href = 'user_management.php';
                        } else {
                            const errorElement = document.createElement('p');
                            errorElement.className = 'error';
                            errorElement.textContent = data.message;
                            modal.querySelector('.modal-content').insertBefore(errorElement, userForm);
                            setTimeout(() => errorElement.remove(), 5000);
                        }
                    })
                    .catch(error => {
                        console.error('Form submission error:', error);
                        const errorElement = document.createElement('p');
                        errorElement.className = 'error';
                        errorElement.textContent = 'An error occurred while processing the request.';
                        modal.querySelector('.modal-content').insertBefore(errorElement, userForm);
                        setTimeout(() => errorElement.remove(), 5000);
                    });
            });
        }

        // Prefill form for editing
        function initializeEditPrefill() {
            <?php if (isset($_GET['edit'])): ?>
                modal.style.display = 'block';
                fetch(`get_user_data.php?user_id=<?php echo htmlspecialchars($_GET['edit'], ENT_QUOTES, 'UTF-8'); ?>&csrf_token=<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>`)
                    .then(response => response.json())
                    .then(data => {
                        console.log('Fetch user data response:', data);
                        if (data.success) {
                            const user = data.data.user;
                            document.getElementById('username').value = user.username;
                            document.getElementById('position').value = user.position;
                            document.getElementById('role').value = user.role;
                            if (user.profile_pic) {
                                profilePicPreview.src = `data:image/png;base64,${user.profile_pic}`;
                            }

                            user.departments.forEach(dept => {
                                const item = document.querySelector(`.department-item[data-id="${dept.department_id}"]`);
                                if (item) {
                                    selectedDepartments.add(dept.department_id);
                                    item.classList.add('selected');
                                    const selectedItem = document.createElement('div');
                                    selectedItem.className = 'selected-department';
                                    selectedItem.setAttribute('data-department-id', dept.department_id);
                                    selectedItem.innerHTML = `${item.querySelector('span').textContent} <span class="remove-department">×</span>`;
                                    selectedDepartmentsContainer.appendChild(selectedItem);
                                    selectedItem.querySelector('.remove-department').addEventListener('click', () => {
                                        selectedDepartments.delete(dept.department_id);
                                        item.classList.remove('selected');
                                        selectedItem.remove();
                                        updateSelectedDepartmentsInput();
                                    });
                                }
                            });

                            updateSelectedDepartmentsInput();
                        } else {
                            alert('Failed to load user data: ' + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching user data:', error);
                        alert('An error occurred while loading user data.');
                    });
            <?php endif; ?>
        }

        // Initialize sidebar toggle
        function initializeSidebarToggle() {
            document.querySelector('.toggle-btn').addEventListener('click', () => {
                document.querySelector('.sidebar').classList.toggle('minimized');
                document.querySelector('.main-content').classList.toggle('sidebar-expanded');
                document.querySelector('.main-content').classList.toggle('sidebar-minimized');
            });
        }

        // Initialize all functionality
        document.addEventListener('DOMContentLoaded', () => {
            initializeProfilePictureCropper();
            initializeModal();
            initializeDepartmentSelection();
            initializeTableFiltering();
            initializeFormSubmission();
            initializeEditPrefill();
            initializeSidebarToggle();
        });
    </script>
</body>

</html>