<?php

declare(strict_types=1);
session_start();
require 'db_connection.php';

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Content-Security-Policy: default-src \'self\'; script-src \'self\' https://code.jquery.com https://cdn.jsdelivr.net; style-src \'self\' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src https://fonts.gstatic.com;');

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

$error = isset($_SESSION['error']) ? $_SESSION['error'] : "";
$success = isset($_SESSION['success']) ? $_SESSION['success'] : "";
unset($_SESSION['error'], $_SESSION['success']); // Clear messages after display

// Valid department types and name types
const VALID_DEPARTMENT_TYPES = ['college', 'office', 'sub_department'];
const VALID_NAME_TYPES = ['Academic', 'Administrative', 'Program'];

// Handle form submission for adding/editing departments
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && validateCsrfToken($_POST['csrf_token'] ?? '')) {
    $action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

    if ($action === 'add_dept' || $action === 'edit_dept') {
        $department_id = isset($_POST['department_id']) ? filter_var($_POST['department_id'], FILTER_VALIDATE_INT) : null;
        $name = trim(filter_input(INPUT_POST, 'name', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
        $type = trim(filter_input(INPUT_POST, 'type', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
        $name_type = ($type === 'college') ? 'Academic' : 'Administrative';

        if (empty($name) || empty($type) || !in_array($type, ['college', 'office'])) {
            $error = "Department name and valid type (college or office) are required.";
            logTransaction($pdo, $userId, 'Failure', 8, $error);
        } elseif (!in_array($name_type, VALID_NAME_TYPES)) {
            $error = "Invalid name type for department.";
            logTransaction($pdo, $userId, 'Failure', 8, $error);
        } else {
            // Check for duplicate department name
            $checkStmt = executeQuery(
                $pdo,
                "SELECT Department_id FROM departments WHERE Department_name = ? AND Department_id != ? AND Department_type IN ('college', 'office')",
                [$name, $department_id ?? 0]
            );
            if ($checkStmt && $checkStmt->rowCount() > 0) {
                $error = "Department name already exists.";
                logTransaction($pdo, $userId, 'Failure', 8, $error);
            } else {
                if ($action === 'add_dept') {
                    $stmt = executeQuery(
                        $pdo,
                        "INSERT INTO departments (Department_name, Department_type, Name_type, Parent_department_id) VALUES (?, ?, ?, NULL)",
                        [$name, $type, $name_type]
                    );
                    $message = "Added department: $name";
                    $transType = 8;
                } elseif ($action === 'edit_dept' && $department_id) {
                    $stmt = executeQuery(
                        $pdo,
                        "UPDATE departments SET Department_name = ?, Department_type = ?, Name_type = ? WHERE Department_id = ? AND Department_type IN ('college', 'office')",
                        [$name, $type, $name_type, $department_id]
                    );
                    $message = "Updated department: $name";
                    $transType = 9;
                }

                if ($stmt) {
                    $_SESSION['success'] = $message;
                    logTransaction($pdo, $userId, 'Success', $transType, $message);
                    header("Location: department_management.php");
                    exit();
                } else {
                    $error = "Failed to " . ($action === 'add_dept' ? "add" : "update") . " department.";
                    logTransaction($pdo, $userId, 'Failure', $transType, $error);
                }
            }
        }
    } elseif ($action === 'add_subdept') {
        $name = trim(filter_input(INPUT_POST, 'name', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
        $parent_dept_id = filter_var($_POST['parent_dept_id'], FILTER_VALIDATE_INT);
        $name_type = 'Program';

        if (empty($name) || !$parent_dept_id) {
            $error = "Subdepartment name and parent department are required.";
            logTransaction($pdo, $userId, 'Failure', 10, $error);
        } elseif (!in_array($name_type, VALID_NAME_TYPES)) {
            $error = "Invalid name type for subdepartment.";
            logTransaction($pdo, $userId, 'Failure', 10, $error);
        } else {
            // Validate parent department
            $parentStmt = executeQuery(
                $pdo,
                "SELECT Department_id FROM departments WHERE Department_id = ? AND Department_type IN ('college', 'office')",
                [$parent_dept_id]
            );
            if (!$parentStmt || $parentStmt->rowCount() === 0) {
                $error = "Invalid parent department selected.";
                logTransaction($pdo, $userId, 'Failure', 10, $error);
            } else {
                // Check for duplicate subdepartment name within parent
                $checkStmt = executeQuery(
                    $pdo,
                    "SELECT Department_id FROM departments WHERE Department_name = ? AND Parent_department_id = ? AND Department_type = 'sub_department'",
                    [$name, $parent_dept_id]
                );
                if ($checkStmt && $checkStmt->rowCount() > 0) {
                    $error = "Subdepartment name already exists under this parent department.";
                    logTransaction($pdo, $userId, 'Failure', 10, $error);
                } else {
                    $stmt = executeQuery(
                        $pdo,
                        "INSERT INTO departments (Department_name, Department_type, Name_type, Parent_department_id) VALUES (?, 'sub_department', ?, ?)",
                        [$name, $name_type, $parent_dept_id]
                    );
                    if ($stmt) {
                        $_SESSION['success'] = "Added subdepartment: $name under parent ID: $parent_dept_id";
                        logTransaction($pdo, $userId, 'Success', 10, $_SESSION['success']);
                        header("Location: department_management.php");
                        exit();
                    } else {
                        $error = "Failed to add subdepartment.";
                        logTransaction($pdo, $userId, 'Failure', 10, $error);
                    }
                }
            }
        }
    }
    $_SESSION['error'] = $error; // Store error for display after redirect
}

// Handle department deletion
if (isset($_GET['delete_dept']) && validateCsrfToken($_GET['csrf_token'] ?? '')) {
    $department_id = filter_var($_GET['delete_dept'], FILTER_VALIDATE_INT);
    if ($department_id) {
        // Check if department has subdepartments or users
        $checkSubStmt = executeQuery(
            $pdo,
            "SELECT Department_id FROM departments WHERE Parent_department_id = ?",
            [$department_id]
        );
        $checkUsersStmt = executeQuery(
            $pdo,
            "SELECT Users_Department_id FROM users_department WHERE Department_id = ?",
            [$department_id]
        );

        if ($checkSubStmt && $checkSubStmt->rowCount() > 0) {
            $_SESSION['error'] = "Cannot delete department with subdepartments.";
            logTransaction($pdo, $userId, 'Failure', 11, $_SESSION['error']);
        } elseif ($checkUsersStmt && $checkUsersStmt->rowCount() > 0) {
            $_SESSION['error'] = "Cannot delete department with assigned users.";
            logTransaction($pdo, $userId, 'Failure', 11, $_SESSION['error']);
        } else {
            $stmt = executeQuery(
                $pdo,
                "DELETE FROM departments WHERE Department_id = ?",
                [$department_id]
            );
            if ($stmt) {
                $_SESSION['success'] = "Deleted department ID: $department_id";
                logTransaction($pdo, $userId, 'Success', 11, $_SESSION['success']);
            } else {
                $_SESSION['error'] = "Failed to delete department.";
                logTransaction($pdo, $userId, 'Failure', 11, $_SESSION['error']);
            }
        }
        header("Location: department_management.php");
        exit();
    } else {
        $_SESSION['error'] = "Invalid department ID.";
        logTransaction($pdo, $userId, 'Failure', 11, $_SESSION['error']);
    }
}

// Fetch all parent departments
$departmentsStmt = executeQuery(
    $pdo,
    "SELECT Department_id, Department_name, Department_type, Name_type FROM departments WHERE Department_type IN ('college', 'office') AND Parent_department_id IS NULL ORDER BY Department_name ASC"
);
$departments = $departmentsStmt ? $departmentsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

// Fetch all subdepartments with parent department names
$subdepartmentsStmt = executeQuery(
    $pdo,
    "SELECT d1.Department_id, d1.Department_name AS subdepartment_name, d1.Parent_department_id, d1.Name_type, d2.Department_name AS parent_dept_name
     FROM departments d1
     LEFT JOIN departments d2 ON d1.Parent_department_id = d2.Department_id
     WHERE d1.Department_type = 'sub_department'
     ORDER BY d2.Department_name, d1.Department_name ASC"
);
$subdepartments = $subdepartmentsStmt ? $subdepartmentsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Department Management - Arc-Hive</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" integrity="sha512-Kc323vGBEqzTmouAECnVceyQqyqdsSiqLQISBL29aUW4U/M7pSPA/gEUZQqv1cwx4OnYxTxve5UMg5GT6L4JJg==" crossorigin="anonymous">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.css">
    <link rel="stylesheet" href="admin-sidebar.css">
    <link rel="stylesheet" href="style/department_management.css">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.js"></script>
</head>

<body class="department-management">
    <!-- Admin Sidebar -->
    <div class="sidebar">
        <button class="toggle-btn" title="Toggle Sidebar" aria-label="Toggle Sidebar"><i class="fas fa-bars"></i></button>
        <h2 class="sidebar-title">Admin Panel</h2>
        <a href="dashboard.php" class="client-btn"><i class="fas fa-exchange-alt"></i><span class="link-text">Switch to Client View</span></a>
        <a href="admin_dashboard.php"><i class="fas fa-home"></i><span class="link-text">Dashboard</span></a>
        <a href="admin_search.php"><i class="fas fa-search"></i><span class="link-text">View All Files</span></a>
        <a href="user_management.php"><i class="fas fa-users"></i><span class="link-text">User Management</span></a>
        <a href="department_management.php" class="active"><i class="fas fa-building"></i><span class="link-text">Department Management</span></a>
        <a href="physical_storage_management.php"><i class="fas fa-archive"></i><span class="link-text">Physical Storage</span></a>
        <a href="document_type_management.php"><i class="fas fa-file-alt"></i><span class="link-text">Document Type Management</span></a>
        <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i><span class="link-text">Logout</span></a>
    </div>

    <!-- Main Content -->
    <div class="main-content sidebar-expanded">
        <!-- CSRF Token -->
        <input type="hidden" id="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">

        <!-- Header -->
        <header class="page-header">
            <h1>Department Management</h1>
            <p>Manage departments and subdepartments for efficient organization.</p>
        </header>

        <!-- Action Buttons -->
        <div class="action-buttons-container">
            <button id="open-dept-modal-btn" class="open-modal-btn" aria-label="Add New Department"><i class="fas fa-plus"></i> Add Department</button>
            <button id="open-subdept-modal-btn" class="open-modal-btn" aria-label="Add New Subdepartment"><i class="fas fa-plus"></i> Add Subdepartment</button>
        </div>

        <!-- Messages -->
        <?php if (!empty($error)) { ?>
            <div class="error-message" role="alert"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        <?php } ?>
        <?php if (!empty($success)) { ?>
            <div class="success-message" role="alert"><?= htmlspecialchars($success, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        <?php } ?>

        <!-- Departments Display -->
        <div class="table-container">
            <h2>Departments & Subdepartments</h2>
            <?php if (empty($departments)) { ?>
                <p class="no-data">No departments found. Add a department to get started.</p>
            <?php } else { ?>
                <?php foreach ($departments as $dept) { ?>
                    <div class="dept-section">
                        <div class="dept-header">
                            <h3><?php echo htmlspecialchars($dept['Department_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                (<?php echo htmlspecialchars($dept['Department_type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>,
                                <?php echo htmlspecialchars($dept['Name_type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>)
                            </h3>
                            <div class="action-buttons">
                                <a href="department_management.php?edit_dept=<?php echo htmlspecialchars((string)$dept['Department_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>&csrf_token=<?php echo htmlspecialchars(generateCsrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                    class="edit-btn"
                                    aria-label="Edit <?php echo htmlspecialchars($dept['Department_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                                <button class="delete-btn"
                                    onclick="confirmDelete(<?php echo htmlspecialchars((string)$dept['Department_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>)"
                                    aria-label="Delete <?php echo htmlspecialchars($dept['Department_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                                <?php
                                $subdepts = array_filter($subdepartments, fn($sd) => $sd['Parent_department_id'] == $dept['Department_id']);
                                ?>
                                <div class="dropdown">
                                    <button class="dropdown-btn" aria-haspopup="true" aria-expanded="false" aria-label="View subdepartments for <?php echo htmlspecialchars($dept['Department_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                                        <i class="fas fa-sitemap"></i> Hierarchy
                                    </button>
                                    <div class="dropdown-content">
                                        <div class="dropdown-header">
                                            <span><?php echo htmlspecialchars($dept['Department_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                                        </div>
                                        <?php if (empty($subdepts)) { ?>
                                            <div class="dropdown-item no-subdepts">
                                                <span>No subdepartments</span>
                                            </div>
                                        <?php } else { ?>
                                            <?php foreach ($subdepts as $subdept) { ?>
                                                <div class="dropdown-item subdept-item">
                                                    <span><i class="fas fa-angle-right"></i> <?php echo htmlspecialchars($subdept['subdepartment_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                                                    <button class="delete-btn"
                                                        onclick="confirmDelete(<?php echo htmlspecialchars((string)$subdept['Department_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>)"
                                                        aria-label="Delete <?php echo htmlspecialchars($subdept['subdepartment_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            <?php } ?>
                                        <?php } ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php } ?>
            <?php } ?>
        </div>

        <!-- Department Modal -->
        <div class="modal" id="dept-modal" role="dialog" aria-labelledby="dept-modal-title" aria-modal="true">
            <div class="modal-content">
                <span class="close" aria-label="Close Modal">×</span>
                <h2 id="dept-modal-title"><?php echo isset($_GET['edit_dept']) ? 'Edit Department' : 'Add Department'; ?></h2>
                <form method="POST" action="department_management.php" class="modal-form">
                    <input type="hidden" name="action" value="<?php echo isset($_GET['edit_dept']) ? 'edit_dept' : 'add_dept'; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                    <?php if (isset($_GET['edit_dept'])) {
                        $editDeptId = filter_var($_GET['edit_dept'], FILTER_VALIDATE_INT);
                        $editStmt = executeQuery($pdo, "SELECT Department_name, Department_type, Name_type FROM departments WHERE Department_id = ? AND Department_type IN ('college', 'office')", [$editDeptId]);
                        $editDept = $editStmt ? $editStmt->fetch(PDO::FETCH_ASSOC) : null;
                    ?>
                        <input type="hidden" name="department_id" value="<?php echo htmlspecialchars((string)$editDeptId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                        <div class="form-group">
                            <label for="dept-name">Department Name</label>
                            <input type="text" id="dept-name" name="name" placeholder="Enter department name"
                                value="<?php echo htmlspecialchars($editDept['Department_name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                required aria-required="true">
                        </div>
                        <div class="form-group">
                            <label for="dept-type">Type</label>
                            <select id="dept-type" name="type" required aria-required="true">
                                <option value="college" <?php echo ($editDept['Department_type'] ?? '') === 'college' ? 'selected' : ''; ?>>College</option>
                                <option value="office" <?php echo ($editDept['Department_type'] ?? '') === 'office' ? 'selected' : ''; ?>>Office</option>
                            </select>
                        </div>
                    <?php } else { ?>
                        <div class="form-group">
                            <label for="dept-name">Department Name</label>
                            <input type="text" id="dept-name" name="name" placeholder="Enter department name" required aria-required="true">
                        </div>
                        <div class="form-group">
                            <label for="dept-type">Type</label>
                            <select id="dept-type" name="type" required aria-required="true">
                                <option value="" disabled selected>Select Type</option>
                                <option value="college">College</option>
                                <option value="office">Office</option>
                            </select>
                        </div>
                    <?php } ?>
                    <button type="submit" class="submit-btn"><?php echo isset($_GET['edit_dept']) ? 'Update Department' : 'Add Department'; ?></button>
                </form>
            </div>
        </div>

        <!-- Subdepartment Modal -->
        <div class="modal" id="subdept-modal" role="dialog" aria-labelledby="subdept-modal-title" aria-modal="true">
            <div class="modal-content">
                <span class="close" aria-label="Close Modal">×</span>
                <h2 id="subdept-modal-title">Add Subdepartment</h2>
                <form method="POST" action="department_management.php" class="modal-form">
                    <input type="hidden" name="action" value="add_subdept">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                    <div class="form-group">
                        <label for="subdept-name">Subdepartment Name</label>
                        <input type="text" id="subdept-name" name="name" placeholder="Enter subdepartment name" required aria-required="true">
                    </div>
                    <div class="form-group">
                        <label for="parent-dept">Parent Department</label>
                        <select id="parent-dept" name="parent_dept_id" required aria-required="true">
                            <option value="" disabled selected>Select Parent Department</option>
                            <?php foreach ($departments as $dept) { ?>
                                <option value="<?php echo htmlspecialchars((string)$dept['Department_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars($dept['Department_name'] . ' (' . $dept['Department_type'] . ')', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>
                    <button type="submit" class="submit-btn">Add Subdepartment</button>
                </form>
            </div>
        </div>

        <!-- Warning Modal for Deletion -->
        <div class="modal warning-modal" id="warning-delete-modal" role="dialog" aria-labelledby="warning-modal-title" aria-modal="true">
            <div class="warning-modal-content">
                <span class="close" aria-label="Close Modal">×</span>
                <h2 id="warning-modal-title">Confirm Deletion</h2>
                <p>Are you sure you want to delete this department/subdepartment? This action cannot be undone.</p>
                <div class="buttons">
                    <button class="confirm-btn" id="confirm-delete">Yes</button>
                    <button class="cancel-btn">No</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Initialize Notyf for notifications
        const notyf = new Notyf({
            duration: 5000,
            position: {
                x: 'right',
                y: 'top'
            },
            ripple: true
        });

        document.addEventListener('DOMContentLoaded', () => {
            // Show persisted messages
            <?php if (!empty($error)) { ?>
                notyf.error('<?php echo htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>');
            <?php } ?>
            <?php if (!empty($success)) { ?>
                notyf.success('<?php echo htmlspecialchars($success, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>');
            <?php } ?>

            // Sidebar toggle
            const sidebar = document.querySelector('.sidebar');
            const mainContent = document.querySelector('.main-content');
            const toggleBtn = document.querySelector('.toggle-btn');
            if (toggleBtn) {
                toggleBtn.addEventListener('click', () => {
                    sidebar.classList.toggle('minimized');
                    mainContent.classList.toggle('sidebar-expanded');
                    mainContent.classList.toggle('sidebar-minimized');
                });
            }

            // Modal handling
            const modals = {
                dept: document.getElementById('dept-modal'),
                subdept: document.getElementById('subdept-modal'),
                warning: document.getElementById('warning-delete-modal')
            };

            const openModal = (modalId) => {
                const modal = modals[modalId];
                if (modal) {
                    modal.style.display = 'flex';
                    modal.querySelector('.modal-content')?.focus();
                }
            };

            const closeModal = (modalId) => {
                const modal = modals[modalId];
                if (modal) {
                    modal.style.display = 'none';
                }
            };

            // Department Modal
            const openDeptModalBtn = document.getElementById('open-dept-modal-btn');
            if (openDeptModalBtn) {
                openDeptModalBtn.addEventListener('click', () => openModal('dept'));
            }

            // Subdepartment Modal
            const openSubdeptModalBtn = document.getElementById('open-subdept-modal-btn');
            if (openSubdeptModalBtn) {
                openSubdeptModalBtn.addEventListener('click', () => openModal('subdept'));
            }

            // Close modals
            document.querySelectorAll('.modal .close').forEach(btn => {
                btn.addEventListener('click', () => {
                    const modal = btn.closest('.modal');
                    closeModal(modal.id.split('-')[0]);
                });
            });

            // Close modals when clicking outside
            window.addEventListener('click', (event) => {
                Object.keys(modals).forEach(key => {
                    if (event.target === modals[key]) {
                        closeModal(key);
                    }
                });
            });

            // Auto-open modal for editing
            <?php if (isset($_GET['edit_dept'])) { ?>
                openModal('dept');
            <?php } ?>

            // Dropdown handling
            document.querySelectorAll('.dropdown-btn').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const dropdownContent = btn.nextElementSibling;
                    const isOpen = dropdownContent.classList.contains('show');

                    // Close all other dropdowns
                    document.querySelectorAll('.dropdown-content.show').forEach(content => {
                        content.classList.remove('show');
                        content.previousElementSibling.setAttribute('aria-expanded', 'false');
                    });

                    // Toggle current dropdown
                    if (!isOpen) {
                        dropdownContent.classList.add('show');
                        btn.setAttribute('aria-expanded', 'true');
                    }
                });

                // Keyboard support for dropdown
                btn.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        btn.click();
                    }
                });
            });

            // Close dropdowns when clicking outside
            document.addEventListener('click', (e) => {
                if (!e.target.closest('.dropdown')) {
                    document.querySelectorAll('.dropdown-content.show').forEach(content => {
                        content.classList.remove('show');
                        content.previousElementSibling.setAttribute('aria-expanded', 'false');
                    });
                }
            });

            // Close dropdowns with Escape key
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    document.querySelectorAll('.dropdown-content.show').forEach(content => {
                        content.classList.remove('show');
                        content.previousElementSibling.setAttribute('aria-expanded', 'false');
                    });
                }
            });

            // Form submission with loading state
            document.querySelectorAll('.modal-form').forEach(form => {
                form.addEventListener('submit', (e) => {
                    const submitBtn = form.querySelector('.submit-btn');
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                    }
                    const csrfToken = document.getElementById('csrf_token')?.value;
                    if (!csrfToken) {
                        e.preventDefault();
                        notyf.error('CSRF token missing');
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = form.querySelector('input[name="action"]').value === 'add_dept' ? 'Add Department' : 'Update Department';
                        }
                    }
                });
            });

            // Deletion confirmation
            let pendingDeptId = null;

            window.confirmDelete = (deptId) => {
                pendingDeptId = deptId;
                openModal('warning');
            };

            const confirmDeleteBtn = document.getElementById('confirm-delete');
            if (confirmDeleteBtn) {
                confirmDeleteBtn.addEventListener('click', () => {
                    if (pendingDeptId !== null) {
                        const csrfToken = document.getElementById('csrf_token')?.value;
                        window.location.href = `department_management.php?delete_dept=${pendingDeptId}&csrf_token=${encodeURIComponent(csrfToken)}`;
                    }
                    closeModal('warning');
                    pendingDeptId = null;
                });
            }

            const cancelWarningBtn = document.querySelector('.warning-modal .cancel-btn');
            if (cancelWarningBtn) {
                cancelWarningBtn.addEventListener('click', () => closeModal('warning'));
            }

            // Keyboard navigation for modals
            document.querySelectorAll('.modal').forEach(modal => {
                modal.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape') {
                        closeModal(modal.id.split('-')[0]);
                    }
                });
            });
        });
    </script>
</body>

</html>