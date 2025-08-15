<?php
session_start();

// Required dependencies with validation
$requiredFiles = ['db_connection.php', 'log_activity.php', 'notification.php', 'vendor/autoload.php'];
foreach ($requiredFiles as $file) {
    if (!file_exists($file)) {
        error_log("Missing required file: $file");
        http_response_code(500);
        exit("<html><body><h1>Server Error</h1><p>Missing critical dependency. Please contact the administrator.</p></body></html>");
    }
    require_once $file;
}

use Dotenv\Dotenv;

// Load environment variables securely
$dotenv = Dotenv::createImmutable(__DIR__, ['.env']);
$dotenv->safeLoad();

// Configure error handling
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('error_log', __DIR__ . '/logs/error_log.log');
error_reporting(E_ALL);

// File-based cache configuration
$cacheDir = __DIR__ . '/cache';
if (!file_exists($cacheDir)) {
    mkdir($cacheDir, 0777, true);
}
$cacheTTL = (int)($_ENV['CACHE_TTL'] ?? 300);

/**
 * Sends a JSON response with appropriate HTTP status.
 *
 * @param bool $success
 * @param string $message
 * @param array $data
 * @param int $statusCode
 * @return void
 */
function sendJsonResponse(bool $success, string $message, array $data, int $statusCode): void
{
    header('Content-Type: application/json');
    http_response_code($statusCode);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}

/**
 * Stores data in cache.
 *
 * @param string $key
 * @param mixed $value
 * @param int $ttl
 * @return bool
 */
function cacheStore(string $key, $value, int $ttl): bool
{
    global $cacheDir;
    $filename = $cacheDir . '/' . md5($key) . '.cache';
    $data = serialize(['data' => $value, 'expires' => time() + $ttl]);
    return file_put_contents($filename, $data) !== false;
}

/**
 * Fetches data from cache.
 *
 * @param string $key
 * @return mixed
 */
function cacheFetch(string $key)
{
    global $cacheDir;
    $filename = $cacheDir . '/' . md5($key) . '.cache';
    if (file_exists($filename)) {
        $content = unserialize(file_get_contents($filename));
        if ($content['expires'] > time()) {
            return $content['data'];
        } else {
            unlink($filename);
        }
    }
    return false;
}

/**
 * Checks if cache exists and is valid.
 *
 * @param string $key
 * @return bool
 */
function cacheExists(string $key): bool
{
    global $cacheDir;
    $filename = $cacheDir . '/' . md5($key) . '.cache';
    if (file_exists($filename)) {
        $content = unserialize(file_get_contents($filename));
        if ($content['expires'] > time()) {
            return true;
        } else {
            unlink($filename);
        }
    }
    return false;
}

/**
 * Fetches departments and sub-departments for a user.
 *
 * @param PDO $pdo
 * @param int $userId
 * @return array
 */
function fetchUserDepartmentsWithSub(PDO $pdo, int $userId): array
{
    $cacheKey = "departments_user_$userId";
    if (cacheExists($cacheKey)) {
        return cacheFetch($cacheKey);
    }
    try {
        $stmt = $pdo->prepare("
            WITH RECURSIVE dept_hierarchy AS (
                SELECT d.department_id, d.department_name, d.parent_department_id, ud.users_department_id
                FROM departments d
                JOIN users_department ud ON d.department_id = ud.department_id
                WHERE ud.user_id = ?
                UNION ALL
                SELECT d.department_id, d.department_name, d.parent_department_id, ud.users_department_id
                FROM departments d
                JOIN dept_hierarchy dh ON d.parent_department_id = dh.department_id
                JOIN users_department ud ON d.department_id = ud.department_id
            )
            SELECT DISTINCT department_id AS id, department_name AS name, parent_department_id AS parent_id, users_department_id
            FROM dept_hierarchy
            ORDER BY department_name
        ");
        $stmt->execute([$userId]);
        $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($departments === false) {
            error_log("No departments found for user ID: {$userId}");
            return [];
        }
        cacheStore($cacheKey, $departments, $GLOBALS['cacheTTL']);
        return $departments;
    } catch (PDOException $e) {
        error_log("Error fetching departments for user {$userId}: " . $e->getMessage());
        sendJsonResponse(false, 'Failed to fetch departments: ' . $e->getMessage(), [], 500);
        return [];
    }
}

/**
 * Fetches user files.
 *
 * @param PDO $pdo
 * @param int $userId
 * @param ?int $parentFileId
 * @return array
 */
function fetchUserFiles(PDO $pdo, int $userId, ?int $parentFileId): array
{
    $cacheKey = "user_files_{$userId}_" . ($parentFileId ?: 'null');
    if (cacheExists($cacheKey)) {
        return cacheFetch($cacheKey);
    }
    try {
        $query = "
            SELECT f.file_id, f.file_name, f.file_type, f.upload_date, f.file_status, f.copy_type, 
                   f.physical_storage, COALESCE(dt.type_name, 'Unknown Type') AS document_type
            FROM files f
            LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
            WHERE f.user_id = ? AND f.file_status != 'disposed'
            " . ($parentFileId ? "AND f.parent_file_id = ?" : "AND f.parent_file_id IS NULL") . "
            ORDER BY f.upload_date DESC
        ";
        $stmt = $pdo->prepare($query);
        $params = [$userId];
        if ($parentFileId) {
            $params[] = $parentFileId;
        }
        $stmt->execute($params);
        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
        cacheStore($cacheKey, $files, $GLOBALS['cacheTTL']);
        return $files;
    } catch (PDOException $e) {
        error_log("Error fetching user files for user {$userId}: " . $e->getMessage());
        return [];
    }
}

/**
 * Fetches department files.
 *
 * @param PDO $pdo
 * @param int $userId
 * @param int $departmentId
 * @param ?int $parentFileId
 * @return array
 */
function fetchDepartmentFiles(PDO $pdo, int $userId, int $departmentId, ?int $parentFileId): array
{
    $cacheKey = "dept_files_{$userId}_{$departmentId}_" . ($parentFileId ?: 'null');
    if (cacheExists($cacheKey)) {
        return cacheFetch($cacheKey);
    }
    try {
        $query = "
            SELECT DISTINCT f.file_id, f.file_name, f.file_type, f.upload_date, f.file_status, 
                   f.copy_type, f.physical_storage, COALESCE(dt.type_name, 'Unknown Type') AS document_type
            FROM files f
            JOIN transactions t ON f.file_id = t.file_id
            JOIN users_department ud ON t.users_department_id = ud.users_department_id
            LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
            WHERE ud.department_id = ? AND ud.user_id = ? 
            AND t.transaction_type IN ('send', 'accept') 
            AND t.transaction_status = 'completed'
            AND f.file_status != 'disposed'
            " . ($parentFileId ? "AND f.parent_file_id = ?" : "AND f.parent_file_id IS NULL") . "
            ORDER BY f.upload_date DESC
        ";
        $stmt = $pdo->prepare($query);
        $params = [$departmentId, $userId];
        if ($parentFileId) {
            $params[] = $parentFileId;
        }
        $stmt->execute($params);
        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
        cacheStore($cacheKey, $files, $GLOBALS['cacheTTL']);
        return $files;
    } catch (PDOException $e) {
        error_log("Error fetching department files for user {$userId}, dept {$departmentId}: " . $e->getMessage());
        return [];
    }
}

/**
 * Fetches all department files for a user.
 *
 * @param PDO $pdo
 * @param int $userId
 * @param ?int $parentFileId
 * @return array
 */
function fetchAllDepartmentFiles(PDO $pdo, int $userId, ?int $parentFileId): array
{
    $cacheKey = "all_dept_files_{$userId}_" . ($parentFileId ?: 'null');
    if (cacheExists($cacheKey)) {
        return cacheFetch($cacheKey);
    }
    try {
        $stmt = $pdo->prepare("SELECT department_id FROM users_department WHERE user_id = ?");
        $stmt->execute([$userId]);
        $departmentIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'department_id');
        if (empty($departmentIds)) {
            return [];
        }
        $query = "
            SELECT DISTINCT f.file_id, f.file_name, f.file_type, f.upload_date, f.file_status, 
                   f.copy_type, f.physical_storage, COALESCE(dt.type_name, 'Unknown Type') AS document_type
            FROM files f
            JOIN transactions t ON f.file_id = t.file_id
            JOIN users_department ud ON t.users_department_id = ud.users_department_id
            LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
            WHERE ud.department_id IN (" . implode(',', array_fill(0, count($departmentIds), '?')) . ") 
            AND ud.user_id = ?
            AND t.transaction_type IN ('send', 'accept')
            AND t.transaction_status = 'completed'
            AND f.file_status != 'disposed'
            " . ($parentFileId ? "AND f.parent_file_id = ?" : "AND f.parent_file_id IS NULL") . "
            ORDER BY f.upload_date DESC
        ";
        $params = array_merge($departmentIds, [$userId]);
        if ($parentFileId) {
            $params[] = $parentFileId;
        }
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
        cacheStore($cacheKey, $files, $GLOBALS['cacheTTL']);
        return $files;
    } catch (PDOException $e) {
        error_log("Error fetching all department files for user {$userId}: " . $e->getMessage());
        return [];
    }
}

/**
 * Fetches notifications for pending file approvals.
 *
 * @param PDO $pdo
 * @param int $userId
 * @return array
 */
function fetchNotifications(PDO $pdo, int $userId): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT t.transaction_id AS id, t.file_id, t.transaction_status AS status, 
                   t.transaction_time AS timestamp, t.message,
                   COALESCE(f.file_name, 'Unknown File') AS file_name,
                   f.file_path, f.copy_type
            FROM transactions t
            LEFT JOIN files f ON t.file_id = f.file_id
            WHERE t.user_id = ? AND t.transaction_type IN ('request', 'send')
            AND t.transaction_status = 'pending'
            AND (f.file_status != 'disposed' OR f.file_id IS NULL)
            ORDER BY t.transaction_time DESC
            LIMIT 10
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching notifications for user {$userId}: " . $e->getMessage());
        return [];
    }
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
    $iconMap = [
        'pdf' => 'fas fa-file-pdf',
        'doc' => 'fas fa-file-word',
        'docx' => 'fas fa-file-word',
        'xls' => 'fas fa-file-excel',
        'xlsx' => 'fas fa-file-excel',
        'ppt' => 'fas fa-file-powerpoint',
        'pptx' => 'fas fa-file-powerpoint',
        'jpg' => 'fas fa-file-image',
        'png' => 'fas fa-file-image',
        'jpeg' => 'fas fa-file-image',
        'gif' => 'fas fa-file-image',
        'txt' => 'fas fa-file-alt',
        'zip' => 'fas fa-file-archive',
        'rar' => 'fas fa-file-archive',
        'csv' => 'fas fa-file-csv'
    ];
    return $iconMap[$extension] ?? 'fas fa-file';
}

try {
    // Validate user session
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
        header('Location: logout.php');
        exit;
    }
    $userId = (int)$_SESSION['user_id'];
    $username = htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8');
    $userRole = htmlspecialchars($_SESSION['role'], ENT_QUOTES, 'UTF-8');
    session_regenerate_id(true);

    // Generate CSRF token
    $csrfToken = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $csrfToken;

    global $pdo;

    // Fetch user details
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
    $userDetails = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$userDetails) {
        error_log("User not found for ID: $userId");
        header('Location: logout.php');
        exit;
    }

    // Fetch departments
    $departments = fetchUserDepartmentsWithSub($pdo, $userId);

    // Fetch personal files
    $personalFiles = fetchUserFiles($pdo, $userId, null);

    // Fetch department files
    $departmentFiles = [];
    foreach ($departments as $dept) {
        $departmentFiles[$dept['id']] = fetchDepartmentFiles($pdo, $userId, $dept['id'], null);
    }

    // Fetch notifications
    $notifications = fetchNotifications($pdo, $userId);
} catch (Exception $e) {
    error_log("Error in folders.php: " . $e->getMessage());
    sendJsonResponse(false, 'Server error: ' . $e->getMessage(), [], 500);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <title>Folders - File Management System</title>
    <link rel="stylesheet" href="style/folder-page.css">
    <link rel="stylesheet" href="style/client-sidebar.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js"></script>
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
    <script src="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.css">
</head>

<body>
    <div class="sidebar">
        <button class="toggle-btn"><i class="fas fa-bars"></i></button>
        <h2>File Management</h2>
        <?php if ($userRole === 'admin'): ?>
            <a href="admin_dashboard.php" class="admin-dashboard-btn" data-tooltip="Admin Dashboard"><i class="fas fa-cog"></i><span class="link-text">Admin Dashboard</span></a>
        <?php endif; ?>
        <a href="dashboard.php" class="active" data-tooltip="Dashboard"><i class="fas fa-home"></i><span class="link-text">Dashboard</span></a>
        <a href="folders.php" class="active" data-tooltip="My Folder"><i class="fas fa-folder"></i><span class="link-text">My Folder</span></a>
        <a href="my-report.php" data-tooltip="My Report" class="active">
            <i class="fas fa-chart-bar"></i><span class="link-text">My Report</span>
        </a>

        <a href="logout.php" class="logout-btn" data-tooltip="Logout"><i class="fas fa-sign-out-alt"></i><span class="link-text">Logout</span></a>
    </div>

    <div class="top-nav">
        <h2>Folders</h2>
        <div class="search-container">
            <i class="fas fa-search search-icon"></i>
            <input type="text" class="search-bar" id="fileSearch" placeholder="Search files...">
        </div>
        <button id="toggleSidebar"><i class="fas fa-bars"></i></button>
    </div>

    <div class="main-content">
        <div class="notification-log">
            <h3>Notifications</h3>
            <div class="log-entries">
                <?php foreach ($notifications as $log): ?>
                    <div class="log-entry" data-file-id="<?= htmlspecialchars($log['file_id'], ENT_QUOTES, 'UTF-8') ?>">
                        <i class="fas fa-bell"></i>
                        <p><?= htmlspecialchars($log['message'], ENT_QUOTES, 'UTF-8') ?></p>
                        <span><?= date('M d, Y H:i', strtotime($log['timestamp'])) ?></span>
                        <button class="select-file-button" onclick="showFilePreview(<?= htmlspecialchars($log['file_id'], ENT_QUOTES, 'UTF-8') ?>)">Preview</button>
                        <button class="select-file-button" onclick="handleAccessRequest(<?= htmlspecialchars($log['file_id'], ENT_QUOTES, 'UTF-8') ?>, 'accept')">Accept</button>
                        <button class="select-file-button" onclick="handleAccessRequest(<?= htmlspecialchars($log['file_id'], ENT_QUOTES, 'UTF-8') ?>, 'deny')">Deny</button>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($notifications)): ?>
                    <p class="no-results">No pending notifications</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="view-tabs">
            <div class="view-tab active" data-view="personal">Personal Files</div>
            <div class="view-tab" data-view="department">Department Files</div>
        </div>

        <div id="personalFiles" class="file-card-container">
            <h3>Personal Files</h3>
            <?php if (empty($personalFiles)): ?>
                <p class="no-results">No personal files found</p>
            <?php else: ?>
                <div class="file-grid">
                    <?php foreach ($personalFiles as $file): ?>
                        <div class="file-card"
                            data-file-id="<?= htmlspecialchars($file['file_id'], ENT_QUOTES, 'UTF-8') ?>"
                            data-file-name="<?= htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8') ?>"
                            data-document-type="<?= htmlspecialchars($file['document_type'], ENT_QUOTES, 'UTF-8') ?>">
                            <i class="<?= htmlspecialchars(getFileIcon($file['file_name']), ENT_QUOTES, 'UTF-8') ?>"></i>
                            <p class="file-name"><?= htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8') ?></p>
                            <p><?= htmlspecialchars($file['document_type'], ENT_QUOTES, 'UTF-8') ?></p>
                            <p><?= date('M d, Y', strtotime($file['upload_date'])) ?></p>
                            <div class="file-actions">
                                <button class="rename-btn" title="Rename"><i class="fas fa-edit"></i></button>
                                <button class="delete-btn" title="Delete"><i class="fas fa-trash"></i></button>
                                <button class="info-btn" title="Info"><i class="fas fa-info-circle"></i></button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div id="departmentFiles" class="file-card-container" style="display: none;">
            <h3>Department Files</h3>
            <?php if (empty($departments)): ?>
                <p class="no-departments-message">You are not assigned to any departments</p>
            <?php else: ?>
                <div class="department-selector">
                    <select id="departmentSelect">
                        <option value="">Select a Department</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= htmlspecialchars($dept['id'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($dept['name'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="departmentFileGrid" class="file-grid">
                    <p class="no-results">Select a department to view files</p>
                </div>
            <?php endif; ?>
        </div>

        <div id="renameModal" class="modal">
            <div class="modal-content">
                <span class="close">&times;</span>
                <h2>Rename File</h2>
                <form id="renameForm">
                    <input type="hidden" id="renameFileId" name="file_id">
                    <label for="newFileName">New File Name</label>
                    <input type="text" id="newFileName" name="new_file_name" required>
                    <div class="confirm-buttons">
                        <button type="submit">Rename</button>
                        <button type="button" onclick="closeModal('rename')">Cancel</button>
                    </div>
                </form>
            </div>
        </div>

        <div id="fileInfoSidebar" class="file-info-sidebar">
            <div class="file-info-header">
                <div class="active" data-section="general">General</div>
                <div data-section="history">History</div>
            </div>
            <div id="generalSection" class="info-section active">
                <p><strong>Name:</strong> <span id="infoFileName"></span></p>
                <p><strong>Type:</strong> <span id="infoFileType"></span></p>
                <p><strong>Uploaded:</strong> <span id="infoUploadDate"></span></p>
                <p><strong>Status:</strong> <span id="infoFileStatus"></span></p>
                <p><strong>Copy Type:</strong> <span id="infoCopyType"></span></p>
                <p><strong>Physical Storage:</strong> <span id="infoPhysicalStorage"></span></p>
            </div>
            <div id="historySection" class="info-section">
                <div id="fileHistory"></div>
            </div>
            <button class="close-file-info"><i class="fas fa-times"></i></button>
        </div>

        <div id="fullPreviewModal" class="full-preview-modal">
            <div class="full-preview-content">
                <div id="filePreview"></div>
                <button class="close-full-preview"><i class="fas fa-times"></i></button>
            </div>
        </div>

        <div id="fileAcceptancePopup" class="modal">
            <div class="modal-content">
                <span class="close">&times;</span>
                <h2>File Preview</h2>
                <div class="file-preview" id="filePreviewContent"></div>
                <div class="confirm-buttons">
                    <button id="acceptFileButton" onclick="handleAccessRequest($('#fileAcceptancePopup').data('file-id'), 'accept')">Accept</button>
                    <button id="denyFileButton" onclick="handleAccessRequest($('#fileAcceptancePopup').data('file-id'), 'deny')">Deny</button>
                    <button type="button" onclick="closeModal('fileAcceptance')">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        const notyf = new Notyf();
        const state = {
            activeModal: null,
            activeSidebar: false,
            isLoading: false
        };

        $(document).ready(function() {
            // Sidebar toggle
            $('#toggleSidebar').on('click', function() {
                $('.sidebar').toggleClass('minimized');
                $('.top-nav, .main-content').toggleClass('resized');
                state.activeSidebar = !state.activeSidebar;
            });

            // View tabs
            $('.view-tab').on('click', function() {
                $('.view-tab').removeClass('active');
                $(this).addClass('active');
                $('#personalFiles, #departmentFiles').hide();
                $(`#${$(this).data('view')}Files`).show();
                if ($(this).data('view') === 'department' && $('#departmentSelect').val()) {
                    loadDepartmentFiles($('#departmentSelect').val());
                }
            });

            // Department selector
            $('#departmentSelect').on('change', function() {
                loadDepartmentFiles($(this).val());
            });

            // File search
            $('#fileSearch').on('input', function() {
                const searchTerm = $(this).val().toLowerCase();
                $('.file-card').each(function() {
                    const fileName = $(this).data('fileName').toLowerCase();
                    $(this).toggle(fileName.includes(searchTerm));
                });
            });

            // Modal close
            $('.close').on('click', function() {
                $(this).closest('.modal').hide();
                state.activeModal = null;
            });

            // File cards
            $('.file-card').each(function() {
                const card = $(this);
                card.on('click', function(e) {
                    if (e.target.closest('.file-actions')) return;
                    openFullPreview(card.data('fileId'));
                });
                card.find('.rename-btn').on('click', function() {
                    $('#renameFileId').val(card.data('fileId'));
                    $('#newFileName').val(card.find('.file-name').text());
                    openModal('rename');
                });
                card.find('.delete-btn').on('click', function() {
                    const confirmModal = $('<div class="modal"><div class="modal-content"><h2>Confirm Delete</h2><p>Are you sure you want to delete this file?</p><div class="confirm-buttons"><button onclick="deleteFile(' + card.data('fileId') + ')">Delete</button><button onclick="$(this).closest(\'.modal\').remove()">Cancel</button></div></div></div>');
                    $('body').append(confirmModal);
                    confirmModal.show();
                });
                card.find('.info-btn').on('click', function() {
                    showFileInfo(card.data('fileId'));
                });
            });

            // File info sidebar tabs
            $('.file-info-header div').on('click', function() {
                $('.file-info-header div').removeClass('active');
                $('.info-section').removeClass('active');
                $(this).addClass('active');
                $(`#${$(this).data('section')}Section`).addClass('active');
            });

            // Close file info
            $('.close-file-info').on('click', closeFileInfo);

            // Close full preview
            $('.close-full-preview').on('click', closeFullPreview);

            // Handle escape key
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape') {
                    if (state.activeModal) {
                        closeModal(state.activeModal);
                    }
                    if (state.activeSidebar) {
                        $('.sidebar').addClass('minimized');
                        $('.top-nav, .main-content').addClass('resized');
                        state.activeSidebar = false;
                    }
                    if ($('#fileInfoSidebar').hasClass('active')) {
                        closeFileInfo();
                    }
                    if ($('#fullPreviewModal').hasClass('open')) {
                        closeFullPreview();
                    }
                    $('.modal').each(function() {
                        if ($(this).css('display') === 'block' && !$(this).attr('id')) {
                            $(this).remove();
                        }
                    });
                }
            });

            // Prevent double form submission
            $('form').on('submit', function(e) {
                if (state.isLoading) {
                    e.preventDefault();
                }
            });

            // Focus management
            $('.modal').on('transitionend', function() {
                if ($(this).css('display') === 'block') {
                    $(this).find('button, input, select').first().focus();
                }
            });

            // Rename form submission
            $('#renameForm').on('submit', function(e) {
                e.preventDefault();
                setLoadingState(true);
                const fileId = $('#renameFileId').val();
                const newFileName = $('#newFileName').val();
                $.ajax({
                    url: 'rename_file.php',
                    method: 'POST',
                    headers: {
                        'X-CSRF-Token': $('meta[name="csrf-token"]').attr('content')
                    },
                    data: JSON.stringify({
                        file_id: fileId,
                        new_file_name: newFileName
                    }),
                    contentType: 'application/json',
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            notyf.success(response.message);
                            closeModal('rename');
                            location.reload();
                        } else {
                            notyf.error(response.message || 'Failed to rename file.');
                        }
                    },
                    error: function() {
                        notyf.error('Error renaming file.');
                    },
                    complete: function() {
                        setLoadingState(false);
                    }
                });
            });
        });

        function setLoadingState(isLoading) {
            state.isLoading = isLoading;
            $('.loading-spinner').toggle(isLoading);
        }

        function showAlert(message, type, redirect) {
            const alert = $('<div class="custom-alert ' + type + '"><p>' + message + '</p></div>');
            $('body').append(alert);
            setTimeout(() => {
                alert.remove();
                if (redirect) {
                    eval(redirect);
                }
            }, 3000);
        }

        function openModal(modalId) {
            state.activeModal = modalId;
            $(`#${modalId}Modal`).show();
        }

        function closeModal(modalId) {
            $(`#${modalId}Modal`).hide();
            state.activeModal = null;
        }

        function showFileInfo(fileId) {
            $.ajax({
                url: 'get_file_details.php',
                method: 'POST',
                data: {
                    file_id: fileId,
                    csrf_token: $('meta[name="csrf-token"]').attr('content')
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        const data = response.data;
                        $('#infoFileName').text(data.file_name || 'N/A');
                        $('#infoFileType').text(data.document_type || 'N/A');
                        $('#infoUploadDate').text(data.upload_date ? new Date(data.upload_date).toLocaleDateString('en-US') : 'N/A');
                        $('#infoFileStatus').text(data.file_status || 'N/A');
                        $('#infoCopyType').text(data.copy_type || 'N/A');
                        $('#infoPhysicalStorage').text(data.physical_storage || 'None');
                        $('#fileHistory').html(data.history ? data.history.map(h => `<p>${h.action} on ${new Date(h.timestamp).toLocaleString('en-US')}</p>`).join('') : '<p>No history available</p>');
                        $('#fileInfoSidebar').addClass('active');
                    } else {
                        notyf.error(response.message || 'Error fetching file info.');
                    }
                },
                error: function() {
                    notyf.error('Error fetching file info.');
                }
            });
        }

        function closeFileInfo() {
            $('#fileInfoSidebar').removeClass('active');
        }

        function openFullPreview(fileId) {
            $('#fullPreviewModal').data('file-id', fileId).addClass('open');
            const preview = $('#filePreview');
            $.ajax({
                url: 'get_file_details.php',
                method: 'POST',
                data: {
                    file_id: fileId,
                    csrf_token: $('meta[name="csrf-token"]').attr('content')
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        const data = response.data;
                        if (data.file_path) {
                            const ext = data.file_path.split('.').pop().toLowerCase();
                            if (ext === 'pdf') {
                                preview.html(`<iframe src="${data.file_path}" title="File Preview" style="width: 100%; height: 80vh;"></iframe>`);
                            } else if (['jpg', 'png', 'jpeg', 'gif'].includes(ext)) {
                                preview.html(`<img src="${data.file_path}" alt="File Preview" style="max-width: 100%; max-height: 80vh;">`);
                            } else {
                                preview.html('<p>Preview not available for this file type</p>');
                            }
                        } else if (data.copy_type === 'hard') {
                            preview.html('<p>Hardcopy - No digital preview available</p>');
                        } else {
                            preview.html('<p>No preview available</p>');
                        }
                    } else {
                        notyf.error(response.message || 'Error fetching file preview.');
                    }
                },
                error: function() {
                    notyf.error('Error fetching file preview.');
                }
            });
        }

        function closeFullPreview() {
            $('#fullPreviewModal').removeClass('open');
            $('#filePreview').empty();
        }

        function showFilePreview(fileId) {
            $('#fileAcceptancePopup').data('file-id', fileId).show();
            const preview = $('#filePreviewContent');
            $.ajax({
                url: 'get_file_details.php',
                method: 'POST',
                data: {
                    file_id: fileId,
                    csrf_token: $('meta[name="csrf-token"]').attr('content')
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        const data = response.data;
                        if (data.file_path) {
                            const ext = data.file_path.split('.').pop().toLowerCase();
                            if (ext === 'pdf') {
                                preview.html(`<iframe src="${data.file_path}" title="File Preview" style="width: 100%; height: 300px;"></iframe>`);
                            } else if (['jpg', 'png', 'jpeg', 'gif'].includes(ext)) {
                                preview.html(`<img src="${data.file_path}" alt="File Preview" style="max-width: 100%; max-height: 300px;">`);
                            } else {
                                preview.html('<p>Preview not available for this file type</p>');
                            }
                        } else if (data.copy_type === 'hard') {
                            preview.html('<p>Hardcopy - No digital preview available</p>');
                        } else {
                            preview.html('<p>No preview available</p>');
                        }
                    } else {
                        notyf.error(response.message || 'Error fetching file preview.');
                    }
                },
                error: function() {
                    notyf.error('Error fetching file preview.');
                }
            });
        }

        function handleAccessRequest(fileId, action) {
            setLoadingState(true);
            $.ajax({
                url: 'handle_access_request.php',
                method: 'POST',
                data: JSON.stringify({
                    file_id: fileId,
                    action: action,
                    csrf_token: $('meta[name="csrf-token"]').attr('content')
                }),
                contentType: 'application/json',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        notyf.success(response.message);
                        $('#fileAcceptancePopup').hide();
                        location.reload();
                    } else {
                        notyf.error(response.message || 'Error handling access request.');
                    }
                },
                error: function() {
                    notyf.error('Error handling access request.');
                },
                complete: function() {
                    setLoadingState(false);
                }
            });
        }

        function deleteFile(fileId) {
            setLoadingState(true);
            $.ajax({
                url: 'delete_file.php',
                method: 'POST',
                headers: {
                    'X-CSRF-Token': $('meta[name="csrf-token"]').attr('content')
                },
                data: JSON.stringify({
                    file_id: fileId
                }),
                contentType: 'application/json',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        notyf.success(response.message);
                        location.reload();
                    } else {
                        notyf.error(response.message || 'Failed to delete file.');
                    }
                },
                error: function() {
                    notyf.error('Error deleting file.');
                },
                complete: function() {
                    setLoadingState(false);
                }
            });
        }

        function loadDepartmentFiles(deptId) {
            if (!deptId) {
                $('#departmentFileGrid').html('<p class="no-results">Select a department to view files</p>');
                return;
            }
            setLoadingState(true);
            $.ajax({
                url: 'get_department_files.php',
                method: 'POST',
                data: {
                    department_id: deptId,
                    csrf_token: $('meta[name="csrf-token"]').attr('content')
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        const files = response.data;
                        const grid = $('#departmentFileGrid').empty();
                        if (files.length === 0) {
                            grid.append('<p class="no-results">No files found for this department</p>');
                        } else {
                            files.forEach(file => {
                                const card = $(`
                            <div class="file-card" 
                                 data-file-id="${file.file_id}" 
                                 data-file-name="${file.file_name}" 
                                 data-document-type="${file.document_type}">
                                <i class="${getFileIcon(file.file_name)}"></i>
                                <p class="file-name">${file.file_name}</p>
                                <p>${file.document_type}</p>
                                <p>${new Date(file.upload_date).toLocaleDateString('en-US')}</p>
                                <div class="file-actions">
                                    <button class="info-btn" title="Info"><i class="fas fa-info-circle"></i></button>
                                </div>
                            </div>
                        `);
                                card.on('click', function(e) {
                                    if (e.target.closest('.file-actions')) return;
                                    openFullPreview(file.file_id);
                                });
                                card.find('.info-btn').on('click', function() {
                                    showFileInfo(file.file_id);
                                });
                                grid.append(card);
                            });
                        }
                    } else {
                        notyf.error(response.message || 'Error fetching department files.');
                    }
                },
                error: function() {
                    notyf.error('Error fetching department files.');
                },
                complete: function() {
                    setLoadingState(false);
                }
            });
        }

        function getFileIcon(fileName) {
            const extension = fileName.split('.').pop().toLowerCase();
            const iconClasses = {
                'pdf': 'fas fa-file-pdf',
                'doc': 'fas fa-file-word',
                'docx': 'fas fa-file-word',
                'xls': 'fas fa-file-excel',
                'xlsx': 'fas fa-file-excel',
                'ppt': 'fas fa-file-powerpoint',
                'pptx': 'fas fa-file-powerpoint',
                'jpg': 'fas fa-file-image',
                'jpeg': 'fas fa-file-image',
                'png': 'fas fa-file-image',
                'gif': 'fas fa-file-image',
                'txt': 'fas fa-file-alt',
                'zip': 'fas fa-file-archive',
                'rar': 'fas fa-file-archive',
                'csv': 'fas fa-file-csv'
            };
            return iconClasses[extension] || 'fas fa-file';
        }
    </script>
</body>

</html>