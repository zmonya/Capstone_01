<?php
require 'db_connection.php';

/**
 * Maps action types to integer values for transaction table.
 *
 * @param string $action
 * @return int
 */
function getTransactionType(string $action): int
{
    $types = [
        'upload' => 1,
        'send' => 2,
        'notification' => 3,
        'request' => 10,
        'approve' => 11,
        'edit' => 13,
        'delete' => 14,
        'add' => 15,
        'other' => 4 // Default for non-user-initiated actions
    ];
    return $types[strtolower($action)] ?? 4;
}

/**
 * Logs an activity in the transaction table.
 *
 * @param int $userId The ID of the user performing the action.
 * @param string $action The action being logged (e.g., "Uploaded file: filename").
 * @param int|null $fileId The ID of the file (if applicable).
 * @param int|null $departmentId The ID of the department (if applicable).
 * @param int|null $usersDepartmentId The ID of the user's department affiliation.
 * @param string|null $transactionType Optional explicit transaction type.
 * @throws Exception If logging fails.
 */
function logActivity(int $userId, string $action, ?int $fileId = null, ?int $departmentId = null, ?int $usersDepartmentId = null, ?string $transactionType = null): void
{
    global $pdo;

    // Use provided transaction type or infer from action
    $type = $transactionType ? getTransactionType($transactionType) : getTransactionType($action);

    $stmt = $pdo->prepare("
        INSERT INTO transaction (User_id, Users_Department_id, File_id, Transaction_status, Transaction_type, Time, Massage)
        VALUES (?, ?, ?, 'completed', ?, NOW(), ?)
    ");
    $stmt->execute([$userId, $usersDepartmentId, $fileId, $type, $action]);
}

/**
 * Logs file-related activities, including sending files to departments.
 *
 * @param int $userId The ID of the user performing the action.
 * @param string $fileName The name of the file.
 * @param int $fileId The ID of the file.
 * @param array $departmentIds An array of department IDs the file was sent to.
 * @throws Exception If logging fails.
 */
function logFileActivity(int $userId, string $fileName, int $fileId, array $departmentIds = []): void
{
    global $pdo;

    // Get user's department affiliation
    $stmt = $pdo->prepare("SELECT Users_Department_id FROM users_department WHERE User_id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $usersDepartmentId = $stmt->fetchColumn() ?: null;

    if (empty($departmentIds)) {
        logActivity($userId, "Uploaded file: $fileName", $fileId, null, $usersDepartmentId, 'upload');
    } else {
        $placeholders = implode(',', array_fill(0, count($departmentIds), '?'));
        $stmt = $pdo->prepare("SELECT Department_id, Department_name FROM departments WHERE Department_id IN ($placeholders)");
        $stmt->execute($departmentIds);
        $departments = $stmt->fetchAll();

        $departmentMap = array_column($departments, 'Department_name', 'Department_id');
        foreach ($departmentIds as $departmentId) {
            $departmentName = $departmentMap[$departmentId] ?? 'Unknown Department';
            logActivity($userId, "Sent file: $fileName to department: $departmentName", $fileId, $departmentId, $usersDepartmentId, 'send');
        }
    }
}
