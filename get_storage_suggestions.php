<?php
session_start();
require 'db_connection.php';
require 'log_activity.php';
require 'vendor/autoload.php';

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
 * Sends a JSON response with appropriate HTTP status.
 *
 * @param bool $success
 * @param mixed $data
 * @param int $statusCode
 * @return void
 */
function sendResponse(bool $success, $data, int $statusCode): void
{
    header('Content-Type: application/json');
    http_response_code($statusCode);
    echo json_encode($success ? ['success' => true, 'metadata' => $data] : ['success' => false, 'message' => $data]);
    exit;
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
 * Validates user session and department access.
 *
 * @param int $departmentId
 * @return int User ID
 * @throws Exception If user is not authenticated or lacks access
 */
function validateUserSessionAndDepartment(int $departmentId): int
{
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('User not authenticated.');
    }
    $userId = (int)$_SESSION['user_id'];

    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users_department WHERE User_id = ? AND Department_id = ?");
    $stmt->execute([$userId, $departmentId]);
    if ($stmt->fetchColumn() == 0) {
        throw new Exception('User does not have access to this department.');
    }
    return $userId;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendResponse(false, 'Invalid request method.', 405);
    }
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        sendResponse(false, 'Invalid CSRF token.', 403);
    }

    $departmentId = filter_input(INPUT_POST, 'department_id', FILTER_VALIDATE_INT);
    if (!$departmentId || $departmentId <= 0) {
        sendResponse(false, 'Invalid department ID.', 400);
    }

    $userId = validateUserSessionAndDepartment($departmentId);

    global $pdo;

    // Fetch the most recent storage location from files.Meta_data for the department
    $stmt = $pdo->prepare("
        SELECT f.Meta_data
        FROM files f
        JOIN transaction t ON f.File_id = t.File_id
        JOIN users_department ud ON t.Users_Department_id = ud.Users_Department_id
        WHERE ud.Department_id = ? AND f.Copy_type = 'hard' AND f.File_status != 'deleted'
        ORDER BY f.Upload_date DESC
        LIMIT 1
    ");
    $stmt->execute([$departmentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row && !empty($row['Meta_data'])) {
        $metadata = json_decode($row['Meta_data'], true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $suggestion = [
                'cabinet' => $metadata['cabinet'] ?? 'Cabinet-' . $departmentId,
                'layer' => ($metadata['layer'] ?? 0) + 1, // Increment layer for next file
                'box' => $metadata['box'] ?? 1,
                'folder' => $metadata['folder'] ?? 1
            ];
        } else {
            $suggestion = [
                'cabinet' => 'Cabinet-' . $departmentId,
                'layer' => 1,
                'box' => 1,
                'folder' => 1
            ];
        }
    } else {
        // Fallback suggestion
        $suggestion = [
            'cabinet' => 'Cabinet-' . $departmentId,
            'layer' => 1,
            'box' => 1,
            'folder' => 1
        ];
    }

    // Log the suggestion request
    $stmt = $pdo->prepare("
        INSERT INTO transaction (User_id, Transaction_status, Transaction_type, Time, Massage)
        VALUES (?, 'completed', 16, NOW(), ?)
    ");
    $stmt->execute([$userId, "Suggested storage for department ID $departmentId: " . json_encode($suggestion)]);

    sendResponse(true, $suggestion, 200);
} catch (Exception $e) {
    error_log("Error in get_storage_suggestions.php: " . $e->getMessage());
    sendResponse(false, 'Server error: ' . $e->getMessage(), 500);
}
