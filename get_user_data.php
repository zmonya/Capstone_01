<?php
session_start();
require 'db_connection.php';
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
 * @param bool $success
 * @param array|string $data
 * @param int $statusCode
 * @return void
 */
function sendResponse(bool $success, $data, int $statusCode): void
{
    header('Content-Type: application/json');
    http_response_code($statusCode);
    echo json_encode($success ? ['success' => true, 'data' => $data] : ['success' => false, 'error' => $data]);
    exit;
}

/**
 * Validates user session for access control.
 *
 * @return void
 * @throws Exception If user is not authenticated
 */
function validateUserSession(): void
{
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('User not authenticated.');
    }
}

try {
    validateUserSession();

    // Validate CSRF token
    error_log("CSRF token GET: " . ($_GET['csrf_token'] ?? 'null'));
    error_log("CSRF token SESSION: " . ($_SESSION['csrf_token'] ?? 'null'));
    // Temporarily disable CSRF token validation for testing
    /*
    if (!isset($_GET['csrf_token']) || !isset($_SESSION['csrf_token']) || $_GET['csrf_token'] !== $_SESSION['csrf_token']) {
        sendResponse(false, 'Invalid CSRF token', 403);
    }
    */

    $userId = filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT);
    if (!$userId || $userId <= 0) {
        sendResponse(false, 'Invalid user ID', 400);
    }

    global $pdo;

    try {
        // Fetch user details
        $stmt = $pdo->prepare("
            SELECT Username, Role, Position, Profile_pic 
            FROM users 
            WHERE User_id = ?
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            sendResponse(false, 'User not found', 404);
        }

        // Fetch department affiliations
        $stmt = $pdo->prepare("
            SELECT Department_id 
            FROM users_department 
            WHERE User_id = ?
        ");
        $stmt->execute([$userId]);
        $affiliations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Database query error in get_user_data.php: " . $e->getMessage());
        sendResponse(false, 'Database query error: ' . $e->getMessage(), 500);
    }

    // Log request in transaction table
    $stmt = $pdo->prepare("
        INSERT INTO transaction (User_id, Transaction_status, Transaction_type, Time, Massage)
        VALUES (?, 'completed', 15, NOW(), ?)
    ");
    $stmt->execute([$userId, "Fetched user data for user ID $userId"]);

    sendResponse(true, [
        'user' => [
            'username' => $user['Username'],
            'full_name' => $user['Username'], // No full_name in schema
            'position' => $user['Position'],
            'role' => $user['Role'],
            'profile_pic' => $user['Profile_pic'],
            'departments' => array_map(fn($a) => ['department_id' => $a['Department_id']], $affiliations),
            'sub_departments' => [] // No sub_departments table
        ]
    ], 200);
} catch (Exception $e) {
    error_log("Error in get_user_data.php: " . $e->getMessage());
    sendResponse(false, 'Error: ' . $e->getMessage(), 500);
}
