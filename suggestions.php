<?php
session_start();
require 'db_connection.php';
require 'log_activity.php';
require 'vendor/autoload.php'; // Load Composer autoloader for phpdotenv

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
 * Generates a JSON response with appropriate HTTP status.
 *
 * @param array $data
 * @param int $statusCode
 * @return void
 */
function sendResponse(array $data, int $statusCode): void
{
    header('Content-Type: application/json');
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

/**
 * Validates user session.
 *
 * @return int User ID
 * @throws Exception If user is not authenticated
 */
function validateUserSession(): int
{
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('User not authenticated.');
    }
    return (int)$_SESSION['user_id'];
}

try {
    $userId = validateUserSession();
    $query = filter_input(INPUT_GET, 'term', FILTER_SANITIZE_STRING) ?? '';
    if (empty($query)) {
        sendResponse([], 200);
    }

    global $pdo;

    // Fetch user departments for authorization
    $stmt = $pdo->prepare("SELECT Department_id FROM users_department WHERE User_id = ?");
    $stmt->execute([$userId]);
    $userDepartments = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Query suggestions
    $sql = "
        SELECT f.File_name AS label, dtf.Field_name AS document_type
        FROM files f
        LEFT JOIN documents_type_fields dtf ON f.Document_type_id = dtf.Document_type_id
        LEFT JOIN users_department ud ON f.User_id = ud.User_id
        WHERE f.File_name LIKE ?
        AND f.File_status != 'deleted'
        AND (f.User_id = ? OR ud.Department_id IN (" . implode(',', array_fill(0, count($userDepartments), '?')) . "))
        LIMIT 10
    ";
    $params = array_merge(["%$query%", $userId], $userDepartments);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $suggestions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Log request in transaction table
    $stmt = $pdo->prepare("
        INSERT INTO transaction (User_id, Transaction_status, Transaction_type, Time, Massage)
        VALUES (?, 'completed', 23, NOW(), ?)
    ");
    $stmt->execute([$userId, "Fetched suggestions for query: $query"]);

    sendResponse($suggestions, 200);
} catch (Exception $e) {
    error_log("Error in suggestions.php: " . $e->getMessage());
    sendResponse(['error' => 'Server error: ' . $e->getMessage()], 500);
}
