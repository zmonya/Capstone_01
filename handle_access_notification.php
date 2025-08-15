<?php
session_start();
require 'db_connection.php';
require 'vendor/autoload.php'; // Load Composer autoloader for phpdotenv

use Dotenv\Dotenv;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

/**
 * Sends a notification for access request actions or results using the transactions table.
 *
 * @param int $userId The user to notify
 * @param string $message The notification message
 * @param int|null $fileId The associated file ID (optional)
 * @param string $type The notification type ('access_request' or 'access_result')
 * @return bool Success status of the notification insertion
 */
function sendAccessNotification(int $userId, string $message, ?int $fileId, string $type): bool
{
    global $pdo;
    try {
        $description = ($type === 'access_request') ? 'pending' : 'completed';
        $stmt = $pdo->prepare("
            INSERT INTO transactions (user_id, file_id, transaction_type, transaction_time, description)
            VALUES (?, ?, 'notification', NOW(), ?)
        ");
        return $stmt->execute([$userId, $fileId, $message]);
    } catch (PDOException $e) {
        error_log("Database error in sendAccessNotification: " . $e->getMessage());
        return false;
    }
}
