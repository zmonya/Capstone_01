<?php
session_start();
require 'db_connection.php';
require 'log_activity.php';
require 'vendor/autoload.php'; // Load Composer autoloader for phpdotenv

use Dotenv\Dotenv;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Configuration constants
const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB
const UPLOAD_DIR = __DIR__ . '/Uploads/';
const VALID_FILE_TYPES = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'png', 'txt', 'zip', 'other'];
const ENCRYPTION_METHOD = 'AES-256-CBC';
$ENCRYPTION_KEY = hex2bin(getenv('ENCRYPTION_KEY') ?: throw new Exception('Encryption key not found.'));

/**
 * Generates a JSON response.
 *
 * @param bool $success
 * @param string $message
 * @param array $data
 * @param int $statusCode
 * @return void
 */
function generateResponse(bool $success, string $message = '', array $data = [], int $statusCode = 200): void
{
    header('Content-Type: application/json');
    http_response_code($statusCode);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}

/**
 * Encrypts file content using OpenSSL.
 *
 * @param string $data File content
 * @return string Encrypted content
 * @throws Exception If encryption fails
 */
function encryptFile(string $data): string
{
    global $ENCRYPTION_KEY, $ENCRYPTION_METHOD;
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($ENCRYPTION_METHOD));
    $encrypted = openssl_encrypt($data, $ENCRYPTION_METHOD, $ENCRYPTION_KEY, 0, $iv);
    if ($encrypted === false) {
        throw new Exception('File encryption failed.');
    }
    return base64_encode($iv . $encrypted);
}

/**
 * Validates CSRF token.
 *
 * @param string $csrfToken
 * @return bool
 */
function validateCsrfToken(string $csrfToken): bool
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $csrfToken);
}

/**
 * Validates and sanitizes file input.
 *
 * @param array $file
 * @return array Sanitized file details
 * @throws Exception If validation fails
 */
function validateFile(array $file): array
{
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No file uploaded or upload error.');
    }

    if (!is_int($file['size']) || $file['size'] <= 0 || $file['size'] > MAX_FILE_SIZE) {
        throw new Exception('Invalid file size or exceeds 10MB limit.');
    }

    $fileName = trim(filter_var($file['name'], FILTER_SANITIZE_SPECIAL_CHARS));
    $safeFileName = preg_replace('/[^A-Za-z0-9\-\_\.]/', '', $fileName);
    $fileType = normalizeFileType($file['type']);

    return [
        'name' => $fileName,
        'safe_name' => $safeFileName,
        'size' => $file['size'],
        'type' => $fileType,
        'tmp_name' => $file['tmp_name']
    ];
}

/**
 * Normalizes file type to a valid enum value.
 *
 * @param string $fileType
 * @return string Normalized file type
 */
function normalizeFileType(string $fileType): string
{
    $fileTypeEnum = str_replace(['application/', 'image/'], '', $fileType);
    $fileTypeEnum = str_replace('vnd.openxmlformats-officedocument.wordprocessingml.document', 'docx', $fileTypeEnum);
    $fileTypeEnum = str_replace('vnd.ms-excel', 'xls', $fileTypeEnum);
    $fileTypeEnum = str_replace('vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xlsx', $fileTypeEnum);
    $fileTypeEnum = str_replace('text/plain', 'txt', $fileTypeEnum);
    $fileTypeEnum = str_replace('application/zip', 'zip', $fileTypeEnum);

    return in_array($fileTypeEnum, VALID_FILE_TYPES) ? $fileTypeEnum : 'other';
}

/**
 * Ensures the upload directory exists and is secure.
 *
 * @return string Upload directory path
 * @throws Exception If directory creation fails
 */
function ensureUploadDirectory(): string
{
    $uploadDir = realpath(UPLOAD_DIR) . DIRECTORY_SEPARATOR;
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        throw new Exception('Failed to create upload directory.');
    }
    return $uploadDir;
}

/**
 * Validates file path to prevent directory traversal.
 *
 * @param string $uploadDir
 * @param string $absoluteFilePath
 * @throws Exception If path is invalid
 */
function validateFilePath(string $uploadDir, string $absoluteFilePath): void
{
    $normalizedUploadDir = rtrim(str_replace('\\', '/', $uploadDir), '/');
    $normalizedFilePath = str_replace('\\', '/', $absoluteFilePath);
    if (strpos($normalizedFilePath, $normalizedUploadDir) !== 0) {
        throw new Exception('Invalid file path detected.');
    }
}

/**
 * Retrieves document type ID from database.
 *
 * @param PDO $pdo
 * @param string $documentTypeName
 * @return int Document type ID
 * @throws Exception If document type is invalid
 */
function getDocumentTypeId(PDO $pdo, string $documentTypeName): int
{
    $stmt = $pdo->prepare("SELECT Document_type_id FROM documents_type_fields WHERE Field_name = ? LIMIT 1");
    $stmt->execute([$documentTypeName]);
    $documentType = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$documentType) {
        throw new Exception('Invalid document type.');
    }
    return $documentType['Document_type_id'];
}

/**
 * Retrieves user's department ID.
 *
 * @param PDO $pdo
 * @param int $userId
 * @return int Department ID
 * @throws Exception If user is not affiliated with a department
 */
function getDepartmentId(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare("SELECT Department_id FROM users_department WHERE User_id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $department = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$department) {
        throw new Exception('User not affiliated with any department.');
    }
    return $department['Department_id'];
}

/**
 * Processes and validates metadata, including cabinet structure.
 *
 * @param PDO $pdo
 * @param int $documentTypeId
 * @param array $postData
 * @param bool $hardCopyAvailable
 * @return array Metadata array
 * @throws Exception If required fields are missing or invalid
 */
function processMetadata(PDO $pdo, int $documentTypeId, array $postData, bool $hardCopyAvailable): array
{
    $stmt = $pdo->prepare("SELECT Field_name, Is_required FROM documents_type_fields WHERE Document_type_id = ?");
    $stmt->execute([$documentTypeId]);
    $metadataFields = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $metadata = [
        'cabinet' => trim(filter_var($postData['cabinet'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS))
    ];

    if ($hardCopyAvailable) {
        $metadata['layer'] = filter_var($postData['layer'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['default' => 0, 'min_range' => 0]]);
        $metadata['box'] = filter_var($postData['box'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['default' => 0, 'min_range' => 0]]);
        $metadata['folder'] = filter_var($postData['folder'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['default' => 0, 'min_range' => 0]]);
        if (empty($metadata['cabinet']) || $metadata['layer'] === false || $metadata['box'] === false || $metadata['folder'] === false) {
            throw new Exception('Invalid cabinet structure for hard copy.');
        }
    } else {
        $metadata['layer'] = null;
        $metadata['box'] = null;
        $metadata['folder'] = null;
        if (empty($metadata['cabinet'])) {
            $metadata['cabinet'] = 'Digital';
        }
    }

    $missingRequired = [];
    foreach ($metadataFields as $field) {
        $key = $field['Field_name'];
        $value = trim(filter_var($postData[$key] ?? '', FILTER_SANITIZE_SPECIAL_CHARS));
        if ($key === 'registration_link') {
            $value = trim(filter_var($postData[$key] ?? '', FILTER_SANITIZE_URL));
        }
        if ($field['Is_required'] && empty($value)) {
            $missingRequired[] = $key;
        }
        $metadata[$key] = $value;
    }

    if (!empty($missingRequired)) {
        throw new Exception('Missing required metadata fields: ' . implode(', ', $missingRequired));
    }

    return $metadata;
}

/**
 * Resolves or creates parent file for cabinet structure.
 *
 * @param PDO $pdo
 * @param array $metadata
 * @param int $departmentId
 * @return int Parent File_id
 * @throws Exception If parent file cannot be resolved or created
 */
function resolveParentFile(PDO $pdo, array $metadata, int $departmentId): ?int
{
    if (empty($metadata['cabinet'])) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT File_id 
        FROM files 
        WHERE Parent_file_id IS NULL 
        AND Meta_data LIKE ? 
        AND File_status != 'deleted'
        LIMIT 1
    ");
    $stmt->execute(['%"cabinet":"' . $metadata['cabinet'] . '"%']);
    $parent = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($parent) {
        return $parent['File_id'];
    }

    // Create new cabinet file
    $stmt = $pdo->prepare("
        INSERT INTO files (File_name, Meta_data, User_id, File_status, Copy_type, Document_type_id)
        VALUES (?, ?, ?, 'active', 'soft', NULL)
    ");
    $cabinetMeta = json_encode(['cabinet' => $metadata['cabinet']]);
    $stmt->execute([$metadata['cabinet'], $cabinetMeta, $_SESSION['user_id']]);
    return $pdo->lastInsertId();
}

try {
    // Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method.');
    }

    // Validate CSRF token
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($csrfToken)) {
        error_log("CSRF token validation failed for user: " . ($_SESSION['user_id'] ?? 'unknown'));
        generateResponse(false, 'Invalid CSRF token.', [], 403);
    }

    // Check user authentication
    if (!isset($_SESSION['user_id'])) {
        generateResponse(false, 'User not authenticated.', [], 401);
    }

    $userId = (int)$_SESSION['user_id'];
    $documentTypeName = trim(filter_var($_POST['document_type'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS));
    $hardCopyAvailable = filter_var($_POST['hard_copy_available'] ?? false, FILTER_VALIDATE_BOOLEAN);

    // Validate document type
    if (empty($documentTypeName)) {
        generateResponse(false, 'Document type is required.', [], 400);
    }

    // Validate file
    $fileDetails = validateFile($_FILES['file'] ?? []);

    // Get database connection
    global $pdo;

    // Fetch document type ID
    $documentTypeId = getDocumentTypeId($pdo, $documentTypeName);

    // Fetch user's department
    $departmentId = getDepartmentId($pdo, $userId);

    // Process metadata, including cabinet structure
    $metadata = processMetadata($pdo, $documentTypeId, $_POST, $hardCopyAvailable);

    // Resolve parent file (cabinet)
    $parentFileId = resolveParentFile($pdo, $metadata, $departmentId);

    // Prepare upload directory
    $uploadDir = ensureUploadDirectory();
    $fileNamePrefix = bin2hex(random_bytes(8)) . '_' . $fileDetails['safe_name'];
    $relativeFilePath = 'Uploads' . DIRECTORY_SEPARATOR . $fileNamePrefix;
    $absoluteFilePath = $uploadDir . $fileNamePrefix;

    // Validate file path
    validateFilePath($uploadDir, $absoluteFilePath);

    // Encrypt and move uploaded file
    $fileContent = file_get_contents($fileDetails['tmp_name']);
    $encryptedContent = encryptFile($fileContent);
    if (!file_put_contents($absoluteFilePath, $encryptedContent)) {
        error_log("Failed to move encrypted file to $absoluteFilePath for user: $userId");
        generateResponse(false, 'Failed to upload file.', [], 500);
    }

    // Start transaction
    $pdo->beginTransaction();

    // Insert file record
    $stmt = $pdo->prepare("
        INSERT INTO files (
            File_name, File_path, User_id, File_size, File_type, Document_type_id, 
            File_status, Copy_type, Meta_data, Parent_file_id
        ) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?)
    ");
    $stmt->execute([
        $fileDetails['name'],
        $relativeFilePath,
        $userId,
        $fileDetails['size'],
        $fileDetails['type'],
        $documentTypeId,
        $hardCopyAvailable ? 'hard' : 'soft',
        json_encode($metadata),
        $parentFileId
    ]);
    $fileId = $pdo->lastInsertId();

    // Log transaction
    $stmt = $pdo->prepare("
        INSERT INTO transaction (User_id, File_id, Transaction_status, Transaction_type, Time, Massage)
        VALUES (?, ?, 'completed', 1, NOW(), ?)
    ");
    $stmt->execute([$userId, $fileId, "File uploaded: {$fileDetails['name']}"]);

    // Commit transaction
    $pdo->commit();

    generateResponse(true, 'File uploaded successfully.', ['file_id' => $fileId], 200);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $errorMessage = $e->getMessage();
    error_log("Upload error for user: " . ($_SESSION['user_id'] ?? 'unknown') . " - $errorMessage");
    generateResponse(false, 'Upload failed: ' . $errorMessage, [], 500);
}
