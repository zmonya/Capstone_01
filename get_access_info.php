<?php
session_start();
require 'db_connection.php';
require 'vendor/autoload.php'; // Load Composer autoloader for phpdotenv

use Dotenv\Dotenv;

// Load environment variables for consistency with other files
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

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
 * Validates user session.
 *
 * @return int User ID
 * @throws Exception If user is not authenticated
 */
function validateUserSession(): int
{
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Unauthorized');
    }
    return (int)$_SESSION['user_id'];
}

try {
    $userId = validateUserSession();
    $fileId = filter_input(INPUT_GET, 'file_id', FILTER_VALIDATE_INT);
    if (!$fileId || $fileId <= 0) {
        sendResponse(false, 'Invalid file ID', 400);
    }

    global $pdo;

    // Authorization check: user must be the uploader or in the same department
    $authQuery = "
        SELECT COUNT(*) 
        FROM files f
        LEFT JOIN users_department ud ON f.User_id = ud.User_id
        WHERE f.File_id = ? 
        AND (f.User_id = ? OR ud.Department_id IN (
            SELECT Department_id 
            FROM users_department 
            WHERE User_id = ?
        ))
    ";
    $authStmt = $pdo->prepare($authQuery);
    $authStmt->execute([$fileId, $userId, $userId]);
    if ($authStmt->fetchColumn() == 0) {
        sendResponse(false, 'You do not have permission to view access info for this file', 403);
    }

    // Fetch original owner (uploader)
    $ownerStmt = $pdo->prepare("
        SELECT u.Username AS full_name, u.Role
        FROM files f
        JOIN users u ON f.User_id = u.User_id
        WHERE f.File_id = ?
    ");
    $ownerStmt->execute([$fileId]);
    $owner = $ownerStmt->fetch(PDO::FETCH_ASSOC);

    // Fetch users with access through transfers (Transaction_type = 2, status = 'accepted')
    $transferStmt = $pdo->prepare("
        SELECT DISTINCT u.Username AS full_name, u.Role
        FROM transaction t
        JOIN users u ON t.User_id = u.User_id
        WHERE t.File_id = ? AND t.Transaction_type = 2 AND t.Transaction_status = 'accepted'
    ");
    $transferStmt->execute([$fileId]);
    $transferUsers = $transferStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch co-owners (Transaction_type = 4)
    $coOwnerStmt = $pdo->prepare("
        SELECT DISTINCT u.Username AS full_name, u.Role
        FROM transaction t
        JOIN users u ON t.User_id = u.User_id
        WHERE t.File_id = ? AND t.Transaction_type = 4
    ");
    $coOwnerStmt->execute([$fileId]);
    $coOwners = $coOwnerStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch users with approved access requests (Transaction_type = 5, status = 'approved')
    $accessRequestStmt = $pdo->prepare("
        SELECT DISTINCT u.Username AS full_name, u.Role
        FROM transaction t
        JOIN users u ON t.User_id = u.User_id
        WHERE t.File_id = ? AND t.Transaction_type = 5 AND t.Transaction_status = 'approved'
    ");
    $accessRequestStmt->execute([$fileId]);
    $accessRequestUsers = $accessRequestStmt->fetchAll(PDO::FETCH_ASSOC);

    // Combine users and remove duplicates
    $allUsers = array_merge(
        $owner ? [$owner] : [],
        $transferUsers,
        $coOwners,
        $accessRequestUsers
    );
    $uniqueUsers = [];
    $seen = [];
    foreach ($allUsers as $user) {
        $key = $user['full_name'] . '|' . $user['role'];
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $uniqueUsers[] = $user;
        }
    }

    // Sub-department restriction not supported (no sub_departments table)
    $response = [
        'users' => $uniqueUsers,
        'sub_department_name' => null // No sub_departments table in erd01.sql
    ];

    // Log access info request in transaction table
    $logStmt = $pdo->prepare("
        INSERT INTO transaction (User_id, File_id, Transaction_status, Transaction_type, Time, Massage)
        VALUES (?, ?, 'completed', 6, NOW(), 'Viewed access info for file')
    ");
    $logStmt->execute([$userId, $fileId]);

    sendResponse(true, $response, 200);
} catch (Exception $e) {
    error_log("Error in get_access_info.php: " . $e->getMessage());
    sendResponse(false, 'Server error: ' . $e->getMessage(), 500);
}
