<?php
session_start();
require_once 'db_connection.php';
require_once 'log_activity.php';
require_once 'notification.php';
require_once 'vendor/autoload.php'; // Load Composer autoloader for phpdotenv

use Dotenv\Dotenv;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Disable error display in production
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('error_log', __DIR__ . '/logs/error_log.log');
error_reporting(E_ALL);

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
 * Validates user session.
 *
 * @return array|null User ID and role, or null if invalid
 */
function validateSession(): ?array
{
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        error_log("Unauthorized access attempt in department_folder.php: Session invalid.");
        return null;
    }
    session_regenerate_id(true); // Regenerate session ID for security
    return ['user_id' => (int)$_SESSION['user_id'], 'role' => $_SESSION['role']];
}

/**
 * Gets the appropriate Font Awesome icon class for a file based on its extension.
 *
 * @param string $fileName
 * @return string Icon class
 */
function getFileIcon(string $fileName): string
{
    $iconClasses = [
        'pdf' => 'fas fa-file-pdf',
        'doc' => 'fas fa-file-word',
        'docx' => 'fas fa-file-word',
        'xls' => 'fas fa-file-excel',
        'xlsx' => 'fas fa-file-excel',
        'ppt' => 'fas fa-file-powerpoint',
        'pptx' => 'fas fa-file-powerpoint',
        'txt' => 'fas fa-file-alt',
        'jpg' => 'fas fa-file-image',
        'jpeg' => 'fas fa-file-image',
        'png' => 'fas fa-file-image',
        'default' => 'fas fa-file'
    ];
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    return $iconClasses[$extension] ?? $iconClasses['default'];
}

/**
 * Fetches user departments.
 *
 * @param PDO $pdo
 * @param int $userId
 * @return array
 */
function fetchUserDepartments(PDO $pdo, int $userId): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT d.department_id AS id, d.department_name AS name
            FROM departments d
            JOIN users_department ud ON d.department_id = ud.department_id
            WHERE ud.user_id = ?
            ORDER BY d.department_name
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error fetching departments in department_folder.php: " . $e->getMessage());
        return [];
    }
}

/**
 * Fetches document types from documents_type_fields.
 *
 * @param PDO $pdo
 * @return array
 */
function fetchDocumentTypes(PDO $pdo): array
{
    try {
        $stmt = $pdo->prepare("SELECT DISTINCT field_name AS name FROM documents_type_fields ORDER BY field_name");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error fetching document types in department_folder.php: " . $e->getMessage());
        return [];
    }
}

/**
 * Fetches department files accessible to the user.
 *
 * @param PDO $pdo
 * @param int $userId
 * @param int $departmentId
 * @return array
 */
function fetchDepartmentFiles(PDO $pdo, int $userId, int $departmentId): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT f.file_id AS id, f.file_name, f.user_id, f.upload_date,
                   f.file_size, f.file_type, f.file_path, f.meta_data,
                   dtf.field_name AS document_type, u.username AS uploader_name
            FROM files f
            LEFT JOIN documents_type_fields dtf ON f.document_type_id = dtf.document_type_id
            LEFT JOIN users u ON f.user_id = u.user_id
            JOIN users_department ud ON ud.user_id = f.user_id AND ud.department_id = ?
            WHERE f.file_status != 'deleted' AND ud.user_id IN (
                SELECT user_id FROM users_department WHERE department_id = ?
            )
            ORDER BY f.upload_date DESC
        ");
        $stmt->execute([$departmentId, $departmentId]);
        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($files as &$file) {
            $metaData = json_decode($file['meta_data'] ?? '{}', true);
            $file['hard_copy_available'] = $metaData['hard_copy_available'] ?? 0;
            $file['cabinet_name'] = $metaData['cabinet_name'] ?? 'N/A';
            $file['layer'] = $metaData['layer'] ?? 'N/A';
            $file['box'] = $metaData['box'] ?? 'N/A';
            $file['folder'] = $metaData['folder'] ?? 'N/A';
            $file['pages'] = $metaData['pages'] ?? 'N/A';
            $file['purpose'] = $metaData['purpose'] ?? 'Not specified';
            $file['subject'] = $metaData['subject'] ?? 'Not specified';
        }
        unset($file);
        return $files;
    } catch (Exception $e) {
        error_log("Error fetching department files in department_folder.php: " . $e->getMessage());
        return [];
    }
}

/**
 * Validates department access for a user.
 *
 * @param PDO $pdo
 * @param int $userId
 * @param int $departmentId
 * @return bool
 */
function validateDepartmentAccess(PDO $pdo, int $userId, int $departmentId): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM users_department
            WHERE user_id = ? AND department_id = ?
        ");
        $stmt->execute([$userId, $departmentId]);
        return $stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        error_log("Error validating department access in department_folder.php: " . $e->getMessage());
        return false;
    }
}

// Initialize variables
$errorMessage = '';
$userId = null;
$userRole = 'user';
$user = ['username' => 'Unknown'];
$userDepartments = [];
$documentTypes = [];
$files = [];
$filteredFiles = [];
$departmentName = 'Unknown Department';
$departmentId = null;
$csrfToken = bin2hex(random_bytes(32));

// Handle POST requests for file actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        if (!isset($pdo) || !$pdo instanceof PDO) {
            throw new Exception('Database connection not available.', 500);
        }

        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception('Invalid CSRF token.', 403);
        }

        $session = validateSession();
        if ($session === null) {
            throw new Exception('Unauthorized access: Please log in.', 401);
        }
        $userId = $session['user_id'];

        $action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_STRING);
        $fileId = filter_input(INPUT_POST, 'file_id', FILTER_VALIDATE_INT);
        if (!$fileId) {
            throw new Exception('Invalid file ID.', 400);
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT file_name, user_id, meta_data FROM files WHERE file_id = ? AND file_status != 'deleted'");
        $stmt->execute([$fileId]);
        $file = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$file) {
            throw new Exception('File not found.', 404);
        }

        $logMessage = '';
        switch ($action) {
            case 'rename':
                $newName = trim(filter_input(INPUT_POST, 'file_name', FILTER_SANITIZE_SPECIAL_CHARS));
                if (empty($newName) || strlen($newName) > 255) {
                    throw new Exception('File name is required and must be 255 characters or less.', 400);
                }
                $stmt = $pdo->prepare("UPDATE files SET file_name = ? WHERE file_id = ?");
                $stmt->execute([$newName, $fileId]);
                $logMessage = "Renamed file ID $fileId to $newName";
                break;

            case 'delete':
                $stmt = $pdo->prepare("UPDATE files SET file_status = 'deleted' WHERE file_id = ?");
                $stmt->execute([$fileId]);
                $logMessage = "Deleted file ID $fileId";
                break;

            case 'make_copy':
                $metaData = json_decode($file['meta_data'] ?? '{}', true);
                $stmt = $pdo->prepare("
                    INSERT INTO files (parent_file_id, file_name, meta_data, user_id, upload_date, file_size, file_type, document_type_id, file_status, copy_type, file_path, type_id)
                    SELECT file_id, CONCAT(file_name, '_copy'), meta_data, user_id, NOW(), file_size, file_type, document_type_id, file_status, 'copy', file_path, type_id
                    FROM files WHERE file_id = ?
                ");
                $stmt->execute([$fileId]);
                $newFileId = $pdo->lastInsertId();
                $logMessage = "Created copy of file ID $fileId as file ID $newFileId";
                break;

            default:
                throw new Exception('Invalid action.', 400);
        }

        $stmt = $pdo->prepare("
            INSERT INTO transactions (user_id, file_id, transaction_status, transaction_type, time, message)
            VALUES (?, ?, 'completed', ?, NOW(), ?)
        ");
        $stmt->execute([$userId, $fileId, $action, $logMessage]);
        $pdo->commit();

        sendJsonResponse(true, 'Action completed successfully.', [], 200);
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error in POST action in department_folder.php: " . $e->getMessage());
        sendJsonResponse(false, 'Server error: ' . $e->getMessage(), [], $e->getCode() ?: 500);
    }
}

// Main page logic
if (!isset($pdo) || !$pdo instanceof PDO) {
    $errorMessage = 'Database connection not available. Please try again later.';
    error_log("Database connection not available in department_folder.php.");
} else {
    // Validate session
    $session = validateSession();
    if ($session === null) {
        header('Location: login.php');
        exit;
    }
    $userId = $session['user_id'];
    $userRole = $session['role'];

    // Handle CSRF token
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = $csrfToken;
    } else {
        $csrfToken = $_SESSION['csrf_token'];
    }

    // Validate department ID
    $departmentId = filter_input(INPUT_GET, 'department_id', FILTER_VALIDATE_INT);
    if (!$departmentId) {
        $errorMessage = 'Department ID is required.';
        error_log("Department ID missing in department_folder.php.");
    } else {
        // Validate user
        try {
            $stmt = $pdo->prepare("SELECT username FROM users WHERE user_id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['username' => 'Unknown'];
        } catch (Exception $e) {
            error_log("Error validating user in department_folder.php: " . $e->getMessage());
            $user = ['username' => 'Unknown'];
        }

        // Validate department
        try {
            $stmt = $pdo->prepare("SELECT department_name AS name FROM departments WHERE department_id = ?");
            $stmt->execute([$departmentId]);
            $department = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$department) {
                $errorMessage = 'Department not found.';
                error_log("Department ID $departmentId not found in department_folder.php.");
            } else {
                $departmentName = $department['name'];
            }
        } catch (Exception $e) {
            $errorMessage = 'Error fetching department information.';
            error_log("Error fetching department in department_folder.php: " . $e->getMessage());
        }

        // Validate department access
        if (!$errorMessage && !validateDepartmentAccess($pdo, $userId, $departmentId)) {
            $errorMessage = 'You do not have access to this department.';
            error_log("User ID $userId denied access to department ID $departmentId in department_folder.php.");
        }

        // Fetch data
        if (!$errorMessage) {
            $userDepartments = fetchUserDepartments($pdo, $userId);
            $documentTypes = fetchDocumentTypes($pdo);
            $files = fetchDepartmentFiles($pdo, $userId, $departmentId);

            // Apply filters
            $sortFilter = filter_input(INPUT_GET, 'sort', FILTER_SANITIZE_STRING, ['options' => ['default' => 'all']]);
            $validSorts = ['all', 'uploaded-by-me', 'received', 'hardcopy', 'softcopy'];
            if (!in_array($sortFilter, $validSorts)) {
                $sortFilter = 'all';
            }

            $filteredFiles = array_filter($files, function ($file) use ($userId, $sortFilter) {
                $isUploadedByMe = $file['user_id'] == $userId;
                $isReceived = !$isUploadedByMe;
                $isHardcopyOnly = ($file['hard_copy_available'] ?? 0) == 1 && empty($file['file_path']);
                $isSoftcopyOnly = ($file['hard_copy_available'] ?? 0) == 0 && !empty($file['file_path']);
                return match ($sortFilter) {
                    'uploaded-by-me' => $isUploadedByMe,
                    'received' => $isReceived,
                    'hardcopy' => $isHardcopyOnly,
                    'softcopy' => $isSoftcopyOnly,
                    default => true
                };
            });
        }

        // Log page access
        error_log(sprintf(
            "[%s] User %d (%s) accessed Department Folder page for department ID %d",
            date('Y-m-d H:i:s'),
            $userId,
            $user['username'] ?? 'Unknown',
            $departmentId
        ));
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<>   
    <title><?= htmlspecialchars($departmentName, ENT_QUOTES, 'UTF-8') ?> - Document Archival</title>

    <?php
    include 'user_head.php'; // Include user-specific styles and scripts
    ?>
    
</head>

<body>
    <?php if ($errorMessage): ?>
        <div class="error-message" style="color: red; padding: 20px; text-align: center;">
            <p><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
    <?php else: ?>
        <div class="top-nav">
            <button class="toggle-btn" title="Toggle Sidebar" aria-label="Toggle Sidebar"><i class="fas fa-bars"></i></button>
            <h2><?= htmlspecialchars($departmentName, ENT_QUOTES, 'UTF-8') ?></h2>
            <div class="search-container">
                <i class="fas fa-search search-icon"></i>
                <input type="text" placeholder="Search documents..." class="search-bar" id="searchBar" aria-label="Search documents">
            </div>
            <button id="hardcopyStorageButton" aria-label="Recommend Storage"><i class="fas fa-archive"></i> Recommend Storage</button>
        </div>

<!--         <div class="sidebar">
            <h2 class="sidebar-title">Document Archival</h2>
            <?php if ($userRole === 'admin'): ?>
                <a href="admin_dashboard.php" class="<?= basename($_SERVER['PHP_SELF']) == 'admin_dashboard.php' ? 'active' : '' ?>" data-tooltip="Admin Dashboard">
                    <i class="fas fa-user-shield"></i><span class="link-text">Admin Dashboard</span>
                </a>
            <?php endif; ?>
            <a href="dashboard.php" class="<?= basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : '' ?>" data-tooltip="Dashboard">
                <i class="fas fa-home"></i><span class="link-text">Dashboard</span>
            </a>
            <a href="my-report.php" class="<?= basename($_SERVER['PHP_SELF']) == 'my-report.php' ? 'active' : '' ?>" data-tooltip="My Report">
                <i class="fas fa-chart-bar"></i><span class="link-text">My Report</span>
            </a>
            <a href="my-folder.php" class="<?= basename($_SERVER['PHP_SELF']) == 'my-folder.php' ? 'active' : '' ?>" data-tooltip="My Folder">
                <i class="fas fa-folder"></i><span class="link-text">My Folder</span>
            </a>
            <?php foreach ($userDepartments as $dept): ?>
                <a href="department_folder.php?department_id=<?= htmlspecialchars($dept['id'], ENT_QUOTES, 'UTF-8') ?>"
                    class="<?= $dept['id'] == $departmentId ? 'active' : '' ?>"
                    data-tooltip="<?= htmlspecialchars($dept['name'], ENT_QUOTES, 'UTF-8') ?>">
                    <i class="fas fa-folder"></i><span class="link-text"><?= htmlspecialchars($dept['name'], ENT_QUOTES, 'UTF-8') ?></span>
                </a>
            <?php endforeach; ?>
            <a href="logout.php" class="logout-btn" data-tooltip="Logout">
                <i class="fas fa-sign-out-alt"></i><span class="link-text">Logout</span>
            </a>
        </div>
 -->

    <?php
    include 'user_menu.php'; // Include the sidebar menu
    ?>

        <?php foreach ($userDepartments as $dept): ?>
                <a href="department_folder.php?department_id=<?= htmlspecialchars($dept['id'], ENT_QUOTES, 'UTF-8') ?>"
                    class="<?= $dept['id'] == $departmentId ? 'active' : '' ?>"
                    data-tooltip="<?= htmlspecialchars($dept['name'], ENT_QUOTES, 'UTF-8') ?>">
                    <i class="fas fa-folder"></i><span class="link-text"><?= htmlspecialchars($dept['name'], ENT_QUOTES, 'UTF-8') ?></span>
                </a>
        <?php endforeach; ?>
        
        <a href="logout.php" class="logout-btn" data-tooltip="Logout" aria-label="Logout">
            <i class="fas fa-sign-out-alt"></i><span class="link-text">Logout</span>
        </a>
    </aside>

        <main class="main-content">
            <div class="sorting-buttons">
                <button class="sort-btn <?= $sortFilter === 'all' ? 'active' : '' ?>" data-filter="all" aria-label="Show all files">All Files</button>
                <button class="sort-btn <?= $sortFilter === 'uploaded-by-me' ? 'active' : '' ?>" data-filter="uploaded-by-me" aria-label="Show files uploaded by me">Uploaded by Me</button>
                <button class="sort-btn <?= $sortFilter === 'received' ? 'active' : '' ?>" data-filter="received" aria-label="Show received files">Files Received</button>
                <button class="sort-btn <?= $sortFilter === 'hardcopy' ? 'active' : '' ?>" data-filter="hardcopy" aria-label="Show hardcopy files">Hardcopy</button>
                <button class="sort-btn <?= $sortFilter === 'softcopy' ? 'active' : '' ?>" data-filter="softcopy" aria-label="Show softcopy files">Softcopy</button>
            </div>

            <div class="ftypes">
                <?php foreach ($documentTypes as $type):
                    $fileCount = count(array_filter($filteredFiles, fn($file) => $file['document_type'] === $type['name']));
                ?>
                    <div class="ftype-card"
                        data-type="<?= htmlspecialchars(strtolower(str_replace(' ', '-', $type['name'])), ENT_QUOTES, 'UTF-8') ?>"
                        role="button"
                        tabindex="0"
                        aria-label="View <?= htmlspecialchars(ucfirst($type['name']), ENT_QUOTES, 'UTF-8') ?> files (<?= $fileCount ?>)">
                        <p><?= htmlspecialchars(ucfirst($type['name']), ENT_QUOTES, 'UTF-8') ?> <span class="file-count">(<?= $fileCount ?>)</span></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="masonry-grid">
                <section class="masonry-section">
                    <h3>Department Files</h3>
                    <div class="file-card-container" id="departmentFiles">
                        <?php foreach (array_slice($filteredFiles, 0, 4) as $file): ?>
                            <div class="file-card"
                                data-file-id="<?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>"
                                data-file-name="<?= htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8') ?>"
                                role="button"
                                tabindex="0"
                                aria-label="View details for <?= htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8') ?>">
                                <div class="file-icon-container"><i class="<?= getFileIcon($file['file_name']) ?> file-icon"></i></div>
                                <p class="file-name"><?= htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8') ?></p>
                                <span class="file-type-badge"><?= htmlspecialchars($file['document_type'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></span>
                                <div class="file-options"
                                    role="button"
                                    tabindex="0"
                                    aria-label="File options for <?= htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8') ?>">
                                    <i class="fas fa-ellipsis-v" aria-hidden="true"></i>
                                    <div class="options-menu">
                                        <div data-action="Rename" data-file-id="<?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>">Rename</div>
                                        <div data-action="Delete" data-file-id="<?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>">Delete</div>
                                        <div data-action="Make Copy" data-file-id="<?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>">Make Copy</div>
                                        <div data-action="File Information" data-file-id="<?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>">File Information</div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (count($filteredFiles) > 4): ?>
                        <div class="view-more">
                            <button onclick="openModal('department')" aria-label="View more department files">View More</button>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        </main>

        <div class="popup-questionnaire" id="hardcopyStoragePopup" role="dialog" aria-labelledby="hardcopyStorageTitle">
            <form id="fileDetailsForm" class="modal-content">
                <button type="button" class="close-modal" onclick="closePopup('hardcopyStoragePopup')" aria-label="Close storage popup">×</button>
                <h2 id="hardcopyStorageTitle">File Details</h2>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <label for="documentType">Document Type:</label>
                <select id="documentType" name="document_type" required aria-required="true">
                    <option value="">Select Document Type</option>
                    <?php foreach ($documentTypes as $type): ?>
                        <option value="<?= htmlspecialchars($type['name'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars(ucfirst($type['name']), ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div id="dynamicFields">
                    <label for="fileName">File Name:</label>
                    <input type="text" id="fileName" name="file_name" required maxlength="255" aria-required="true">
                </div>
                <button type="submit" class="submit-button" aria-label="Get storage suggestion">Get Storage Suggestion</button>
                <div id="storageSuggestion" role="alert"></div>
            </form>
        </div>

        <aside class="file-info-sidebar" role="complementary" aria-label="File information">
            <div class="file-name-container">
                <h2 class="file-name-title" id="sidebarFileName">File Name</h2>
                <button class="close-sidebar-btn" onclick="closeSidebar()" aria-label="Close sidebar">×</button>
            </div>
            <div class="file-preview" id="filePreview" aria-live="polite"></div>
            <div class="file-info-header">
                <div class="file-info-location active" onclick="showSection('locationSection')" role="tab" aria-selected="true" tabindex="0">
                    <h4>Location</h4>
                </div>
                <div class="file-info-details" onclick="showSection('detailsSection')" role="tab" aria-selected="false" tabindex="0">
                    <h4>Details</h4>
                </div>
            </div>
            <div class="info-section active" id="locationSection" role="tabpanel">
                <div class="info-item"><span class="info-label">Department:</span><span class="info-value" id="departmentCollege"><?= htmlspecialchars($departmentName, ENT_QUOTES, 'UTF-8') ?></span></div>
                <div class="info-item"><span class="info-label">Physical Location:</span><span class="info-value" id="physicalLocation">Not assigned</span></div>
                <div class="info-item"><span class="info-label">Cabinet:</span><span class="info-value" id="cabinet">N/A</span></div>
                <div class="info-item"><span class="info-label">Layer/Box/Folder:</span><span class="info-value" id="storageDetails">N/A</span></div>
            </div>
            <div class="info-section" id="detailsSection" role="tabpanel">
                <div class="access-log">
                    <h3>Who Has Access</h3>
                    <div class="access-users" id="accessUsers" aria-live="polite"></div>
                    <p class="access-info" id="accessInfo"></p>
                </div>
                <div class="file-details">
                    <h3>File Details</h3>
                    <div class="info-item"><span class="info-label">Uploader:</span><span class="info-value" id="uploader">N/A</span></div>
                    <div class="info-item"><span class="info-label">File Type:</span><span class="info-value" id="fileType">N/A</span></div>
                    <div class="info-item"><span class="info-label">File Size:</span><span class="info-value" id="fileSize">N/A</span></div>
                    <div class="info-item"><span class="info-label">Category:</span><span class="info-value" id="fileCategory">N/A</span></div>
                    <div class="info-item"><span class="info-label">Date Uploaded:</span><span class="info-value" id="dateUpload">N/A</span></div>
                    <div class="info-item"><span class="info-label">Pages:</span><span class="info-value" id="pages">N/A</span></div>
                    <div class="info-item"><span class="info-label">Purpose:</span><span class="info-value" id="purpose">N/A</span></div>
                    <div class="info-item"><span class="info-label">Subject:</span><span class="info-value" id="subject">N/A</span></div>
                </div>
            </div>
        </aside>

        <div class="full-preview-modal" id="fullPreviewModal" role="dialog" aria-labelledby="fullPreviewTitle">
            <div class="full-preview-content">
                <button class="close-full-preview" onclick="closeFullPreview()" aria-label="Close full preview">✕</button>
                <div id="fullPreviewContent" aria-live="polite"></div>
            </div>
        </div>

        <?php foreach ($documentTypes as $type): ?>
            <div id="<?= htmlspecialchars(strtolower(str_replace(' ', '-', $type['name'])), ENT_QUOTES, 'UTF-8') ?>Modal"
                class="modal"
                role="dialog"
                aria-labelledby="<?= htmlspecialchars(strtolower(str_replace(' ', '-', $type['name'])), ENT_QUOTES, 'UTF-8') ?>ModalTitle">
                <div class="modal-content">
                    <button class="close-modal"
                        onclick="closeModal('<?= htmlspecialchars(strtolower(str_replace(' ', '-', $type['name'])), ENT_QUOTES, 'UTF-8') ?>')"
                        aria-label="Close <?= htmlspecialchars(ucfirst($type['name']), ENT_QUOTES, 'UTF-8') ?> modal">✕</button>
                    <h2 id="<?= htmlspecialchars(strtolower(str_replace(' ', '-', $type['name'])), ENT_QUOTES, 'UTF-8') ?>ModalTitle">
                        <?= htmlspecialchars(ucfirst($type['name']), ENT_QUOTES, 'UTF-8') ?> Files
                    </h2>
                    <div class="modal-grid" id="<?= htmlspecialchars(strtolower(str_replace(' ', '-', $type['name'])), ENT_QUOTES, 'UTF-8') ?>Grid">
                        <?php foreach (array_filter($filteredFiles, fn($file) => $file['document_type'] === $type['name']) as $file): ?>
                            <div class="file-card"
                                data-file-id="<?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>"
                                data-file-name="<?= htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8') ?>"
                                role="button"
                                tabindex="0"
                                aria-label="View details for <?= htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8') ?>">
                                <div class="file-icon-container"><i class="<?= getFileIcon($file['file_name']) ?> file-icon"></i></div>
                                <p class="file-name"><?= htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8') ?></p>
                                <span class="file-type-badge"><?= htmlspecialchars($file['document_type'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></span>
                                <div class="file-options"
                                    role="button"
                                    tabindex="0"
                                    aria-label="File options for <?= htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8') ?>">
                                    <i class="fas fa-ellipsis-v" aria-hidden="true"></i>
                                    <div class="options-menu">
                                        <div data-action="Rename" data-file-id="<?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>">Rename</div>
                                        <div data-action="Delete" data-file-id="<?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>">Delete</div>
                                        <div data-action="Make Copy" data-file-id="<?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>">Make Copy</div>
                                        <div data-action="File Information" data-file-id="<?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>">File Information</div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <div id="departmentModal" class="modal" role="dialog" aria-labelledby="departmentModalTitle">
            <div class="modal-content">
                <button class="close-modal" onclick="closeModal('department')" aria-label="Close department modal">✕</button>
                <h2 id="departmentModalTitle">All Department Files</h2>
                <div class="modal-grid" id="departmentGrid">
                    <?php foreach ($filteredFiles as $file): ?>
                        <div class="file-card"
                            data-file-id="<?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>"
                            data-file-name="<?= htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8') ?>"
                            role="button"
                            tabindex="0"
                            aria-label="View details for <?= htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8') ?>">
                            <div class="file-icon-container"><i class="<?= getFileIcon($file['file_name']) ?> file-icon"></i></div>
                            <p class="file-name"><?= htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8') ?></p>
                            <span class="file-type-badge"><?= htmlspecialchars($file['document_type'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></span>
                            <div class="file-options"
                                role="button"
                                tabindex="0"
                                aria-label="File options for <?= htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8') ?>">
                                <i class="fas fa-ellipsis-v" aria-hidden="true"></i>
                                <div class="options-menu">
                                    <div data-action="Rename" data-file-id="<?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>">Rename</div>
                                    <div data-action="Delete" data-file-id="<?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>">Delete</div>
                                    <div data-action="Make Copy" data-file-id="<?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>">Make Copy</div>
                                    <div data-action="File Information" data-file-id="<?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>">File Information</div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div id="renameModal" class="modal" role="dialog" aria-labelledby="renameModalTitle">
            <div class="modal-content">
                <button class="close-modal" onclick="closeModal('rename')" aria-label="Close rename modal">✕</button>
                <h2 id="renameModalTitle">Rename File</h2>
                <form id="renameForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="rename">
                    <input type="hidden" name="file_id" id="renameFileId">
                    <label for="renameFileName">New File Name:</label>
                    <input type="text" id="renameFileName" name="file_name" required maxlength="255" aria-required="true">
                    <button type="submit" aria-label="Rename file">Rename</button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <script>
        // Initialize DOM elements
        const elements = {
            searchBar: document.getElementById('searchBar'),
            hardcopyStorageButton: document.getElementById('hardcopyStorageButton'),
            fileDetailsForm: document.getElementById('fileDetailsForm'),
            documentTypeSelect: document.getElementById('documentType'),
            dynamicFields: document.getElementById('dynamicFields'),
            storageSuggestion: document.getElementById('storageSuggestion'),
            renameForm: document.getElementById('renameForm'),
            renameModal: document.getElementById('renameModal'),
            departmentFiles: document.getElementById('departmentFiles'),
            sidebar: document.querySelector('.file-info-sidebar'),
            fullPreviewModal: document.getElementById('fullPreviewModal')
        };

        // Initialize sidebar toggle
        function initializeSidebarToggle() {
            const toggleBtn = document.querySelector('.toggle-btn');
            if (toggleBtn) {
                toggleBtn.addEventListener('click', () => {
                    const sidebar = document.querySelector('.sidebar');
                    const topNav = document.querySelector('.top-nav');
                    if (sidebar && topNav) {
                        sidebar.classList.toggle('minimized');
                        topNav.classList.toggle('resized', sidebar.classList.contains('minimized'));
                    }
                });
            }
        }

        // Toggle file options menu
        function toggleOptions(event, element) {
            document.querySelectorAll('.options-menu').forEach(menu => {
                if (menu !== element.querySelector('.options-menu')) menu.classList.remove('show');
            });
            element.querySelector('.options-menu').classList.toggle('show');
            event.stopPropagation();
        }

        document.addEventListener('click', (event) => {
            if (!event.target.closest('.file-options')) {
                document.querySelectorAll('.options-menu').forEach(menu => menu.classList.remove('show'));
            }
        });

        // Handle file options
        function handleOption(option, fileId) {
            if (!fileId || isNaN(fileId)) {
                showAlert('Invalid file ID', 'error');
                return;
            }
            switch (option) {
                case 'Rename':
                    openRenameModal(fileId);
                    break;
                case 'Delete':
                    if (confirm('Are you sure you want to delete this file?')) {
                        performFileAction('delete', fileId);
                    }
                    break;
                case 'Make Copy':
                    performFileAction('make_copy', fileId);
                    break;
                case 'File Information':
                    openSidebar(fileId);
                    break;
                default:
                    showAlert(`Unknown option: ${option}`, 'error');
            }
        }

        // Open rename modal
        function openRenameModal(fileId) {
            if (elements.renameModal) {
                document.getElementById('renameFileId').value = fileId;
                elements.renameModal.style.display = 'flex';
                elements.renameModal.classList.add('open');
                const modalContent = elements.renameModal.querySelector('.modal-content');
                if (modalContent) {
                    setTimeout(() => modalContent.classList.add('open'), 10);
                }
                document.getElementById('renameFileName').focus();
            }
        }

        // Perform file action via AJAX
        function performFileAction(action, fileId) {
            const formData = new FormData();
            formData.append('action', action);
            formData.append('file_id', fileId);
            formData.append('csrf_token', '<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>');

            fetch('department_folder.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert(data.message, 'success', () => window.location.reload());
                } else {
                    showAlert(`Error: ${data.message || 'Unknown error'}`, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('An error occurred while processing the request.', 'error');
            });
        }

        // Open file info sidebar
        function openSidebar(fileId) {
            fetch(`get_file_info.php?file_id=${fileId}`, {
                method: 'GET',
                credentials: 'same-origin'
            })
            .then(response => {
                if (!response.ok) {
                    return response.json().then(err => { throw new Error(err.message || 'Unknown error'); });
                }
                return response.json();
            })
            .then(data => {
                if (!data.success) {
                    showAlert(`Error: ${data.message}`, 'error');
                    return;
                }

                const sidebarElements = {
                    sidebarFileName: document.getElementById('sidebarFileName'),
                    departmentCollege: document.getElementById('departmentCollege'),
                    physicalLocation: document.getElementById('physicalLocation'),
                    cabinet: document.getElementById('cabinet'),
                    storageDetails: document.getElementById('storageDetails'),
                    uploader: document.getElementById('uploader'),
                    fileType: document.getElementById('fileType'),
                    fileSize: document.getElementById('fileSize'),
                    fileCategory: document.getElementById('fileCategory'),
                    dateUpload: document.getElementById('dateUpload'),
                    pages: document.getElementById('pages'),
                    purpose: document.getElementById('purpose'),
                    subject: document.getElementById('subject'),
                    filePreview: document.getElementById('filePreview')
                };

                for (const [key, element] of Object.entries(sidebarElements)) {
                    if (!element) {
                        console.error(`Element with ID '${key}' not found`);
                        showAlert(`UI error: Element '${key}' missing`, 'error');
                        return;
                    }
                }

                sidebarElements.sidebarFileName.textContent = data.data.file_name || 'Unnamed File';
                sidebarElements.departmentCollege.textContent = data.data.department_name || 'N/A';
                sidebarElements.physicalLocation.textContent = data.data.hard_copy_available ? (data.data.cabinet_name !== 'N/A' ? 'Assigned' : 'Not assigned') : 'Digital only';
                sidebarElements.cabinet.textContent = data.data.cabinet_name || 'N/A';
                sidebarElements.storageDetails.textContent = data.data.layer !== 'N/A' && data.data.box !== 'N/A' && data.data.folder !== 'N/A' ? `${data.data.layer}/${data.data.box}/${data.data.folder}` : 'N/A';
                sidebarElements.uploader.textContent = data.data.uploader_name || 'N/A';
                sidebarElements.fileType.textContent = data.data.file_type || 'N/A';
                sidebarElements.fileSize.textContent = data.data.file_size ? formatFileSize(data.data.file_size) : 'N/A';
                sidebarElements.fileCategory.textContent = data.data.document_type || 'N/A';
                sidebarElements.dateUpload.textContent = data.data.upload_date || 'N/A';
                sidebarElements.pages.textContent = data.data.pages || 'N/A';
                sidebarElements.purpose.textContent = data.data.purpose || 'Not specified';
                sidebarElements.subject.textContent = data.data.subject || 'Not specified';

                sidebarElements.filePreview.innerHTML = '';
                if (data.data.file_path) {
                    const ext = data.data.file_type.toLowerCase();
                    if (ext === 'pdf') {
                        sidebarElements.filePreview.innerHTML = `<iframe src="${data.data.file_path}" title="File Preview" aria-label="File preview"></iframe><p>Click to view full file${data.data.hard_copy_available ? ' (Hardcopy available)' : ''}</p>`;
                        sidebarElements.filePreview.querySelector('iframe').addEventListener('click', () => openFullPreview(data.data.file_path));
                    } else if (['jpg', 'png', 'jpeg', 'gif'].includes(ext)) {
                        sidebarElements.filePreview.innerHTML = `<img src="${data.data.file_path}" alt="File Preview" aria-label="Image preview"><p>Click to view full image${data.data.hard_copy_available ? ' (Hardcopy available)' : ''}</p>`;
                        sidebarElements.filePreview.querySelector('img').addEventListener('click', () => openFullPreview(data.data.file_path));
                    } else {
                        sidebarElements.filePreview.innerHTML = '<p>Preview not available for this file type</p>';
                    }
                } else if (data.data.hard_copy_available) {
                    sidebarElements.filePreview.innerHTML = '<p>Hardcopy - No digital preview available</p>';
                } else {
                    sidebarElements.filePreview.innerHTML = '<p>No preview available (missing file data)</p>';
                }

                // Dynamically update file details section
                const detailsSection = document.querySelector('.file-details');
                detailsSection.innerHTML = '<h3>File Details</h3>';
                const baseFields = [
                    { label: 'Uploader', value: data.data.uploader_name || 'N/A' },
                    { label: 'File Type', value: data.data.file_type || 'N/A' },
                    { label: 'File Size', value: data.data.file_size ? formatFileSize(data.data.file_size) : 'N/A' },
                    { label: 'Category', value: data.data.document_type || 'N/A' },
                    { label: 'Date Uploaded', value: data.data.upload_date || 'N/A' },
                    { label: 'Pages', value: data.data.pages || 'N/A' },
                    { label: 'Purpose', value: data.data.purpose || 'Not specified' },
                    { label: 'Subject', value: data.data.subject || 'Not specified' }
                ];
                baseFields.forEach(field => {
                    detailsSection.innerHTML += `
                        <div class="info-item">
                            <span class="info-label">${field.label}:</span>
                            <span class="info-value">${field.value}</span>
                        </div>`;
                });

                if (data.data.document_type) {
                    $.ajax({
                        url: 'get_document_type.php',
                        method: 'GET',
                        data: { document_type_name: data.data.document_type },
                        dataType: 'json',
                        success: function(fieldsData) {
                            if (fieldsData.success && fieldsData.data && fieldsData.data.fields) {
                                fieldsData.data.fields.forEach(field => {
                                    const value = data.data[field.field_name] || 'N/A';
                                    detailsSection.innerHTML += `
                                        <div class="info-item">
                                            <span class="info-label">${field.field_label}:</span>
                                            <span class="info-value">${value}</span>
                                        </div>`;
                                });
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('Error fetching document type fields:', error);
                        }
                    });
                }

                fetchAccessInfo(fileId);
                elements.sidebar.classList.add('active');
            })
            .catch(error => {
                console.error('Fetch error:', error.message);
                showAlert(`Failed to load file information: ${error.message}`, 'error');
            });
        }

        // Fetch access information
        function fetchAccessInfo(fileId) {
            fetch(`get_access_info.php?file_id=${fileId}`, {
                method: 'GET',
                credentials: 'same-origin'
            })
            .then(response => response.json())
            .then(data => {
                const accessUsers = document.getElementById('accessUsers');
                const accessInfo = document.getElementById('accessInfo');
                if (!accessUsers || !accessInfo) {
                    console.error('Access info elements missing');
                    return;
                }
                accessUsers.innerHTML = '';
                if (data.data && data.data.users && data.data.users.length > 0) {
                    data.data.users.forEach(user => {
                        accessUsers.innerHTML += `<div>${user.username} (${user.role})</div>`;
                    });
                    accessInfo.textContent = `${data.data.users.length} user(s) have access`;
                } else {
                    accessUsers.innerHTML = '<div>Department-wide access</div>';
                    accessInfo.textContent = 'All department users have access';
                }
            })
            .catch(error => {
                console.error('Error fetching access info:', error);
                document.getElementById('accessUsers').innerHTML = 'Error loading access info';
            });
        }

        // Modal open/close
        function openModal(type) {
            const modal = document.getElementById(`${type}Modal`);
            if (modal) {
                updateModalGrid(type);
                modal.style.display = 'flex';
                modal.classList.add('open');
                const modalContent = modal.querySelector('.modal-content');
                if (modalContent) {
                    setTimeout(() => modalContent.classList.add('open'), 10);
                } else {
                    showAlert('UI error: Modal content missing', 'error');
                }
            } else {
                showAlert(`Error: Modal for ${type} not found`, 'error');
            }
        }

        function closeModal(type) {
            const modal = document.getElementById(`${type}Modal`);
            if (modal) {
                const modalContent = modal.querySelector('.modal-content');
                if (modalContent) {
                    modalContent.classList.remove('open');
                }
                setTimeout(() => {
                    modal.style.display = 'none';
                    modal.classList.remove('open');
                }, 300);
            }
        }

        // Update modal grid with filtered files
        function updateModalGrid(type) {
            const modalGrid = document.getElementById(`${type}Grid`);
            if (!modalGrid) return;

            let filesToShow = <?= json_encode(array_values($filteredFiles)) ?>;
            if (type !== 'department') {
                filesToShow = filesToShow.filter(file => file.document_type === type);
            }

            const searchQuery = elements.searchBar.value.toLowerCase();
            modalGrid.innerHTML = '';
            const filteredModalFiles = filesToShow.filter(file => file.file_name.toLowerCase().includes(searchQuery));

            if (filteredModalFiles.length === 0) {
                modalGrid.innerHTML = '<p class="no-results">No files found</p>';
                return;
            }

            filteredModalFiles.forEach(file => {
                modalGrid.innerHTML += `
                    <div class="file-card" data-file-id="${file.id}" data-file-name="${file.file_name}" role="button" tabindex="0" aria-label="View details for ${file.file_name}">
                        <div class="file-icon-container"><i class="${getFileIcon(file.file_name)} file-icon"></i></div>
                        <p class="file-name">${file.file_name}</p>
                        <span class="file-type-badge">${file.document_type || 'Unknown'}</span>
                        <div class="file-options" role="button" tabindex="0" aria-label="File options for ${file.file_name}">
                            <i class="fas fa-ellipsis-v" aria-hidden="true"></i>
                            <div class="options-menu">
                                <div data-action="Rename" data-file-id="${file.id}">Rename</div>
                                <div data-action="Delete" data-file-id="${file.id}">Delete</div>
                                <div data-action="Make Copy" data-file-id="${file.id}">Make Copy</div>
                                <div data-action="File Information" data-file-id="${file.id}">File Information</div>
                            </div>
                        </div>
                    </div>`;
            });
        }

        // Full preview
        function openFullPreview(filePath) {
            const content = document.getElementById('fullPreviewContent');
            if (!elements.fullPreviewModal || !content) {
                showAlert('Preview modal not found', 'error');
                return;
            }
            const ext = filePath.split('.').pop().toLowerCase();
            content.innerHTML = ['pdf'].includes(ext) ?
                `<iframe src="${filePath}" title="Full file preview" aria-label="Full file preview"></iframe>` :
                `<img src="${filePath}" style="max-width: 100%; max-height: 80vh;" alt="Full image preview" aria-label="Full image preview">`;
            elements.fullPreviewModal.style.display = 'flex';
            elements.fullPreviewModal.classList.add('open');
            setTimeout(() => content.parentElement.classList.add('open'), 10);
        }

        function closeFullPreview() {
            const content = document.getElementById('fullPreviewContent');
            if (content) content.parentElement.classList.remove('open');
            setTimeout(() => {
                elements.fullPreviewModal.style.display = 'none';
                elements.fullPreviewModal.classList.remove('open');
            }, 300);
        }

        // Sidebar section toggle
        function closeSidebar() {
            elements.sidebar.classList.remove('active');
        }

        function showSection(sectionId) {
            document.querySelectorAll('.info-section').forEach(section => section.classList.remove('active'));
            document.getElementById(sectionId).classList.add('active');
            document.querySelectorAll('.file-info-header div').forEach(div => div.classList.remove('active'));
            document.querySelector(`.file-info-${sectionId === 'locationSection' ? 'location' : 'details'}`).classList.add('active');
        }

        // Search filter
        function filterFiles() {
            const searchQuery = elements.searchBar.value.toLowerCase();
            const fileCards = document.querySelectorAll('#departmentFiles .file-card');
            let hasResults = false;
            fileCards.forEach(card => {
                const fileName = card.dataset.fileName.toLowerCase();
                const isVisible = fileName.includes(searchQuery);
                card.classList.toggle('hidden', !isVisible);
                if (isVisible) hasResults = true;
            });
            const noResults = document.getElementById('noResults');
            if (noResults) noResults.remove();
            if (!hasResults && searchQuery) {
                elements.departmentFiles.insertAdjacentHTML('beforeend', '<p id="noResults" class="no-results">No files found</p>');
            }
            // Update modals
            document.querySelectorAll('.modal').forEach(modal => {
                const type = modal.id.replace('Modal', '');
                if (modal.style.display === 'flex') {
                    updateModalGrid(type);
                }
            });
        }

        // Initialize sorting buttons
        function initializeSortButtons() {
            document.querySelectorAll('.sort-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const filter = this.getAttribute('data-filter');
                    window.location.href = `department_folder.php?department_id=<?= htmlspecialchars($departmentId, ENT_QUOTES, 'UTF-8') ?>&sort=${filter}`;
                });
            });
        }

        // Hardcopy storage popup
        function initializeHardcopyStorage() {
            elements.hardcopyStorageButton.addEventListener('click', () => {
                document.getElementById('hardcopyStoragePopup').style.display = 'flex';
                document.getElementById('documentType').focus();
            });
        }

        function closePopup(popupId) {
            const popup = document.getElementById(popupId);
            popup.style.display = 'none';
            elements.storageSuggestion.textContent = '';
            popup.querySelector('.modal-content')?.classList.remove('open');
        }

        // Document type fields
        function initializeDocumentTypeFields() {
            elements.documentTypeSelect.addEventListener('change', function() {
                const type = this.value;
                elements.dynamicFields.innerHTML = `
                    <label for="fileName">File Name:</label>
                    <input type="text" id="fileName" name="file_name" required maxlength="255" aria-required="true">
                `;

                if (type) {
                    $.ajax({
                        url: 'get_document_type.php',
                        method: 'GET',
                        data: {
                            document_type_name: type,
                            csrf_token: '<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>'
                        },
                        dataType: 'json',
                        success: function(data) {
                            if (data.success && data.data && data.data.fields) {
                                let fieldsHtml = '';
                                data.data.fields.forEach(field => {
                                    const inputType = field.field_type === 'date' ? 'date' :
                                        field.field_type === 'textarea' ? 'textarea' : 'text';
                                    const required = field.is_required ? 'required' : '';
                                    fieldsHtml += `
                                        <label for="${field.field_name}">${field.field_label}:</label>
                                        ${inputType === 'textarea' ?
                                            `<textarea id="${field.field_name}" name="${field.field_name}" ${required} aria-required="${field.is_required}"></textarea>` :
                                            `<input type="${inputType}" id="${field.field_name}" name="${field.field_name}" ${required} aria-required="${field.is_required}">`}
                                    `;
                                });
                                elements.dynamicFields.innerHTML += fieldsHtml;
                            } else {
                                showAlert(data.message || 'No fields found for this document type.', 'error');
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('AJAX Error:', status, error);
                            showAlert('Failed to load document fields.', 'error');
                        }
                    });
                }
            });
        }

        // File details form
        function initializeFileDetailsForm() {
            elements.fileDetailsForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                formData.append('department_id', '<?= htmlspecialchars($departmentId, ENT_QUOTES, 'UTF-8') ?>');
                formData.append('csrf_token', '<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>');

                fetch('get_storage_suggestions.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                .then(response => {
                    if (!response.ok) throw new Error('Network response was not ok');
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        elements.storageSuggestion.textContent = data.suggestion;
                        elements.storageSuggestion.style.color = 'green';
                        formData.append('storage_metadata', JSON.stringify(data.storage_metadata));
                        saveFileDetails(formData);
                    } else {
                        elements.storageSuggestion.textContent = data.message || 'No storage suggestion available.';
                        elements.storageSuggestion.style.color = 'red';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showAlert('An error occurred while fetching storage suggestion.', 'error');
                });
            });
        }

        function saveFileDetails(formData) {
            fetch('save_file_details.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(response => {
                if (!response.ok) throw new Error('Network response was not ok');
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    showAlert('File details and storage location saved successfully!', 'success', () => window.location.reload());
                } else {
                    showAlert(`Failed to save: ${data.message || 'Unknown error'}`, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('An error occurred while saving.', 'error');
            });
        }

        // Rename form
        function initializeRenameForm() {
            elements.renameForm.addEventListener('submit', function(e) {
                e.preventDefault();
                performFileAction('rename', document.getElementById('renameFileId').value);
            });
        }

        // Alert function
        function showAlert(message, type, callback = null) {
            const alertDiv = document.createElement('div');
            alertDiv.className = `custom-alert ${type}`;
            alertDiv.setAttribute('role', 'alert');
            alertDiv.innerHTML = `
                <p>${message}</p>
                <button onclick="this.parentElement.remove(); ${callback ? 'callback()' : ''}" aria-label="Close alert">OK</button>
            `;
            document.body.appendChild(alertDiv);
            setTimeout(() => alertDiv.remove(), 5000);
        }

        // File size formatter
        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        // Client-side getFileIcon function
        function getFileIcon(fileName) {
            const iconClasses = {
                'pdf': 'fas fa-file-pdf',
                'doc': 'fas fa-file-word',
                'docx': 'fas fa-file-word',
                'xls': 'fas fa-file-excel',
                'xlsx': 'fas fa-file-excel',
                'ppt': 'fas fa-file-powerpoint',
                'pptx': 'fas fa-file-powerpoint',
                'txt': 'fas fa-file-alt',
                'jpg': 'fas fa-file-image',
                'jpeg': 'fas fa-file-image',
                'png': 'fas fa-file-image',
                'default': 'fas fa-file'
            };
            const extension = fileName.split('.').pop().toLowerCase();
            return iconClasses[extension] || iconClasses['default'];
        }

        // Initialize event delegation
        function initializeEventDelegation() {
            document.addEventListener('click', (e) => {
                const target = e.target;

                // File card click
                if (target.closest('.file-card') && !target.closest('.file-options')) {
                    const fileId = target.closest('.file-card').dataset.fileId;
                    if (fileId) openSidebar(fileId);
                }

                // File options click
                if (target.closest('.file-options')) {
                    toggleOptions(e, target.closest('.file-options'));
                }

                // Options menu item click
                if (target.closest('.options-menu div')) {
                    const menuItem = target.closest('.options-menu div');
                    const action = menuItem.dataset.action;
                    const fileId = menuItem.dataset.fileId;
                    if (action && fileId) {
                        handleOption(action, fileId);
                    }
                }

                // File type card click
                if (target.closest('.ftype-card')) {
                    const type = target.closest('.ftype-card').dataset.type;
                    if (type) openModal(type);
                }
            });

            // Keyboard navigation
            document.addEventListener('keydown', (e) => {
                const target = e.target;
                if (e.key === 'Enter' || e.key === ' ') {
                    if (target.closest('.file-card, .ftype-card, .sort-btn, .file-options')) {
                        e.preventDefault();
                        target.closest('.file-card, .ftype-card, .sort-btn, .file-options').click();
                    }
                }
                if (e.key === 'Escape') {
                    if (document.querySelector('.modal.open')) {
                        closeModal(document.querySelector('.modal.open').id.replace('Modal', ''));
                    }
                    closeSidebar();
                    document.querySelectorAll('.options-menu.show').forEach(menu => menu.classList.remove('show'));
                    closeFullPreview();
                    closePopup('hardcopyStoragePopup');
                }
            });
        }

        // Initialize all functionality
        document.addEventListener('DOMContentLoaded', () => {
            if (elements.searchBar) elements.searchBar.addEventListener('input', filterFiles);
            elements.searchBar.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') e.preventDefault();
            });
            initializeSidebarToggle();
            initializeSortButtons();
            initializeHardcopyStorage();
            initializeDocumentTypeFields();
            initializeFileDetailsForm();
            initializeRenameForm();
            initializeEventDelegation();
        });
    </script>
</body>

</html>