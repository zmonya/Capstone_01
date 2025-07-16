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
 * Fetches files accessible to the user (uploaded or via transactions).
 *
 * @param PDO $pdo
 * @param int $userId
 * @return array
 */
function fetchUserFiles(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare("
        SELECT DISTINCT f.File_id AS id, f.File_name AS file_name, f.User_id AS user_id, f.Upload_date AS upload_date,
               f.File_size AS file_size, f.File_type AS file_type, f.File_path AS file_path, f.Meta_data AS meta_data,
               dtf.Field_name AS document_type, u.Username AS uploader_name
        FROM files f
        LEFT JOIN documents_type_fields dtf ON f.Document_type_id = dtf.Document_type_id
        LEFT JOIN users u ON f.User_id = u.User_id
        WHERE f.File_status != 'deleted' AND (
            f.User_id = ? OR EXISTS (
                SELECT 1 FROM transaction t
                WHERE t.File_id = f.File_id
                AND t.User_id = ?
                AND t.Transaction_type IN (2, 4, 5)
                AND t.Transaction_status = 'accepted'
            )
        )
        ORDER BY f.Upload_date DESC
    ");
    $stmt->execute([$userId, $userId]);
    $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Parse Meta_data JSON
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
}

try {
    // Validate session
    $session = validateSession();
    $userId = $session['user_id'];
    $userRole = $session['role'];

    // Handle CSRF token
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $csrfToken = $_SESSION['csrf_token'];

    // Validate user
    global $pdo;
    $stmt = $pdo->prepare("SELECT Username FROM users WHERE User_id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        throw new Exception('User not found.', 404);
    }

    // Fetch user departments and document types
    $userDepartments = fetchUserDepartments($pdo, $userId);
    $documentTypes = fetchDocumentTypes($pdo);

    // Fetch user files
    $files = fetchUserFiles($pdo, $userId);

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

    // Separate uploaded and received files
    $uploadedFiles = array_filter($filteredFiles, fn($file) => $file['user_id'] == $userId);
    $receivedFiles = array_filter($filteredFiles, fn($file) => $file['user_id'] != $userId);
} catch (Exception $e) {
    error_log("Error in my-Folder.php: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    die('Error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
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
    <title><?= htmlspecialchars($user['Username'], ENT_QUOTES, 'UTF-8') ?>'s Folder - Document Archival</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style/client-sidebar.css">
    <link rel="stylesheet" href="style/folder-page.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
</head>

<body>
    <div class="top-nav">
        <button class="toggle-btn" title="Toggle Sidebar" aria-label="Toggle Sidebar"><i class="fas fa-bars"></i></button>
        <h2><?= htmlspecialchars($user['Username'], ENT_QUOTES, 'UTF-8') ?>'s Folder</h2>
        <div class="search-container">
            <i class="fas fa-search search-icon"></i>
            <input type="text" placeholder="Search documents..." class="search-bar" id="searchBar" aria-label="Search documents">
        </div>
    </div>

    <div class="sidebar">
        <h2 class="sidebar-title">Document Archival</h2>
        <?php if ($userRole === 'admin'): ?>
            <a href="admin_dashboard.php" data-tooltip="Admin Dashboard"><i class="fas fa-user-shield"></i><span class="link-text">Admin Dashboard</span></a>
        <?php endif; ?>
        <a href="dashboard.php" class="<?= basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : '' ?>" data-tooltip="Dashboard"><i class="fas fa-home"></i><span class="link-text">Dashboard</span></a>
        <a href="my-report.php" class="<?= basename($_SERVER['PHP_SELF']) == 'my-report.php' ? 'active' : '' ?>" data-tooltip="My Report"><i class="fas fa-chart-bar"></i><span class="link-text">My Report</span></a>
        <a href="my-folder.php" class="<?= basename($_SERVER['PHP_SELF']) == 'my-folder.php' ? 'active' : '' ?>" data-tooltip="My Folder"><i class="fas fa-folder"></i><span class="link-text">My Folder</span></a>
        <?php foreach ($userDepartments as $dept): ?>
            <a href="department_folder.php?department_id=<?= htmlspecialchars($dept['id'], ENT_QUOTES, 'UTF-8') ?>" data-tooltip="<?= htmlspecialchars($dept['name'], ENT_QUOTES, 'UTF-8') ?>"><i class="fas fa-folder"></i><span class="link-text"><?= htmlspecialchars($dept['name'], ENT_QUOTES, 'UTF-8') ?></span></a>
        <?php endforeach; ?>
        <a href="logout.php" class="logout-btn" data-tooltip="Logout"><i class="fas fa-sign-out-alt"></i><span class="link-text">Logout</span></a>
    </div>

    <div class="main-content">
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
                <div class="ftype-card" onclick="openModal('<?= htmlspecialchars(strtolower(str_replace(' ', '-', $type['name'])), ENT_QUOTES, 'UTF-8') ?>')" tabindex="0" aria-label="<?= htmlspecialchars(ucfirst($type['name']), ENT_QUOTES, 'UTF-8') ?> (<?= $fileCount ?> files)">
                    <p><?= htmlspecialchars(ucfirst($type['name']), ENT_QUOTES, 'UTF-8') ?> <span class="file-count">(<?= $fileCount ?>)</span></p>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="masonry-grid">
            <div class="masonry-section">
                <h3>Uploaded Files</h3>
                <div class="file-card-container" id="uploadedFiles">
                    <?php foreach (array_slice($uploadedFiles, 0, 4) as $file): ?>
                        <div class="file-card" data-file-id="<?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>" data-file-name="<?= htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8') ?>" onclick="openSidebar(<?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>)" tabindex="0" aria-label="View details for <?= htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8') ?>">
                            <div class="file-icon-container"><i class="<?= getFileIcon($file['file_name']) ?> file-icon"></i></div>
                            <p class="file-name"><?= htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8') ?></p>
                            <div class="file-type-badge"><?= htmlspecialchars($file['document_type'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="file-options" onclick="toggleOptions(event, this)" aria-label="File options">
                                <i class="fas fa-ellipsis-v"></i>
                                <div class="options-menu">
                                    <div onclick="handleOption('Rename', <?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>)">Rename</div>
                                    <?php if ($file['user_id'] == $userId): ?>
                                        <div onclick="handleOption('Delete', <?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>)">Delete</div>
                                    <?php endif; ?>
                                    <div onclick="handleOption('Make Copy', <?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>)">Make Copy</div>
                                    <div onclick="handleOption('File Information', <?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>)">File Information</div>
                                    <?php if ($file['user_id'] != $userId): ?>
                                        <div onclick="requestAccess(<?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>)">Request Document</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (count($uploadedFiles) > 4): ?>
                    <div class="view-more"><button onclick="openModal('uploaded')" aria-label="View more uploaded files">View More</button></div>
                <?php endif; ?>
            </div>
            <div class="masonry-section">
                <h3>Received Files</h3>
                <div class="file-card-container" id="receivedFiles">
                    <?php foreach (array_slice($receivedFiles, 0, 4) as $file): ?>
                        <div class="file-card" data-file-id="<?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>" data-file-name="<?= htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8') ?>" onclick="openSidebar(<?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>)" tabindex="0" aria-label="View details for <?= htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8') ?>">
                            <div class="file-icon-container"><i class="<?= getFileIcon($file['file_name']) ?> file-icon"></i></div>
                            <p class="file-name"><?= htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8') ?></p>
                            <div class="file-type-badge"><?= htmlspecialchars($file['document_type'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="file-options" onclick="toggleOptions(event, this)" aria-label="File options">
                                <i class="fas fa-ellipsis-v"></i>
                                <div class="options-menu">
                                    <div onclick="handleOption('Rename', <?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>)">Rename</div>
                                    <?php if ($file['user_id'] == $userId): ?>
                                        <div onclick="handleOption('Delete', <?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>)">Delete</div>
                                    <?php endif; ?>
                                    <div onclick="handleOption('Make Copy', <?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>)">Make Copy</div>
                                    <div onclick="handleOption('File Information', <?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>)">File Information</div>
                                    <?php if ($file['user_id'] != $userId): ?>
                                        <div onclick="requestAccess(<?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>)">Request Document</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (count($receivedFiles) > 4): ?>
                    <div class="view-more"><button onclick="openModal('received')" aria-label="View more received files">View More</button></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="file-info-sidebar">
        <div class="file-name-container">
            <div class="file-name-title" id="sidebarFileName">File Name</div>
            <button class="close-sidebar-btn" onclick="closeSidebar()" aria-label="Close sidebar">×</button>
        </div>
        <div class="file-preview" id="filePreview"></div>
        <div class="file-info-header">
            <div class="file-info-location active" onclick="showSection('locationSection')" aria-label="Show location details">Location</div>
            <div class="file-info-details" onclick="showSection('detailsSection')" aria-label="Show file details">Details</div>
        </div>
        <div class="info-section active" id="locationSection">
            <div class="info-item"><span class="info-label">Department:</span><span class="info-value" id="departmentCollege">N/A</span></div>
            <div class="info-item"><span class="info-label">Physical Location:</span><span class="info-value" id="physicalLocation">Not assigned</span></div>
            <div class="info-item"><span class="info-label">Cabinet:</span><span class="info-value" id="cabinet">N/A</span></div>
            <div class="info-item"><span class="info-label">Layer/Box/Folder:</span><span class="info-value" id="storageDetails">N/A</span></div>
        </div>
        <div class="info-section" id="detailsSection">
            <div class="access-log">
                <h3>Who Has Access</h3>
                <div class="access-users" id="accessUsers"></div>
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
    </div>

    <div class="full-preview-modal" id="fullPreviewModal">
        <div class="full-preview-content">
            <button class="close-full-preview" onclick="closeFullPreview()" aria-label="Close full preview">✕</button>
            <div id="fullPreviewContent"></div>
        </div>
    </div>

    <?php foreach ($documentTypes as $type): ?>
        <div id="<?= htmlspecialchars(strtolower(str_replace(' ', '-', $type['name'])), ENT_QUOTES, 'UTF-8') ?>Modal" class="modal">
            <div class="modal-content">
                <button class="close-modal" onclick="closeModal('<?= htmlspecialchars(strtolower(str_replace(' ', '-', $type['name'])), ENT_QUOTES, 'UTF-8') ?>')" aria-label="Close modal">✕</button>
                <h2><?= htmlspecialchars(ucfirst($type['name']), ENT_QUOTES, 'UTF-8') ?> Files</h2>
                <div class="modal-grid">
                    <?php foreach (array_filter($filteredFiles, fn($file) => $file['document_type'] === $type['name']) as $file): ?>
                        <div class="file-card" data-file-id="<?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>" data-file-name="<?= htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8') ?>" onclick="openSidebar(<?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>)" tabindex="0" aria-label="View details for <?= htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8') ?>">
                            <div class="file-icon-container"><i class="<?= getFileIcon($file['file_name']) ?> file-icon"></i></div>
                            <p class="file-name"><?= htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8') ?></p>
                            <div class="file-type-badge"><?= htmlspecialchars($file['document_type'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="file-options" onclick="toggleOptions(event, this)" aria-label="File options">
                                <i class="fas fa-ellipsis-v"></i>
                                <div class="options-menu">
                                    <div onclick="handleOption('Rename', <?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>)">Rename</div>
                                    <?php if ($file['user_id'] == $userId): ?>
                                        <div onclick="handleOption('Delete', <?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>)">Delete</div>
                                    <?php endif; ?>
                                    <div onclick="handleOption('Make Copy', <?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>)">Make Copy</div>
                                    <div onclick="handleOption('File Information', <?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>)">File Information</div>
                                    <?php if ($file['user_id'] != $userId): ?>
                                        <div onclick="requestAccess(<?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>)">Request Document</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <div id="uploadedModal" class="modal">
        <div class="modal-content">
            <button class="close-modal" onclick="closeModal('uploaded')" aria-label="Close modal">✕</button>
            <h2>All Uploaded Files</h2>
            <div class="modal-grid">
                <?php foreach ($uploadedFiles as $file): ?>
                    <div class="file-card" data-file-id="<?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>" data-file-name="<?= htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8') ?>" onclick="openSidebar(<?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>)" tabindex="0" aria-label="View details for <?= htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8') ?>">
                        <div class="file-icon-container"><i class="<?= getFileIcon($file['file_name']) ?> file-icon"></i></div>
                        <p class="file-name"><?= htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8') ?></p>
                        <div class="file-type-badge"><?= htmlspecialchars($file['document_type'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="file-options" onclick="toggleOptions(event, this)" aria-label="File options">
                            <i class="fas fa-ellipsis-v"></i>
                            <div class="options-menu">
                                <div onclick="handleOption('Rename', <?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>)">Rename</div>
                                <?php if ($file['user_id'] == $userId): ?>
                                    <div onclick="handleOption('Delete', <?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>)">Delete</div>
                                <?php endif; ?>
                                <div onclick="handleOption('Make Copy', <?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>)">Make Copy</div>
                                <div onclick="handleOption('File Information', <?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>)">File Information</div>
                                <?php if ($file['user_id'] != $userId): ?>
                                    <div onclick="requestAccess(<?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>)">Request Document</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div id="receivedModal" class="modal">
        <div class="modal-content">
            <button class="close-modal" onclick="closeModal('received')" aria-label="Close modal">✕</button>
            <h2>All Received Files</h2>
            <div class="modal-grid">
                <?php foreach ($receivedFiles as $file): ?>
                    <div class="file-card" data-file-id="<?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>" data-file-name="<?= htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8') ?>" onclick="openSidebar(<?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>)" tabindex="0" aria-label="View details for <?= htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8') ?>">
                        <div class="file-icon-container"><i class="<?= getFileIcon($file['file_name']) ?> file-icon"></i></div>
                        <p class="file-name"><?= htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8') ?></p>
                        <div class="file-type-badge"><?= htmlspecialchars($file['document_type'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="file-options" onclick="toggleOptions(event, this)" aria-label="File options">
                            <i class="fas fa-ellipsis-v"></i>
                            <div class="options-menu">
                                <div onclick="handleOption('Rename', <?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>)">Rename</div>
                                <?php if ($file['user_id'] == $userId): ?>
                                    <div onclick="handleOption('Delete', <?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>)">Delete</div>
                                <?php endif; ?>
                                <div onclick="handleOption('Make Copy', <?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>)">Make Copy</div>
                                <div onclick="handleOption('File Information', <?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>)">File Information</div>
                                <?php if ($file['user_id'] != $userId): ?>
                                    <div onclick="requestAccess(<?= htmlspecialchars($file['id'], ENT_QUOTES, 'UTF-8') ?>)">Request Document</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div id="renameModal" class="modal">
        <div class="modal-content">
            <button class="close-modal" onclick="closeModal('rename')" aria-label="Close rename modal">✕</button>
            <h2>Rename File</h2>
            <form id="renameForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="file_id" id="renameFileId">
                <label for="renameFileName">New File Name:</label>
                <input type="text" id="renameFileName" name="new_name" required maxlength="255" aria-label="New file name">
                <button type="submit" aria-label="Submit rename">Rename</button>
            </form>
        </div>
    </div>

    <script>
        // Initialize DOM elements
        const searchBar = document.getElementById('searchBar');
        const renameForm = document.getElementById('renameForm');
        const renameModal = document.getElementById('renameModal');

        // Initialize sidebar toggle
        function initializeSidebarToggle() {
            document.querySelector('.toggle-btn').addEventListener('click', () => {
                const sidebar = document.querySelector('.sidebar');
                const topNav = document.querySelector('.top-nav');
                sidebar.classList.toggle('minimized');
                topNav.classList.toggle('resized', sidebar.classList.contains('minimized'));
            });
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
                        performFileAction('delete_file.php', {
                            file_id: fileId
                        });
                    }
                    break;
                case 'Make Copy':
                    performFileAction('make_copy.php', {
                        file_id: fileId
                    });
                    break;
                case 'File Information':
                    openSidebar(fileId);
                    break;
                case 'Request Document':
                    requestAccess(fileId);
                    break;
                default:
                    showAlert(`Unknown option: ${option}`, 'error');
            }
        }

        // Open rename modal
        function openRenameModal(fileId) {
            document.getElementById('renameFileId').value = fileId;
            renameModal.style.display = 'flex';
            renameModal.classList.add('open');
            const modalContent = renameModal.querySelector('.modal-content');
            if (modalContent) {
                setTimeout(() => modalContent.classList.add('open'), 10);
            }
        }

        // Perform file action via AJAX
        function performFileAction(url, data) {
            data.csrf_token = '<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>';
            fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(data)
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

        // Request access to a file
        function requestAccess(fileId) {
            fetch('handle_access_request.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        file_id: fileId,
                        action: 'request',
                        csrf_token: '<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showAlert('Access request sent successfully', 'success');
                    } else {
                        showAlert(`Failed to send access request: ${data.message || 'Unknown error'}`, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showAlert('An error occurred while requesting access.', 'error');
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
                        return response.json().then(err => {
                            throw new Error(err.error || 'Unknown error');
                        });
                    }
                    return response.json();
                })
                .then(data => {
                    if (!data.success) {
                        showAlert(`Error: ${data.error}`, 'error');
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

                    elements.sidebarFileName.textContent = data.data.file_name || 'Unnamed File';
                    elements.departmentCollege.textContent = data.data.department_name || 'N/A';
                    elements.physicalLocation.textContent = data.data.hard_copy_available ? (data.data.cabinet_name !== 'N/A' ? 'Assigned' : 'Not assigned') : 'Digital only';
                    elements.cabinet.textContent = data.data.cabinet_name || 'N/A';
                    elements.storageDetails.textContent = data.data.layer !== 'N/A' && data.data.box !== 'N/A' && data.data.folder !== 'N/A' ? `${data.data.layer}/${data.data.box}/${data.data.folder}` : 'N/A';
                    elements.uploader.textContent = data.data.uploader_name || 'N/A';
                    elements.fileType.textContent = data.data.file_type || 'N/A';
                    elements.fileSize.textContent = data.data.file_size ? formatFileSize(data.data.file_size) : 'N/A';
                    elements.fileCategory.textContent = data.data.document_type || 'N/A';
                    elements.dateUpload.textContent = data.data.upload_date || 'N/A';
                    elements.pages.textContent = data.data.pages || 'N/A';
                    elements.purpose.textContent = data.data.purpose || 'Not specified';
                    elements.subject.textContent = data.data.subject || 'Not specified';

                    elements.filePreview.innerHTML = '';
                    if (data.data.file_path) {
                        const ext = data.data.file_type.toLowerCase();
                        if (ext === 'pdf') {
                            elements.filePreview.innerHTML = `<iframe src="${data.data.file_path}" title="File Preview" aria-label="File preview"></iframe><p>Click to view full file${data.data.hard_copy_available ? ' (Hardcopy available)' : ''}</p>`;
                            elements.filePreview.querySelector('iframe').addEventListener('click', () => openFullPreview(data.data.file_path));
                        } else if (['jpg', 'png', 'jpeg', 'gif'].includes(ext)) {
                            elements.filePreview.innerHTML = `<img src="${data.data.file_path}" alt="File Preview" aria-label="Image preview"><p>Click to view full image${data.data.hard_copy_available ? ' (Hardcopy available)' : ''}</p>`;
                            elements.filePreview.querySelector('img').addEventListener('click', () => openFullPreview(data.data.file_path));
                        } else {
                            elements.filePreview.innerHTML = '<p>Preview not available for this file type</p>';
                        }
                    } else if (data.data.hard_copy_available) {
                        elements.filePreview.innerHTML = '<p>Hardcopy - No digital preview available</p>';
                    } else {
                        elements.filePreview.innerHTML = '<p>No preview available (missing file data)</p>';
                    }

                    // Dynamically update file details section
                    const detailsSection = document.querySelector('.file-details');
                    detailsSection.innerHTML = '<h3>File Details</h3>';
                    const baseFields = [{
                            label: 'Uploader',
                            value: data.data.uploader_name || 'N/A'
                        },
                        {
                            label: 'File Type',
                            value: data.data.file_type || 'N/A'
                        },
                        {
                            label: 'File Size',
                            value: data.data.file_size ? formatFileSize(data.data.file_size) : 'N/A'
                        },
                        {
                            label: 'Category',
                            value: data.data.document_type || 'N/A'
                        },
                        {
                            label: 'Date Uploaded',
                            value: data.data.upload_date || 'N/A'
                        },
                        {
                            label: 'Pages',
                            value: data.data.pages || 'N/A'
                        },
                        {
                            label: 'Purpose',
                            value: data.data.purpose || 'Not specified'
                        },
                        {
                            label: 'Subject',
                            value: data.data.subject || 'Not specified'
                        }
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
                            data: {
                                document_type_name: data.data.document_type
                            },
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
                    document.querySelector('.file-info-sidebar').classList.add('active');
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
                            accessUsers.innerHTML += `<div>${user.full_name} (${user.role})</div>`;
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

        // Full preview
        function openFullPreview(filePath) {
            const modal = document.getElementById('fullPreviewModal');
            const content = document.getElementById('fullPreviewContent');
            if (!modal || !content) {
                showAlert('Preview modal not found', 'error');
                return;
            }
            const ext = filePath.split('.').pop().toLowerCase();
            content.innerHTML = ['pdf'].includes(ext) ? `<iframe src="${filePath}" aria-label="Full file preview"></iframe>` : `<img src="${filePath}" style="max-width: 100%; max-height: 80vh;" alt="Full image preview" aria-label="Full image preview">`;
            modal.style.display = 'flex';
            modal.classList.add('open');
        }

        function closeFullPreview() {
            const modal = document.getElementById('fullPreviewModal');
            modal.classList.remove('open');
            setTimeout(() => modal.style.display = 'none', 300);
        }

        // Sidebar section toggle
        function closeSidebar() {
            document.querySelector('.file-info-sidebar').classList.remove('active');
        }

        function showSection(sectionId) {
            document.querySelectorAll('.info-section').forEach(section => section.classList.remove('active'));
            document.getElementById(sectionId).classList.add('active');
            document.querySelectorAll('.file-info-header div').forEach(div => div.classList.remove('active'));
            document.querySelector(`.file-info-${sectionId === 'locationSection' ? 'location' : 'details'}`).classList.add('active');
        }

        // Search filter
        function filterFiles() {
            const searchQuery = searchBar.value.toLowerCase();
            const containers = ['uploadedFiles', 'receivedFiles'];
            let hasResults = false;
            containers.forEach(containerId => {
                const fileCards = document.querySelectorAll(`#${containerId} .file-card`);
                fileCards.forEach(card => {
                    const fileName = card.dataset.fileName.toLowerCase();
                    const isVisible = fileName.includes(searchQuery);
                    card.classList.toggle('hidden', !isVisible);
                    if (isVisible) hasResults = true;
                });
                const noResults = document.getElementById(`noResults-${containerId}`);
                if (noResults) noResults.remove();
                if (!hasResults && searchQuery) {
                    const container = document.getElementById(containerId);
                    container.insertAdjacentHTML('beforeend', `<p id="noResults-${containerId}" class="no-results">No files found</p>`);
                }
            });
        }

        // Initialize sorting buttons
        function initializeSortButtons() {
            document.querySelectorAll('.sort-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const filter = this.getAttribute('data-filter');
                    window.location.href = `my-folder.php?sort=${filter}`;
                });
            });
        }

        // Handle rename form submission
        function initializeRenameForm() {
            renameForm.addEventListener('submit', function(event) {
                event.preventDefault();
                const formData = new FormData(this);
                const data = Object.fromEntries(formData);
                performFileAction('rename_file.php', data);
            });
        }

        // Alert function
        function showAlert(message, type, callback = null) {
            const alertDiv = document.createElement('div');
            alertDiv.className = `custom-alert ${type}`;
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

        // Initialize keyboard navigation
        function initializeKeyboardNavigation() {
            document.querySelectorAll('.file-card, .ftype-card, .sort-btn').forEach(element => {
                element.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        element.click();
                    }
                });
            });
        }

        // Initialize all functionality
        document.addEventListener('DOMContentLoaded', () => {
            searchBar.addEventListener('input', filterFiles);
            initializeSortButtons();
            initializeRenameForm();
            initializeSidebarToggle();
            initializeKeyboardNavigation();
        });
    </script>
</body>

</html>