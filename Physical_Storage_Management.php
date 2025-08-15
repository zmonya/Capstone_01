<?php
session_start();
require 'db_connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

$error = $success = "";

/**
 * Fetch storage locations with their cabinets and departments
 *
 * @param PDO $pdo
 * @return array
 */
/**
 * Fetch storage locations grouped by department with their cabinets
 *
 * @param PDO $pdo
 * @return array
 */
function fetchStorageLocations(PDO $pdo): array
{
    $stmt = $pdo->prepare("
        SELECT 
            d.department_id, 
            d.department_name,
            f.storage_location, 
            f.physical_storage_path, 
            MIN(f.file_name) AS file_name
        FROM files f
        LEFT JOIN users_department ud ON f.user_id = ud.user_id
        LEFT JOIN departments d ON ud.department_id = d.department_id
        WHERE f.physical_storage_path IS NOT NULL
        AND f.file_status = 'active'
        GROUP BY d.department_id, d.department_name, f.storage_location, f.physical_storage_path
        ORDER BY d.department_name, f.storage_location, f.physical_storage_path
    ");
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $departments = [];
    foreach ($results as $row) {
        $department_id = $row['department_id'] ?: 0; // Use 0 for null department_id
        $department_name = $row['department_name'] ?: 'Unassigned Department';
        $location = $row['storage_location'] ?: 'Unspecified Location';
        $cabinet_id = explode('/', $row['physical_storage_path'])[0];
        $cabinet_name = "Cabinet $cabinet_id";

        if (!isset($departments[$department_id])) {
            $departments[$department_id] = [
                'department_name' => $department_name,
                'locations' => []
            ];
        }
        if (!isset($departments[$department_id]['locations'][$location])) {
            $departments[$department_id]['locations'][$location] = [
                'cabinets' => []
            ];
        }
        $departments[$department_id]['locations'][$location]['cabinets'][] = [
            'physical_storage_path' => $row['physical_storage_path'],
            'cabinet_name' => $cabinet_name,
            'department_id' => $row['department_id'],
            'department_name' => $row['department_name']
        ];
    }
    return $departments;
}

/**
 * Fetch storage details for a physical storage path
 *
 * @param PDO $pdo
 * @param string $physical_storage_path
 * @return array
 */
function fetchStorageDetails(PDO $pdo, string $physical_storage_path): array
{
    $stmt = $pdo->prepare("
        SELECT f.file_id, f.file_name, f.physical_storage_path, f.storage_capacity, u.username AS uploader, dt.type_name AS document_category
        FROM files f
        LEFT JOIN users u ON f.user_id = u.user_id
        LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
        WHERE f.physical_storage_path = ? AND f.file_status = 'active'
        AND f.file_name NOT LIKE 'Placeholder%'
    ");
    $stmt->execute([$physical_storage_path]);
    $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $structure = [];
    $default_capacity = 10; // Default if storage_capacity is NULL
    foreach ($files as $file) {
        $path_parts = explode('/', $file['physical_storage_path']);
        if (count($path_parts) !== 4) continue;
        $layer = $path_parts[1];
        $box = $path_parts[2];
        $folder = $path_parts[3];

        if (!isset($structure[$layer])) {
            $structure[$layer] = [];
        }
        if (!isset($structure[$layer][$box])) {
            $structure[$layer][$box] = [];
        }
        if (!isset($structure[$layer][$box][$folder])) {
            $structure[$layer][$box][$folder] = [
                'id' => $file['physical_storage_path'],
                'file_count' => 0,
                'files' => [],
                'capacity' => $file['storage_capacity'] ?? $default_capacity
            ];
        }
        $structure[$layer][$box][$folder]['files'][] = $file;
        $structure[$layer][$box][$folder]['file_count']++;
    }

    return $structure;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        $physical_storage_path = trim(filter_input(INPUT_POST, 'physical_storage_path', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
        $cabinet_name = trim(filter_input(INPUT_POST, 'cabinet_name', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
        $department_id = filter_input(INPUT_POST, 'department_id', FILTER_VALIDATE_INT);
        $sub_department_id = filter_input(INPUT_POST, 'sub_department_id', FILTER_VALIDATE_INT) ?: null;
        $storage_location = trim(filter_input(INPUT_POST, 'storage_location', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
        $storage_capacity = filter_input(INPUT_POST, 'storage_capacity', FILTER_VALIDATE_INT) ?: 10;

        if (empty($cabinet_name) || !$department_id || empty($physical_storage_path) || empty($storage_location) || !$storage_capacity) {
            $error = "All required fields must be filled.";
        } elseif (!preg_match('/^[A-Z][0-9]+(\/[A-Z][0-9]+){3}$/', $physical_storage_path)) {
            $error = "Invalid physical storage path format. Use format like C1/L1/B1/F1.";
        } else {
            try {
                global $pdo;
                $pdo->beginTransaction();

                if ($action === 'add') {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM files WHERE physical_storage_path = ?");
                    $stmt->execute([$physical_storage_path]);
                    if ($stmt->fetchColumn() > 0) {
                        $error = "Physical storage path already exists.";
                    } else {
                        $stmt = $pdo->prepare("
            INSERT INTO files (file_name, user_id, file_size, file_type, file_status, physical_storage_path, storage_location, storage_capacity, file_path)
            VALUES (?, ?, 0, 'pdf', 'active', ?, ?, ?, ?)
        ");
                        $sanitized_file_path = preg_replace('/[^A-Za-z0-9\/._-]/', '_', "uploads/placeholder_$physical_storage_path.pdf");
                        $stmt->execute([
                            "Placeholder for $physical_storage_path",
                            $_SESSION['user_id'],
                            $physical_storage_path,
                            $storage_location,
                            $storage_capacity,
                            $sanitized_file_path
                        ]);
                        $fileId = $pdo->lastInsertId();

                        $stmt = $pdo->prepare("
            INSERT INTO users_department (user_id, department_id)
            SELECT ?, ? WHERE NOT EXISTS (
                SELECT 1 FROM users_department WHERE user_id = ? AND department_id = ?
            )
        ");
                        $stmt->execute([$_SESSION['user_id'], $department_id, $_SESSION['user_id'], $department_id]);

                        $stmt = $pdo->prepare("
            INSERT INTO transactions (user_id, file_id, transaction_type, transaction_time, description)
            VALUES (?, ?, 'storage_management', NOW(), ?)
        ");
                        $stmt->execute([
                            $_SESSION['user_id'],
                            $fileId,
                            "Added storage location $physical_storage_path"
                        ]);

                        $success = "Storage location added successfully.";
                    }
                } elseif ($action === 'edit' && $physical_storage_path) {
                    $stmt = $pdo->prepare("
        UPDATE files 
        SET storage_location = ?, storage_capacity = ?
        WHERE physical_storage_path = ?
    ");
                    $stmt->execute([$storage_location, $storage_capacity, $physical_storage_path]);

                    $stmt = $pdo->prepare("
        INSERT INTO transactions (user_id, transaction_type, transaction_time, description)
        VALUES (?, 'storage_management', NOW(), ?)
    ");
                    $stmt->execute([
                        $_SESSION['user_id'],
                        "Edited storage location $physical_storage_path"
                    ]);

                    $success = "Storage location updated successfully.";
                }

                $pdo->commit();
                header('Content-Type: application/json');
                echo json_encode(['success' => empty($error), 'message' => $error ?: $success]);
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Storage management error: " . $e->getMessage());
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => "Error: " . $e->getMessage()]);
                exit;
            }
        }
        header('Content-Type: application/json');
        echo json_encode(['success' => empty($error), 'message' => $error ?: $success]);
        exit;
    } elseif (isset($_POST['assign_file'])) {
        $file_id = filter_input(INPUT_POST, 'file_id', FILTER_VALIDATE_INT);
        $physical_storage_path = filter_input(INPUT_POST, 'physical_storage_path', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        if (!$file_id || !$physical_storage_path) {
            $error = "Invalid file ID or storage path.";
        } else {
            try {
                $pdo->beginTransaction();

                // Check capacity
                $stmt = $pdo->prepare("
                SELECT storage_capacity, (SELECT COUNT(*) FROM files WHERE physical_storage_path = ? AND file_status = 'active' AND file_name NOT LIKE 'Placeholder%') AS file_count
                FROM files 
                WHERE physical_storage_path = ? AND file_name LIKE 'Placeholder%' AND file_status = 'active'
                LIMIT 1
            ");
                $stmt->execute([$physical_storage_path, $physical_storage_path]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($result && $result['file_count'] >= ($result['storage_capacity'] ?? 10)) {
                    $error = "Storage capacity exceeded.";
                } else {
                    $stmt = $pdo->prepare("
                    UPDATE files 
                    SET physical_storage_path = ? 
                    WHERE file_id = ? AND file_status = 'active' AND physical_storage_path IS NULL
                ");
                    $stmt->execute([$physical_storage_path, $file_id]);

                    $stmt = $pdo->prepare("
                    INSERT INTO transactions (user_id, file_id, transaction_type, transaction_time, description)
                    VALUES (?, ?, 'storage_management', NOW(), ?)
                ");
                    $stmt->execute([
                        $_SESSION['user_id'],
                        $file_id,
                        "Assigned file ID $file_id to storage $physical_storage_path"
                    ]);

                    $success = "File assigned successfully.";
                }

                $pdo->commit();
                header('Content-Type: application/json');
                echo json_encode(['success' => empty($error), 'message' => $error ?: $success]);
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("File assignment error: " . $e->getMessage());
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => "Error: " . $e->getMessage()]);
                exit;
            }
        }
        header('Content-Type: application/json');
        echo json_encode(['success' => empty($error), 'message' => $error ?: $success]);
        exit;
    } elseif (isset($_POST['remove_file']) && $_POST['remove_file'] == '1') {
        $file_id = filter_input(INPUT_POST, 'file_id', FILTER_VALIDATE_INT);
        $physical_storage_path = filter_input(INPUT_POST, 'physical_storage_path', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        if (!$file_id || !$physical_storage_path) {
            $error = "Invalid file ID or storage path.";
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                UPDATE files 
                SET physical_storage_path = NULL 
                WHERE file_id = ? AND physical_storage_path = ? AND file_status = 'active'
            ");
                $stmt->execute([$file_id, $physical_storage_path]);

                $stmt = $pdo->prepare("
                INSERT INTO transactions (user_id, file_id, transaction_type, transaction_time, description)
                VALUES (?, ?, 'storage_management', NOW(), ?)
            ");
                $stmt->execute([
                    $_SESSION['user_id'],
                    $file_id,
                    "Removed file ID $file_id from storage $physical_storage_path"
                ]);

                $pdo->commit();
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'File removed successfully']);
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("File removal error: " . $e->getMessage());
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
                exit;
            }
        }
    }
}

// Fetch storage locations
$storage_locations = fetchStorageLocations($pdo);
$departments = $pdo->query("SELECT department_id, department_name FROM departments WHERE department_type IN ('college', 'office')")->fetchAll(PDO::FETCH_ASSOC);
$sub_departments = $pdo->query("SELECT department_id, department_name, parent_department_id FROM departments WHERE department_type = 'sub_department'")->fetchAll(PDO::FETCH_ASSOC);
$unassigned_files = $pdo->query("SELECT file_id, file_name FROM files WHERE file_status = 'active' AND physical_storage_path IS NULL")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Physical Storage Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style/admin-sidebar.css">
    <link rel="stylesheet" href="style/Physical_Storage_Management.css">
</head>

<body>
    <div class="sidebar">
        <button class="toggle-btn" title="Toggle Sidebar"><i class="fas fa-bars"></i></button>
        <h2 class="sidebar-title">Admin Panel</h2>
        <a href="dashboard.php" class="client-btn"><i class="fas fa-exchange-alt"></i><span class="link-text">Switch to Client View</span></a>
        <a href="admin_dashboard.php"><i class="fas fa-home"></i><span class="link-text">Dashboard</span></a>
        <a href="admin_search.php"><i class="fas fa-search"></i><span class="link-text">View All Files</span></a>
        <a href="user_management.php"><i class="fas fa-users"></i><span class="link-text">User Management</span></a>
        <a href="department_management.php"><i class="fas fa-building"></i><span class="link-text">Department Management</span></a>
        <a href="physical_storage_management.php" class="active"><i class="fas fa-archive"></i><span class="link-text">Physical Storage</span></a>
        <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i><span class="link-text">Logout</span></a>
    </div>

    <div class="modal-backdrop"></div>
    <div class="main-content">
        <div class="header-controls">
            <button id="open-modal-btn" class="open-modal-btn">Add Storage Location</button>
            <div class="legend">
                <div class="occupied" data-tooltip="Storage is occupied"><span>Occupied</span></div>
                <div class="available" data-tooltip="Storage is available"><span>Available</span></div>
            </div>
        </div>

        <div class="modal storage-form" id="storage-modal">
            <div class="modal-content">
                <span class="close" onclick="closeModal('storage-modal')">&times;</span>
                <h2><?= isset($_GET['edit']) ? 'Edit Storage Location' : 'Add Storage Location' ?></h2>
                <form action="physical_storage_management.php" method="POST">
                    <input type="hidden" name="action" value="<?= isset($_GET['edit']) ? 'edit' : 'add' ?>">
                    <label for="physical_storage_path" data-error="" aria-describedby="physical_storage_path_help">Physical Storage Path (e.g., C1/L1/B1/F1):</label>
                    <input type="text" id="physical_storage_path" name="physical_storage_path" value="<?= isset($_GET['edit']) ? htmlspecialchars($_GET['edit']) : '' ?>" <?= isset($_GET['edit']) ? 'readonly' : '' ?> required pattern="[A-Z][0-9]+(/[A-Z][0-9]+){3}">
                    <span id="physical_storage_path_help" class="form-help">Enter in format C1/L1/B1/F1 (e.g., Cabinet 1, Layer 1, Box 1, Folder 1)</span>
                    <label for="cabinet_name" data-error="">Cabinet Name (Display Only):</label>
                    <input type="text" id="cabinet_name" name="cabinet_name" value="<?= isset($_GET['edit']) ? htmlspecialchars('Cabinet ' . explode('/', $_GET['edit'])[0]) : '' ?>" required>
                    <label for="department_id" data-error="">Department:</label>
                    <select id="department_id" name="department_id" onchange="loadSubDepartments()" required>
                        <option value="">Select Department</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= $dept['department_id'] ?>" <?= isset($_GET['edit']) && ($storage_locations[$_GET['edit']]['department_id'] ?? '') == $dept['department_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($dept['department_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <label for="sub_department_id" data-error="">Sub-Department (Optional):</label>
                    <select id="sub_department_id" name="sub_department_id" data-selected="<?= isset($_GET['edit']) ? htmlspecialchars($storage_locations[$_GET['edit']]['sub_department_id'] ?? '') : '' ?>">
                        <option value="">Select Sub-Department (Optional)</option>
                    </select>
                    <label for="storage_location" data-error="">Storage Location (e.g., Archive Room 101):</label>
                    <input type="text" id="storage_location" name="storage_location" value="<?= isset($_GET['edit']) ? htmlspecialchars($storage_locations[$_GET['edit']]['storage_location'] ?? '') : '' ?>" required>
                    <label for="storage_capacity" data-error="">Storage Capacity (Number of Files):</label>
                    <input type="number" id="storage_capacity" name="storage_capacity" value="<?= isset($_GET['edit']) ? htmlspecialchars($storage_locations[$_GET['edit']]['storage_capacity'] ?? '') : '' ?>" min="1" required>
                    <div class="buttons">
                        <button type="submit" class="confirm-btn">Save</button>
                        <button type="button" class="cancel-btn" onclick="closeModal('storage-modal')">Cancel</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="modal assign-form" id="assign-file-modal">
            <div class="modal-content">
                <span class="close" onclick="closeModal('assign-file-modal')">&times;</span>
                <h2>Assign File</h2>
                <form action="physical_storage_management.php" method="POST">
                    <input type="hidden" name="assign_file" value="1">
                    <input type="hidden" name="physical_storage_path" id="assign_physical_storage_path">
                    <label for="file_id" data-error="">File:</label>
                    <select id="file_id" name="file_id" required>
                        <option value="">Select File</option>
                        <?php foreach ($unassigned_files as $file): ?>
                            <option value="<?= $file['file_id'] ?>"><?= htmlspecialchars($file['file_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="buttons">
                        <button type="submit" class="confirm-btn">Assign</button>
                        <button type="button" class="cancel-btn" onclick="closeModal('assign-file-modal')">Cancel</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="modal warning-modal" id="warning-remove-file-modal">
            <div class="warning-modal-content">
                <span class="close" onclick="closeModal('warning-remove-file-modal')">&times;</span>
                <h2>Warning</h2>
                <p>Are you sure you want to remove this file? This action cannot be undone.</p>
                <div class="buttons">
                    <button class="confirm-btn" id="confirm-remove-file">Yes</button>
                    <button class="cancel-btn" onclick="closeModal('warning-remove-file-modal')">No</button>
                </div>
            </div>
        </div>
        <div role="tree" aria-label="Department Storage">
            <?php foreach ($storage_locations as $dept_id => $dept_data): ?>
                <section class="department-section" role="treeitem" aria-level="1" data-department="<?= htmlspecialchars($dept_data['department_name']) ?>">
                    <h2><?= htmlspecialchars($dept_data['department_name']) ?></h2>
                    <?php foreach ($dept_data['locations'] as $location => $location_data): ?>
                        <div class="location-section" role="treeitem" aria-level="2" data-location="<?= htmlspecialchars($location) ?>">
                            <h3><?= htmlspecialchars($location) ?></h3>
                            <div class="cabinet-grid">
                                <?php foreach ($location_data['cabinets'] as $cabinet): ?>
                                    <div class="cabinet-card" role="treeitem" aria-level="3">
                                        <div class="card-header">
                                            <h4><?= htmlspecialchars($cabinet['cabinet_name']) ?></h4>
                                            <div class="card-actions">
                                                <a href="?edit=<?= htmlspecialchars($cabinet['physical_storage_path']) ?>" class="edit-btn" title="Edit Cabinet"><i class="fas fa-edit"></i></a>
                                                <a href="?delete=<?= htmlspecialchars($cabinet['physical_storage_path']) ?>" class="delete-btn" onclick="return confirm('Are you sure you want to delete this cabinet?')" title="Delete Cabinet"><i class="fas fa-trash"></i></a>
                                                <button class="toggle-details" data-target="cabinet-details-<?= htmlspecialchars($cabinet['physical_storage_path']) ?>" aria-expanded="false" aria-controls="cabinet-details-<?= htmlspecialchars($cabinet['physical_storage_path']) ?>" title="Toggle Details"><i class="fas fa-chevron-down"></i></button>
                                            </div>
                                        </div>
                                        <p><strong>Path:</strong> <?= htmlspecialchars($cabinet['physical_storage_path']) ?></p>
                                        <div class="cabinet-details" id="cabinet-details-<?= htmlspecialchars($cabinet['physical_storage_path']) ?>" role="group" hidden>
                                            <?php $storage_details = fetchStorageDetails($pdo, $cabinet['physical_storage_path']); ?>
                                            <?php foreach ($storage_details as $layer => $boxes): ?>
                                                <div class="hierarchy-level layer-level" role="treeitem" aria-level="4">
                                                    <h5>Layer <?= htmlspecialchars($layer) ?></h5>
                                                    <?php foreach ($boxes as $box => $folders): ?>
                                                        <div class="hierarchy-level box-level" role="treeitem" aria-level="5">
                                                            <h6>Box <?= htmlspecialchars($box) ?></h6>
                                                            <?php foreach ($folders as $folder => $data): ?>
                                                                <div class="hierarchy-level folder-level" role="treeitem" aria-level="6">
                                                                    <h6>Folder <?= htmlspecialchars($folder) ?> <span class="status <?= $data['file_count'] > 0 ? 'occupied' : 'available' ?>"><?= $data['file_count'] > 0 ? 'Occupied' : 'Available' ?></span></h6>
                                                                    <div class="capacity-controls">
                                                                        <span class="capacity-display"><?= htmlspecialchars($data['file_count']) . '/' . htmlspecialchars($data['capacity']) ?></span>
                                                                        <input type="range" class="capacity-slider" min="0" max="<?= htmlspecialchars($data['capacity']) ?>" value="<?= htmlspecialchars($data['file_count']) ?>" disabled aria-label="Storage capacity">
                                                                        <div class="capacity-progress">
                                                                            <div class="progress-bar <?= $data['file_count'] / $data['capacity'] >= 0.8 ? 'warning' : '' ?> <?= $data['file_count'] >= $data['capacity'] ? 'full' : '' ?>" style="width: <?= min(($data['file_count'] / $data['capacity'] * 100), 100) ?>%"></div>
                                                                        </div>
                                                                    </div>
                                                                    <table class="storage-table" data-sortable>
                                                                        <thead>
                                                                            <tr>
                                                                                <th data-sort="file_name">File Name <i class="fas fa-sort"></i></th>
                                                                                <th data-sort="uploader">Uploader <i class="fas fa-sort"></i></th>
                                                                                <th data-sort="category">Category <i class="fas fa-sort"></i></th>
                                                                                <th>Actions</th>
                                                                            </tr>
                                                                        </thead>
                                                                        <tbody>
                                                                            <?php if (empty($data['files'])): ?>
                                                                                <tr>
                                                                                    <td colspan="3">No files assigned</td>
                                                                                    <td>
                                                                                        <form class="inline-assign-form" action="physical_storage_management.php" method="POST">
                                                                                            <input type="hidden" name="assign_file" value="1">
                                                                                            <input type="hidden" name="physical_storage_path" value="<?= htmlspecialchars($data['id']) ?>">
                                                                                            <select name="file_id" required onchange="this.form.submit()" aria-label="Assign file to folder">
                                                                                                <option value="">Assign File</option>
                                                                                                <?php foreach ($unassigned_files as $file): ?>
                                                                                                    <option value="<?= $file['file_id'] ?>"><?= htmlspecialchars($file['file_name']) ?></option>
                                                                                                <?php endforeach; ?>
                                                                                            </select>
                                                                                        </form>
                                                                                        <button class="action-btn assign-btn" onclick="showAssignForm('<?= htmlspecialchars($data['id']) ?>')" title="Detailed Assign">Detailed Assign</button>
                                                                                    </td>
                                                                                </tr>
                                                                            <?php else: ?>
                                                                                <?php foreach ($data['files'] as $file): ?>
                                                                                    <tr>
                                                                                        <td><?= htmlspecialchars($file['file_name']) ?></td>
                                                                                        <td><?= htmlspecialchars($file['uploader'] ?? 'Unknown') ?></td>
                                                                                        <td><?= htmlspecialchars($file['document_category'] ?? 'N/A') ?></td>
                                                                                        <td>
                                                                                            <form class="inline-assign-form" action="physical_storage_management.php" method="POST">
                                                                                                <input type="hidden" name="assign_file" value="1">
                                                                                                <input type="hidden" name="physical_storage_path" value="<?= htmlspecialchars($data['id']) ?>">
                                                                                                <select name="file_id" required onchange="this.form.submit()" aria-label="Assign file to folder">
                                                                                                    <option value="">Assign File</option>
                                                                                                    <?php foreach ($unassigned_files as $uf): ?>
                                                                                                        <option value="<?= $uf['file_id'] ?>"><?= htmlspecialchars($uf['file_name']) ?></option>
                                                                                                    <?php endforeach; ?>
                                                                                                </select>
                                                                                            </form>
                                                                                            <button class="action-btn remove-btn" onclick="confirmRemoveFile(<?= htmlspecialchars($file['file_id']) ?>, '<?= htmlspecialchars($data['id']) ?>')" title="Remove File">Remove</button>
                                                                                        </td>
                                                                                    </tr>
                                                                                <?php endforeach; ?>
                                                                            <?php endif; ?>
                                                                        </tbody>
                                                                    </table>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </section>
            <?php endforeach; ?>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Form validation
            document.getElementById('physical_storage_path').addEventListener('input', (e) => {
                const input = e.target;
                const regex = /^[A-Z][0-9]+(\/[A-Z][0-9]+){0,3}$/;
                const label = input.previousElementSibling;
                if (!regex.test(input.value)) {
                    label.classList.add('error');
                    label.setAttribute('data-error', 'Invalid format. Use C1/L1/B1/F1');
                } else {
                    label.classList.remove('error');
                    label.setAttribute('data-error', '');
                }
            });

            document.getElementById('storage_capacity').addEventListener('input', (e) => {
                const input = e.target;
                const label = input.previousElementSibling;
                if (input.value < 1) {
                    label.classList.add('error');
                    label.setAttribute('data-error', 'Capacity must be at least 1');
                } else {
                    label.classList.remove('error');
                    label.setAttribute('data-error', '');
                }
            });

            document.getElementById('cabinet_name').addEventListener('input', (e) => {
                const input = e.target;
                const label = input.previousElementSibling;
                if (input.value.trim() === '') {
                    label.classList.add('error');
                    label.setAttribute('data-error', 'Cabinet name is required');
                } else {
                    label.classList.remove('error');
                    label.setAttribute('data-error', '');
                }
            });

            // Form submission with toast feedback
            document.querySelectorAll('.storage-form form, .inline-assign-form').forEach(form => {
                form.addEventListener('submit', async (e) => {
                    e.preventDefault();
                    const submitBtn = form.querySelector('.confirm-btn') || form.querySelector('select');
                    submitBtn.disabled = true;
                    if (submitBtn.tagName === 'SELECT') submitBtn.classList.add('loading');

                    try {
                        const response = await fetch(form.action, {
                            method: 'POST',
                            body: new FormData(form)
                        });
                        const result = await response.json();
                        if (result.success) {
                            showToast(result.message);
                            setTimeout(() => location.reload(), 1000);
                        } else {
                            showToast(result.message, 'error');
                        }
                    } catch (error) {
                        showToast('An error occurred', 'error');
                    } finally {
                        submitBtn.disabled = false;
                        if (submitBtn.tagName === 'SELECT') submitBtn.classList.remove('loading');
                    }
                });
            });

            // Toggle cabinet details
            document.querySelectorAll('.toggle-details').forEach(btn => {
                btn.addEventListener('click', () => {
                    const targetId = btn.dataset.target;
                    const target = document.getElementById(targetId);
                    const isExpanded = btn.getAttribute('aria-expanded') === 'true';
                    btn.setAttribute('aria-expanded', !isExpanded);
                    btn.querySelector('i').className = isExpanded ? 'fas fa-chevron-down' : 'fas fa-chevron-up';
                    target.hidden = isExpanded;
                });
            });

            // Table sorting
            document.querySelectorAll('[data-sortable] th[data-sort]').forEach(th => {
                th.addEventListener('click', () => {
                    const table = th.closest('table');
                    const rows = Array.from(table.querySelectorAll('tbody tr'));
                    const index = Array.from(th.parentElement.children).indexOf(th);
                    const key = th.dataset.sort;
                    const asc = th.classList.toggle('asc');
                    th.querySelector('i').className = asc ? 'fas fa-sort-up' : 'fas fa-sort-down';

                    rows.sort((a, b) => {
                        let aValue = a.children[index].textContent.trim();
                        let bValue = b.children[index].textContent.trim();
                        return asc ? aValue.localeCompare(bValue) : bValue.localeCompare(aValue);
                    });

                    table.querySelector('tbody').innerHTML = '';
                    rows.forEach(row => table.querySelector('tbody').appendChild(row));
                });
            });

            // Load sub-departments
            function loadSubDepartments() {
                const deptId = document.getElementById('department_id').value;
                const subDeptSelect = document.getElementById('sub_department_id');
                subDeptSelect.innerHTML = '<option value="">Select Sub-Department (Optional)</option>';
                if (deptId) {
                    const subDepts = <?= json_encode($sub_departments) ?>.filter(d => d.parent_department_id == deptId);
                    subDepts.forEach(d => {
                        const option = document.createElement('option');
                        option.value = d.department_id;
                        option.textContent = d.department_name;
                        subDeptSelect.appendChild(option);
                    });
                }
            }

            document.getElementById('department_id').addEventListener('change', loadSubDepartments);
            loadSubDepartments();
        });

        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            modal.style.display = 'block';
            modal.classList.add('active');
            document.querySelector('.modal-backdrop').classList.add('active');
            trapFocus(modalId);
            modal.querySelector('input, button, select')?.focus();
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            modal.style.display = 'none';
            modal.classList.remove('active');
            document.querySelector('.modal-backdrop').classList.remove('active');
        }

        function trapFocus(modalId) {
            const modal = document.getElementById(modalId);
            const focusableElements = modal.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
            const firstElement = focusableElements[0];
            const lastElement = focusableElements[focusableElements.length - 1];

            modal.addEventListener('keydown', (e) => {
                if (e.key === 'Tab') {
                    if (e.shiftKey && document.activeElement === firstElement) {
                        e.preventDefault();
                        lastElement.focus();
                    } else if (!e.shiftKey && document.activeElement === lastElement) {
                        e.preventDefault();
                        firstElement.focus();
                    }
                }
            });
        }

        function showToast(message, type = 'success') {
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.textContent = message;
            document.body.appendChild(toast);
            setTimeout(() => toast.classList.add('show'), 100);
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }

        function showAssignForm(physicalStoragePath) {
            document.getElementById('assign_physical_storage_path').value = physicalStoragePath;
            openModal('assign-file-modal');
        }

        const fileManager = {
            pendingFileId: null,
            pendingLocationId: null,
            confirmRemoveFile(fileId, physicalStoragePath) {
                this.pendingFileId = fileId;
                this.pendingLocationId = physicalStoragePath;
                openModal('warning-remove-file-modal');
            },
            async removeFile() {
                if (this.pendingFileId !== null && this.pendingLocationId !== null) {
                    try {
                        const response = await fetch('Physical_Storage_Management.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded'
                            },
                            body: `remove_file=1&file_id=${this.pendingFileId}&physical_storage_path=${encodeURIComponent(this.pendingLocationId)}`
                        });
                        const result = await response.json();
                        if (result.success) {
                            showToast(result.message);
                            setTimeout(() => location.reload(), 1000);
                        } else {
                            showToast(result.message, 'error');
                        }
                    } catch (error) {
                        showToast('Failed to remove file: ' + error.message, 'error');
                    }
                    closeModal('warning-remove-file-modal');
                    this.pendingFileId = null;
                    this.pendingLocationId = null;
                }
            }
        };

        function confirmRemoveFile(fileId, physicalStoragePath) {
            fileManager.confirmRemoveFile(fileId, physicalStoragePath);
        }

        document.getElementById('open-modal-btn').addEventListener('click', () => openModal('storage-modal'));
        document.getElementById('confirm-remove-file').addEventListener('click', () => fileManager.removeFile());
    </script>
</body>

</html>