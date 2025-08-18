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

// Load environment variables
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
    header('Content-Type: application/json; charset=utf-8');
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
    return file_put_contents($filename, $data, LOCK_EX) !== false;
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
        }
        unlink($filename);
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
        }
        unlink($filename);
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
            SELECT DISTINCT department_id AS id, department_name AS name, parent_department_id AS parent_id
            FROM dept_hierarchy
            ORDER BY department_name
        ");
        $stmt->execute([$userId]);
        $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        cacheStore($cacheKey, $departments, $GLOBALS['cacheTTL']);
        return $departments;
    } catch (PDOException $e) {
        error_log("Error fetching departments for user {$userId}: " . $e->getMessage());
        sendJsonResponse(false, 'Failed to fetch departments.', [], 500);
        return []; // 👈 ensures array is always returned
    }
}



/**
 * Fetches personal files for a user.
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
                   f.physical_storage_path AS physical_storage, COALESCE(dt.type_name, 'Unknown Type') AS document_type
            FROM files f
            LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
            WHERE f.user_id = ? AND f.file_status != 'disposed'
            " . ($parentFileId ? "AND f.parent_file_id = ?" : "AND f.parent_file_id IS NULL") . "
            ORDER BY f.upload_date DESC
            LIMIT 100
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
 * Fetches department files for a user.
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
                   f.copy_type, f.physical_storage_path AS physical_storage, COALESCE(dt.type_name, 'Unknown Type') AS document_type
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
            LIMIT 100
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
    sendJsonResponse(false, 'Server error occurred.', [], 500);
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
    <link rel="stylesheet" href="style/client-sidebar.css">
    <link rel="stylesheet" href="style/folder-page.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/noty/3.1.4/noty.min.css">

</head>

<body>
    <div class="sidebar <?php echo $userRole === 'admin' ? '' : 'minimized'; ?>">
        <button class="toggle-btn"><i class="fas fa-bars"></i></button>
        <h2>File Management System</h2>
        <a href="dashboard.php" class="<?php echo $userRole === 'admin' ? 'admin-dashboard-btn' : ''; ?>" data-tooltip="Dashboard">
            <i class="fas fa-tachometer-alt"></i>
            <span class="link-text">Dashboard</span>
        </a>
        <a href="folders.php" class="active" data-tooltip="My Folders">
            <i class="fas fa-folder"></i>
            <span class="link-text">My Folders</span>
        </a>
        <?php if ($userRole === 'admin'): ?>
            <a href="admin.php" data-tooltip="Admin Panel">
                <i class="fas fa-user-shield"></i>
                <span class="link-text">Admin Panel</span>
            </a>
        <?php endif; ?>
        <a href="logout.php" class="logout-btn" data-tooltip="Logout">
            <i class="fas fa-sign-out-alt"></i>
            <span class="link-text">Logout</span>
        </a>
    </div>

    <div class="main-content <?php echo $userRole === 'admin' ? '' : 'resized'; ?>">
        <div class="top-nav <?php echo $userRole === 'admin' ? '' : 'resized'; ?>">
            <button class="toggle-btn"><i class="fas fa-bars"></i></button>
            <h2>My Folders</h2>
            <div class="search-container">
                <input type="text" class="search-bar" id="searchInput" placeholder="Search files...">
            </div>
            <button onclick="openModal('upload')"><i class="fas fa-upload"></i> Upload File</button>
        </div>

        <div class="view-tabs">
            <div class="view-tab <?php echo !isset($_GET['dept']) ? 'active' : ''; ?>" data-view="personal">Personal Files</div>
            <div class="view-tab <?php echo isset($_GET['dept']) ? 'active' : ''; ?>" data-view="department">Department Files</div>
        </div>

        <?php if (empty($departments) && isset($_GET['dept'])): ?>
            <div class="no-departments-message">You are not assigned to any departments.</div>
        <?php endif; ?>

        <div class="department-selector" <?php echo !isset($_GET['dept']) ? 'style="display: none;"' : ''; ?>>
            <select id="departmentSelect" onchange="loadDepartmentFiles(this.value)">
                <option value="">Select Department</option>
                <?php foreach ($departments as $dept): ?>
                    <option value="<?php echo $dept['id']; ?>" <?php echo isset($_GET['dept']) && $_GET['dept'] == $dept['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($dept['name'], ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="sorting-buttons">
            <button class="sort-btn" data-sort="name">Name</button>
            <button class="sort-btn" data-sort="date">Date</button>
            <button class="sort-btn" data-sort="type">Type</button>
        </div>

        <div class="masonry-section">
            <h3 id="sectionTitle"><?php echo isset($_GET['dept']) ? 'Department Files' : 'Personal Files'; ?></h3>
            <div class="file-card-container" id="fileGrid">
                <?php if (!isset($_GET['dept'])): ?>
                    <?php foreach ($personalFiles as $file): ?>
                        <div class="file-card" data-file-id="<?php echo $file['file_id']; ?>" data-file-name="<?php echo htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8'); ?>" data-document-type="<?php echo htmlspecialchars($file['document_type'], ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="file-status <?php echo strtolower($file['file_status']); ?>"><?php echo htmlspecialchars($file['file_status'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="file-icon-container">
                                <i class="<?php echo getFileIcon($file['file_name']); ?>"></i>
                            </div>
                            <p class="file-name"><?php echo htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8'); ?></p>
                            <p class="file-type-badge"><?php echo htmlspecialchars($file['document_type'], ENT_QUOTES, 'UTF-8'); ?></p>
                            <p><?php echo $file['upload_date'] ? date('M d, Y', strtotime($file['upload_date'])) : 'N/A'; ?></p>
                            <div class="file-actions">
                                <button class="info-btn" title="Info" onclick="showFileInfo(<?php echo $file['file_id']; ?>)"><i class="fas fa-info-circle"></i></button>
                                <button class="rename-btn" title="Rename" onclick="openModal('rename', <?php echo $file['file_id']; ?>, '<?php echo htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8'); ?>')"><i class="fas fa-edit"></i></button>
                                <button class="delete-btn" title="Delete" onclick="openModal('confirm', <?php echo $file['file_id']; ?>)"><i class="fas fa-trash"></i></button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div id="departmentFileGrid"></div>
                <?php endif; ?>
            </div>
            <?php if (!isset($_GET['dept']) && count($personalFiles) >= 100): ?>
                <div class="view-more">
                    <button onclick="loadMoreFiles()">View More</button>
                </div>
            <?php endif; ?>
        </div>

        <div class="masonry-section">
            <h3>Notifications</h3>
            <div id="notificationList">
                <?php foreach ($notifications as $notif): ?>
                    <div class="notification-card" data-transaction-id="<?php echo $notif['id']; ?>">
                        <p><?php echo htmlspecialchars($notif['message'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars($notif['file_name'], ENT_QUOTES, 'UTF-8'); ?>)</p>
                        <div class="notification-actions">
                            <button onclick="handleAccessRequest(<?php echo $notif['file_id']; ?>, 'accept')">Accept</button>
                            <button class="reject" onclick="handleAccessRequest(<?php echo $notif['file_id']; ?>, 'reject')">Reject</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- File Info Sidebar -->
    <div class="file-info-sidebar" id="fileInfoSidebar">
        <div class="file-name-container">
            <h3 class="file-name-title" id="infoFileName"></h3>
            <button class="close-sidebar-btn" onclick="closeFileInfo()"><i class="fas fa-times"></i></button>
        </div>
        <div class="file-info-header">
            <div class="active" data-tab="details">Details</div>
            <div data-tab="history">History</div>
        </div>
        <div class="info-section active" id="detailsSection">
            <div class="info-item">
                <span class="info-label">Type:</span>
                <span class="info-value" id="infoFileType"></span>
            </div>
            <div class="info-item">
                <span class="info-label">Upload Date:</span>
                <span class="info-value" id="infoUploadDate"></span>
            </div>
            <div class="info-item">
                <span class="info-label">Status:</span>
                <span class="info-value" id="infoFileStatus"></span>
            </div>
            <div class="info-item">
                <span class="info-label">Copy Type:</span>
                <span class="info-value" id="infoCopyType"></span>
            </div>
            <div class="info-item">
                <span class="info-label">Physical Storage:</span>
                <span class="info-value" id="infoPhysicalStorage"></span>
            </div>
        </div>
        <div class="info-section" id="historySection">
            <div class="access-log">
                <h3>File History</h3>
                <div id="fileHistory"></div>
            </div>
        </div>
    </div>

    <!-- Modals -->
    <div class="modal" id="renameModal">
        <div class="modal-content">
            <h3>Rename File</h3>
            <form id="renameForm">
                <input type="hidden" id="renameFileId">
                <label for="newFileName">New File Name</label>
                <input type="text" id="newFileName" required>
                <button type="submit">Rename</button>
            </form>
        </div>
    </div>

    <div class="modal" id="confirmModal">
        <div class="modal-content">
            <p>Are you sure you want to delete this file?</p>
            <div class="confirm-buttons">
                <button onclick="deleteFile($('#confirmModal').data('file-id'))">Yes</button>
                <button onclick="closeModal('confirm')">No</button>
            </div>
        </div>
    </div>

    <div class="modal full-preview-modal" id="fullPreviewModal">
        <div class="full-preview-content">
            <button class="close-full-preview" onclick="closeFullPreview()"><i class="fas fa-times"></i></button>
            <div id="filePreview"></div>
        </div>
    </div>

    <div class="modal" id="uploadModal">
        <div class="modal-content">
            <h3>Upload File</h3>
            <form id="uploadForm" enctype="multipart/form-data">
                <label for="fileUpload">Select File</label>
                <input type="file" id="fileUpload" accept=".pdf,.docx,.txt,.png,.jpg,.jpeg,.csv,.xlsx" required>
                <label for="documentType">Document Type</label>
                <select id="documentType">
                    <option value="">Select Type</option>
                    <?php
                    $stmt = $pdo->query("SELECT document_type_id, type_name FROM document_types ORDER BY type_name");
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        echo "<option value=\"{$row['document_type_id']}\">" . htmlspecialchars($row['type_name'], ENT_QUOTES, 'UTF-8') . "</option>";
                    }
                    ?>
                </select>
                <label for="department">Send to Department (Optional)</label>
                <select id="department">
                    <option value="">Personal File</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit">Upload</button>
            </form>
        </div>
    </div>

    <div class="loading-spinner"></div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/noty/3.1.4/noty.min.js"></script>
    <script>
        const notyf = new Noty({
            timeout: 3000,
            theme: 'metroui'
        });
        let state = {
            isLoading: false,
            activeModal: null,
            currentPage: 1
        };

        $(document).ready(function() {
            $('.toggle-btn').on('click', function() {
                $('.sidebar, .main-content, .top-nav').toggleClass('minimized resized');
            });

            $('.view-tab').on('click', function() {
                $('.view-tab').removeClass('active');
                $(this).addClass('active');
                const view = $(this).data('view');
                if (view === 'personal') {
                    window.location.href = 'folders.php';
                } else {
                    $('#departmentSelect').val('').trigger('change');
                    $('.department-selector').show();
                    $('#sectionTitle').text('Department Files');
                    $('#fileGrid').html('<p class="no-results">Select a department to view files</p>');
                }
            });

            $('.sort-btn').on('click', function() {
                $('.sort-btn').removeClass('active');
                $(this).addClass('active');
                const sortBy = $(this).data('sort');
                sortFiles(sortBy);
            });

            $('#searchInput').on('input', function() {
                const query = $(this).val().toLowerCase();
                $('.file-card').each(function() {
                    const fileName = $(this).data('file-name').toLowerCase();
                    const fileType = $(this).data('document-type').toLowerCase();
                    $(this).toggle(fileName.includes(query) || fileType.includes(query));
                });
            });

            $('.file-card').on('click', function(e) {
                if (e.target.closest('.file-actions')) return;
                openFullPreview($(this).data('file-id'));
            });

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

            $('#uploadForm').on('submit', function(e) {
                e.preventDefault();
                setLoadingState(true);
                const formData = new FormData();
                formData.append('file', $('#fileUpload')[0].files[0]);
                formData.append('document_type_id', $('#documentType').val());
                formData.append('department_id', $('#department').val());
                formData.append('csrf_token', $('meta[name="csrf-token"]').attr('content'));

                $.ajax({
                    url: 'upload_file.php',
                    method: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            notyf.success(response.message);
                            closeModal('upload');
                            location.reload();
                        } else {
                            notyf.error(response.message || 'Failed to upload file.');
                        }
                    },
                    error: function() {
                        notyf.error('Error uploading file.');
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

        function showAlert(message, type) {
            notyf.open({
                type: type,
                message: message
            });
        }

        function openModal(modalId, fileId = null, fileName = '') {
            state.activeModal = modalId;
            $(`#${modalId}Modal`).addClass('open');
            if (modalId === 'rename' && fileId) {
                $('#renameFileId').val(fileId);
                $('#newFileName').val(fileName);
            } else if (modalId === 'confirm' && fileId) {
                $('#confirmModal').data('file-id', fileId);
            }
        }

        function closeModal(modalId) {
            $(`#${modalId}Modal`).removeClass('open');
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
                        $('#notificationList').find(`[data-transaction-id="${response.transaction_id}"]`).remove();
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
                                    <div class="file-card" data-file-id="${file.file_id}" data-file-name="${file.file_name}" data-document-type="${file.document_type}">
                                        <div class="file-status ${file.file_status.toLowerCase()}">${file.file_status}</div>
                                        <div class="file-icon-container">
                                            <i class="${getFileIcon(file.file_name)}"></i>
                                        </div>
                                        <p class="file-name">${file.file_name}</p>
                                        <p class="file-type-badge">${file.document_type}</p>
                                        <p>${new Date(file.upload_date).toLocaleDateString('en-US')}</p>
                                        <div class="file-actions">
                                            <button class="info-btn" title="Info" onclick="showFileInfo(${file.file_id})"><i class="fas fa-info-circle"></i></button>
                                            <button class="rename-btn" title="Rename" onclick="openModal('rename', ${file.file_id}, '${file.file_name}')"><i class="fas fa-edit"></i></button>
                                            <button class="delete-btn" title="Delete" onclick="openModal('confirm', ${file.file_id})"><i class="fas fa-trash"></i></button>
                                        </div>
                                    </div>
                                `);
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

        function sortFiles(criteria) {
            const grid = $('#fileGrid, #departmentFileGrid');
            const cards = grid.find('.file-card').get();
            cards.sort((a, b) => {
                const aVal = $(a).data(criteria === 'name' ? 'file-name' : criteria === 'type' ? 'document-type' : 'upload-date');
                const bVal = $(b).data(criteria === 'name' ? 'file-name' : criteria === 'type' ? 'document-type' : 'upload-date');
                if (criteria === 'date') {
                    return new Date(bVal) - new Date(aVal);
                }
                return aVal.localeCompare(bVal);
            });
            grid.empty().append(cards);
        }

        function loadMoreFiles() {
            state.currentPage++;
            $.ajax({
                url: 'get_user_files.php',
                method: 'POST',
                data: {
                    page: state.currentPage,
                    csrf_token: $('meta[name="csrf-token"]').attr('content')
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success && response.data.length > 0) {
                        response.data.forEach(file => {
                            const card = $(`
                                <div class="file-card" data-file-id="${file.file_id}" data-file-name="${file.file_name}" data-document-type="${file.document_type}">
                                    <div class="file-status ${file.file_status.toLowerCase()}">${file.file_status}</div>
                                    <div class="file-icon-container">
                                        <i class="${getFileIcon(file.file_name)}"></i>
                                    </div>
                                    <p class="file-name">${file.file_name}</p>
                                    <p class="file-type-badge">${file.document_type}</p>
                                    <p>${new Date(file.upload_date).toLocaleDateString('en-US')}</p>
                                    <div class="file-actions">
                                        <button class="info-btn" title="Info" onclick="showFileInfo(${file.file_id})"><i class="fas fa-info-circle"></i></button>
                                        <button class="rename-btn" title="Rename" onclick="openModal('rename', ${file.file_id}, '${file.file_name}')"><i class="fas fa-edit"></i></button>
                                        <button class="delete-btn" title="Delete" onclick="openModal('confirm', ${file.file_id})"><i class="fas fa-trash"></i></button>
                                    </div>
                                </div>
                            `);
                            $('#fileGrid').append(card);
                        });
                    } else {
                        $('.view-more').hide();
                        notyf.info('No more files to load.');
                    }
                },
                error: function() {
                    notyf.error('Error loading more files.');
                }
            });
        }
    </script>
</body>

</html>