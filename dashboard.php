<?php
session_start();
require 'db_connection.php';
require 'log_activity.php';

// Security: Validate session and regenerate ID
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
session_regenerate_id(true);

// CSRF Token Generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$userId = filter_var($_SESSION['user_id'], FILTER_SANITIZE_NUMBER_INT);
$userRole = filter_var($_SESSION['role'] ?? 'user', FILTER_SANITIZE_STRING);

// Fetch user details including department
$stmt = $pdo->prepare("
    SELECT u.User_id, u.Username, u.Role, u.Profile_pic, u.Position, 
           d.Department_id, d.Department_name
    FROM users u
    LEFT JOIN users_department ud ON u.User_id = ud.User_id
    LEFT JOIN departments d ON ud.Department_id = d.Department_id
    WHERE u.User_id = ?
    LIMIT 1
");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    error_log("User not found for ID: $userId");
    header('Location: logout.php');
    exit;
}

// Fetch user departments
$stmt = $pdo->prepare("
    SELECT d.Department_id, d.Department_name
    FROM departments d
    JOIN users_department ud ON d.Department_id = ud.Department_id
    WHERE ud.User_id = ?
");
$stmt->execute([$userId]);
$userDepartments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch document types
$stmt = $pdo->prepare("SELECT DISTINCT Field_name AS name FROM documents_type_fields ORDER BY Field_name ASC");
$stmt->execute();
$docTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch recent files (owned by user)
$stmt = $pdo->prepare("
    SELECT f.File_id, f.File_name, f.File_type, f.Upload_date, f.Copy_type, dtf.Field_name AS document_type
    FROM files f
    LEFT JOIN documents_type_fields dtf ON f.Document_type_id = dtf.Document_type_id
    WHERE f.User_id = ? AND f.File_status != 'deleted'
    ORDER BY f.Upload_date DESC
    LIMIT 5
");
$stmt->execute([$userId]);
$recentFiles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch notifications (Transaction_type = 12)
$stmt = $pdo->prepare("
    SELECT t.Transaction_id AS id, t.File_id, t.Transaction_status AS status, t.Time AS timestamp, t.Massage AS message,
           COALESCE(f.File_name, 'Unknown File') AS file_name
    FROM transaction t
    LEFT JOIN files f ON t.File_id = f.File_id
    WHERE t.User_id = ? AND t.Transaction_type = 12
    AND (f.File_status != 'deleted' OR f.File_id IS NULL)
    ORDER BY t.Time DESC
    LIMIT 5
");
$stmt->execute([$userId]);
$notificationLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch activity logs (only user-initiated actions)
$stmt = $pdo->prepare("
    SELECT t.Transaction_id, t.Massage AS action, t.Time AS timestamp
    FROM transaction t
    LEFT JOIN files f ON t.File_id = f.File_id
    WHERE t.User_id = ? 
    AND t.Transaction_type IN (1, 2, 10, 11, 13, 14, 15)
    AND (f.File_status != 'deleted' OR f.File_id IS NULL)
    ORDER BY t.Time DESC
    LIMIT 5
");
$stmt->execute([$userId]);
$activityLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all user files
$stmt = $pdo->prepare("
    SELECT f.File_id, f.File_name, f.File_type, f.Upload_date, f.Copy_type, dtf.Field_name AS document_type
    FROM files f
    LEFT JOIN documents_type_fields dtf ON f.Document_type_id = dtf.Document_type_id
    WHERE f.User_id = ? AND f.File_status != 'deleted'
    ORDER BY f.Upload_date DESC
");
$stmt->execute([$userId]);
$filesUploaded = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch files sent to user (Transaction_type = 2)
$stmt = $pdo->prepare("
    SELECT DISTINCT f.File_id, f.File_name, f.File_type, f.Upload_date, f.Copy_type, dtf.Field_name AS document_type
    FROM files f
    JOIN transaction t ON f.File_id = t.File_id
    LEFT JOIN documents_type_fields dtf ON f.Document_type_id = dtf.Document_type_id
    WHERE t.User_id = ? AND t.Transaction_type = 2
    AND t.Transaction_status IN ('pending', 'accepted')
    AND f.File_status != 'deleted'
    ORDER BY f.Upload_date DESC
");
$stmt->execute([$userId]);
$filesSentToMe = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch files requested by user (Transaction_type = 10)
$stmt = $pdo->prepare("
    SELECT DISTINCT f.File_id, f.File_name, f.File_type, f.Upload_date, f.Copy_type, dtf.Field_name AS document_type
    FROM files f
    JOIN transaction t ON f.File_id = t.File_id
    LEFT JOIN documents_type_fields dtf ON f.Document_type_id = dtf.Document_type_id
    WHERE t.User_id = ? AND t.Transaction_type = 10
    AND t.Transaction_status = 'pending'
    AND f.File_status != 'deleted'
    ORDER BY t.Time DESC
");
$stmt->execute([$userId]);
$filesRequested = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch department files
$departmentFiles = [];
foreach ($userDepartments as $dept) {
    $deptId = $dept['Department_id'];
    $stmt = $pdo->prepare("
        SELECT f.File_id, f.File_name, f.File_type, f.Upload_date, f.Copy_type, dtf.Field_name AS document_type
        FROM files f
        JOIN transaction t ON f.File_id = t.File_id
        LEFT JOIN documents_type_fields dtf ON f.Document_type_id = dtf.Document_type_id
        WHERE t.Users_Department_id IN (
            SELECT Users_Department_id FROM users_department WHERE Department_id = ? AND User_id = ?
        )
        AND t.Transaction_type = 2
        AND t.Transaction_status = 'accepted'
        AND f.File_status != 'deleted'
        ORDER BY f.Upload_date DESC
    ");
    $stmt->execute([$deptId, $userId]);
    $departmentFiles[$deptId] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Returns Font Awesome icon class based on file extension.
 *
 * @param string $fileName
 * @return string
 */
function getFileIcon(string $fileName): string
{
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    switch ($extension) {
        case 'pdf':
            return 'fas fa-file-pdf';
        case 'doc':
        case 'docx':
            return 'fas fa-file-word';
        case 'xls':
        case 'xlsx':
            return 'fas fa-file-excel';
        case 'jpg':
        case 'png':
            return 'fas fa-file-image';
        default:
            return 'fas fa-file';
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
    <title>Dashboard - Document Archival</title>
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link href="arXiv.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <link rel="stylesheet" href="style/dashboard.css">
    <link rel="stylesheet" href="style/client-sidebar.css">
    <style>
        .notification-log .no-notifications {
            color: #6c757d;
            font-style: italic;
            text-align: center;
            padding: 10px;
        }
    </style>
</head>

<body>
    <aside class="sidebar">
        <button class="toggle-btn" title="Toggle Sidebar" aria-label="Toggle Sidebar"><i class="fas fa-bars"></i></button>
        <h2 class="sidebar-title">Document Archival</h2>
        <?php if ($userRole === 'admin'): ?>
            <a href="admin_dashboard.php" class="admin-dashboard-btn" data-tooltip="Admin Dashboard"><i class="fas fa-user-shield"></i><span class="link-text">Admin Dashboard</span></a>
        <?php endif; ?>
        <a href="dashboard.php" class="<?= htmlspecialchars(basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'active' : '') ?>" data-tooltip="Dashboard"><i class="fas fa-home"></i><span class="link-text">Dashboard</span></a>
        <a href="my-report.php" data-tooltip="My Report"><i class="fas fa-chart-bar"></i><span class="link-text">My Report</span></a>
        <a href="my-folder.php" class="<?= htmlspecialchars(basename($_SERVER['PHP_SELF']) === 'my-folder.php' ? 'active' : '') ?>" data-tooltip="My Folder"><i class="fas fa-folder"></i><span class="link-text">My Folder</span></a>
        <?php foreach ($userDepartments as $dept): ?>
            <a href="department_folder.php?department_id=<?= htmlspecialchars($dept['Department_id']) ?>"
                class="<?= isset($_GET['department_id']) && (int)$_GET['department_id'] === $dept['Department_id'] ? 'active' : '' ?>"
                data-tooltip="<?= htmlspecialchars($dept['Department_name'] ?? 'Unnamed Department') ?>">
                <i class="fas fa-folder"></i>
                <span class="link-text"><?= htmlspecialchars($dept['Department_name'] ?? 'Unnamed Department') ?></span>
            </a>
        <?php endforeach; ?>
        <a href="logout.php" class="logout-btn" data-tooltip="Logout"><i class="fas fa-sign-out-alt"></i><span class="link-text">Logout</span></a>
    </aside>

    <header class="top-nav">
        <h2>Dashboard</h2>
        <form action="search.php" method="GET" class="search-container" id="search-form">
            <input type="text" id="searchInput" name="q" placeholder="Search documents..." value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
            <select name="type" id="document-type">
                <option value="">All Document Types</option>
                <?php foreach ($docTypes as $type): ?>
                    <option value="<?= htmlspecialchars($type['name']) ?>" <?= ($_GET['type'] ?? '') === $type['name'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars(ucfirst($type['name'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="folder" id="folder">
                <option value="">All Folders</option>
                <option value="my-folder" <?= ($_GET['folder'] ?? '') === 'my-folder' ? 'selected' : '' ?>>My Folder</option>
                <?php foreach ($userDepartments as $dept): ?>
                    <option value="department-<?= htmlspecialchars($dept['Department_id']) ?>"
                        <?= ($_GET['folder'] ?? '') === 'department-' . $dept['Department_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($dept['Department_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" aria-label="Search Documents"><i class="fas fa-search"></i></button>
        </form>
        <i class="fas fa-history activity-log-icon" onclick="toggleActivityLog()" title="View Activity Log" aria-label="View Activity Log"></i>
    </header>

    <main class="main-content">
        <section class="user-id-calendar-container">
            <div class="user-id">
                <img src="<?= htmlspecialchars($user['Profile_pic'] ? 'data:image/jpeg;base64,' . base64_encode($user['Profile_pic']) : 'user.jpg') ?>"
                    alt="User Picture" class="user-picture">
                <div class="user-info">
                    <p class="user-name"><?= htmlspecialchars($user['Username'] ?? 'Unknown User') ?></p>
                    <p class="user-position"><?= htmlspecialchars($user['Position'] ?? 'No Position') ?></p>
                    <p class="user-department"><?= htmlspecialchars($user['Department_name'] ?? 'No Department') ?></p>
                </div>
            </div>
            <div class="digital-calendar-clock">
                <p id="currentDate"></p>
                <p id="currentTime"></p>
            </div>
        </section>

        <section class="upload-activity-container">
            <div class="upload-file" id="upload">
                <h3>Send a Document</h3>
                <button id="selectDocumentButton">Select Document</button>
            </div>
            <div class="upload-file" id="fileUpload">
                <h3>Upload File</h3>
                <button id="uploadFileButton">Upload File</button>
            </div>
            <div class="notification-log">
                <h3>Notifications</h3>
                <div class="log-entries">
                    <?php if (!empty($notificationLogs)): ?>
                        <?php foreach ($notificationLogs as $notification): ?>
                            <div class="log-entry notification-item <?= $notification['status'] === 'pending' ? 'pending-access' : 'processed-access' ?>"
                                data-notification-id="<?= htmlspecialchars($notification['id']) ?>"
                                data-file-id="<?= htmlspecialchars($notification['file_id'] ?? '') ?>"
                                data-message="<?= htmlspecialchars($notification['message']) ?>"
                                data-status="<?= htmlspecialchars($notification['status']) ?>">
                                <i class="fas fa-bell"></i>
                                <p><?= htmlspecialchars($notification['message']) ?></p>
                                <span><?= date('h:i A', strtotime($notification['timestamp'])) ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="log-entry no-notifications">
                            <p>No new notifications.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="owned-files-section">
            <h2>My Files</h2>
            <div class="files-grid">
                <div class="file-subsection">
                    <h3>My Documents</h3>
                    <div class="file-controls">
                        <div class="sort-controls">
                            <label>Sort by Name:</label>
                            <select class="sort-personal-name" onchange="sortPersonalFiles()">
                                <option value="">Select</option>
                                <option value="name-asc">A-Z</option>
                                <option value="name-desc">Z-A</option>
                            </select>
                            <label>Sort by Document Type:</label>
                            <select class="sort-personal-type" onchange="sortPersonalFiles()">
                                <option value="">Select</option>
                                <?php foreach ($docTypes as $type): ?>
                                    <option value="type-<?= htmlspecialchars($type['name']) ?>">
                                        <?= htmlspecialchars(ucfirst($type['name'])) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <label>Sort by Source:</label>
                            <select class="sort-personal-source" onchange="sortPersonalFiles()">
                                <option value="">Select</option>
                                <option value="uploaded">Uploaded Files</option>
                                <option value="received">Files Received</option>
                                <option value="requested">Files Requested</option>
                            </select>
                        </div>
                        <div class="filter-controls">
                            <label><input type="checkbox" id="hardCopyPersonalFilter" onchange="filterPersonalFilesByHardCopy()"> Hard Copy Only</label>
                        </div>
                    </div>
                    <div class="file-grid" id="personalFiles">
                        <?php if (!empty($filesUploaded) || !empty($filesSentToMe) || !empty($filesRequested)): ?>
                            <?php foreach (array_merge($filesUploaded, $filesSentToMe, $filesRequested) as $file): ?>
                                <div class="file-item"
                                    data-file-id="<?= htmlspecialchars($file['File_id']) ?>"
                                    data-file-name="<?= htmlspecialchars($file['File_name']) ?>"
                                    data-document-type="<?= htmlspecialchars($file['document_type'] ?? 'Unknown') ?>"
                                    data-upload-date="<?= htmlspecialchars($file['Upload_date']) ?>"
                                    data-hard-copy="<?= htmlspecialchars($file['Copy_type'] === 'hard' ? '1' : '0') ?>"
                                    data-source="<?= in_array($file, $filesUploaded) ? 'uploaded' : (in_array($file, $filesSentToMe) ? 'received' : 'requested') ?>">
                                    <i class="<?= getFileIcon($file['File_name']) ?> file-icon"></i>
                                    <p class="file-name"><?= htmlspecialchars($file['File_name']) ?></p>
                                    <p class="file-type"><?= htmlspecialchars(ucfirst($file['document_type'] ?? 'Unknown')) ?></p>
                                    <p class="file-date"><?= date('M d, Y', strtotime($file['Upload_date'])) ?></p>
                                    <span class="hard-copy-indicator"><?= $file['Copy_type'] === 'hard' ? '<i class="fas fa-print"></i> Hard Copy' : '' ?></span>
                                    <button class="view-file-button" onclick="viewFile(<?= htmlspecialchars($file['File_id']) ?>)">View</button>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p>No personal files available.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <?php foreach ($userDepartments as $dept): ?>
                    <div class="file-subsection">
                        <h3><?= htmlspecialchars($dept['Department_name']) ?> Documents</h3>
                        <div class="file-controls">
                            <div class="sort-controls">
                                <label>Sort by Name:</label>
                                <select class="sort-department-name" data-dept-id="<?= htmlspecialchars($dept['Department_id']) ?>"
                                    onchange="sortDepartmentFiles(<?= htmlspecialchars($dept['Department_id']) ?>)">
                                    <option value="">Select</option>
                                    <option value="name-asc">A-Z</option>
                                    <option value="name-desc">Z-A</option>
                                </select>
                                <label>Sort by Document Type:</label>
                                <select class="sort-department-type" data-dept-id="<?= htmlspecialchars($dept['Department_id']) ?>"
                                    onchange="sortDepartmentFiles(<?= htmlspecialchars($dept['Department_id']) ?>)">
                                    <option value="">Select</option>
                                    <?php foreach ($docTypes as $type): ?>
                                        <option value="type-<?= htmlspecialchars($type['name']) ?>">
                                            <?= htmlspecialchars(ucfirst($type['name'])) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-controls">
                                <label><input type="checkbox" class="hard-copy-department-filter"
                                        data-dept-id="<?= htmlspecialchars($dept['Department_id']) ?>"
                                        onchange="filterDepartmentFilesByHardCopy(<?= htmlspecialchars($dept['Department_id']) ?>)"> Hard Copy Only</label>
                            </div>
                        </div>
                        <div class="file-grid department-files-grid" id="departmentFiles-<?= htmlspecialchars($dept['Department_id']) ?>">
                            <?php if (!empty($departmentFiles[$dept['Department_id']])): ?>
                                <?php foreach ($departmentFiles[$dept['Department_id']] as $file): ?>
                                    <div class="file-item"
                                        data-file-id="<?= htmlspecialchars($file['File_id']) ?>"
                                        data-file-name="<?= htmlspecialchars($file['File_name']) ?>"
                                        data-document-type="<?= htmlspecialchars($file['document_type'] ?? 'Unknown') ?>"
                                        data-upload-date="<?= htmlspecialchars($file['Upload_date']) ?>"
                                        data-hard-copy="<?= htmlspecialchars($file['Copy_type'] === 'hard' ? '1' : '0') ?>"
                                        data-source="department">
                                        <i class="<?= getFileIcon($file['File_name']) ?> file-icon"></i>
                                        <p class="file-name"><?= htmlspecialchars($file['File_name']) ?></p>
                                        <p class="file-type"><?= htmlspecialchars(ucfirst($file['document_type'] ?? 'Unknown')) ?></p>
                                        <p class="file-date"><?= date('M d, Y', strtotime($file['Upload_date'])) ?></p>
                                        <span class="hard-copy-indicator"><?= $file['Copy_type'] === 'hard' ? '<i class="fas fa-print"></i> Hard Copy' : '' ?></span>
                                        <button class="view-file-button" onclick="viewFile(<?= htmlspecialchars($file['File_id']) ?>)">View</button>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p>No files available for this department.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Popups -->
        <div class="popup-file-selection" id="fileSelectionPopup">
            <button class="exit-button" onclick="closePopup('fileSelectionPopup')" aria-label="Close Popup">×</button>
            <h3>Select a Document</h3>
            <div class="search-container">
                <input type="text" id="fileSearch" placeholder="Search files..." oninput="filterFiles()">
                <select id="documentTypeFilter" onchange="filterFilesByType()">
                    <option value="">All Document Types</option>
                    <?php foreach ($docTypes as $type): ?>
                        <option value="<?= htmlspecialchars($type['name']) ?>"><?= htmlspecialchars(ucfirst($type['name'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="view-toggle">
                <button id="thumbnailViewButton" class="active" onclick="switchView('thumbnail')"><i class="fas fa-th-large"></i> Thumbnails</button>
                <button id="listViewButton" onclick="switchView('list')"><i class="fas fa-list"></i> List</button>
            </div>
            <div id="fileDisplay" class="thumbnail-view masonry-grid">
                <?php foreach ($filesUploaded as $file): ?>
                    <div class="file-item"
                        data-file-id="<?= htmlspecialchars($file['File_id']) ?>"
                        data-file-name="<?= htmlspecialchars($file['File_name']) ?>"
                        data-document-type="<?= htmlspecialchars($file['document_type'] ?? 'Unknown') ?>">
                        <div class="file-icon"><i class="<?= getFileIcon($file['File_name']) ?>"></i></div>
                        <p><?= htmlspecialchars($file['File_name']) ?></p>
                        <button class="select-file-button">Select</button>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="popup-questionnaire" id="fileDetailsPopup">
            <button class="exit-button" onclick="closePopup('fileDetailsPopup')" aria-label="Close Popup">×</button>
            <h3>Upload File Details</h3>
            <p class="subtitle">Provide details for the document you're uploading.</p>
            <form id="fileDetailsForm" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <label for="departmentId">Department:</label>
                <select id="departmentId" name="department_id">
                    <option value="">No Department</option>
                    <?php foreach ($userDepartments as $dept): ?>
                        <option value="<?= htmlspecialchars($dept['Department_id']) ?>" <?= $dept['Department_id'] == $user['Department_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($dept['Department_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <label for="documentType">Document Type:</label>
                <select id="documentType" name="document_type" required>
                    <option value="">Select Document Type</option>
                    <?php foreach ($docTypes as $type): ?>
                        <option value="<?= htmlspecialchars($type['name']) ?>"><?= htmlspecialchars(ucfirst($type['name'])) ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="cabinet">Cabinet:</label>
                <input type="text" id="cabinet" name="cabinet">
                <div id="dynamicFields"></div>
                <div class="hardcopy-options">
                    <label class="checkbox-container">
                        <input type="checkbox" id="hardcopyCheckbox" name="hard_copy_available">
                        <span class="checkmark"></span>
                        This file has a hardcopy
                    </label>
                    <div class="radio-group" id="hardcopyOptions" style="display: none;">
                        <label class="radio-container">
                            <input type="radio" name="hardcopyOption" value="link" checked>
                            <span class="radio-checkmark"></span>
                            Link to existing hardcopy
                        </label>
                        <label class="radio-container">
                            <input type="radio" name="hardcopyOption" value="new">
                            <span class="radio-checkmark"></span>
                            Suggest new storage location
                        </label>
                        <div class="storage-suggestion" id="storageSuggestion"></div>
                    </div>
                    <div class="hardcopy-details" id="hardcopyDetails" style="display: none;">
                        <label for="layer">Layer:</label>
                        <input type="number" id="layer" name="layer" min="0">
                        <label for="box">Box:</label>
                        <input type="number" id="box" name="box" min="0">
                        <label for="folder">Folder:</label>
                        <input type="number" id="folder" name="folder" min="0">
                    </div>
                </div>
                <div class="button-group">
                    <button type="button" class="btn-back" onclick="closePopup('fileDetailsPopup')">Cancel</button>
                    <button type="button" class="btn-next" onclick="proceedToHardcopy()">Next</button>
                </div>
            </form>
        </div>

        <div class="popup-questionnaire" id="sendFilePopup">
            <button class="exit-button" onclick="closePopup('sendFilePopup')" aria-label="Close Popup">×</button>
            <h3>Send a File</h3>
            <form id="sendFileForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <label for="recipients">Recipients (Users or Departments):</label>
                <select id="recipientSelect" name="recipients[]" multiple style="width: 100%;">
                    <optgroup label="Users">
                        <?php
                        $stmt = $pdo->prepare("SELECT User_id, Username FROM users WHERE User_id != ?");
                        $stmt->execute([$userId]);
                        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($users as $userOption): ?>
                            <option value="user:<?= htmlspecialchars($userOption['User_id']) ?>">
                                <?= htmlspecialchars($userOption['Username']) ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                    <optgroup label="Departments">
                        <?php
                        $stmt = $pdo->prepare("SELECT Department_id, Department_name FROM departments");
                        $stmt->execute();
                        $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($departments as $dept): ?>
                            <option value="department:<?= htmlspecialchars($dept['Department_id']) ?>">
                                <?= htmlspecialchars($dept['Department_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                </select>
                <div class="button-group">
                    <button type="button" class="btn-back" onclick="closePopup('sendFilePopup')">Cancel</button>
                    <button type="button" class="btn-next" onclick="sendFile()">Send</button>
                </div>
            </form>
        </div>

        <div class="popup-questionnaire" id="hardcopyStoragePopup">
            <button class="exit-button" onclick="closePopup('hardcopyStoragePopup')" aria-label="Close Popup">×</button>
            <h3>Hardcopy Storage</h3>
            <p class="subtitle">Specify how to manage the physical copy.</p>
            <div class="hardcopy-options">
                <label class="checkbox-container">
                    <input type="checkbox" id="hardcopyCheckbox" name="hard_copy_available">
                    <span class="checkmark"></span>
                    This file has a hardcopy
                </label>
                <div class="radio-group" id="hardcopyOptions" style="display: none;">
                    <label class="radio-container">
                        <input type="radio" name="hardcopyOption" value="link" checked>
                        <span class="radio-checkmark"></span>
                        Link to existing hardcopy
                    </label>
                    <label class="radio-container">
                        <input type="radio" name="hardcopyOption" value="new">
                        <span class="radio-checkmark"></span>
                        Suggest new storage location
                    </label>
                    <div class="storage-suggestion" id="storageSuggestion"></div>
                </div>
                <div class="hardcopy-details" id="hardcopyDetails" style="display: none;">
                    <label for="layer">Layer:</label>
                    <input type="number" id="layer" name="layer" min="0">
                    <label for="box">Box:</label>
                    <input type="number" id="box" name="box" min="0">
                    <label for="folder">Folder:</label>
                    <input type="number" id="folder" name="folder" min="0">
                </div>
            </div>
            <div class="button-group">
                <button class="btn-back" onclick="handleHardcopyBack()">Back</button>
                <button class="btn-next" onclick="handleHardcopyNext()">Next</button>
            </div>
        </div>

        <div class="popup-questionnaire" id="linkHardcopyPopup">
            <button class="exit-button" onclick="closePopup('linkHardcopyPopup')" aria-label="Close Popup">×</button>
            <h3>Link to Existing Hardcopy</h3>
            <div class="search-container">
                <input type="text" id="hardcopySearch" placeholder="Search hardcopy files..." oninput="filterHardcopies()">
            </div>
            <div class="file-list" id="hardcopyList"></div>
            <div class="button-group">
                <button class="btn-back" onclick="closePopup('linkHardcopyPopup')">Cancel</button>
                <button id="linkHardcopyButton" class="btn-next" disabled onclick="linkHardcopy()">Link</button>
            </div>
        </div>

        <div class="popup-questionnaire" id="fileAcceptancePopup">
            <button class="exit-button" onclick="closePopup('fileAcceptancePopup')" aria-label="Close Popup">×</button>
            <h3 id="fileAcceptanceTitle">Review File</h3>
            <p id="fileAcceptanceMessage"></p>
            <div class="file-preview" id="filePreview"></div>
            <div class="button-group">
                <button id="acceptFileButton">Accept</button>
                <button id="denyFileButton">Deny</button>
            </div>
        </div>

        <div class="popup-questionnaire" id="alreadyProcessedPopup">
            <button class="exit-button" onclick="closePopup('alreadyProcessedPopup')" aria-label="Close Popup">×</button>
            <h3>Request Status</h3>
            <p id="alreadyProcessedMessage"></p>
        </div>

        <div class="activity-log" id="activityLog">
            <h3>Activity Log</h3>
            <div class="log-entries">
                <?php if (!empty($activityLogs)): ?>
                    <?php foreach ($activityLogs as $log): ?>
                        <div class="log-entry">
                            <i class="fas fa-history"></i>
                            <p><?= htmlspecialchars($log['action']) ?></p>
                            <span><?= date('h:i A', strtotime($log['timestamp'])) ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="log-entry no-notifications">
                        <p>No recent activity.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        const notyf = new Notyf();
        let selectedFile = null;
        let selectedHardcopyId = null;

        $(document).ready(function() {
            $('#recipientSelect').select2({
                placeholder: "Select users or departments",
                allowClear: true,
                dropdownCssClass: 'select2-high-zindex'
            });

            $('.toggle-btn').on('click', function() {
                $('.sidebar').toggleClass('minimized');
                $('.top-nav, .main-content').toggleClass('resized', $('.sidebar').hasClass('minimized'));
            });

            function updateDateTime() {
                const now = new Date();
                $('#currentDate').text(now.toLocaleDateString('en-US', {
                    weekday: 'long',
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric'
                }));
                $('#currentTime').text(now.toLocaleTimeString('en-US'));
            }
            setInterval(updateDateTime, 1000);
            updateDateTime();

            $('#selectDocumentButton').on('click', function() {
                $('#fileSelectionPopup').show();
            });

            $('#uploadFileButton').on('click', function() {
                const fileInput = $('<input type="file" id="fileInput" style="display: none;" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.png,.txt,.zip">');
                $('body').append(fileInput);
                fileInput.trigger('click');
                fileInput.on('change', function() {
                    const file = this.files[0];
                    if (file) {
                        if (file.size > 10 * 1024 * 1024) {
                            notyf.error('File size exceeds 10MB.');
                            return;
                        }
                        selectedFile = file;
                        $('#fileDetailsPopup').show();
                    }
                    fileInput.remove();
                });
            });

            $('#hardcopyCheckbox').on('change', function() {
                $('#hardcopyOptions, #hardcopyDetails').toggle(this.checked);
                if (!this.checked) {
                    $('#storageSuggestion').hide().empty();
                } else if ($('input[name="hardcopyOption"]:checked').val() === 'new') {
                    fetchStorageSuggestion();
                }
            });

            $('input[name="hardcopyOption"]').on('change', function() {
                if (this.value === 'new') {
                    $('#hardcopyDetails').show();
                    fetchStorageSuggestion();
                } else {
                    $('#hardcopyDetails').hide();
                    $('#storageSuggestion').hide().empty();
                }
            });

            $("#searchInput").autocomplete({
                source: function(request, response) {
                    $.ajax({
                        url: "autocomplete.php",
                        dataType: "json",
                        data: {
                            term: request.term,
                            csrf_token: '<?= htmlspecialchars($_SESSION['csrf_token']) ?>'
                        },
                        success: function(data) {
                            if (data.success) {
                                response(data.results);
                            } else {
                                notyf.error(data.message);
                            }
                        },
                        error: function() {
                            notyf.error('Error fetching autocomplete suggestions.');
                        }
                    });
                },
                minLength: 2,
                select: function(event, ui) {
                    $("#searchInput").val(ui.item.value);
                    if (ui.item.document_type) $("#document-type").val(ui.item.document_type.toLowerCase());
                    if (ui.item.department_id) $("#folder").val("department-" + ui.item.department_id);
                    $("#search-form").submit();
                }
            });

            $('#documentType').on('change', function() {
                const docTypeName = $(this).val();
                const dynamicFields = $('#dynamicFields');
                dynamicFields.empty();

                if (docTypeName) {
                    $.ajax({
                        url: 'get_document_type_field.php',
                        method: 'POST',
                        data: {
                            document_type_name: docTypeName,
                            csrf_token: '<?= htmlspecialchars($_SESSION['csrf_token']) ?>'
                        },
                        dataType: 'json',
                        success: function(data) {
                            if (data.success && Array.isArray(data.data.fields) && data.data.fields.length > 0) {
                                data.data.fields.forEach(field => {
                                    const requiredAttr = field.is_required ? 'required' : '';
                                    let inputField = '';
                                    switch (field.field_type) {
                                        case 'text':
                                            inputField = `<input type="text" id="${field.field_name}" name="${field.field_name}" ${requiredAttr}>`;
                                            break;
                                        case 'textarea':
                                            inputField = `<textarea id="${field.field_name}" name="${field.field_name}" ${requiredAttr}></textarea>`;
                                            break;
                                        case 'date':
                                            inputField = `<input type="date" id="${field.field_name}" name="${field.field_name}" ${requiredAttr}>`;
                                            break;
                                    }
                                    dynamicFields.append(`
                                        <label for="${field.field_name}">${field.field_label}${field.is_required ? ' *' : ''}:</label>
                                        ${inputField}
                                    `);
                                });
                            } else {
                                dynamicFields.append(`<p>${data.message || 'No metadata fields defined for this document type.'}</p>`);
                            }
                        },
                        error: function(jqXHR, textStatus, errorThrown) {
                            console.error('AJAX error:', textStatus, errorThrown, jqXHR.responseText);
                            notyf.error('Failed to load metadata fields.');
                        }
                    });
                }
            });

            $(document).on('click', '.select-file-button', function() {
                $('.file-item').removeClass('selected');
                const $fileItem = $(this).closest('.file-item');
                $fileItem.addClass('selected');
                $('#sendFilePopup').data('selected-file-id', $fileItem.data('file-id'));
                $('#fileSelectionPopup').hide();
                $('#sendFilePopup').show();
            });

            $(document).on('click', '.notification-item', function() {
                const status = $(this).data('status');
                const fileId = $(this).data('file-id');
                const notificationId = $(this).data('notification-id');
                const message = $(this).data('message');

                if (status !== 'pending') {
                    $('#alreadyProcessedMessage').text('This request has already been processed.');
                    $('#alreadyProcessedPopup').show();
                    return;
                }

                $('#fileAcceptanceTitle').text('Review File');
                $('#fileAcceptanceMessage').text(message);
                $('#fileAcceptancePopup').data('notification-id', notificationId).data('file-id', fileId).show();
                showFilePreview(fileId);
            });

            fetchNotifications();
            setInterval(fetchNotifications, 5000);
        });

        function fetchNotifications() {
            $.ajax({
                url: 'fetch_notifications.php',
                method: 'GET',
                data: {
                    csrf_token: '<?= htmlspecialchars($_SESSION['csrf_token']) ?>'
                },
                dataType: 'json',
                success: function(data) {
                    const notificationContainer = $('.notification-log .log-entries');
                    if (data.success) {
                        const currentIds = notificationContainer.find('.notification-item').map(function() {
                            return $(this).data('notification-id');
                        }).get();
                        const newIds = data.notifications.map(n => n.id);

                        if (JSON.stringify(currentIds) !== JSON.stringify(newIds)) {
                            notificationContainer.empty();
                            if (data.notifications.length > 0) {
                                data.notifications.forEach(n => {
                                    const notificationClass = n.status === 'pending' ? 'pending-access' : 'processed-access';
                                    notificationContainer.append(`
                                        <div class="log-entry notification-item ${notificationClass}"
                                             data-notification-id="${n.id}"
                                             data-file-id="${n.file_id || ''}"
                                             data-message="${n.message}"
                                             data-status="${n.status}">
                                            <i class="fas fa-bell"></i>
                                            <p>${n.message}</p>
                                            <span>${new Date(n.timestamp).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })}</span>
                                        </div>
                                    `);
                                });
                            } else {
                                notificationContainer.empty().append('<div class="log-entry no-notifications"><p>No new notifications.</p></div>');
                            }
                        }
                    } else {
                        notyf.error(data.message);
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    console.error('Notification fetch error:', textStatus, errorThrown, jqXHR.responseText);
                    notyf.error('Failed to fetch notifications. Check console for details.');
                }
            });
        }

        function showFilePreview(fileId) {
            if (!fileId) {
                $('#filePreview').html('<p>No file selected.</p>');
                return;
            }
            $.ajax({
                url: 'get_file_preview.php',
                method: 'GET',
                data: {
                    file_id: fileId
                },
                success: function(data) {
                    $('#filePreview').html(data);
                },
                error: function() {
                    $('#filePreview').html('<p>Unable to load preview.</p>');
                }
            });
        }

        function handleFileAction(notificationId, fileId, action) {
            if (!notificationId || !fileId) {
                notyf.error('Invalid notification or file ID.');
                return;
            }
            $.ajax({
                url: 'handle_file_acceptance.php',
                method: 'POST',
                data: {
                    notification_id: notificationId,
                    file_id: fileId,
                    action: action,
                    csrf_token: '<?= htmlspecialchars($_SESSION['csrf_token']) ?>'
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        notyf.success(response.message);
                        $('#fileAcceptancePopup').hide();
                        $('.notification-item[data-notification-id="' + notificationId + '"]')
                            .removeClass('pending-access')
                            .addClass('processed-access')
                            .off('click')
                            .find('p').text(response.message + ' (Processed)');
                        fetchNotifications();
                        if (response.redirect) window.location.href = response.redirect;
                    } else {
                        notyf.error(response.message);
                    }
                },
                error: function() {
                    notyf.error('Error processing file action.');
                }
            });
        }

        function closePopup(popupId) {
            $(`#${popupId}`).hide();
            if (popupId === 'sendFilePopup') {
                $('.file-item').removeClass('selected');
                $('#sendFilePopup').removeData('selected-file-id');
            }
            if (popupId === 'fileDetailsPopup') {
                selectedFile = null;
                $('#dynamicFields').empty();
                $('#hardcopyDetails').hide();
                $('#hardcopyCheckbox').prop('checked', false);
            }
            if (popupId === 'hardcopyStoragePopup') {
                $('#storageSuggestion').empty();
                $('#hardcopyDetails').hide();
            }
            if (popupId === 'linkHardcopyPopup') {
                selectedHardcopyId = null;
                $('#hardcopyList').empty();
                $('#linkHardcopyButton').prop('disabled', true);
            }
        }

        function toggleActivityLog() {
            $('#activityLog').toggle();
        }

        $(document).on('click', function(event) {
            if (!$(event.target).closest('.activity-log, .activity-log-icon').length) {
                $('#activityLog').hide();
            }
        });

        function proceedToHardcopy() {
            const documentType = $('#documentType').val();
            if (!documentType) {
                notyf.error('Please select a document type.');
                return;
            }
            $('#fileDetailsPopup').hide();
            $('#hardcopyStoragePopup').show();
            if ($('#hardcopyCheckbox').is(':checked') && $('input[name="hardcopyOption"]:checked').val() === 'new') {
                fetchStorageSuggestion();
            }
        }

        function handleHardcopyBack() {
            $('#hardcopyStoragePopup').hide();
            $('#fileDetailsPopup').show();
        }

        function handleHardcopyNext() {
            const hardcopyAvailable = $('#hardcopyCheckbox').is(':checked');
            if (hardcopyAvailable && $('input[name="hardcopyOption"]:checked').val() === 'link') {
                $('#hardcopyStoragePopup').hide();
                $('#linkHardcopyPopup').show();
                fetchHardcopyFiles();
            } else {
                uploadFile();
            }
        }

        function fetchHardcopyFiles() {
            const departmentId = $('#departmentId').val();
            if (!departmentId) {
                notyf.error('Please select a department.');
                return;
            }
            $.ajax({
                url: 'fetch_hardcopy_files.php',
                method: 'POST',
                data: {
                    department_id: departmentId,
                    csrf_token: '<?= htmlspecialchars($_SESSION['csrf_token']) ?>'
                },
                dataType: 'json',
                success: function(data) {
                    const hardcopyList = $('#hardcopyList');
                    hardcopyList.empty();
                    if (data.success && data.files.length > 0) {
                        data.files.forEach(file => {
                            const metadata = file.meta_data ? JSON.parse(file.meta_data) : {};
                            const location = metadata.cabinet ?
                                `Cabinet: ${metadata.cabinet}, Layer: ${metadata.layer || 'N/A'}, Box: ${metadata.box || 'N/A'}, Folder: ${metadata.folder || 'N/A'}` : 'No location specified';
                            hardcopyList.append(`
                                <div class="file-item" data-file-id="${file.id}">
                                    <input type="radio" name="hardcopyFile" value="${file.id}">
                                    <span>${file.file_name} (${location})</span>
                                </div>
                            `);
                        });
                        hardcopyList.find('input').on('change', function() {
                            selectedHardcopyId = $(this).val();
                            $('#linkHardcopyButton').prop('disabled', false);
                        });
                    } else {
                        hardcopyList.append('<p>No hardcopy files available.</p>');
                    }
                },
                error: function() {
                    notyf.error('Failed to fetch hardcopy files.');
                }
            });
        }

        function filterHardcopies() {
            const searchTerm = $('#hardcopySearch').val().toLowerCase();
            $('#hardcopyList .file-item').each(function() {
                const fileName = $(this).find('span').text().toLowerCase();
                $(this).toggle(fileName.includes(searchTerm));
            });
        }

        function linkHardcopy() {
            if (!selectedHardcopyId) {
                notyf.error('Please select a hardcopy to link.');
                return;
            }
            uploadFile();
        }

        function fetchStorageSuggestion() {
            const departmentId = $('#departmentId').val();
            if (!departmentId) {
                $('#storageSuggestion').html('<p>No department selected.</p>').show();
                return;
            }
            $.ajax({
                url: 'get_storage_suggestions.php',
                method: 'POST',
                data: {
                    department_id: departmentId,
                    csrf_token: '<?= htmlspecialchars($_SESSION['csrf_token']) ?>'
                },
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        window.storageMetadata = data.metadata;
                        $('#storageSuggestion').html(`<p>Suggested Location: Cabinet ${data.metadata.cabinet}, Layer ${data.metadata.layer}, Box ${data.metadata.box}, Folder ${data.metadata.folder}</p>`).show();
                        $('#layer').val(data.metadata.layer);
                        $('#box').val(data.metadata.box);
                        $('#folder').val(data.metadata.folder);
                    } else {
                        $('#storageSuggestion').html(`<p>${data.message}</p>`).show();
                    }
                },
                error: function() {
                    $('#storageSuggestion').html('<p>Failed to fetch suggestion.</p>').show();
                }
            });
        }

        function uploadFile() {
            const documentType = $('#documentType').val();
            if (!documentType) {
                notyf.error('Please select a document type.');
                return;
            }
            if (!selectedFile) {
                notyf.error('No file selected.');
                return;
            }

            const formData = new FormData();
            formData.append('file', selectedFile);
            formData.append('document_type', documentType);
            formData.append('user_id', '<?= htmlspecialchars($userId) ?>');
            formData.append('csrf_token', '<?= htmlspecialchars($_SESSION['csrf_token']) ?>');
            formData.append('hard_copy_available', $('#hardcopyCheckbox').is(':checked') ? 1 : 0);
            formData.append('cabinet', $('#cabinet').val());

            if ($('#hardcopyCheckbox').is(':checked') && $('input[name="hardcopyOption"]:checked').val() === 'new') {
                formData.append('layer', $('#layer').val() || window.storageMetadata?.layer || 0);
                formData.append('box', $('#box').val() || window.storageMetadata?.box || 0);
                formData.append('folder', $('#folder').val() || window.storageMetadata?.folder || 0);
            } else if ($('#hardcopyCheckbox').is(':checked') && $('input[name="hardcopyOption"]:checked').val() === 'link' && selectedHardcopyId) {
                formData.append('link_hardcopy_id', selectedHardcopyId);
            }

            $('#fileDetailsForm').find('input, textarea, select').each(function() {
                const name = $(this).attr('name');
                const value = $(this).val();
                if (name && value && !['department_id', 'document_type', 'csrf_token', 'cabinet', 'layer', 'box', 'folder', 'hard_copy_available'].includes(name)) {
                    formData.append(name, value);
                }
            });

            $.ajax({
                url: 'upload_handler.php',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                xhr: function() {
                    const xhr = new XMLHttpRequest();
                    xhr.upload.addEventListener('progress', function(e) {
                        if (e.lengthComputable) {
                            const percent = Math.round((e.loaded / e.total) * 100);
                            notyf.open({
                                type: 'info',
                                message: `Uploading: ${percent}%`,
                                duration: 0
                            });
                        }
                    }, false);
                    return xhr;
                },
                success: function(data) {
                    const response = typeof data === 'string' ? JSON.parse(data) : data;
                    if (response.success) {
                        notyf.success(response.message);
                        $('#hardcopyStoragePopup').hide();
                        $('#linkHardcopyPopup').hide();
                        selectedFile = null;
                        selectedHardcopyId = null;
                        window.storageMetadata = null;
                        window.location.href = 'my-folder.php';
                    } else {
                        notyf.error(response.message || 'Failed to upload file.');
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    console.error('Upload error:', textStatus, errorThrown, jqXHR.responseText);
                    notyf.error('An error occurred while uploading the file.');
                }
            });
        }

        function sendFile() {
            const recipients = $('#recipientSelect').val();
            if (!recipients || recipients.length === 0) {
                notyf.error('Please select at least one recipient.');
                return;
            }
            const fileId = $('.file-item.selected').data('file-id') || $('#sendFilePopup').data('selected-file-id');
            if (!fileId) {
                notyf.error('No file selected to send.');
                return;
            }

            $.ajax({
                url: 'send_file_handler.php',
                method: 'POST',
                data: {
                    file_id: fileId,
                    recipients: recipients,
                    csrf_token: '<?= htmlspecialchars($_SESSION['csrf_token']) ?>'
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        notyf.success(response.message);
                        $('#sendFilePopup').hide();
                        $('.file-item').removeClass('selected');
                        $('#sendFilePopup').removeData('selected-file-id');
                    } else {
                        notyf.error(response.message || 'Error sending file.');
                    }
                },
                error: function() {
                    notyf.error('Error sending file.');
                }
            });
        }

        function viewFile(fileId) {
            showFilePreview(fileId);
            $('#fileAcceptancePopup').data('file-id', fileId).show();
            $('#fileAcceptanceTitle').text('View File');
            $('#fileAcceptanceMessage').text('Previewing file.');
            $('#acceptFileButton, #denyFileButton').hide();
        }

        function sortPersonalFiles() {
            const nameSort = $('.sort-personal-name').val();
            const typeSort = $('.sort-personal-type').val();
            const sourceSort = $('.sort-personal-source').val();
            const $container = $('#personalFiles');
            const $items = $container.find('.file-item').get();

            $items.sort(function(a, b) {
                const aData = $(a).data();
                const bData = $(b).data();

                if (nameSort) {
                    return nameSort === 'name-asc' ?
                        aData.fileName.localeCompare(bData.fileName) :
                        bData.fileName.localeCompare(aData.fileName);
                }
                if (typeSort) {
                    const docType = typeSort.replace('type-', '');
                    if (aData.documentType === docType && bData.documentType !== docType) return -1;
                    if (bData.documentType === docType && aData.documentType !== docType) return 1;
                    return 0;
                }
                if (sourceSort) {
                    if (sourceSort === aData.source) return -1;
                    if (sourceSort === bData.source) return 1;
                    return 0;
                }
                return 0;
            });

            $container.empty().append($items);
        }

        function filterPersonalFilesByHardCopy() {
            const showHardCopyOnly = $('#hardCopyPersonalFilter').is(':checked');
            $('#personalFiles .file-item').each(function() {
                const hasHardCopy = $(this).data('hard-copy') == 1;
                $(this).toggle(!showHardCopyOnly || hasHardCopy);
            });
        }

        function sortDepartmentFiles(deptId) {
            const nameSort = $(`.sort-department-name[data-dept-id="${deptId}"]`).val();
            const typeSort = $(`.sort-department-type[data-dept-id="${deptId}"]`).val();
            const $container = $(`#departmentFiles-${deptId}`);
            const $items = $container.find('.file-item').get();

            $items.sort(function(a, b) {
                const aData = $(a).data();
                const bData = $(b).data();

                if (nameSort) {
                    return nameSort === 'name-asc' ?
                        aData.fileName.localeCompare(bData.fileName) :
                        bData.fileName.localeCompare(aData.fileName);
                }
                if (typeSort) {
                    const docType = typeSort.replace('type-', '');
                    if (aData.documentType === docType && bData.documentType !== docType) return -1;
                    if (bData.documentType === docType && aData.documentType !== docType) return 1;
                    return 0;
                }
                return 0;
            });

            $container.empty().append($items);
        }

        function filterDepartmentFilesByHardCopy(deptId) {
            const showHardCopyOnly = $(`.hard-copy-department-filter[data-dept-id="${deptId}"]`).is(':checked');
            $(`#departmentFiles-${deptId} .file-item`).each(function() {
                const hasHardCopy = $(this).data('hard-copy') == 1;
                $(this).toggle(!showHardCopyOnly || hasHardCopy);
            });
        }

        function switchView(view) {
            const fileDisplay = $('#fileDisplay');
            if (view === 'thumbnail') {
                fileDisplay.removeClass('list-view').addClass('thumbnail-view masonry-grid');
                $('#thumbnailViewButton').addClass('active');
                $('#listViewButton').removeClass('active');
            } else {
                fileDisplay.removeClass('thumbnail-view masonry-grid').addClass('list-view');
                $('#listViewButton').addClass('active');
                $('#thumbnailViewButton').removeClass('active');
            }
        }

        function filterFilesByType() {
            const typeFilter = $('#documentTypeFilter').val().toLowerCase();
            $('#fileDisplay .file-item').each(function() {
                const docType = $(this).data('document-type').toLowerCase();
                $(this).toggle(typeFilter === '' || docType === typeFilter);
            });
        }

        function filterFiles() {
            const searchTerm = $('#fileSearch').val().toLowerCase();
            const typeFilter = $('#documentTypeFilter').val().toLowerCase();
            $('#fileDisplay .file-item').each(function() {
                const fileName = $(this).data('file-name').toLowerCase();
                const docType = $(this).data('document-type').toLowerCase();
                const matchesSearch = fileName.includes(searchTerm);
                const matchesType = typeFilter === '' || docType === typeFilter;
                $(this).toggle(matchesSearch && matchesType);
            });
        }
    </script>
</body>

</html>