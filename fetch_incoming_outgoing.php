<?php
session_start();
require 'db_connection.php';
require 'log_activity.php';
require 'vendor/autoload.php'; // Load Composer autoloader for phpdotenv

use Dotenv\Dotenv;

// Load environment variables (if needed)
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Disable error display in production; log instead
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('error_log', __DIR__ . '/logs/error_log.log');
error_reporting(E_ALL);

/**
 * Sends JSON response and exits.
 *
 * @param array $data
 * @param int $statusCode
 */
function sendResponse(array $data, int $statusCode = 200): void
{
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

/**
 * Validate the user's session and CSRF token.
 *
 * @param PDO $pdo
 * @return array ['user_id'=>int,'users_department_id'=>int]
 * @throws Exception
 */
function validateUserSession(PDO $pdo): array
{
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('User not logged in.', 401);
    }

    // CSRF protection using header only
    $csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    $expected = $_SESSION['csrf_token'] ?? null;

    if (!$expected || $csrfHeader !== $expected) {
        throw new Exception('Invalid CSRF token.', 403);
    }

    $userId = (int)$_SESSION['user_id'];

    // Fetch at least one users_department_id
    $stmt = $pdo->prepare("SELECT users_department_id FROM users_department WHERE user_id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $usersDepartmentId = $stmt->fetchColumn();

    if ($usersDepartmentId === false) {
        throw new Exception('User is not associated with any department.', 403);
    }

    return ['user_id' => $userId, 'users_department_id' => (int)$usersDepartmentId];
}

/**
 * Validates & sanitizes input parameters.
 *
 * @param string $interval
 * @param string|null $startDate
 * @param string|null $endDate
 * @return array
 * @throws Exception
 */
function validateInput(string $interval, ?string $startDate, ?string $endDate): array
{
    $validIntervals = ['day', 'week', 'month', 'range'];
    if (!in_array($interval, $validIntervals, true)) {
        throw new Exception('Invalid interval specified.', 400);
    }

    if ($interval === 'range') {
        if (empty($startDate) || empty($endDate)) {
            throw new Exception('Start and end dates are required for custom range.', 400);
        }
        $s = DateTime::createFromFormat('Y-m-d', $startDate);
        $e = DateTime::createFromFormat('Y-m-d', $endDate);
        if (!$s || !$e) {
            throw new Exception('Invalid date format. Use YYYY-MM-DD.', 400);
        }
        if ($e < $s) {
            throw new Exception('End date cannot be earlier than start date.', 400);
        }
    }

    return [
        'interval' => $interval,
        'startDate' => $startDate,
        'endDate' => $endDate
    ];
}
/**
 * Builds a placeholder map for an array of ids for safe binding in PDO.
 *
 * @param array $items
 * @param string $prefix
 * @return array [placeholdersString, assocParamsArray]
 */
function buildNamedPlaceholders(array $items, string $prefix = 'p'): array
{
    if (empty($items)) {
        $items = [-1];
    }

    $placeholders = [];
    $params = [];
    foreach (array_values($items) as $i => $v) {
        $key = ':' . $prefix . $i;
        $placeholders[] = $key;
        $params[$key] = (int)$v;
    }

    return [implode(',', $placeholders), $params];
}

/**
 * Generates mock data for ADMIN user
 *
 * @param string $interval
 * @param string|null $startDate
 * @param string|null $endDate
 * @return array
 */
function generateMockData(string $interval, ?string $startDate, ?string $endDate): array
{
    $labels = [];
    $filesSent = [];
    $filesReceived = [];
    $filesRequested = [];
    $filesReceivedFromRequest = [];
    $tableData = [];

    // Define date ranges based on interval
    $now = new DateTime('2025-08-13'); // Updated to match current date
    if ($interval === 'day') {
        for ($i = 0; $i < 24; $i++) {
            $labels[] = sprintf("%02d:00", $i);
            $filesSent[] = rand(0, 5);
            $filesReceived[] = rand(0, 4);
            $filesRequested[] = rand(0, 3);
            $filesReceivedFromRequest[] = rand(0, 2);
        }
    } elseif ($interval === 'week') {
        for ($i = 6; $i >= 0; $i--) {
            $date = (clone $now)->modify("-$i days");
            $labels[] = $date->format('Y-m-d');
            $filesSent[] = rand(5, 20);
            $filesReceived[] = rand(5, 15);
            $filesRequested[] = rand(2, 10);
            $filesReceivedFromRequest[] = rand(1, 8);
        }
    } elseif ($interval === 'month') {
        for ($i = 29; $i >= 0; $i--) {
            $date = (clone $now)->modify("-$i days");
            $labels[] = $date->format('Y-m-d');
            $filesSent[] = rand(10, 30);
            $filesReceived[] = rand(8, 25);
            $filesRequested[] = rand(5, 15);
            $filesReceivedFromRequest[] = rand(3, 12);
        }
    } elseif ($interval === 'range') {
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);
        $intervalObj = new DateInterval('P1D');
        $period = new DatePeriod($start, $intervalObj, $end->modify('+1 day'));
        foreach ($period as $date) {
            $labels[] = $date->format('Y-m-d');
            $filesSent[] = rand(5, 25);
            $filesReceived[] = rand(5, 20);
            $filesRequested[] = rand(2, 10);
            $filesReceivedFromRequest[] = rand(1, 8);
        }
    }

    // Mock table data (aligned with new database schema)
    $documentTypes = [
        1 => 'Memorandum',
        2 => 'Letter',
        3 => 'Notice',
        4 => 'Announcement',
        5 => 'Invitation',
        6 => 'Sample Type'
    ];
    $departments = [
        1 => 'College of Education',
        2 => 'College of Arts and Sciences',
        3 => 'College of Engineering and Technology',
        29 => 'Management Information Systems',
        30 => 'Office of the President'
    ];
    $directions = ['Sent', 'Received']; // Simplified to match new schema
    $start = $interval === 'range' ? new DateTime($startDate) : (clone $now)->modify('-30 days');
    $end = $interval === 'range' ? new DateTime($endDate) : $now;

    for ($i = 0; $i < 10; $i++) {
        $date = (clone $start)->modify('+' . rand(0, (int)$start->diff($end)->days) . ' days');
        $tableData[] = [
            'file_id' => rand(14, 34), // Valid file_id range from schema
            'file_name' => "Document_" . rand(1000, 9999) . ".pdf",
            'document_type' => $documentTypes[rand(1, 6)],
            'upload_date' => $date->format('Y-m-d H:i:s'),
            'department_name' => $departments[array_rand($departments)],
            'uploader' => 'ADMIN',
            'direction' => $directions[array_rand($directions)]
        ];
    }

    return [
        'labels' => $labels,
        'datasets' => [
            'files_sent' => $filesSent,
            'files_received' => $filesReceived,
            'files_requested' => $filesRequested, // Kept for compatibility, but could be removed
            'files_received_from_request' => $filesReceivedFromRequest // Kept for compatibility
        ],
        'tableData' => $tableData
    ];
}

// Main logic (replacing the try-catch block after session validation)
try {
    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new Exception('Database connection not available.', 500);
    }

    $sessionData = validateUserSession($pdo);
    $userId = $sessionData['user_id'];
    $usersDepartmentId = $sessionData['users_department_id'];

    // Sanitize inputs
    $interval = filter_input(INPUT_GET, 'interval', FILTER_SANITIZE_STRING) ?: 'day';
    $startDate = filter_input(INPUT_GET, 'startDate', FILTER_SANITIZE_STRING) ?: null;
    $endDate = filter_input(INPUT_GET, 'endDate', FILTER_SANITIZE_STRING) ?: null;

    $validated = validateInput($interval, $startDate, $endDate);

    // For ADMIN (user_id=14), return mock data for demonstration
    if ($userId === 14) {
        $mockData = generateMockData($validated['interval'], $validated['startDate'], $validated['endDate']);
        error_log(sprintf(
            "[%s] User %d fetched mock incoming/outgoing report (interval=%s%s)",
            date('Y-m-d H:i:s'),
            $userId,
            $validated['interval'],
            ($validated['interval'] === 'range' ? " {$validated['startDate']} to {$validated['endDate']}" : '')
        ));
        sendResponse($mockData, 200);
    }

    // Fetch all departments for this user
    $stmt = $pdo->prepare("SELECT users_department_id, department_id FROM users_department WHERE user_id = ?");
    $stmt->execute([$userId]);
    $userDepartments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $usersDepartmentIds = array_column($userDepartments, 'users_department_id');
    [$udPlaceholders, $udParams] = buildNamedPlaceholders($usersDepartmentIds, 'ud');

    // Chart Query
    $groupBy = match ($validated['interval']) {
        'day' => "DATE_FORMAT(t.transaction_time, '%Y-%m-%d %H:00:00')",
        'week', 'month' => "DATE(t.transaction_time)",
        'range' => "DATE(t.transaction_time)",
        default => "DATE(t.transaction_time)"
    };

    $chartQuery = "
        SELECT 
            {$groupBy} AS period,
            SUM(CASE WHEN t.transaction_type = 'upload' AND t.user_id = :userId THEN 1 ELSE 0 END) AS files_sent,
            SUM(CASE WHEN t.transaction_type = 'upload' AND t.transaction_status = 'completed' AND t.users_department_id IN ({$udPlaceholders}) THEN 1 ELSE 0 END) AS files_received,
            SUM(CASE WHEN t.transaction_type = 'upload' AND t.user_id = :userId THEN 1 ELSE 0 END) AS files_requested,
            SUM(CASE WHEN t.transaction_type = 'upload' AND t.transaction_status = 'completed' AND t.user_id = :userId THEN 1 ELSE 0 END) AS files_received_from_request
        FROM transactions t
        WHERE t.transaction_type = 'upload'
          AND (t.user_id = :userId OR t.users_department_id IN ({$udPlaceholders}))
    ";

    $chartParams = array_merge([':userId' => $userId], $udParams);

    if ($validated['interval'] === 'range') {
        $chartQuery .= " AND t.transaction_time BETWEEN :startDate AND :endDate";
        $chartParams[':startDate'] = $validated['startDate'] . ' 00:00:00';
        $chartParams[':endDate'] = $validated['endDate'] . ' 23:59:59';
    }

    $chartQuery .= " GROUP BY period ORDER BY period ASC";

    $stmt = $pdo->prepare($chartQuery);
    $stmt->execute($chartParams);
    $chartResults = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Table Query
    [$udPlaceholders2, $udParams2] = buildNamedPlaceholders($usersDepartmentIds, 'ud2');

    $tableQuery = "
        SELECT DISTINCT
            f.file_id AS file_id,
            COALESCE(f.file_name, 'Unnamed File') AS file_name,
            COALESCE(dt.type_name, 'Unknown Type') AS document_type,
            t.transaction_time AS event_date,
            COALESCE(d.department_name, 'No Department') AS department_name,
            COALESCE(u.username, 'Unknown User') AS uploader,
            CASE 
                WHEN t.transaction_type = 'upload' AND t.user_id = :userId THEN 'Sent'
                WHEN t.transaction_type = 'upload' AND t.transaction_status = 'completed' AND t.users_department_id IN ({$udPlaceholders2}) THEN 'Received'
                ELSE 'Unknown'
            END AS direction
        FROM files f
        LEFT JOIN transactions t ON f.file_id = t.file_id
        LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
        LEFT JOIN users_department ud ON t.users_department_id = ud.users_department_id
        LEFT JOIN departments d ON ud.department_id = d.department_id
        LEFT JOIN users u ON f.user_id = u.user_id
        WHERE t.transaction_type = 'upload'
          AND (f.file_status IS NULL OR f.file_status != 'disposed')
          AND (t.user_id = :userId OR t.users_department_id IN ({$udPlaceholders2}))
    ";

    $tableParams = array_merge([':userId' => $userId], $udParams2);

    if ($validated['interval'] === 'range') {
        $tableQuery .= " AND t.transaction_time BETWEEN :startDate AND :endDate";
        $tableParams[':startDate'] = $validated['startDate'] . ' 00:00:00';
        $tableParams[':endDate'] = $validated['endDate'] . ' 23:59:59';
    }

    $tableQuery .= " ORDER BY event_date ASC";

    $stmt = $pdo->prepare($tableQuery);
    $stmt->execute($tableParams);
    $filesResults = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Log access without file_id
    error_log(sprintf(
        "[%s] User %d fetched incoming/outgoing report (interval=%s%s)",
        date('Y-m-d H:i:s'),
        $userId,
        $validated['interval'],
        ($validated['interval'] === 'range' ? " {$validated['startDate']} to {$validated['endDate']}" : '')
    ));

    // Prepare chart data response
    $labels = array_map(fn($r) => $r['period'] ?? '', $chartResults);
    $datasets = [
        'files_sent' => array_map(fn($r) => (int)($r['files_sent'] ?? 0), $chartResults),
        'files_received' => array_map(fn($r) => (int)($r['files_received'] ?? 0), $chartResults),
        'files_requested' => array_map(fn($r) => (int)($r['files_requested'] ?? 0), $chartResults),
        'files_received_from_request' => array_map(fn($r) => (int)($r['files_received_from_request'] ?? 0), $chartResults)
    ];

    // Sanitize table data
    $tableData = array_map(function ($row) {
        $row['upload_date'] = $row['event_date'] ?? null;
        unset($row['event_date']);
        return $row;
    }, $filesResults);

    sendResponse([
        'labels' => $labels,
        'datasets' => $datasets,
        'tableData' => $tableData
    ], 200);
} catch (Exception $e) {
    error_log("Error in fetch_incoming_outgoing.php: " . $e->getMessage());
    sendResponse(['error' => 'Server error: ' . $e->getMessage()], $e->getCode() && $e->getCode() >= 400 ? $e->getCode() : 500);
}
