<?php
session_start();
require 'db_connection.php';
require 'notification.php'; // For notyf error handling, if needed

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

// Validate session
if (!isset($_SESSION['user_id'])) {
    sendJsonResponse(false, 'User not authenticated.', [], 401);
}

// Validate CSRF token
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    sendJsonResponse(false, 'Invalid CSRF token.', [], 403);
}

// Validate department_id
$departmentId = isset($_POST['department_id']) ? filter_var($_POST['department_id'], FILTER_VALIDATE_INT) : null;
if ($departmentId === false || $departmentId <= 0) {
    sendJsonResponse(false, 'Invalid department ID.', [], 400);
}

try {
    global $pdo;
    // Fetch files associated with the department via transactions
    $stmt = $pdo->prepare("
        SELECT DISTINCT f.file_id, f.file_name, dt.type_name AS document_type, f.upload_date
        FROM files f
        JOIN transactions t ON f.file_id = t.file_id
        LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
        WHERE t.users_department_id IN (
            SELECT users_department_id 
            FROM users_department 
            WHERE department_id = ? AND user_id = ?
        )
        AND t.transaction_type = '2'
        AND t.transaction_status = 'accepted'
        AND f.file_status != 'deleted'
        ORDER BY f.upload_date DESC
    ");
    $stmt->execute([$departmentId, $_SESSION['user_id']]);
    $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

    sendJsonResponse(true, 'Files fetched successfully.', ['data' => $files], 200);
} catch (PDOException $e) {
    error_log("Error fetching department files: " . $e->getMessage());
    sendJsonResponse(false, 'Failed to fetch department files.', [], 500);
}
