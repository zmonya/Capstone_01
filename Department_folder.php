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
 * @return array User ID and role
 * @throws Exception If user is not authenticated
 */
function validateSession(): array
{
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        throw new Exception('Unauthorized access: Please log in.', 401);
    }
    session_regenerate_id(true);
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
    $stmt = $pdo->prepare("
        SELECT d.Department_id AS id, d.Department_name AS name
        FROM departments d
        JOIN users_department ud ON d.Department_id = ud.Department_id
        WHERE ud.User_id = ?
        ORDER BY d.Department_name
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fetches document types from documents_type_fields.
 *
 * @param PDO $pdo
 * @return array
 */
function fetchDocumentTypes(PDO $pdo): array
{
    $stmt = $pdo->prepare("SELECT DISTINCT Field_name AS name FROM documents_type_fields ORDER BY Field_name");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
    $stmt = $pdo->prepare("
        SELECT f.File_id AS id, f.File_name AS file_name, f.User_id AS user_id, f.Upload_date AS upload_date,
               f.File_size AS file_size, f.File_type AS file_type, f.File_path AS file_path, f.Meta_data AS meta_data,
               dtf.Field_name AS document_type, u.Username AS uploader_name
        FROM files f
        LEFT JOIN documents_type_fields dtf ON f.Document_type_id = dtf.Document_type_id
        LEFT JOIN users u ON f.User_id = u.User_id
        JOIN users_department ud ON ud.User_id = f.User_id AND ud.Department_id = ?
        WHERE f.File_status != 'deleted' AND ud.User_id IN (
            SELECT User_id FROM users_department WHERE Department_id = ?
        )
        ORDER BY f.Upload_date DESC
    ");
    $stmt->execute([$departmentId, $departmentId]);
    $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($files as &$file) {
        $metaData = json_decode($file['meta_data'] ?? '{}', true);
        $file['hard_copy_available'] = $metaData['hard_copy_available'] ?? 0;
        $file['cabinet_name'] = $metaData['cabinet_name'] ?? null;
        $file['layer'] = $metaData['layer'] ?? null;
        $file['box'] = $metaData['box'] ?? null;
        $file['folder'] = $metaData['folder'] ?? null;
        $file['pages'] = $metaData['pages'] ?? null;
        $file['purpose'] = $metaData['purpose'] ?? null;
        $file['subject'] = $metaData['subject'] ?? null;
    }
    unset($file);
    return $files;
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
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM users_department
        WHERE User_id = ? AND Department_id = ?
    ");
    $stmt->execute([$userId, $departmentId]);
    return $stmt->fetchColumn() > 0;
}

try {
    $session = validateSession();
    $userId = $session['user_id'];
    $userRole = $session['role'];

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $csrfToken = $_SESSION['csrf_token'];

    $departmentId = filter_input(INPUT_GET, 'department_id', FILTER_VALIDATE_INT);
    if (!$departmentId) {
        throw new Exception('Department ID is required.', 400);
    }

    global $pdo;
    $stmt = $pdo->prepare("SELECT Username FROM users WHERE User_id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        throw new Exception('User not found.', 404);
    }

    $stmt = $pdo->prepare("SELECT Department_name AS name FROM departments WHERE Department_id = ?");
    $stmt->execute([$departmentId]);
    $department = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$department) {
        throw new Exception('Department not found.', 404);
    }
    $departmentName = $department['name'];

    if (!validateDepartmentAccess($pdo, $userId, $departmentId)) {
        throw new Exception('You do not have access to this department.', 403);
    }

    $userDepartments = fetchUserDepartments($pdo, $userId);
    $documentTypes = fetchDocumentTypes($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrfToken) {
            throw new Exception('Invalid CSRF token.', 403);
        }

        $action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_STRING);
        $fileId = filter_input(INPUT_POST, 'file_id', FILTER_VALIDATE_INT);
        if (!$fileId) {
            throw new Exception('Invalid file ID.', 400);
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT File_name, User_id, Meta_data FROM files WHERE File_id = ? AND File_status != 'deleted'");
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
                $stmt = $pdo->prepare("UPDATE files SET File_name = ? WHERE File_id = ?");
                $stmt->execute([$newName, $fileId]);
                $logMessage = "Renamed file ID $fileId to $newName";
                break;

            case 'delete':
                $stmt = $pdo->prepare("UPDATE files SET File_status = 'deleted' WHERE File_id = ?");
                $stmt->execute([$fileId]);
                $logMessage = "Deleted file ID $fileId";
                break;

            case 'make_copy':
                $metaData = json_decode($file['Meta_data'] ?? '{}', true);
                $stmt = $pdo->prepare("
                    INSERT INTO files (Parent_file_id, File_name, Meta_data, User_id, Upload_date, File_size, File_type, Document_type_id, File_status, Copy_type, File_path, Type_id)
                    SELECT File_id, CONCAT(File_name, '_copy'), Meta_data, User_id, NOW(), File_size, File_type, Document_type_id, File_status, 'copy', File_path, Type_id
                    FROM files WHERE File_id = ?
                ");
                $stmt->execute([$fileId]);
                $newFileId = $pdo->lastInsertId();
                $logMessage = "Created copy of file ID $fileId as file ID $newFileId";
                break;

            default:
                throw new Exception('Invalid action.', 400);
        }

        $stmt = $pdo->prepare("
            INSERT INTO transaction (User_id, File_id, Transaction_status, Transaction_type, Time, Massage)
            VALUES (?, ?, 'completed', 23, NOW(), ?)
        ");
        $stmt->execute([$userId, $fileId, $logMessage]);
        $pdo->commit();

        sendJsonResponse(true, 'Action completed successfully.', [], 200);
    }

    $files = fetchDepartmentFiles($pdo, $userId, $departmentId);

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
} catch (Exception $e) {
    error_log("Error in Department_folder.php: " . $e->getMessage());
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        sendJsonResponse(false, 'Server error: ' . $e->getMessage(), [], $e->getCode() ?: 500);
    } else {
        http_response_code($e->getCode() ?: 500);
        die('Error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
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
    <meta name="description" content="Document archival system for <?= htmlspecialchars($departmentName); ?>">
    <title><?= htmlspecialchars($departmentName); ?> - Document Archival</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style/client-sidebar.css">
    <link rel="stylesheet" href="style/folder-page.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
</head>

<body>
    <div class="top-nav">
        <button class="toggle-btn" title="Toggle Sidebar" aria-label="Toggle Sidebar"><i class="fas fa-bars"></i></button>
        <h2><?= htmlspecialchars($departmentName); ?></h2>
        <div class="search-container">
            <i class="fas fa-search search-icon"></i>
            <input type="text" placeholder="Search documents..." class="search-bar" id="searchBar" aria-label="Search documents">
        </div>
        <button id="hardcopyStorageButton" aria-label="Recommend Storage"><i class="fas fa-archive"></i> Recommend Storage</button>
    </div>

    <div class="sidebar">
        <h2 class="sidebar-title">Document Archival</h2>
        <?php if ($userRole === 'admin'): ?>
            <a href="admin_dashboard.php" data-tooltip="Admin Dashboard" class="<?= basename($_SERVER['PHP_SELF']) == 'admin_dashboard.php' ? 'active' : '' ?>">
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
                data-tooltip="<?= htmlspecialchars($dept['name']) ?>">
                <i class="fas fa-folder"></i><span class="link-text"><?= htmlspecialchars($dept['name']) ?></span>
            </a>
        <?php endforeach; ?>
        <a href="logout.php" class="logout-btn" data-tooltip="Logout">
            <i class="fas fa-sign-out-alt"></i><span class="link-text">Logout</span>
        </a>
    </div>

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
                    data-type="<?= htmlspecialchars(strtolower($type['name']), ENT_QUOTES, 'UTF-8') ?>"
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
                <div class="view-more">
                    <button onclick="openModal('department')" aria-label="View more department files">View More</button>
                </div>
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
        <div id="<?= htmlspecialchars(strtolower($type['name']), ENT_QUOTES, 'UTF-8') ?>Modal"
            class="modal"
            role="dialog"
            aria-labelledby="<?= htmlspecialchars(strtolower($type['name']), ENT_QUOTES, 'UTF-8') ?>ModalTitle">
            <div class="modal-content">
                <button class="close-modal"
                    onclick="closeModal('<?= htmlspecialchars(strtolower($type['name']), ENT_QUOTES, 'UTF-8') ?>')"
                    aria-label="Close <?= htmlspecialchars(ucfirst($type['name']), ENT_QUOTES, 'UTF-8') ?> modal">✕</button>
                <h2 id="<?= htmlspecialchars(strtolower($type['name']), ENT_QUOTES, 'UTF-8') ?>ModalTitle">
                    <?= htmlspecialchars(ucfirst($type['name']), ENT_QUOTES, 'UTF-8') ?> Files
                </h2>
                <div class="modal-grid">
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
            <div class="modal-grid">
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

    <script>
        // State management
        const state = {
            isLoading: false,
            activeModal: null,
            activeSidebar: false,
            activeOptionsMenu: null
        };

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

        // Utility functions
        const formatFileSize = (bytes) => {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        };

        const showAlert = (message, type, callback = null) => {
            if (state.isLoading) return;
            const alertDiv = document.createElement('div');
            alertDiv.className = `custom-alert ${type}`;
            alertDiv.setAttribute('role', 'alert');
            alertDiv.innerHTML = `
                <p>${message}</p>
                <button onclick="this.parentElement.remove(); ${callback ? 'callback()' : ''}" aria-label="Close alert">OK</button>
            `;
            document.body.appendChild(alertDiv);
            setTimeout(() => alertDiv.remove(), 5000);
        };

        const setLoadingState = (isLoading) => {
            state.isLoading = isLoading;
            document.body.style.cursor = isLoading ? 'progress' : 'default';
            elements.departmentFiles.style.opacity = isLoading ? '0.5' : '1';
        };

        // Event handlers
        const toggleSidebar = () => {
            const sidebar = document.querySelector('.sidebar');
            const topNav = document.querySelector('.top-nav');
            sidebar.classList.toggle('minimized');
            topNav.classList.toggle('resized', sidebar.classList.contains('minimized'));
        };

        const toggleOptions = (event, element) => {
            event.stopPropagation();
            const menu = element.querySelector('.options-menu');
            if (state.activeOptionsMenu && state.activeOptionsMenu !== menu) {
                state.activeOptionsMenu.classList.remove('show');
            }
            menu.classList.toggle('show');
            state.activeOptionsMenu = menu.classList.contains('show') ? menu : null;
        };

        const closeAllOptionsMenus = () => {
            document.querySelectorAll('.options-menu').forEach(menu => menu.classList.remove('show'));
            state.activeOptionsMenu = null;
        };

        const openModal = (type) => {
            if (state.activeModal) closeModal(state.activeModal);
            const modal = document.getElementById(`${type}Modal`);
            if (!modal) {
                showAlert(`Error: Modal for ${type} not found`, 'error');
                return;
            }
            modal.style.display = 'flex';
            modal.classList.add('open');
            state.activeModal = type;
            setTimeout(() => modal.querySelector('.modal-content')?.classList.add('open'), 10);
            modal.focus();
        };

        const closeModal = (type) => {
            const modal = document.getElementById(`${type}Modal`);
            if (!modal) return;
            const modalContent = modal.querySelector('.modal-content');
            if (modalContent) modalContent.classList.remove('open');
            setTimeout(() => {
                modal.style.display = 'none';
                modal.classList.remove('open');
                state.activeModal = null;
            }, 300);
        };

        const openSidebar = (fileId) => {
            if (state.isLoading) return;
            setLoadingState(true);
            fetch(`get_file_info.php?file_id=${fileId}&csrf_token=<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>`, {
                    method: 'GET',
                    credentials: 'same-origin'
                })
                .then(response => {
                    if (!response.ok) return response.json().then(err => {
                        throw new Error(err.message || 'Unknown error');
                    });
                    return response.json();
                })
                .then(data => {
                    if (!data.success) {
                        showAlert(`Error: ${data.message}`, 'error');
                        return;
                    }

                    const elements = {
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

                    for (const [key, element] of Object.entries(elements)) {
                        if (!element) {
                            console.error(`Element with ID '${key}' not found`);
                            showAlert(`UI error: Element '${key}' missing`, 'error');
                            return;
                        }
                    }

                    elements.sidebarFileName.textContent = data.file_name || 'Unnamed File';
                    elements.departmentCollege.textContent = data.department_name || 'N/A';
                    elements.physicalLocation.textContent = data.hard_copy_available ? (data.cabinet_name ? 'Assigned' : 'Not assigned') : 'Digital only';
                    elements.cabinet.textContent = data.cabinet_name || 'N/A';
                    elements.storageDetails.textContent = data.layer && data.box && data.folder ? `${data.layer}/${data.box}/${data.folder}` : 'N/A';
                    elements.uploader.textContent = data.uploader_name || 'N/A';
                    elements.fileType.textContent = data.file_type || 'N/A';
                    elements.fileSize.textContent = data.file_size ? formatFileSize(data.file_size) : 'N/A';
                    elements.fileCategory.textContent = data.document_type || 'N/A';
                    elements.dateUpload.textContent = data.upload_date || 'N/A';
                    elements.pages.textContent = data.pages || 'N/A';
                    elements.purpose.textContent = data.purpose || 'Not specified';
                    elements.subject.textContent = data.subject || 'Not specified';

                    elements.filePreview.innerHTML = '';
                    if (data.file_path) {
                        const ext = data.file_type?.toLowerCase();
                        if (ext === 'pdf') {
                            elements.filePreview.innerHTML = `<iframe src="${data.file_path}" title="File Preview" aria-label="File preview"></iframe><p>Click to view full file${data.hard_copy_available ? ' (Hardcopy available)' : ''}</p>`;
                            elements.filePreview.querySelector('iframe').addEventListener('click', () => openFullPreview(data.file_path));
                        } else if (['jpg', 'png', 'jpeg', 'gif'].includes(ext)) {
                            elements.filePreview.innerHTML = `<img src="${data.file_path}" alt="File Preview" loading="lazy"><p>Click to view full image${data.hard_copy_available ? ' (Hardcopy available)' : ''}</p>`;
                            elements.filePreview.querySelector('img').addEventListener('click', () => openFullPreview(data.file_path));
                        } else {
                            elements.filePreview.innerHTML = '<p>Preview not available for this file type</p>';
                        }
                    } else if (data.hard_copy_available) {
                        elements.filePreview.innerHTML = '<p>Hardcopy - No digital preview available</p>';
                    } else {
                        elements.filePreview.innerHTML = '<p>No preview available</p>';
                    }

                    fetchAccessInfo(fileId);
                    elements.sidebar.classList.add('active');
                    elements.sidebar.focus();
                })
                .catch(error => {
                    console.error('Fetch error:', error.message);
                    showAlert(`Failed to load file information: ${error.message}`, 'error');
                })
                .finally(() => setLoadingState(false));
        };

        const fetchAccessInfo = (fileId) => {
            fetch(`get_access_info.php?file_id=${fileId}&csrf_token=<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>`, {
                    method: 'GET',
                    credentials: 'same-origin'
                })
                .then(response => response.json())
                .then(data => {
                    const accessUsers = document.getElementById('accessUsers');
                    const accessInfo = document.getElementById('accessInfo');
                    if (!accessUsers || !accessInfo) return;
                    accessUsers.innerHTML = '';
                    if (data.users && data.users.length > 0) {
                        accessUsers.innerHTML = data.users.map(user => `<div>${user.username} (${user.role})</div>`).join('');
                        accessInfo.textContent = `${data.users.length} user(s) have access`;
                    } else {
                        accessUsers.innerHTML = '<div>Department-wide access</div>';
                        accessInfo.textContent = 'All department users have access';
                    }
                })
                .catch(error => {
                    console.error('Error fetching access info:', error);
                    document.getElementById('accessUsers').innerHTML = 'Error loading access info';
                });
        };

        const openFullPreview = (filePath) => {
            const content = document.getElementById('fullPreviewContent');
            if (!elements.fullPreviewModal || !content) {
                showAlert('Preview modal not found', 'error');
                return;
            }
            const ext = filePath.split('.').pop().toLowerCase();
            content.innerHTML = ['pdf'].includes(ext) ?
                `<iframe src="${filePath}" title="Full file preview" aria-label="Full file preview"></iframe>` :
                `<img src="${filePath}" alt="Full image preview" style="max-width: 100%; max-height: 80vh;" loading="lazy">`;
            elements.fullPreviewModal.style.display = 'flex';
            elements.fullPreviewModal.classList.add('open');
            setTimeout(() => content.parentElement.classList.add('open'), 10);
            elements.fullPreviewModal.focus();
        };

        const closeFullPreview = () => {
            const content = document.getElementById('fullPreviewContent');
            if (content) content.parentElement.classList.remove('open');
            setTimeout(() => {
                elements.fullPreviewModal.style.display = 'none';
                elements.fullPreviewModal.classList.remove('open');
            }, 300);
        };

        const closeSidebar = () => {
            elements.sidebar.classList.remove('active');
            state.activeSidebar = false;
        };

        const showSection = (sectionId) => {
            document.querySelectorAll('.info-section').forEach(section => section.classList.remove('active'));
            document.getElementById(sectionId).classList.add('active');
            document.querySelectorAll('.file-info-header div').forEach(div => div.classList.remove('active'));
            document.querySelector(`.file-info-${sectionId === 'locationSection' ? 'location' : 'details'}`).classList.add('active');
            document.getElementById(sectionId).focus();
        };

        const openRenameModal = (fileId) => {
            document.getElementById('renameFileId').value = fileId;
            elements.renameModal.style.display = 'flex';
            elements.renameModal.classList.add('open');
            setTimeout(() => elements.renameModal.querySelector('.modal-content')?.classList.add('open'), 10);
            document.getElementById('renameFileName').focus();
        };

        const performFileAction = (action, fileId) => {
            if (state.isLoading || !fileId || isNaN(fileId)) {
                showAlert('Invalid file ID or operation in progress', 'error');
                return;
            }
            if (action === 'delete' && !confirm('Are you sure you want to delete this file?')) {
                return;
            }

            setLoadingState(true);
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
                        showAlert(`Error: ${data.message}`, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showAlert('An error occurred while processing the request.', 'error');
                })
                .finally(() => setLoadingState(false));
        };

        const filterFiles = () => {
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
        };

        // Event delegation
        const initializeEventDelegation = () => {
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
                        switch (action) {
                            case 'Rename':
                                openRenameModal(fileId);
                                break;
                            case 'Delete':
                            case 'Make Copy':
                            case 'File Information':
                                performFileAction(action.toLowerCase().replace(' ', '_'), fileId);
                                break;
                        }
                    }
                }

                // File type card click
                if (target.closest('.ftype-card')) {
                    const type = target.closest('.ftype-card').dataset.type;
                    if (type) openModal(type);
                }

                // Close options menus when clicking outside
                if (!target.closest('.file-options')) {
                    closeAllOptionsMenus();
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
                    if (state.activeModal) closeModal(state.activeModal);
                    if (state.activeSidebar) closeSidebar();
                    closeAllOptionsMenus();
                    closeFullPreview();
                }
            });
        };

        // Initialize components
        const initializeSortButtons = () => {
            document.querySelectorAll('.sort-btn').forEach(button => {
                button.addEventListener('click', () => {
                    const filter = button.getAttribute('data-filter');
                    window.location.href = `department_folder.php?department_id=<?= htmlspecialchars($departmentId, ENT_QUOTES, 'UTF-8') ?>&sort=${filter}`;
                });
            });
        };

        const initializeHardcopyStorage = () => {
            elements.hardcopyStorageButton.addEventListener('click', () => {
                document.getElementById('hardcopyStoragePopup').style.display = 'flex';
                document.getElementById('documentType').focus();
            });
        };

        const closePopup = (popupId) => {
            const popup = document.getElementById(popupId);
            popup.style.display = 'none';
            elements.storageSuggestion.textContent = '';
            popup.querySelector('.modal-content')?.classList.remove('open');
        };

        const initializeDocumentTypeFields = () => {
            elements.documentTypeSelect.addEventListener('change', function() {
                const type = this.value;
                elements.dynamicFields.innerHTML = `
                    <label for="fileName">File Name:</label>
                    <input type="text" id="fileName" name="file_name" required maxlength="255" aria-required="true">
                `;

                if (type) {
                    setLoadingState(true);
                    $.ajax({
                        url: 'get_document_type.php',
                        method: 'GET',
                        data: {
                            document_type_name: type,
                            csrf_token: '<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>'
                        },
                        dataType: 'json',
                        success: function(data) {
                            if (data.success && data.fields.length > 0) {
                                let fieldsHtml = '';
                                data.fields.forEach(field => {
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
                        },
                        complete: () => setLoadingState(false)
                    });
                }
            });
        };

        const initializeFileDetailsForm = () => {
            elements.fileDetailsForm.addEventListener('submit', function(e) {
                e.preventDefault();
                if (state.isLoading) return;
                setLoadingState(true);
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
                    })
                    .finally(() => setLoadingState(false));
            });
        };

        const saveFileDetails = (formData) => {
            setLoadingState(true);
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
                })
                .finally(() => setLoadingState(false));
        };

        const initializeRenameForm = () => {
            elements.renameForm.addEventListener('submit', function(e) {
                e.preventDefault();
                if (state.isLoading) return;
                performFileAction('rename', document.getElementById('renameFileId').value);
            });
        };

        // Initialize all functionality
        document.addEventListener('DOMContentLoaded', () => {
            elements.searchBar.addEventListener('input', filterFiles);
            elements.searchBar.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') e.preventDefault();
            });
            document.querySelector('.toggle-btn').addEventListener('click', toggleSidebar);
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