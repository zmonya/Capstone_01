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
    SELECT u.user_id, u.username, u.role, u.profile_pic, u.position, 
           d.department_id, d.department_name
    FROM users u
    LEFT JOIN users_department ud ON u.user_id = ud.user_id
    LEFT JOIN departments d ON ud.department_id = d.department_id
    WHERE u.user_id = ?
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
    SELECT d.department_id, d.department_name
    FROM departments d
    JOIN users_department ud ON d.department_id = ud.department_id
    WHERE ud.user_id = ?
");
$stmt->execute([$userId]);
$userDepartments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch document types
$stmt = $pdo->prepare("SELECT DISTINCT type_name AS name FROM document_types ORDER BY type_name ASC");
$stmt->execute();
$docTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch recent files (owned by user)
$stmt = $pdo->prepare("
    SELECT f.file_id, f.file_name, f.file_type, f.upload_date, f.copy_type, dtf.type_name AS document_type
    FROM files f
    LEFT JOIN document_types dtf ON f.document_type_id = dtf.document_type_id
    WHERE f.user_id = ? AND f.file_status != 'deleted'
    ORDER BY f.upload_date DESC
    LIMIT 5
");
$stmt->execute([$userId]);
$recentFiles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch notifications (transaction_type = 'notification')
$stmt = $pdo->prepare("
    SELECT t.transaction_id AS id, t.file_id, t.transaction_type, t.transaction_time AS timestamp, t.description AS message,
           COALESCE(f.file_name, 'Unknown File') AS file_name
    FROM transactions t
    LEFT JOIN files f ON t.file_id = f.file_id
    WHERE t.user_id = ? AND t.transaction_type = 'notification'
    AND (f.file_status != 'deleted' OR f.file_id IS NULL)
    ORDER BY t.transaction_time DESC
    LIMIT 5
");
$stmt->execute([$userId]);
$notificationLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch activity logs (only user-initiated actions)
$stmt = $pdo->prepare("
    SELECT t.transaction_id, t.description AS action, t.transaction_time AS timestamp
    FROM transactions t
    LEFT JOIN files f ON t.file_id = f.file_id
    WHERE t.user_id = ? 
    AND t.transaction_type IN ('login_success', 'file_sent', 'file_request', 'file_approve', 'file_reject', 'file_upload', 'file_delete')
    AND (f.file_status != 'deleted' OR f.file_id IS NULL)
    ORDER BY t.transaction_time DESC
    LIMIT 5
");
$stmt->execute([$userId]);
$activityLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all user files
$stmt = $pdo->prepare("
    SELECT f.file_id, f.file_name, f.file_type, f.upload_date, f.copy_type, dtf.type_name AS document_type
    FROM files f
    LEFT JOIN document_types dtf ON f.document_type_id = dtf.document_type_id
    WHERE f.user_id = ? AND f.file_status != 'deleted'
    ORDER BY f.upload_date DESC
");
$stmt->execute([$userId]);
$filesUploaded = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch files sent to user (transaction_type = 'file_sent')
$stmt = $pdo->prepare("
    SELECT DISTINCT f.file_id, f.file_name, f.file_type, f.upload_date, f.copy_type, dtf.type_name AS document_type
    FROM files f
    JOIN transactions t ON f.file_id = t.file_id
    LEFT JOIN document_types dtf ON f.document_type_id = dtf.document_type_id
    WHERE t.user_id = ? AND t.transaction_type = 'file_sent'
    AND t.description IN ('pending', 'accepted')
    AND f.file_status != 'deleted'
    ORDER BY f.upload_date DESC
");
$stmt->execute([$userId]);
$filesSentToMe = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch files requested by user (transaction_type = 'file_request')
$stmt = $pdo->prepare("
    SELECT DISTINCT f.file_id, f.file_name, f.file_type, f.upload_date, f.copy_type, dtf.type_name AS document_type
    FROM files f
    JOIN transactions t ON f.file_id = t.file_id
    LEFT JOIN document_types dtf ON f.document_type_id = dtf.document_type_id
    WHERE t.user_id = ? AND t.transaction_type = 'file_request'
    AND t.description = 'pending'
    AND f.file_status != 'deleted'
    ORDER BY t.transaction_time DESC
");
$stmt->execute([$userId]);
$filesRequested = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch department files
$departmentFiles = [];
foreach ($userDepartments as $dept) {
    $deptId = $dept['department_id'];
    $stmt = $pdo->prepare("
        SELECT f.file_id, f.file_name, f.file_type, f.upload_date, f.copy_type, dtf.type_name AS document_type
        FROM files f
        JOIN transactions t ON f.file_id = t.file_id
        LEFT JOIN document_types dtf ON f.document_type_id = dtf.document_type_id
        WHERE t.users_department_id IN (
            SELECT users_department_id FROM users_department WHERE department_id = ? AND user_id = ?
        )
        AND t.transaction_type = 'file_sent'
        AND t.description = 'accepted'
        AND f.file_status != 'deleted'
        ORDER BY f.upload_date DESC
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
    <title>Dashboard - Document Archival</title>

    <?php
     include 'user_head.php';
    ?> 

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
<?php
    include 'user_menu.php';
?>



        <?php foreach ($userDepartments as $dept): ?>
            <a href="department_folder.php?department_id=<?= htmlspecialchars(filter_var($dept['department_id'], FILTER_SANITIZE_NUMBER_INT)) ?>"
                class="<?= isset($_GET['department_id']) && (int)$_GET['department_id'] === $dept['department_id'] ? 'active' : '' ?>"
                data-tooltip="<?= htmlspecialchars($dept['department_name'] ?? 'Unnamed Department') ?>"
                aria-label="<?= htmlspecialchars($dept['department_name'] ?? 'Unnamed Department') ?> Folder">
                <i class="fas fa-folder"></i>
                <span class="link-text"><?= htmlspecialchars($dept['department_name'] ?? 'Unnamed Department') ?></span>
            </a>
        <?php endforeach; ?>


        <a href="logout.php" class="logout-btn" data-tooltip="Logout" aria-label="Logout">
            <i class="fas fa-sign-out-alt"></i><span class="link-text">Logout</span>
        </a>
    </aside>


    <header class="top-nav" role="banner">
        <h2>Dashboard</h2>
        <form action="search.php" method="GET" class="search-container" id="search-form">
            <input type="text" id="searchInput" name="q" placeholder="Search documents..." value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" aria-label="Search Documents">
            <select name="type" id="document-type" aria-label="Document Type Filter">
                <option value="">All Document Types</option>
                <?php foreach ($docTypes as $type): ?>
                    <option value="<?= htmlspecialchars($type['name']) ?>" <?= ($_GET['type'] ?? '') === $type['name'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars(ucfirst($type['name'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="folder" id="folder" aria-label="Folder Filter">
                <option value="">All Folders</option>
                <option value="my-folder" <?= ($_GET['folder'] ?? '') === 'my-folder' ? 'selected' : '' ?>>My Folder</option>
                <?php foreach ($userDepartments as $dept): ?>
                    <option value="department-<?= htmlspecialchars(filter_var($dept['department_id'], FILTER_SANITIZE_NUMBER_INT)) ?>"
                        <?= ($_GET['folder'] ?? '') === 'department-' . $dept['department_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($dept['department_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" aria-label="Search Documents"><i class="fas fa-search"></i></button>
        </form>
        <i class="fas fa-history activity-log-icon" onclick="toggleActivityLog()" title="View Activity Log" aria-label="View Activity Log"></i>
    </header>

    <main class="main-content" role="main">
        <section class="user-id-calendar-container">
            <div class="user-id">
                <img src="<?= htmlspecialchars($user['profile_pic'] ? 'data:image/jpeg;base64,' . base64_encode($user['profile_pic']) : 'user.jpg') ?>"
                    alt="User Picture" class="user-picture">
                <div class="user-info">
                    <p class="user-name"><?= htmlspecialchars($user['username'] ?? 'Unknown User') ?></p>
                    <p class="user-position"><?= htmlspecialchars($user['position'] ?? 'No Position') ?></p>
                    <p class="user-department"><?= htmlspecialchars($user['department_name'] ?? 'No Department') ?></p>
                </div>
            </div>
            <div class="digital-calendar-clock" aria-live="polite">
                <p id="currentDate" aria-label="Current Date"></p>
                <p id="currentTime" aria-label="Current Time"></p>
            </div>
        </section>

        <section class="upload-activity-container">
            <div class="upload-file" id="upload">
                <h3>Send a Document</h3>
                <button id="selectDocumentButton" aria-label="Select Document">Select Document</button>
            </div>
            <div class="upload-file" id="fileUpload">
                <h3>Upload File</h3>
                <input type="file" id="fileInput" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.png,.txt,.zip" style="display: none;" aria-label="Upload File">
                <button type="button" id="uploadFileButton" aria-label="Upload File">Upload File</button>
            </div>
            <div class="notification-log">
                <h3>Notifications</h3>
                <div class="log-entries">
                    <?php if (!empty($notificationLogs)): ?>
                        <?php foreach ($notificationLogs as $notification): ?>
                            <div class="log-entry notification-item <?= $notification['transaction_status'] === 'completed' ? 'processed-access' : 'pending-access' ?>"
                                data-notification-id="<?= htmlspecialchars($notification['id']) ?>"
                                data-file-id="<?= htmlspecialchars($notification['file_id'] ?? '') ?>"
                                data-message="<?= htmlspecialchars($notification['message']) ?>"
                                data-status="<?= htmlspecialchars($notification['transaction_status']) ?>">
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
                            <label for="sort-personal-name">Sort by Name:</label>
                            <select id="sort-personal-name" class="sort-personal-name" onchange="sortPersonalFiles()" aria-label="Sort Personal Files by Name">
                                <option value="">Select</option>
                                <option value="name-asc">A-Z</option>
                                <option value="name-desc">Z-A</option>
                            </select>
                            <label for="sort-personal-type">Sort by Document Type:</label>
                            <select id="sort-personal-type" class="sort-personal-type" onchange="sortPersonalFiles()" aria-label="Sort Personal Files by Document Type">
                                <option value="">Select</option>
                                <?php foreach ($docTypes as $type): ?>
                                    <option value="type-<?= htmlspecialchars($type['name']) ?>">
                                        <?= htmlspecialchars(ucfirst($type['name'])) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <label for="sort-personal-source">Sort by Source:</label>
                            <select id="sort-personal-source" class="sort-personal-source" onchange="sortPersonalFiles()" aria-label="Sort Personal Files by Source">
                                <option value="">Select</option>
                                <option value="uploaded">Uploaded Files</option>
                                <option value="received">Files Received</option>
                                <option value="requested">Files Requested</option>
                            </select>
                        </div>
                        <div class="filter-controls">
                            <label><input type="checkbox" id="hardCopyPersonalFilter" onchange="filterPersonalFilesByHardCopy()" aria-label="Filter Personal Files by Hard Copy"> Hard Copy Only</label>
                        </div>
                    </div>
                    <div class="file-grid" id="personalFiles">
                        <?php
                        // Merge files and remove duplicates based on file_id
                        $allFiles = array_merge($filesUploaded, $filesSentToMe, $filesRequested);
                        $uniqueFiles = [];
                        foreach ($allFiles as $file) {
                            $uniqueFiles[$file['file_id']] = $file;
                        }
                        ?>
                        <?php if (!empty($uniqueFiles)): ?>
                            <?php foreach ($uniqueFiles as $file): ?>
                                <div class="file-item"
                                    data-file-id="<?= htmlspecialchars($file['file_id']) ?>"
                                    data-file-name="<?= htmlspecialchars($file['file_name']) ?>"
                                    data-document-type="<?= htmlspecialchars($file['document_type'] ?? 'Unknown') ?>"
                                    data-upload-date="<?= htmlspecialchars($file['upload_date']) ?>"
                                    data-hard-copy="<?= htmlspecialchars($file['copy_type'] === 'copy' ? '1' : '0') ?>"
                                    data-source="<?= in_array($file, $filesUploaded) ? 'uploaded' : (in_array($file, $filesSentToMe) ? 'received' : 'requested') ?>">
                                    <i class="<?= getFileIcon($file['file_name']) ?> file-icon"></i>
                                    <p class="file-name"><?= htmlspecialchars($file['file_name']) ?></p>
                                    <p class="file-type"><?= htmlspecialchars(ucfirst($file['document_type'] ?? 'Unknown')) ?></p>
                                    <p class="file-date"><?= date('M d, Y', strtotime($file['upload_date'])) ?></p>
                                    <span class="hard-copy-indicator"><?= $file['copy_type'] === 'copy' ? '<i class="fas fa-print"></i> Hard Copy' : '' ?></span>
                                    <button class="view-file-button" onclick="viewFile(<?= htmlspecialchars($file['file_id']) ?>)" aria-label="View File">View</button>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p>No personal files available.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <?php foreach ($userDepartments as $dept): ?>
                    <div class="file-subsection">
                        <h3><?= htmlspecialchars($dept['department_name']) ?> Documents</h3>
                        <div class="file-controls">
                            <div class="sort-controls">
                                <label for="sort-department-name-<?= htmlspecialchars($dept['department_id']) ?>">Sort by Name:</label>
                                <select id="sort-department-name-<?= htmlspecialchars($dept['department_id']) ?>"
                                    class="sort-department-name"
                                    data-dept-id="<?= htmlspecialchars($dept['department_id']) ?>"
                                    onchange="sortDepartmentFiles(<?= htmlspecialchars($dept['department_id']) ?>)"
                                    aria-label="Sort Department Files by Name">
                                    <option value="">Select</option>
                                    <option value="name-asc">A-Z</option>
                                    <option value="name-desc">Z-A</option>
                                </select>
                                <label for="sort-department-type-<?= htmlspecialchars($dept['department_id']) ?>">Sort by Document Type:</label>
                                <select id="sort-department-type-<?= htmlspecialchars($dept['department_id']) ?>"
                                    class="sort-department-type"
                                    data-dept-id="<?= htmlspecialchars($dept['department_id']) ?>"
                                    onchange="sortDepartmentFiles(<?= htmlspecialchars($dept['department_id']) ?>)"
                                    aria-label="Sort Department Files by Document Type">
                                    <option value="">Select</option>
                                    <?php foreach ($docTypes as $type): ?>
                                        <option value="type-<?= htmlspecialchars($type['name']) ?>">
                                            <?= htmlspecialchars(ucfirst($type['name'])) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-controls">
                                <label><input type="checkbox"
                                        class="hard-copy-department-filter"
                                        data-dept-id="<?= htmlspecialchars($dept['department_id']) ?>"
                                        onchange="filterDepartmentFilesByHardCopy(<?= htmlspecialchars($dept['department_id']) ?>)"
                                        aria-label="Filter Department Files by Hard Copy"> Hard Copy Only</label>
                            </div>
                        </div>
                        <div class="file-grid department-files-grid" id="departmentFiles-<?= htmlspecialchars($dept['department_id']) ?>">
                            <?php if (!empty($departmentFiles[$dept['department_id']])): ?>
                                <?php foreach ($departmentFiles[$dept['department_id']] as $file): ?>
                                    <div class="file-item"
                                        data-file-id="<?= htmlspecialchars($file['file_id']) ?>"
                                        data-file-name="<?= htmlspecialchars($file['file_name']) ?>"
                                        data-document-type="<?= htmlspecialchars($file['document_type'] ?? 'Unknown') ?>"
                                        data-upload-date="<?= htmlspecialchars($file['upload_date']) ?>"
                                        data-hard-copy="<?= htmlspecialchars($file['copy_type'] === 'copy' ? '1' : '0') ?>"
                                        data-source="department">
                                        <i class="<?= getFileIcon($file['file_name']) ?> file-icon"></i>
                                        <p class="file-name"><?= htmlspecialchars($file['file_name']) ?></p>
                                        <p class="file-type"><?= htmlspecialchars(ucfirst($file['document_type'] ?? 'Unknown')) ?></p>
                                        <p class="file-date"><?= date('M d, Y', strtotime($file['upload_date'])) ?></p>
                                        <span class="hard-copy-indicator"><?= $file['copy_type'] === 'copy' ? '<i class="fas fa-print"></i> Hard Copy' : '' ?></span>
                                        <button class="view-file-button" onclick="viewFile(<?= htmlspecialchars($file['file_id']) ?>)" aria-label="View File">View</button>
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
        <div class="popup-file-selection" id="fileSelectionPopup" role="dialog" aria-labelledby="fileSelectionTitle">
            <button class="exit-button" onclick="closePopup('fileSelectionPopup')" aria-label="Close Popup">×</button>
            <h3 id="fileSelectionTitle">Select a Document</h3>
            <div class="search-container">
                <input type="text" id="fileSearch" placeholder="Search files..." oninput="filterFiles()" aria-label="Search Files">
                <select id="documentTypeFilter" onchange="filterFilesByType()" aria-label="Filter Files by Document Type">
                    <option value="">All Document Types</option>
                    <?php foreach ($docTypes as $type): ?>
                        <option value="<?= htmlspecialchars($type['name']) ?>"><?= htmlspecialchars(ucfirst($type['name'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="view-toggle">
                <button id="thumbnailViewButton" class="active" onclick="switchView('thumbnail')" aria-label="Thumbnail View"><i class="fas fa-th-large"></i> Thumbnails</button>
                <button id="listViewButton" onclick="switchView('list')" aria-label="List View"><i class="fas fa-list"></i> List</button>
            </div>
            <div id="fileDisplay" class="thumbnail-view masonry-grid">
                <?php foreach ($filesUploaded as $file): ?>
                    <div class="file-item"
                        data-file-id="<?= htmlspecialchars($file['file_id']) ?>"
                        data-file-name="<?= htmlspecialchars($file['file_name']) ?>"
                        data-document-type="<?= htmlspecialchars($file['document_type'] ?? 'Unknown') ?>">
                        <div class="file-icon"><i class="<?= getFileIcon($file['file_name']) ?>"></i></div>
                        <p><?= htmlspecialchars($file['file_name']) ?></p>
                        <button class="select-file-button" aria-label="Select File">Select</button>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="popup-questionnaire" id="fileDetailsPopup" role="dialog" aria-labelledby="fileDetailsTitle">
            <button class="exit-button" onclick="closePopup('fileDetailsPopup')" aria-label="Close Popup">×</button>
            <h3 id="fileDetailsTitle">Upload File Details</h3>
            <p class="subtitle">Provide details for the document you're uploading.</p>
            <form id="fileDetailsForm" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <label for="departmentId">Department:</label>
                <select id="departmentId" name="department_id" aria-label="Select Department">
                    <option value="">No Department</option>
                    <?php foreach ($userDepartments as $dept): ?>
                        <option value="<?= htmlspecialchars($dept['department_id']) ?>" <?= $dept['department_id'] == $user['department_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($dept['department_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <label for="documentType">Document Type:</label>
                <select id="documentType" name="document_type" required aria-label="Select Document Type">
                    <option value="">Select Document Type</option>
                    <?php foreach ($docTypes as $type): ?>
                        <option value="<?= htmlspecialchars($type['name']) ?>"><?= htmlspecialchars(ucfirst($type['name'])) ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="cabinet">Cabinet:</label>
                <input type="text" id="cabinet" name="cabinet" aria-label="Cabinet">
                <div id="dynamicFields"></div>
                <div class="hardcopy-options">
                    <label class="checkbox-container">
                        <input type="checkbox" id="hardcopyCheckbox" name="hard_copy_available" aria-label="Hard Copy Available">
                        <span class="checkmark"></span>
                        This file has a hardcopy
                    </label>
                    <div class="radio-group" id="hardcopyOptions" style="display: none;">
                        <label class="radio-container">
                            <input type="radio" name="hardcopyOption" value="link" checked aria-label="Link to Existing Hardcopy">
                            <span class="radio-checkmark"></span>
                            Link to existing hardcopy
                        </label>
                        <label class="radio-container">
                            <input type="radio" name="hardcopyOption" value="new" aria-label="Suggest New Storage Location">
                            <span class="radio-checkmark"></span>
                            Suggest new storage location
                        </label>
                        <div class="storage-suggestion" id="storageSuggestion"></div>
                    </div>
                    <div class="hardcopy-details" id="hardcopyDetails" style="display: none;">
                        <label for="layer">Layer:</label>
                        <input type="number" id="layer" name="layer" min="0" aria-label="Layer">
                        <label for="box">Box:</label>
                        <input type="number" id="box" name="box" min="0" aria-label="Box">
                        <label for="folder">Folder:</label>
                        <input type="number" id="folder" name="folder" min="0" aria-label="Folder">
                    </div>
                </div>
                <div class="button-group">
                    <button type="button" class="btn-back" onclick="closePopup('fileDetailsPopup')" aria-label="Cancel">Cancel</button>
                    <button type="button" class="btn-next" onclick="proceedToHardcopy()" aria-label="Next">Next</button>
                </div>
            </form>
        </div>

        <div class="popup-questionnaire" id="sendFilePopup" role="dialog" aria-labelledby="sendFileTitle">
            <button class="exit-button" onclick="closePopup('sendFilePopup')" aria-label="Close Popup">×</button>
            <h3 id="sendFileTitle">Send a File</h3>
            <form id="sendFileForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <label for="recipientSelect">Recipients (Users or Departments):</label>
                <select id="recipientSelect" name="recipients[]" multiple style="width: 100%;" aria-label="Select Recipients">
                    <optgroup label="Users">
                        <?php
                        $stmt = $pdo->prepare("SELECT user_id, username FROM users WHERE user_id != ?");
                        $stmt->execute([$userId]);
                        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($users as $userOption): ?>
                            <option value="user:<?= htmlspecialchars($userOption['user_id']) ?>">
                                <?= htmlspecialchars($userOption['username']) ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                    <optgroup label="Departments">
                        <?php
                        $stmt = $pdo->prepare("SELECT department_id, department_name FROM departments");
                        $stmt->execute();
                        $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($departments as $dept): ?>
                            <option value="department:<?= htmlspecialchars($dept['department_id']) ?>">
                                <?= htmlspecialchars($dept['department_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                </select>
                <div class="button-group">
                    <button type="button" class="btn-back" onclick="closePopup('sendFilePopup')" aria-label="Cancel">Cancel</button>
                    <button type="button" class="btn-next" onclick="sendFile()" aria-label="Send">Send</button>
                </div>
            </form>
        </div>

        <div class="popup-questionnaire" id="linkHardcopyPopup" role="dialog" aria-labelledby="linkHardcopyTitle">
            <button class="exit-button" onclick="closePopup('linkHardcopyPopup')" aria-label="Close Popup">×</button>
            <h3 id="linkHardcopyTitle">Link to Existing Hardcopy</h3>
            <div class="search-container">
                <input type="text" id="hardcopySearch" placeholder="Search hardcopy files..." oninput="filterHardcopies()" aria-label="Search Hardcopy Files">
            </div>
            <div class="file-list" id="hardcopyList"></div>
            <div class="button-group">
                <button class="btn-back" onclick="closePopup('linkHardcopyPopup')" aria-label="Cancel">Cancel</button>
                <button id="linkHardcopyButton" class="btn-next" disabled onclick="linkHardcopy()" aria-label="Link Hardcopy">Link</button>
            </div>
        </div>

        <div class="popup-questionnaire" id="fileAcceptancePopup" role="dialog" aria-labelledby="fileAcceptanceTitle">
            <button class="exit-button" onclick="closePopup('fileAcceptancePopup')" aria-label="Close Popup">×</button>
            <h3 id="fileAcceptanceTitle">Review File</h3>
            <p id="fileAcceptanceMessage"></p>
            <div class="file-preview" id="filePreview"></div>
            <div class="button-group">
                <button id="acceptFileButton" aria-label="Accept File">Accept</button>
                <button id="denyFileButton" aria-label="Deny File">Deny</button>
            </div>
        </div>

        <div class="popup-questionnaire" id="alreadyProcessedPopup" role="dialog" aria-labelledby="alreadyProcessedTitle">
            <button class="exit-button" onclick="closePopup('alreadyProcessedPopup')" aria-label="Close Popup">×</button>
            <h3 id="alreadyProcessedTitle">Request Status</h3>
            <p id="alreadyProcessedMessage"></p>
        </div>

        <div class="activity-log" id="activityLog" role="complementary" aria-label="Activity Log">
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

    <script>
        const notyf = new Notyf();
        let selectedFile = null;
        let selectedHardcopyId = null;

        $(document).ready(function() {
            // Initialize Select2
            $('#recipientSelect, #documentType, #departmentId').select2({
                placeholder: "Select an option",
                allowClear: true,
                dropdownCssClass: 'select2-high-zindex'
            });

            // Toggle Sidebar
            $('.toggle-btn').on('click', function() {
                $('.sidebar').toggleClass('minimized');
                $('.top-nav, .main-content').toggleClass('resized', $('.sidebar').hasClass('minimized'));
            });

            // Update Date and Time
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

            // File Selection Popup
            $('#selectDocumentButton').on('click', function() {
                $('#fileSelectionPopup').show();
            });

            // File Upload Button Click Handler
            $('#uploadFileButton').on('click', function() {
                const $fileInput = $('#fileInput');
                if ($fileInput.length) {
                    $fileInput.trigger('click');
                } else {
                    console.error('File input element (#fileInput) not found.');
                    notyf.error('File upload input is not available. Please refresh the page.');
                }
            });

            // File Input Change Handler
            $('#fileInput').on('change', function() {
                const file = this.files[0];
                console.log('File selected:', file);
                if (!file) {
                    notyf.error('No file selected.');
                    return;
                }
                if (file.size > 10 * 1024 * 1024) {
                    notyf.error('File size exceeds 10MB.');
                    this.value = '';
                    return;
                }
                const allowedTypes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'image/jpeg', 'image/png', 'text/plain', 'application/zip'
                ];
                if (!allowedTypes.includes(file.type)) {
                    notyf.error('Invalid file type. Allowed types: PDF, DOC, DOCX, XLS, XLSX, JPG, PNG, TXT, ZIP.');
                    this.value = '';
                    return;
                }
                selectedFile = file;
                $('#fileDetailsPopup').show();
            });

            // Hardcopy Checkbox Handler
            $('#hardcopyCheckbox').on('change', function() {
                $('#hardcopyOptions').toggle(this.checked);
                if (this.checked && $('input[name="hardcopyOption"]:checked').val() === 'new') {
                    $('#hardcopyDetails').show();
                    fetchStorageSuggestion();
                } else {
                    $('#hardcopyDetails').hide();
                    $('#storageSuggestion').hide().empty();
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

            // Autocomplete Setup
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

            // Dynamic Fields for Document Type
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

            // File Selection
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

        // Notification Fetching
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

        // File Preview
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

        // File Action Handling
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

        $('#acceptFileButton').on('click', function() {
            const notificationId = $('#fileAcceptancePopup').data('notification-id');
            const fileId = $('#fileAcceptancePopup').data('file-id');
            handleFileAction(notificationId, fileId, 'accept');
        });

        $('#denyFileButton').on('click', function() {
            const notificationId = $('#fileAcceptancePopup').data('notification-id');
            const fileId = $('#fileAcceptancePopup').data('file-id');
            handleFileAction(notificationId, fileId, 'deny');
        });

        // Popup Management
        function closePopup(popupId) {
            $(`#${popupId}`).hide();
            if (popupId === 'sendFilePopup') {
                $('.file-item').removeClass('selected');
                $('#sendFilePopup').removeData('selected-file-id');
            }
            if (popupId === 'fileDetailsPopup' || popupId === 'linkHardcopyPopup') {
                resetUploadForm();
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

        // Proceed to Hardcopy or Upload
        function proceedToHardcopy() {
            const documentType = $('#documentType').val();
            if (!documentType) {
                notyf.error('Please select a document type.');
                return;
            }
            if ($('#hardcopyCheckbox').is(':checked') && $('input[name="hardcopyOption"]:checked').val() === 'link') {
                fetchHardcopyFiles();
                $('#fileDetailsPopup').hide();
                $('#linkHardcopyPopup').show();
            } else {
                uploadFile();
            }
        }

        // Fetch Hardcopy Files
        function fetchHardcopyFiles() {
            const departmentId = $('#departmentId').val();
            const documentType = $('#documentType').val();
            if (!departmentId || !documentType) {
                notyf.error('Please select both a department and a document type.');
                return;
            }
            $.ajax({
                url: 'fetch_hardcopy_files.php',
                method: 'POST',
                data: {
                    department_id: departmentId,
                    document_type: documentType,
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
                            $('#linkHardcopyButton').prop('disabled', !selectedHardcopyId);
                        });
                    } else {
                        hardcopyList.append('<p>No hardcopy files available for this department and document type.</p>');
                        $('#linkHardcopyButton').prop('disabled', true);
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    console.error('Hardcopy fetch error:', textStatus, errorThrown, jqXHR.responseText);
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
            $('#linkHardcopyPopup').hide();
            uploadFile();
        }

        function fetchStorageSuggestion() {
            const departmentId = $('#departmentId').val();
            const documentType = $('#documentType').val();
            if (!departmentId || !documentType) {
                $('#storageSuggestion').html('<p>Please select both a department and a document type.</p>').show();
                return;
            }
            $.ajax({
                url: 'get_storage_suggestions.php',
                method: 'POST',
                data: {
                    department_id: departmentId,
                    document_type: documentType,
                    csrf_token: '<?= htmlspecialchars($_SESSION['csrf_token']) ?>'
                },
                dataType: 'json',
                success: function(data) {
                    if (data.success && data.metadata) {
                        window.storageMetadata = data.metadata;
                        $('#storageSuggestion').html(`
                        <p>Suggested Location: Cabinet ${data.metadata.cabinet || 'N/A'}, 
                        Layer ${data.metadata.layer || 'N/A'}, 
                        Box ${data.metadata.box || 'N/A'}, 
                        Folder ${data.metadata.folder || 'N/A'}</p>
                    `).show();
                        $('#cabinet').val(data.metadata.cabinet || '');
                        $('#layer').val(data.metadata.layer || '');
                        $('#box').val(data.metadata.box || '');
                        $('#folder').val(data.metadata.folder || '');
                    } else {
                        $('#storageSuggestion').html(`<p>${data.message || 'No storage suggestion available.'}</p>`).show();
                        window.storageMetadata = null;
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    console.error('Storage suggestion error:', textStatus, errorThrown, jqXHR.responseText);
                    $('#storageSuggestion').html('<p>Failed to fetch storage suggestion.</p>').show();
                    window.storageMetadata = null;
                }
            });
        }

        function uploadFile() {
            const documentType = $('#documentType').val();
            const departmentId = $('#departmentId').val();
            console.log('Uploading file:', {
                documentType,
                departmentId,
                selectedFile,
                selectedHardcopyId
            });
            if (!documentType) {
                notyf.error('Please select a document type.');
                return;
            }
            if (!departmentId) {
                notyf.error('Please select a department.');
                return;
            }
            if (!$('#hardcopyCheckbox').is(':checked') && !selectedFile) {
                notyf.error('No file selected for upload.');
                return;
            }

            const formData = new FormData();
            if (selectedFile) {
                formData.append('file', selectedFile);
                console.log('Appending file:', selectedFile.name);
            }
            formData.append('document_type', documentType);
            formData.append('user_id', '<?= htmlspecialchars($userId) ?>');
            formData.append('csrf_token', '<?= htmlspecialchars($_SESSION['csrf_token']) ?>');
            formData.append('department_id', departmentId);
            formData.append('cabinet', $('#cabinet').val() || '');
            formData.append('hard_copy_available', $('#hardcopyCheckbox').is(':checked') ? 1 : 0);

            if ($('#hardcopyCheckbox').is(':checked')) {
                const hardcopyOption = $('input[name="hardcopyOption"]:checked').val();
                if (hardcopyOption === 'new') {
                    const layer = $('#layer').val() || (window.storageMetadata?.layer || 0);
                    const box = $('#box').val() || (window.storageMetadata?.box || 0);
                    const folder = $('#folder').val() || (window.storageMetadata?.folder || 0);
                    if (!$('#cabinet').val() || layer === '' || box === '' || folder === '') {
                        notyf.error('Please provide complete storage details for new hardcopy.');
                        return;
                    }
                    formData.append('layer', layer);
                    formData.append('box', box);
                    formData.append('folder', folder);
                } else if (hardcopyOption === 'link' && selectedHardcopyId) {
                    formData.append('link_hardcopy_id', selectedHardcopyId);
                } else {
                    notyf.error('Please select a hardcopy file to link or specify new storage details.');
                    return;
                }
            }

            $('#fileDetailsForm').find('input:not([type="file"]), textarea, select').each(function() {
                const name = $(this).attr('name');
                const value = $(this).val();
                if (name && value && !['department_id', 'document_type', 'csrf_token', 'cabinet', 'layer', 'box', 'folder', 'hard_copy_available', 'hardcopyOption'].includes(name)) {
                    formData.append(`metadata[${name}]`, value);
                    console.log(`Appending metadata: ${name}=${value}`);
                }
            });

            let progressNotyf = null;
            $.ajax({
                url: 'upload_handler.php',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                xhr: function() {
                    const xhr = new XMLHttpRequest();
                    if (selectedFile) {
                        xhr.upload.addEventListener('progress', function(e) {
                            if (e.lengthComputable) {
                                const percent = Math.round((e.loaded / e.total) * 100);
                                if (progressNotyf) {
                                    notyf.dismiss(progressNotyf);
                                }
                                progressNotyf = notyf.open({
                                    type: 'info',
                                    message: `Uploading: ${percent}%`,
                                    duration: 0
                                });
                            }
                        }, false);
                    }
                    return xhr;
                },
                success: function(data) {
                    if (progressNotyf) {
                        notyf.dismiss(progressNotyf);
                    }
                    console.log('Upload response:', data);
                    let response;
                    try {
                        response = typeof data === 'string' ? JSON.parse(data) : data;
                    } catch (e) {
                        console.error('Invalid server response:', data);
                        notyf.error('Invalid server response. Check console for details.');
                        return;
                    }
                    if (response.success) {
                        notyf.success(response.message);
                        resetUploadForm();
                        window.location.reload();
                    } else {
                        notyf.error(response.message || 'Failed to upload file.');
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    if (progressNotyf) {
                        notyf.dismiss(progressNotyf);
                    }
                    console.error('Upload error:', textStatus, errorThrown, jqXHR.responseText);
                    notyf.error('An error occurred while uploading the file. Check console for details.');
                }
            });
        }

        function resetUploadForm() {
            selectedFile = null;
            selectedHardcopyId = null;
            window.storageMetadata = null;
            $('#fileInput').val('');
            $('#fileDetailsForm')[0].reset();
            $('#dynamicFields').empty();
            $('#hardcopyDetails').hide();
            $('#hardcopyOptions').hide();
            $('#storageSuggestion').hide().empty();
            $('#hardcopyCheckbox').prop('checked', false);
            $('#linkHardcopyButton').prop('disabled', true);
            $('#documentType, #departmentId').val('').trigger('change');
            closePopup('fileDetailsPopup');
            closePopup('linkHardcopyPopup');
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
                        closePopup('sendFilePopup');
                        fetchNotifications();
                    } else {
                        notyf.error(response.message);
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    console.error('Send file error:', textStatus, errorThrown, jqXHR.responseText);
                    notyf.error('Failed to send file. Check console for details.');
                }
            });
        }

        function viewFile(fileId) {
            if (!fileId) {
                notyf.error('Invalid file ID.');
                return;
            }
            window.location.href = `view_file.php?file_id=${fileId}`;
        }

        function sortPersonalFiles() {
            const sortName = $('.sort-personal-name').val();
            const sortType = $('.sort-personal-type').val();
            const sortSource = $('.sort-personal-source').val();
            const isHardCopy = $('#hardCopyPersonalFilter').is(':checked');

            const $files = $('#personalFiles .file-item').get();
            $files.sort(function(a, b) {
                let valA, valB;
                if (sortName) {
                    valA = $(a).data('file-name').toLowerCase();
                    valB = $(b).data('file-name').toLowerCase();
                    return sortName === 'name-asc' ? valA.localeCompare(valB) : valB.localeCompare(valA);
                } else if (sortType) {
                    valA = $(a).data('document-type').toLowerCase();
                    valB = $(b).data('document-type').toLowerCase();
                    return valA.localeCompare(valB);
                } else if (sortSource) {
                    valA = $(a).data('source');
                    valB = $(b).data('source');
                    return valA.localeCompare(valB);
                }
                return 0;
            });

            if (isHardCopy) {
                $files.filter(function() {
                    return !$(this).data('hard-copy');
                }).forEach(function(item) {
                    $(item).hide();
                });
            } else {
                $files.forEach(function(item) {
                    $(item).show();
                });
            }

            $('#personalFiles').empty().append($files);
        }

        function filterPersonalFilesByHardCopy() {
            const isHardCopy = $('#hardCopyPersonalFilter').is(':checked');
            $('#personalFiles .file-item').each(function() {
                $(this).toggle($(this).data('hard-copy') || !isHardCopy);
            });
        }

        function sortDepartmentFiles(deptId) {
            const $grid = $('#departmentFiles-' + deptId);
            const sortName = $grid.closest('.file-subsection').find('.sort-department-name').val();
            const sortType = $grid.closest('.file-subsection').find('.sort-department-type').val();
            const isHardCopy = $grid.closest('.file-subsection').find('.hard-copy-department-filter').is(':checked');

            const $files = $grid.find('.file-item').get();
            $files.sort(function(a, b) {
                let valA, valB;
                if (sortName) {
                    valA = $(a).data('file-name').toLowerCase();
                    valB = $(b).data('file-name').toLowerCase();
                    return sortName === 'name-asc' ? valA.localeCompare(valB) : valB.localeCompare(valA);
                } else if (sortType) {
                    valA = $(a).data('document-type').toLowerCase();
                    valB = $(b).data('document-type').toLowerCase();
                    return valA.localeCompare(valB);
                }
                return 0;
            });

            if (isHardCopy) {
                $files.filter(function() {
                    return !$(this).data('hard-copy');
                }).forEach(function(item) {
                    $(item).hide();
                });
            } else {
                $files.forEach(function(item) {
                    $(item).show();
                });
            }

            $grid.empty().append($files);
        }

        function filterDepartmentFilesByHardCopy(deptId) {
            const isHardCopy = $('#departmentFiles-' + deptId).closest('.file-subsection').find('.hard-copy-department-filter').is(':checked');
            $('#departmentFiles-' + deptId + ' .file-item').each(function() {
                $(this).toggle($(this).data('hard-copy') || !isHardCopy);
            });
        }

        function filterFiles() {
            const searchTerm = $('#fileSearch').val().toLowerCase();
            $('#fileDisplay .file-item').each(function() {
                const fileName = $(this).find('p').text().toLowerCase();
                $(this).toggle(fileName.includes(searchTerm));
            });
        }

        function filterFilesByType() {
            const typeFilter = $('#documentTypeFilter').val().toLowerCase();
            $('#fileDisplay .file-item').each(function() {
                const docType = $(this).data('document-type').toLowerCase();
                $(this).toggle(!typeFilter || docType === typeFilter);
            });
        }

        function switchView(viewType) {
            const $display = $('#fileDisplay');
            $display.removeClass('thumbnail-view list-view').addClass(viewType + '-view');
            $('#thumbnailViewButton').toggleClass('active', viewType === 'thumbnail');
            $('#listViewButton').toggleClass('active', viewType === 'list');
        }
    </script>
</body>

</html>