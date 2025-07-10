<?php
session_start();
require 'db_connection.php';

// Check if the user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

$error = "";

// Handle form submission for adding/editing departments
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'add_dept' || $action === 'edit_dept') {
        $department_id = isset($_POST['department_id']) ? (int)$_POST['department_id'] : null;
        $name = trim(filter_input(INPUT_POST, 'name', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
        $type = trim(filter_input(INPUT_POST, 'type', FILTER_SANITIZE_FULL_SPECIAL_CHARS));

        if (empty($name) || empty($type)) {
            $error = "Department name and type are required.";
        } else {
            if ($action === 'add_dept') {
                $stmt = $pdo->prepare("INSERT INTO departments (name, type) VALUES (?, ?)");
                $success = $stmt->execute([$name, $type]);
            } elseif ($action === 'edit_dept') {
                $stmt = $pdo->prepare("UPDATE departments SET name = ?, type = ? WHERE id = ?");
                $success = $stmt->execute([$name, $type, $department_id]);
            }

            if ($success) {
                header("Location: department_management.php");
                exit();
            } else {
                $error = "Failed to " . ($action === 'add_dept' ? "add" : "update") . " department.";
            }
        }
    } elseif ($action === 'add_subdept') {
        $name = trim(filter_input(INPUT_POST, 'name', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
        $department_id = (int)$_POST['department_id'];

        if (empty($name) || empty($department_id)) {
            $error = "Subdepartment name and department are required.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO sub_departments (name, department_id) VALUES (?, ?)");
            $success = $stmt->execute([$name, $department_id]);

            if ($success) {
                header("Location: department_management.php");
                exit();
            } else {
                $error = "Failed to add subdepartment.";
            }
        }
    }
}

// Handle department deletion
if (isset($_GET['delete_dept'])) {
    $department_id = (int)$_GET['delete_dept'];
    $stmt = $pdo->prepare("DELETE FROM departments WHERE id = ?");
    if ($stmt->execute([$department_id])) {
        header("Location: department_management.php");
        exit();
    } else {
        $error = "Failed to delete department.";
    }
}

// Handle subdepartment deletion
if (isset($_GET['delete_subdept'])) {
    $subdept_id = (int)$_GET['delete_subdept'];
    $stmt = $pdo->prepare("DELETE FROM sub_departments WHERE id = ?");
    if ($stmt->execute([$subdept_id])) {
        header("Location: department_management.php");
        exit();
    } else {
        $error = "Failed to delete subdepartment.";
    }
}

// Fetch all departments
$departments = $pdo->query("SELECT * FROM departments ORDER BY type, name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch all subdepartments with department names
$subdepartments = $pdo->query("
    SELECT sd.id, sd.name AS subdepartment_name, d.id AS department_id, d.name AS department_name
    FROM sub_departments sd
    JOIN departments d ON sd.department_id = d.id
    ORDER BY d.name, sd.name ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Department Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="admin-sidebar.css">
    <link rel="stylesheet" href="admin-interface.css">
    <style>
        .main-content {
            padding: 20px;
        }

        .error-message {
            color: red;
            margin-bottom: 15px;
        }

        .table-container {
            margin-bottom: 30px;
        }

        .dept-section {
            margin-bottom: 20px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }

        .dept-header {
            background: #f5f5f5;
            padding: 10px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .dept-header h3 {
            margin: 0;
        }

        .subdept-table {
            display: none;
            margin: 10px;
        }

        .subdept-table table {
            width: 100%;
            border-collapse: collapse;
        }

        .subdept-table th,
        .subdept-table td {
            padding: 8px;
            border: 1px solid #ddd;
        }

        .action-buttons {
            display: flex;
            gap: 5px;
        }

        .modal-content {
            padding: 20px;
            max-width: 500px;
        }

        .modal-content select,
        .modal-content input {
            width: 100%;
            padding: 8px;
            margin-bottom: 10px;
        }

        .open-modal-btn {
            margin-right: 10px;
            margin-bottom: 15px;
        }
    </style>
</head>

<body>
    <!-- Admin Sidebar -->
    <div class="sidebar">
        <button class="toggle-btn" title="Toggle Sidebar"><i class="fas fa-bars"></i></button>
        <h2 class="sidebar-title">Admin Panel</h2>
        <a href="index.php" class="client-btn"><i class="fas fa-exchange-alt"></i><span class="link-text">Switch to Client View</span></a>
        <a href="admin_dashboard.php"><i class="fas fa-home"></i><span class="link-text">Dashboard</span></a>
        <a href="admin_search.php"><i class="fas fa-search"></i><span class="link-text">View All Files</span></a>
        <a href="user_management.php"><i class="fas fa-users"></i><span class="link-text">User Management</span></a>
        <a href="department_management.php" class="active"><i class="fas fa-building"></i><span class="link-text">Department Management</span></a>
        <a href="physical_storage_management.php"><i class="fas fa-archive"></i><span class="link-text">Physical Storage</span></a>
        <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i><span class="link-text">Logout</span></a>
    </div>

    <!-- Main Content -->
    <div class="main-content sidebar-expanded">
        <?php if (!empty($error)): ?>
            <div class="error-message"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <button id="open-dept-modal-btn" class="open-modal-btn">Add Department</button>
        <button id="open-subdept-modal-btn" class="open-modal-btn">Add Subdepartment</button>

        <!-- Department Modal -->
        <div class="modal" id="dept-modal">
            <div class="modal-content">
                <span class="close">&times;</span>
                <h2><?= isset($_GET['edit_dept']) ? 'Edit Department' : 'Add Department' ?></h2>
                <form method="POST" action="department_management.php">
                    <input type="hidden" name="action" value="<?= isset($_GET['edit_dept']) ? 'edit_dept' : 'add_dept' ?>">
                    <?php if (isset($_GET['edit_dept'])): ?>
                        <input type="hidden" name="department_id" value="<?= htmlspecialchars($_GET['edit_dept']) ?>">
                        <?php
                        $editDeptId = (int)$_GET['edit_dept'];
                        $stmt = $pdo->prepare("SELECT name, type FROM departments WHERE id = ?");
                        $stmt->execute([$editDeptId]);
                        $editDept = $stmt->fetch(PDO::FETCH_ASSOC);
                        ?>
                        <input type="text" name="name" placeholder="Department Name" value="<?= htmlspecialchars($editDept['name']) ?>" required>
                        <select name="type" required>
                            <option value="college" <?= $editDept['type'] === 'college' ? 'selected' : '' ?>>College</option>
                            <option value="office" <?= $editDept['type'] === 'office' ? 'selected' : '' ?>>Office</option>
                        </select>
                    <?php else: ?>
                        <input type="text" name="name" placeholder="Department Name" required>
                        <select name="type" required>
                            <option value="">Select Type</option>
                            <option value="college">College</option>
                            <option value="office">Office</option>
                        </select>
                    <?php endif; ?>
                    <button type="submit"><?= isset($_GET['edit_dept']) ? 'Update Department' : 'Add Department' ?></button>
                </form>
            </div>
        </div>

        <!-- Subdepartment Modal -->
        <div class="modal" id="subdept-modal">
            <div class="modal-content">
                <span class="close">&times;</span>
                <h2>Add Subdepartment</h2>
                <form method="POST" action="department_management.php">
                    <input type="hidden" name="action" value="add_subdept">
                    <input type="text" name="name" placeholder="Subdepartment Name" required>
                    <select name="department_id" required>
                        <option value="">Select Department</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= htmlspecialchars($dept['id']) ?>"><?= htmlspecialchars($dept['name']) ?> (<?= htmlspecialchars($dept['type']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit">Add Subdepartment</button>
                </form>
            </div>
        </div>

        <!-- Departments and Subdepartments Display -->
        <div class="table-container">
            <h3>Departments & Subdepartments</h3>
            <?php foreach ($departments as $dept): ?>
                <div class="dept-section">
                    <div class="dept-header">
                        <h3><?= htmlspecialchars($dept['name']) ?> (<?= htmlspecialchars($dept['type']) ?>)</h3>
                        <div class="action-buttons">
                            <a href="department_management.php?edit_dept=<?= $dept['id'] ?>"><button class="edit-btn">Edit</button></a>
                            <a href="department_management.php?delete_dept=<?= $dept['id'] ?>" onclick="return confirm('Are you sure you want to delete this department and its subdepartments?');"><button class="delete-btn">Delete</button></a>
                            <i class="fas fa-chevron-down toggle-subdept"></i>
                        </div>
                    </div>
                    <div class="subdept-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Subdepartment Name</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $subdepts = array_filter($subdepartments, fn($sd) => $sd['department_id'] == $dept['id']);
                                if (empty($subdepts)): ?>
                                    <tr>
                                        <td colspan="3">No subdepartments found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($subdepts as $subdept): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($subdept['id']) ?></td>
                                            <td><?= htmlspecialchars($subdept['subdepartment_name']) ?></td>
                                            <td class="action-buttons">
                                                <a href="department_management.php?delete_subdept=<?= $subdept['id'] ?>" onclick="return confirm('Are you sure you want to delete this subdepartment?');"><button class="delete-btn">Delete</button></a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <script>
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

            // Department Modal
            const deptModal = document.getElementById("dept-modal");
            const openDeptModalBtn = document.getElementById("open-dept-modal-btn");
            const closeDeptModalBtn = deptModal.querySelector(".close");

            openDeptModalBtn.onclick = () => deptModal.style.display = "flex";
            closeDeptModalBtn.onclick = () => deptModal.style.display = "none";

            // Subdepartment Modal
            const subdeptModal = document.getElementById("subdept-modal");
            const openSubdeptModalBtn = document.getElementById("open-subdept-modal-btn");
            const closeSubdeptModalBtn = subdeptModal.querySelector(".close");

            openSubdeptModalBtn.onclick = () => subdeptModal.style.display = "flex";
            closeSubdeptModalBtn.onclick = () => subdeptModal.style.display = "none";

            // Close modals when clicking outside
            window.onclick = (event) => {
                if (event.target === deptModal) deptModal.style.display = "none";
                else if (event.target === subdeptModal) subdeptModal.style.display = "none";
            };

            // Auto-open modal for editing
            <?php if (isset($_GET['edit_dept'])): ?>
                deptModal.style.display = "flex";
            <?php endif; ?>

            // Toggle subdepartment tables
            document.querySelectorAll('.toggle-subdept').forEach(toggle => {
                toggle.addEventListener('click', () => {
                    const subdeptTable = toggle.closest('.dept-section').querySelector('.subdept-table');
                    subdeptTable.style.display = subdeptTable.style.display === 'block' ? 'none' : 'block';
                    toggle.classList.toggle('fa-chevron-down');
                    toggle.classList.toggle('fa-chevron-up');
                });
            });
        });
    </script>
</body>

</html>