<?php
require 'db_connection.php';

/**
 * Maps action types to string values for transactions table.
 *
 * @param string $action
 * @return string
 */
function getTransactionType(string $action): string
{
    $types = [
        'upload' => 'file_upload',
        'send' => 'file_sent',
        'notification' => 'notification',
        'request' => 'file_request',
        'approve' => 'file_approve',
        'edit' => 'file_edit',
        'delete' => 'file_delete',
        'add' => 'add',
        'other' => 'other',
        'fetch_status' => 'fetch_status',
        'co-ownership' => 'co-ownership'
    ];
    return $types[strtolower($action)] ?? 'other';
}

/**
 * Logs an activity in the transactions table.
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
        INSERT INTO transactions (user_id, users_department_id, file_id, transaction_type, transaction_time, description)
        VALUES (?, ?, ?, ?, NOW(), ?)
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
    $stmt = $pdo->prepare("SELECT users_department_id FROM users_department WHERE user_id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $usersDepartmentId = $stmt->fetchColumn() ?: null;

    if (empty($departmentIds)) {
        logActivity($userId, "Uploaded file: $fileName", $fileId, null, $usersDepartmentId, 'file_upload');
    } else {
        $placeholders = implode(',', array_fill(0, count($departmentIds), '?'));
        $stmt = $pdo->prepare("SELECT department_id, department_name FROM departments WHERE department_id IN ($placeholders)");
        $stmt->execute($departmentIds);
        $departments = $stmt->fetchAll();

        $departmentMap = array_column($departments, 'department_name', 'department_id');
        foreach ($departmentIds as $departmentId) {
            $departmentName = $departmentMap[$departmentId] ?? 'Unknown Department';
            logActivity($userId, "Sent file: $fileName to department: $departmentName", $fileId, $departmentId, $usersDepartmentId, 'file_sent');
        }
    }
}
