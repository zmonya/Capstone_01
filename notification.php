<?php
require 'db_connection.php';

/**
 * Sends a notification to a user by logging it in the transactions table.
 *
 * @param int $userId The ID of the user to notify.
 * @param string $message The notification message.
 * @param int|null $fileId The ID of the file (if applicable).
 * @param string $type The type of notification (e.g., 'received', 'uploaded').
 * @throws Exception If notification logging fails.
 */
function sendNotification(int $userId, string $message, ?int $fileId = null, string $type = 'uploaded'): void
{
    global $pdo;

    $stmt = $pdo->prepare("
        INSERT INTO transactions (user_id, file_id, transaction_type, transaction_time, description)
        VALUES (?, ?, ?, NOW(), ?)
    ");
    $stmt->execute([$userId, $fileId, 'notification', $message]);
}

/**
 * Sends a notification to all users in a department.
 *
 * @param int $departmentId The ID of the department.
 * @param string $message The notification message.
 * @param int|null $fileId The ID of the file (if applicable).
 * @param string $type The type of notification (e.g., 'received', 'uploaded').
 * @throws Exception If notification logging fails.
 */
function sendNotificationToDepartment(int $departmentId, string $message, ?int $fileId = null, string $type = 'uploaded'): void
{
    global $pdo;

    $stmt = $pdo->prepare("SELECT user_id FROM users_department WHERE department_id = ?");
    $stmt->execute([$departmentId]);
    $departmentUsers = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($departmentUsers as $userId) {
        sendNotification($userId, $message, $fileId, $type);
    }
}
