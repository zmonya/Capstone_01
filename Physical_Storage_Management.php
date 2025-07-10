<?php
session_start();
require 'db_connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

$error = $success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        $cabinet_id = filter_input(INPUT_POST, 'cabinet_id', FILTER_VALIDATE_INT) ?: null;
        $cabinet_name = trim(filter_input(INPUT_POST, 'cabinet_name', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
        $department_id = filter_input(INPUT_POST, 'department_id', FILTER_VALIDATE_INT);
        $sub_department_id = filter_input(INPUT_POST, 'sub_department_id', FILTER_VALIDATE_INT) ?: null; // Allow null
        $building = trim(filter_input(INPUT_POST, 'building', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
        $room = trim(filter_input(INPUT_POST, 'room', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
        $location = "$building, $room";
        $layers = filter_input(INPUT_POST, 'layers', FILTER_VALIDATE_INT);
        $boxes = filter_input(INPUT_POST, 'boxes', FILTER_VALIDATE_INT);
        $folders = filter_input(INPUT_POST, 'folders', FILTER_VALIDATE_INT);
        $folder_capacity = filter_input(INPUT_POST, 'folder_capacity', FILTER_VALIDATE_INT);

        if (empty($cabinet_name) || !$department_id || empty($building) || empty($room) || !$layers || !$boxes || !$folders || !$folder_capacity) {
            $error = "All required fields must be filled.";
        } else {
            try {
                if ($action === 'add') {
                    $stmt = $pdo->prepare("INSERT INTO cabinets (cabinet_name, department_id, sub_department_id, location, layers, boxes, folders, folder_capacity) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$cabinet_name, $department_id, $sub_department_id, $location, $layers, $boxes, $folders, $folder_capacity]);
                    $cabinet_id = $pdo->lastInsertId();
                    initializeStorageLocations($pdo, $cabinet_id, $layers, $boxes, $folders);
                    $success = "Cabinet added successfully.";
                } elseif ($action === 'edit' && $cabinet_id) {
                    $stmt = $pdo->prepare("UPDATE cabinets SET cabinet_name = ?, department_id = ?, sub_department_id = ?, location = ?, layers = ?, boxes = ?, folders = ?, folder_capacity = ? WHERE id = ?");
                    $stmt->execute([$cabinet_name, $department_id, $sub_department_id, $location, $layers, $boxes, $folders, $folder_capacity, $cabinet_id]);
                    adjustStorageLocations($pdo, $cabinet_id, $layers, $boxes, $folders);
                    $success = "Cabinet updated successfully.";
                }
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        }
    }

    // Handle storage location updates
    elseif (isset($_POST['update_storage_location'])) {
        $location_id = filter_input(INPUT_POST, 'location_id', FILTER_VALIDATE_INT);
        $is_occupied = filter_input(INPUT_POST, 'is_occupied', FILTER_VALIDATE_INT);
        if ($location_id && in_array($is_occupied, [0, 1])) {
            $stmt = $pdo->prepare("UPDATE storage_locations SET is_occupied = ? WHERE id = ?");
            $stmt->execute([$is_occupied, $location_id]);
            $success = "Storage location updated.";
        } else {
            $error = "Invalid location ID or occupancy status.";
        }
    }

    // Handle file count updates
    elseif (isset($_POST['update_file_count'])) {
        $location_id = filter_input(INPUT_POST, 'location_id', FILTER_VALIDATE_INT);
        $file_count = filter_input(INPUT_POST, 'file_count', FILTER_VALIDATE_INT);
        if ($location_id && $file_count >= 0) {
            $stmt = $pdo->prepare("UPDATE storage_locations SET file_count = ?, is_occupied = ? WHERE id = ?");
            $stmt->execute([$file_count, $file_count > 0 ? 1 : 0, $location_id]);
            $success = "File count updated.";
        } else {
            $error = "Invalid location ID or file count.";
        }
    }

    // Handle file removal
    elseif (isset($_POST['remove_file'])) {
        $file_id = filter_input(INPUT_POST, 'file_id', FILTER_VALIDATE_INT);
        $location_id = filter_input(INPUT_POST, 'location_id', FILTER_VALIDATE_INT);
        if ($file_id && $location_id) {
            $stmt = $pdo->prepare("DELETE FROM file_storage WHERE file_id = ? AND storage_location_id = ?");
            $stmt->execute([$file_id, $location_id]);
            $stmt = $pdo->prepare("UPDATE storage_locations SET file_count = GREATEST(0, file_count - 1), is_occupied = CASE WHEN file_count - 1 > 0 THEN 1 ELSE 0 END WHERE id = ?");
            $stmt->execute([$location_id]);
            $success = "File removed successfully.";
        } else {
            $error = "Invalid file or location ID.";
        }
    }
}

if (isset($_GET['delete'])) {
    $cabinet_id = filter_input(INPUT_GET, 'delete', FILTER_VALIDATE_INT);
    if ($cabinet_id) {
        $stmt = $pdo->prepare("DELETE FROM cabinets WHERE id = ?");
        $stmt->execute([$cabinet_id]);
        $success = "Cabinet deleted successfully.";
    } else {
        $error = "Invalid cabinet ID.";
    }
}

function initializeStorageLocations($pdo, $cabinet_id, $layers, $boxes, $folders, $start_layer = 1, $start_box = 1, $start_folder = 1)
{
    for ($layer = $start_layer; $layer < $start_layer + $layers; $layer++) {
        for ($box = $start_box; $box < $start_box + $boxes; $box++) {
            for ($folder = $start_folder; $folder < $start_folder + $folders; $folder++) {
                $stmt = $pdo->prepare("INSERT INTO storage_locations (cabinet_id, layer, box, folder, is_occupied, file_count) VALUES (?, ?, ?, ?, 0, 0)");
                $stmt->execute([$cabinet_id, $layer, $box, $folder]);
            }
        }
    }
}

function adjustStorageLocations($pdo, $cabinet_id, $new_layers, $new_boxes, $new_folders)
{
    $stmt = $pdo->prepare("SELECT layers, boxes, folders FROM cabinets WHERE id = ?");
    $stmt->execute([$cabinet_id]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($new_layers < $current['layers']) {
        $stmt = $pdo->prepare("DELETE FROM storage_locations WHERE cabinet_id = ? AND layer > ?");
        $stmt->execute([$cabinet_id, $new_layers]);
    } elseif ($new_layers > $current['layers']) {
        initializeStorageLocations($pdo, $cabinet_id, $new_layers - $current['layers'], $new_boxes, $new_folders, $current['layers'] + 1);
    }

    if ($new_boxes < $current['boxes']) {
        $stmt = $pdo->prepare("DELETE FROM storage_locations WHERE cabinet_id = ? AND box > ?");
        $stmt->execute([$cabinet_id, $new_boxes]);
    } elseif ($new_boxes > $current['boxes']) {
        for ($layer = 1; $layer <= $new_layers; $layer++) {
            initializeStorageLocations($pdo, $cabinet_id, 1, $new_boxes - $current['boxes'], $new_folders, $layer, $current['boxes'] + 1);
        }
    }

    if ($new_folders < $current['folders']) {
        $stmt = $pdo->prepare("DELETE FROM storage_locations WHERE cabinet_id = ? AND folder > ?");
        $stmt->execute([$cabinet_id, $new_folders]);
    } elseif ($new_folders > $current['folders']) {
        for ($layer = 1; $layer <= $new_layers; $layer++) {
            for ($box = 1; $box <= $new_boxes; $box++) {
                initializeStorageLocations($pdo, $cabinet_id, 1, 1, $new_folders - $current['folders'], $layer, $box, $current['folders'] + 1);
            }
        }
    }
}

function fetchAllCabinets($pdo)
{
    $stmt = $pdo->prepare("
        SELECT c.*, d.name AS department_name, sd.name AS sub_department_name 
        FROM cabinets c 
        JOIN departments d ON c.department_id = d.id 
        LEFT JOIN sub_departments sd ON c.sub_department_id = sd.id
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchAllDepartments($pdo)
{
    $stmt = $pdo->query("SELECT id, name FROM departments ORDER BY name ASC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchStorageLocations($pdo, $cabinet_id)
{
    // First, get all storage locations with their base details
    $stmt = $pdo->prepare("
        SELECT sl.id, sl.cabinet_id, sl.layer, sl.box, sl.folder, sl.is_occupied, sl.file_count, c.cabinet_name, c.folder_capacity
        FROM storage_locations sl
        JOIN cabinets c ON sl.cabinet_id = c.id
        WHERE sl.cabinet_id = ?
        ORDER BY sl.layer, sl.box, sl.folder
    ");
    $stmt->execute([$cabinet_id]);
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Then, get all files for this cabinet and count them per location
    $stmt = $pdo->prepare("
        SELECT sl.id AS location_id, f.id AS file_id, f.file_name, u.username AS uploader, dt.name AS document_category
        FROM storage_locations sl
        LEFT JOIN file_storage fs ON sl.id = fs.storage_location_id
        LEFT JOIN files f ON fs.file_id = f.id
        LEFT JOIN users u ON f.user_id = u.id
        LEFT JOIN document_types dt ON f.document_type_id = dt.id
        WHERE sl.cabinet_id = ?
    ");
    $stmt->execute([$cabinet_id]);
    $file_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Organize files by location and calculate actual file count
    $files_by_location = [];
    foreach ($file_data as $row) {
        if ($row['file_id']) {
            $files_by_location[$row['location_id']][] = [
                'file_id' => $row['file_id'],
                'file_name' => $row['file_name'],
                'uploader' => $row['uploader'],
                'document_category' => $row['document_category']
            ];
        }
    }

    // Build the organized structure
    $organized = [];
    foreach ($locations as $loc) {
        $layer = (int)$loc['layer'];
        $box = (int)$loc['box'];
        $folder = (int)$loc['folder'];
        $actual_file_count = isset($files_by_location[$loc['id']]) ? count($files_by_location[$loc['id']]) : 0;

        if (!isset($organized[$layer][$box][$folder])) {
            $organized[$layer][$box][$folder] = [
                'id' => $loc['id'],
                'is_occupied' => $loc['is_occupied'],
                'file_count' => $actual_file_count, // Use actual count from file_storage
                'folder_capacity' => $loc['folder_capacity'],
                'files' => $files_by_location[$loc['id']] ?? []
            ];
        }
    }
    return $organized;
}

$cabinets = fetchAllCabinets($pdo);
$departments = fetchAllDepartments($pdo);

$departments_with_cabinets = [];
foreach ($cabinets as $cabinet) {
    $dept_id = $cabinet['department_id'];
    if (!isset($departments_with_cabinets[$dept_id])) {
        $departments_with_cabinets[$dept_id] = [
            'name' => $cabinet['department_name'],
            'cabinets' => []
        ];
    }
    $departments_with_cabinets[$dept_id]['cabinets'][] = $cabinet;
}
usort($departments_with_cabinets, fn($a, $b) => strcmp($a['name'], $b['name']));
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Physical Storage Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="admin-sidebar.css">
    <link rel="stylesheet" href="admin-interface.css">
    <style>
        .success-message {
            color: green;
            font-weight: bold;
        }

        .error-message {
            color: red;
            font-weight: bold;
        }

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

        .search-input,
        .search-select {
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

        .results-table th,
        .results-table td {
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
    </style>
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

    <div class="main-content sidebar-expanded">
        <button id="open-modal-btn" class="open-modal-btn">Add Cabinet</button>
        <?php if (!empty($error)): ?>
            <p class="error-message"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <p class="success-message"><?= htmlspecialchars($success) ?></p>
        <?php endif; ?>

        <?php foreach ($departments_with_cabinets as $dept): ?>
            <div class="department-section" data-dept-id="<?= htmlspecialchars($dept_id) ?>">
                <h2><?= htmlspecialchars($dept['name']) ?></h2>
                <div class="cabinet-grid">
                    <?php foreach ($dept['cabinets'] as $index => $cabinet): ?>
                        <div class="cabinet-card <?= $index >= 4 ? 'hidden' : '' ?>" onclick="openCabinetModal(<?= $cabinet['id'] ?>)">
                            <h3><?= htmlspecialchars($cabinet['cabinet_name']) ?></h3>
                            <p><strong>Location:</strong> <?= htmlspecialchars($cabinet['location']) ?></p>
                            <p><strong>Sub-Department:</strong> <?= htmlspecialchars($cabinet['sub_department_name'] ?? 'N/A') ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (count($dept['cabinets']) > 4): ?>
                    <button class="view-more-btn" onclick="toggleViewMore(this, <?= htmlspecialchars($dept_id) ?>)">View More</button>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <div class="modal" id="cabinet-modal">
            <div class="modal-content">
                <span class="close" onclick="closeModal('cabinet-modal')">×</span>
                <h2><?= isset($_GET['edit']) ? 'Edit Cabinet' : 'Add Cabinet' ?></h2>
                <form method="POST" action="physical_storage_management.php">
                    <input type="hidden" name="action" value="<?= isset($_GET['edit']) ? 'edit' : 'add' ?>">
                    <?php if (isset($_GET['edit'])): ?>
                        <input type="hidden" name="cabinet_id" value="<?= htmlspecialchars($_GET['edit']) ?>">
                    <?php endif; ?>
                    <div class="form-container">
                        <input type="text" name="cabinet_name" placeholder="Cabinet Name" value="<?= isset($_GET['edit']) ? htmlspecialchars($cabinets[array_search($_GET['edit'], array_column($cabinets, 'id'))]['cabinet_name']) : '' ?>" required>
                        <select name="department_id" id="department_id" required onchange="loadSubDepartments()">
                            <option value="">Select Department</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?= htmlspecialchars($dept['id']) ?>" <?= isset($_GET['edit']) && $cabinets[array_search($_GET['edit'], array_column($cabinets, 'id'))]['department_id'] == $dept['id'] ? 'selected' : '' ?>><?= htmlspecialchars($dept['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="sub_department_id" id="sub_department_id">
                            <option value="">Select Sub-Department (Optional)</option>
                            <?php
                            if (isset($_GET['edit'])) {
                                $cabinet = $cabinets[array_search($_GET['edit'], array_column($cabinets, 'id'))];
                                $stmt = $pdo->prepare("SELECT id, name FROM sub_departments WHERE department_id = ? ORDER BY name ASC");
                                $stmt->execute([$cabinet['department_id']]);
                                $sub_depts = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                foreach ($sub_depts as $sub): ?>
                                    <option value="<?= htmlspecialchars($sub['id']) ?>" <?= $cabinet['sub_department_id'] == $sub['id'] ? 'selected' : '' ?>><?= htmlspecialchars($sub['name']) ?></option>
                            <?php endforeach;
                            }
                            ?>
                        </select>
                        <input type="text" name="building" placeholder="Building" value="<?= isset($_GET['edit']) ? htmlspecialchars(explode(', ', $cabinets[array_search($_GET['edit'], array_column($cabinets, 'id'))]['location'])[0]) : '' ?>" required>
                        <input type="text" name="room" placeholder="Room" value="<?= isset($_GET['edit']) ? htmlspecialchars(explode(', ', $cabinets[array_search($_GET['edit'], array_column($cabinets, 'id'))]['location'])[1] ?? '') : '' ?>" required>
                        <input type="number" name="layers" placeholder="Number of Layers" min="1" value="<?= isset($_GET['edit']) ? htmlspecialchars($cabinets[array_search($_GET['edit'], array_column($cabinets, 'id'))]['layers']) : '' ?>" required>
                        <input type="number" name="boxes" placeholder="Number of Boxes" min="1" value="<?= isset($_GET['edit']) ? htmlspecialchars($cabinets[array_search($_GET['edit'], array_column($cabinets, 'id'))]['boxes']) : '' ?>" required>
                        <input type="number" name="folders" placeholder="Number of Folders" min="1" value="<?= isset($_GET['edit']) ? htmlspecialchars($cabinets[array_search($_GET['edit'], array_column($cabinets, 'id'))]['folders']) : '' ?>" required>
                        <input type="number" name="folder_capacity" placeholder="Folder Capacity (Max Files)" min="1" value="<?= isset($_GET['edit']) ? htmlspecialchars($cabinets[array_search($_GET['edit'], array_column($cabinets, 'id'))]['folder_capacity']) : '' ?>" required>
                        <button type="submit"><?= isset($_GET['edit']) ? 'Update Cabinet' : 'Add Cabinet' ?></button>
                    </div>
                </form>
            </div>
        </div>

        <?php foreach ($cabinets as $cabinet): ?>
            <div class="cabinet-modal" id="cabinet-modal-<?= $cabinet['id'] ?>">
                <div class="cabinet-modal-content">
                    <span class="close" onclick="closeModal('cabinet-modal-<?= $cabinet['id'] ?>')">×</span>
                    <h2><?= htmlspecialchars($cabinet['cabinet_name']) ?></h2>
                    <div class="cabinet-details">
                        <table>
                            <tr>
                                <th>Department</th>
                                <td><?= htmlspecialchars($cabinet['department_name']) ?></td>
                            </tr>
                            <tr>
                                <th>Sub-Department</th>
                                <td><?= htmlspecialchars($cabinet['sub_department_name'] ?? 'N/A') ?></td>
                            </tr>
                            <tr>
                                <th>Location</th>
                                <td><?= htmlspecialchars($cabinet['location']) ?></td>
                            </tr>
                            <tr>
                                <th>Layers</th>
                                <td><?= htmlspecialchars($cabinet['layers']) ?></td>
                            </tr>
                            <tr>
                                <th>Boxes</th>
                                <td><?= htmlspecialchars($cabinet['boxes']) ?></td>
                            </tr>
                            <tr>
                                <th>Folders</th>
                                <td><?= htmlspecialchars($cabinet['folders']) ?></td>
                            </tr>
                            <tr>
                                <th>Folder Capacity</th>
                                <td <?= $cabinet['folder_capacity'] <= 0 ? 'style="color: red;"' : '' ?>><?= htmlspecialchars($cabinet['folder_capacity']) ?></td>
                            </tr>
                        </table>
                    </div>
                    <div class="actions">
                        <a href="physical_storage_management.php?edit=<?= $cabinet['id'] ?>"><button class="edit-btn">Edit</button></a>
                        <a href="physical_storage_management.php?delete=<?= $cabinet['id'] ?>" onclick="return confirm('Are you sure you want to delete this cabinet?')"><button class="delete-btn">Delete</button></a>
                    </div>
                    <div class="file-search-form">
                        <h3>Search Files in <?= htmlspecialchars($cabinet['cabinet_name']) ?></h3>
                        <form id="file-search-form-<?= $cabinet['id'] ?>" method="POST" action="physical_storage_management.php">
                            <input type="hidden" name="cabinet_id" value="<?= $cabinet['id'] ?>">
                            <div class="search-filters">
                                <input type="text" name="file_name" placeholder="File Name (optional)" class="search-input">
                                <select name="document_type_id" class="search-select">
                                    <option value="">All Document Types</option>
                                    <?php foreach ($pdo->query("SELECT id, name FROM document_types ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC) as $doc_type): ?>
                                        <option value="<?= htmlspecialchars($doc_type['id']) ?>"><?= htmlspecialchars($doc_type['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="date" name="date_from" placeholder="From Date" class="search-input" title="Start date of upload">
                                <input type="date" name="date_to" placeholder="To Date" class="search-input" title="End date of upload">
                                <select name="uploader_id" class="search-select">
                                    <option value="">All Uploaders</option>
                                    <?php foreach ($pdo->query("SELECT id, username FROM users ORDER BY username ASC")->fetchAll(PDO::FETCH_ASSOC) as $user): ?>
                                        <option value="<?= htmlspecialchars($user['id']) ?>"><?= htmlspecialchars($user['username']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" name="search_files" class="search-btn"><i class="fas fa-search"></i> Search</button>
                            </div>
                        </form>
                        <div id="search-results-<?= $cabinet['id'] ?>" class="search-results">
                            <?php
                            if (isset($_POST['search_files']) && $_POST['cabinet_id'] == $cabinet['id']) {
                                $query = "
                                    SELECT f.id, f.file_name, f.upload_date, u.username, dt.name AS document_type
                                    FROM files f
                                    LEFT JOIN file_storage fs ON f.id = fs.file_id
                                    LEFT JOIN storage_locations sl ON fs.storage_location_id = sl.id
                                    LEFT JOIN users u ON f.user_id = u.id
                                    LEFT JOIN document_types dt ON f.document_type_id = dt.id
                                    WHERE sl.cabinet_id = :cabinet_id
                                    AND f.hard_copy_available = 1
                                ";
                                $params = [':cabinet_id' => $cabinet['id']];

                                if (!empty($_POST['file_name'])) {
                                    $query .= " AND f.file_name LIKE :file_name";
                                    $params[':file_name'] = '%' . $_POST['file_name'] . '%';
                                }
                                if (!empty($_POST['document_type_id'])) {
                                    $query .= " AND f.document_type_id = :document_type_id";
                                    $params[':document_type_id'] = $_POST['document_type_id'];
                                }
                                if (!empty($_POST['date_from'])) {
                                    $query .= " AND f.upload_date >= :date_from";
                                    $params[':date_from'] = $_POST['date_from'] . ' 00:00:00';
                                }
                                if (!empty($_POST['date_to'])) {
                                    $query .= " AND f.upload_date <= :date_to";
                                    $params[':date_to'] = $_POST['date_to'] . ' 23:59:59';
                                }
                                if (!empty($_POST['uploader_id'])) {
                                    $query .= " AND f.user_id = :uploader_id";
                                    $params[':uploader_id'] = $_POST['uploader_id'];
                                }

                                $query .= " ORDER BY f.upload_date DESC";
                                $stmt = $pdo->prepare($query);
                                $stmt->execute($params);
                                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

                                if ($results) {
                                    echo '<table class="results-table">';
                                    echo '<thead><tr><th>File Name</th><th>Document Type</th><th>Uploader</th><th>Upload Date</th></tr></thead>';
                                    echo '<tbody>';
                                    foreach ($results as $result) {
                                        echo '<tr>';
                                        echo '<td>' . htmlspecialchars($result['file_name']) . '</td>';
                                        echo '<td>' . htmlspecialchars($result['document_type']) . '</td>';
                                        echo '<td>' . htmlspecialchars($result['username'] ?? 'Unknown') . '</td>';
                                        echo '<td>' . htmlspecialchars($result['upload_date']) . '</td>';
                                        echo '</tr>';
                                    }
                                    echo '</tbody></table>';
                                } else {
                                    echo '<p class="no-results">No files found matching your criteria.</p>';
                                }
                            }
                            ?>
                        </div>
                    </div>
                    <div class="legend">
                        <div class="occupied"><span>Occupied</span></div>
                        <div class="available"><span>Available</span></div>
                    </div>
                    <div class="storage-container" id="storage-<?= $cabinet['id'] ?>">
                        <?php $storage_locations = fetchStorageLocations($pdo, $cabinet['id']); ?>
                        <div class="tabs" id="layer-tabs-<?= $cabinet['id'] ?>">
                            <?php foreach ($storage_locations as $layer => $boxes): ?>
                                <div class="tab <?= $layer === array_key_first($storage_locations) ? 'active' : '' ?>" onclick="showTab(<?= $cabinet['id'] ?>, 'layer', <?= $layer ?>)">
                                    Layer <?= $layer ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php foreach ($storage_locations as $layer => $boxes): ?>
                            <div class="tab-content <?= $layer === array_key_first($storage_locations) ? 'active' : '' ?>" id="layer-content-<?= $cabinet['id'] ?>-<?= $layer ?>">
                                <div class="tabs" id="box-tabs-<?= $cabinet['id'] ?>-<?= $layer ?>">
                                    <?php foreach ($boxes as $box => $folders): ?>
                                        <div class="tab <?= $box === array_key_first($boxes) ? 'active' : '' ?>" onclick="showTab(<?= $cabinet['id'] ?>, 'box', <?= $layer ?>, <?= $box ?>)">
                                            Box <?= $box ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php foreach ($boxes as $box => $folders): ?>
                                    <div class="tab-content <?= $box === array_key_first($boxes) ? 'active' : '' ?>" id="box-content-<?= $cabinet['id'] ?>-<?= $layer ?>-<?= $box ?>">
                                        <div class="tabs" id="folder-tabs-<?= $cabinet['id'] ?>-<?= $layer ?>-<?= $box ?>">
                                            <?php foreach ($folders as $folder => $data): ?>
                                                <div class="tab <?= $folder === array_key_first($folders) ? 'active' : '' ?>" onclick="showTab(<?= $cabinet['id'] ?>, 'folder', <?= $layer ?>, <?= $box ?>, <?= $folder ?>)">
                                                    Folder <?= $folder ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php foreach ($folders as $folder => $data): ?>
                                            <div class="tab-content <?= $folder === array_key_first($folders) ? 'active' : '' ?>" id="folder-content-<?= $cabinet['id'] ?>-<?= $layer ?>-<?= $box ?>-<?= $folder ?>">
                                                <table class="folder-table">
                                                    <thead>
                                                        <tr>
                                                            <th>Capacity</th>
                                                            <th>Status</th>
                                                            <th>File Name</th>
                                                            <th>Uploader</th>
                                                            <th>Category</th>
                                                            <th>Action</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php if (empty($data['files'])): ?>
                                                            <tr>
                                                                <td>
                                                                    <div class="capacity-controls">
                                                                        <span class="capacity-display" id="capacity-display-<?= $data['id'] ?>"><?= htmlspecialchars($data['file_count']) ?>/<?= htmlspecialchars($data['folder_capacity']) ?></span>
                                                                        <input type="range" class="capacity-slider" min="0" max="<?= htmlspecialchars($data['folder_capacity']) ?>" value="<?= htmlspecialchars($data['file_count']) ?>" onchange="updateFileCount(<?= $data['id'] ?>, this.value, 'capacity-display-<?= $data['id'] ?>')" oninput="updateCapacityDisplay(this.value, 'capacity-display-<?= $data['id'] ?>', <?= htmlspecialchars($data['folder_capacity']) ?>)">
                                                                    </div>
                                                                </td>
                                                                <td>
                                                                    <span class="status <?= $data['is_occupied'] ? 'occupied' : 'available' ?>" onclick="toggleStatus(<?= $data['id'] ?>, <?= $data['is_occupied'] ? 1 : 0 ?>)">
                                                                        <?= $data['is_occupied'] ? 'Occupied' : 'Available' ?>
                                                                    </span>
                                                                </td>
                                                                <td colspan="4" style="text-align: center; color: #7f8c8d;">No files stored.</td>
                                                            </tr>
                                                        <?php else: ?>
                                                            <?php $first = true;
                                                            foreach ($data['files'] as $file): ?>
                                                                <tr>
                                                                    <?php if ($first): ?>
                                                                        <td rowspan="<?= count($data['files']) ?>">
                                                                            <div class="capacity-controls">
                                                                                <span class="capacity-display" id="capacity-display-<?= $data['id'] ?>"><?= htmlspecialchars($data['file_count']) ?>/<?= htmlspecialchars($data['folder_capacity']) ?></span>
                                                                                <input type="range" class="capacity-slider" min="0" max="<?= htmlspecialchars($data['folder_capacity']) ?>" value="<?= htmlspecialchars($data['file_count']) ?>" onchange="updateFileCount(<?= $data['id'] ?>, this.value, 'capacity-display-<?= $data['id'] ?>')" oninput="updateCapacityDisplay(this.value, 'capacity-display-<?= $data['id'] ?>', <?= htmlspecialchars($data['folder_capacity']) ?>)">
                                                                            </div>
                                                                        </td>
                                                                        <td rowspan="<?= count($data['files']) ?>">
                                                                            <span class="status <?= $data['is_occupied'] ? 'occupied' : 'available' ?>" onclick="toggleStatus(<?= $data['id'] ?>, <?= $data['is_occupied'] ? 1 : 0 ?>)">
                                                                                <?= $data['is_occupied'] ? 'Occupied' : 'Available' ?>
                                                                            </span>
                                                                        </td>
                                                                    <?php endif; ?>
                                                                    <td><?= htmlspecialchars($file['file_name']) ?></td>
                                                                    <td><?= htmlspecialchars($file['uploader'] ?? 'Unknown') ?></td>
                                                                    <td><?= htmlspecialchars($file['document_category'] ?? 'N/A') ?></td>
                                                                    <td>
                                                                        <button class="remove-btn" onclick="confirmRemoveFile(<?= $file['file_id'] ?>, <?= $data['id'] ?>)">Remove</button>
                                                                    </td>
                                                                </tr>
                                                                <?php $first = false; ?>
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
            </div>
        <?php endforeach; ?>

        <div class="warning-modal" id="warning-unoccupy-modal">
            <div class="warning-modal-content">
                <span class="close" onclick="closeModal('warning-unoccupy-modal')">×</span>
                <h2>Warning</h2>
                <p>Are you sure you want to mark this as unoccupied? This will not remove files.</p>
                <div class="buttons">
                    <button class="confirm-btn" id="confirm-unoccupy">Yes</button>
                    <button class="cancel-btn" onclick="closeModal('warning-unoccupy-modal')">No</button>
                </div>
            </div>
        </div>

        <div class="warning-modal" id="warning-remove-file-modal">
            <div class="warning-modal-content">
                <span class="close" onclick="closeModal('warning-remove-file-modal')">×</span>
                <h2>Warning</h2>
                <p>Are you sure you want to remove this file? This action cannot be undone.</p>
                <div class="buttons">
                    <button class="confirm-btn" id="confirm-remove-file">Yes</button>
                    <button class="cancel-btn" onclick="closeModal('warning-remove-file-modal')">No</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const sidebar = document.querySelector('.sidebar');
            const mainContent = document.querySelector('.main-content');
            const toggleBtn = document.querySelector('.toggle-btn');
            if (sidebar.classList.contains('minimized')) {
                mainContent.classList.remove('sidebar-expanded');
                mainContent.classList.add('sidebar-minimized');
            } else {
                mainContent.classList.add('sidebar-expanded');
                mainContent.classList.remove('sidebar-minimized');
            }
            toggleBtn.addEventListener('click', () => {
                sidebar.classList.toggle('minimized');
                mainContent.classList.toggle('sidebar-expanded');
                mainContent.classList.toggle('sidebar-minimized');
            });
        });

        const addModal = document.getElementById("cabinet-modal");
        document.getElementById("open-modal-btn").addEventListener('click', () => addModal.style.display = "flex");

        function openCabinetModal(cabinetId) {
            const modal = document.getElementById(`cabinet-modal-${cabinetId}`);
            if (modal) modal.style.display = "flex";
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) modal.style.display = "none";
        }

        window.addEventListener('click', (event) => {
            const target = event.target;
            if (target.classList.contains('modal') || target.classList.contains('cabinet-modal') || target.classList.contains('warning-modal')) {
                target.style.display = "none";
            }
        });

        <?php if (isset($_GET['edit'])): ?>
            addModal.style.display = "flex";
        <?php endif; ?>

        function toggleViewMore(button, deptId) {
            const section = document.querySelector(`.department-section[data-dept-id="${deptId}"]`);
            const grid = section.querySelector('.cabinet-grid');
            const hiddenCards = grid.querySelectorAll('.cabinet-card.hidden');
            hiddenCards.forEach(card => card.classList.remove('hidden'));
            button.style.display = 'none';
        }

        function loadSubDepartments() {
            const deptId = document.getElementById('department_id').value;
            const subDeptSelect = document.getElementById('sub_department_id');
            const selectedSubDeptId = subDeptSelect.dataset.selected || ''; // Preserve selected value if editing
            subDeptSelect.innerHTML = '<option value="">Select Sub-Department (Optional)</option>';
            if (deptId) {
                fetch(`get_sub_departments.php?department_id=${deptId}`)
                    .then(response => response.json())
                    .then(data => {
                        if (Array.isArray(data)) {
                            data.forEach(sub => {
                                const option = document.createElement('option');
                                option.value = sub.id;
                                option.textContent = sub.name;
                                if (sub.id == selectedSubDeptId) option.selected = true;
                                subDeptSelect.appendChild(option);
                            });
                        }
                    })
                    .catch(error => console.error('Error fetching sub-departments:', error));
            }
        }

        // Set the selected sub-department ID when opening the edit modal
        <?php if (isset($_GET['edit'])): ?>
            document.getElementById('sub_department_id').dataset.selected = "<?= htmlspecialchars($cabinets[array_search($_GET['edit'], array_column($cabinets, 'id'))]['sub_department_id'] ?? '') ?>";
            loadSubDepartments();
        <?php endif; ?>

        function showTab(cabinetId, level, layer, box = null, folder = null) {
            const container = document.getElementById(`storage-${cabinetId}`);
            if (level === 'layer') {
                const layerContents = container.querySelectorAll(`[id^="layer-content-${cabinetId}-"]`);
                layerContents.forEach(content => content.classList.remove('active'));
                document.getElementById(`layer-content-${cabinetId}-${layer}`).classList.add('active');
                container.querySelectorAll(`#layer-tabs-${cabinetId} .tab`).forEach(tab => tab.classList.remove('active'));
                container.querySelector(`#layer-tabs-${cabinetId} .tab:nth-child(${layer})`).classList.add('active');
            } else if (level === 'box') {
                const boxContents = container.querySelectorAll(`[id^="box-content-${cabinetId}-${layer}-"]`);
                boxContents.forEach(content => content.classList.remove('active'));
                document.getElementById(`box-content-${cabinetId}-${layer}-${box}`).classList.add('active');
                container.querySelectorAll(`#box-tabs-${cabinetId}-${layer} .tab`).forEach(tab => tab.classList.remove('active'));
                container.querySelector(`#box-tabs-${cabinetId}-${layer} .tab:nth-child(${box})`).classList.add('active');
            } else if (level === 'folder') {
                const folderContents = container.querySelectorAll(`[id^="folder-content-${cabinetId}-${layer}-${box}-"]`);
                folderContents.forEach(content => content.classList.remove('active'));
                document.getElementById(`folder-content-${cabinetId}-${layer}-${box}-${folder}`).classList.add('active');
                container.querySelectorAll(`#folder-tabs-${cabinetId}-${layer}-${box} .tab`).forEach(tab => tab.classList.remove('active'));
                container.querySelector(`#folder-tabs-${cabinetId}-${layer}-${box} .tab:nth-child(${folder})`).classList.add('active');
            }
        }

        const statusManager = {
            pendingLocationId: null,
            pendingIsOccupied: null,
            toggleStatus(locationId, isOccupied) {
                this.pendingLocationId = locationId;
                this.pendingIsOccupied = isOccupied;
                if (isOccupied === 1) {
                    document.getElementById('warning-unoccupy-modal').style.display = 'flex';
                } else {
                    this.updateStatus(locationId, 1);
                }
            },
            updateStatus(locationId, isOccupied) {
                fetch('physical_storage_management.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: `update_storage_location=1&location_id=${locationId}&is_occupied=${isOccupied}`
                }).then(response => {
                    if (!response.ok) throw new Error('Network response was not ok');
                    location.reload();
                }).catch(error => alert('Failed to update status: ' + error.message));
            },
            confirmUnoccupy() {
                if (this.pendingLocationId !== null) {
                    this.updateStatus(this.pendingLocationId, 0);
                    closeModal('warning-unoccupy-modal');
                    this.pendingLocationId = null;
                }
            }
        };

        function toggleStatus(locationId, isOccupied) {
            statusManager.toggleStatus(locationId, isOccupied);
        }

        document.getElementById('confirm-unoccupy').addEventListener('click', () => statusManager.confirmUnoccupy());

        function updateCapacityDisplay(value, displayId, maxCapacity) {
            const display = document.getElementById(displayId);
            display.textContent = `${value}/${maxCapacity}`;
        }

        function updateFileCount(locationId, fileCount, displayId) {
            fetch('physical_storage_management.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `update_file_count=1&location_id=${locationId}&file_count=${fileCount}`
            }).then(response => {
                if (!response.ok) throw new Error('Network response was not ok');
                location.reload();
            }).catch(error => alert('Failed to update file count: ' + error.message));
        }

        const fileManager = {
            pendingFileId: null,
            pendingLocationId: null,
            confirmRemoveFile(fileId, locationId) {
                this.pendingFileId = fileId;
                this.pendingLocationId = locationId;
                document.getElementById('warning-remove-file-modal').style.display = 'flex';
            },
            removeFile() {
                if (this.pendingFileId !== null && this.pendingLocationId !== null) {
                    fetch('physical_storage_management.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: `remove_file=1&file_id=${this.pendingFileId}&location_id=${this.pendingLocationId}`
                    }).then(response => {
                        if (!response.ok) throw new Error('Network response was not ok');
                        location.reload();
                    }).catch(error => alert('Failed to remove file: ' + error.message));
                    closeModal('warning-remove-file-modal');
                    this.pendingFileId = null;
                    this.pendingLocationId = null;
                }
            }
        };

        function confirmRemoveFile(fileId, locationId) {
            fileManager.confirmRemoveFile(fileId, locationId);
        }

        document.getElementById('confirm-remove-file').addEventListener('click', () => fileManager.removeFile());

        document.querySelectorAll('.storage-container').forEach(container => {
            const cabinetId = container.id.replace('storage-', '');
            const firstLayerTab = container.querySelector(`#layer-tabs-${cabinetId} .tab:first-child`);
            if (firstLayerTab) firstLayerTab.click();
        });
    </script>
</body>

</html>