<?php
session_start();
require 'db_connection.php';
require 'log_activity.php';
require 'vendor/autoload.php'; // Load Composer autoload (phpdotenv if used)

use Dotenv\Dotenv;

// Load environment variables safely
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://code.jquery.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; style-src 'self' https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src https://fonts.gstatic.com;");

// Disable error display in production; log errors instead
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('error_log', __DIR__ . '/logs/error_log.log');
error_reporting(E_ALL);

/**
 * Sends a JSON response for internal error handling.
 *
 * @param bool $success
 * @param string $message
 * @param array $data
 * @param int $statusCode
 */
function sendJsonResponse(bool $success, string $message, array $data = [], int $statusCode = 500): void
{
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code($statusCode);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}

/**
 * Validate session and return user info
 *
 * @return array ['user_id'=>int, 'role'=>string]
 * @throws Exception
 */
function validateSession(): array
{
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        throw new Exception('Unauthorized access: Please log in.', 401);
    }
    // Regenerate session ID on sensitive pages
    session_regenerate_id(true);
    return ['user_id' => (int)$_SESSION['user_id'], 'role' => (string)$_SESSION['role']];
}

/**
 * Fetch user's department mappings
 *
 * @param PDO $pdo
 * @param int $userId
 * @return array
 */
function fetchUserDepartments(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare("
        SELECT d.department_id AS id, d.department_name AS name, ud.users_department_id
        FROM departments d
        JOIN users_department ud ON d.department_id = ud.department_id
        WHERE ud.user_id = ?
        ORDER BY d.department_name
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fetch aggregated user details (username + department names)
 *
 * @param PDO $pdo
 * @param int $userId
 * @return array
 */
function fetchUserDetails(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare("
        SELECT u.username AS full_name, GROUP_CONCAT(d.department_name SEPARATOR ', ') AS department_names
        FROM users u
        LEFT JOIN users_department ud ON u.user_id = ud.user_id
        LEFT JOIN departments d ON ud.department_id = d.department_id
        WHERE u.user_id = ?
        GROUP BY u.user_id
    ");
    $stmt->execute([$userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Fetch document copy details (documents that the user is involved with via send/accept)
 *
 * @param PDO $pdo
 * @param int $userId
 * @return array
 */
function fetchDocumentCopyDetails(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare("
        SELECT f.file_id, f.file_name, f.copy_type, f.physical_storage,
               GROUP_CONCAT(DISTINCT d.department_name SEPARATOR ', ') AS departments_with_copy
        FROM files f
        LEFT JOIN transactions t ON f.file_id = t.file_id
        LEFT JOIN users_department ud ON t.users_department_id = ud.users_department_id
        LEFT JOIN departments d ON ud.department_id = d.department_id
        WHERE t.user_id = ? AND t.transaction_type IN ('send', 'accept')
        GROUP BY f.file_id, f.file_name, f.copy_type, f.physical_storage
        ORDER BY f.file_name
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

try {
    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new Exception('Database connection not available.', 500);
    }

    $session = validateSession();
    $userId = $session['user_id'];
    $userRole = $session['role'];

    // Generate CSRF token if missing
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $csrfToken = $_SESSION['csrf_token'];

    // Fetch data for the page
    $userDepartments = fetchUserDepartments($pdo, $userId);
    $user = fetchUserDetails($pdo, $userId);
    $documentCopies = fetchDocumentCopyDetails($pdo, $userId);

    if (empty($user)) {
        throw new Exception("User not found for ID {$userId}.", 404);
    }

    // Determine a users_department_id to use for actions (if any exist)
    $usersDepartmentId = !empty($userDepartments) ? $userDepartments[0]['users_department_id'] : null;

    // Log page access
    error_log(sprintf(
        "[%s] User %d (%s) accessed My Report page (users_department_id=%s)",
        date('Y-m-d H:i:s'),
        $userId,
        $user['full_name'] ?? 'Unknown',
        $usersDepartmentId ?? 'none'
    ));
} catch (Exception $e) {
    error_log("Error in my-report.php: " . $e->getMessage());
    sendJsonResponse(false, 'Server error: ' . $e->getMessage(), [], $e->getCode() ?: 500);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Report - Document Archival</title>
    <meta name="description" content="Document archival system report for <?= htmlspecialchars($user['full_name'] ?? 'User', ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style/client-sidebar.css">
    <link rel="stylesheet" href="style/folder-page.css">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
</head>

<body>
    <div class="sidebar" id="sidebar">
        <button class="toggle-btn" id="toggleSidebar" title="Toggle Sidebar"><i class="fas fa-bars"></i></button>
        <h2>Document Archival</h2>
        <a href="my-folder.php" data-tooltip="My Folder"><i class="fas fa-folder"></i><span class="link-text">My Folder</span></a>
        <a href="my-report.php" class="active" data-tooltip="My Report"><i class="fas fa-chart-bar"></i><span class="link-text">My Report</span></a>
        <?php if ($userRole === 'admin'): ?>
            <a href="admin_dashboard.php" class="admin-dashboard-btn" data-tooltip="Admin Dashboard"><i class="fas fa-user-shield"></i><span class="link-text">Admin Dashboard</span></a>
        <?php endif; ?>
        <button class="logout-btn" onclick="location.href='logout.php'" data-tooltip="Logout"><i class="fas fa-sign-out-alt"></i><span class="link-text">Logout</span></button>
    </div>

    <div class="top-nav" id="topNav">
        <button class="toggle-btn" id="toggleNavSidebar" title="Toggle Sidebar"><i class="fas fa-bars"></i></button>
        <h2>My Report</h2>
        <button onclick="downloadChart()"><i class="fas fa-download"></i> Download PDF</button>
        <button onclick="printChart()"><i class="fas fa-print"></i> Print Report</button>
    </div>

    <div class="main-content">
        <div class="print-header" id="printHeader" style="display: none;">
            <h1>File Activity Report for <?= htmlspecialchars($user['full_name'] ?? 'User', ENT_QUOTES, 'UTF-8'); ?></h1>
            <p>Generated on <?= date('F j, Y'); ?> | Departments: <?= htmlspecialchars($user['department_names'] ?? 'None', ENT_QUOTES, 'UTF-8'); ?></p>
        </div>

        <div class="report-controls">
            <label for="interval">Time Period:</label>
            <select id="interval" onchange="updateChart()">
                <option value="day">Last 24 Hours</option>
                <option value="week">Last 7 Days</option>
                <option value="month" selected>Last 30 Days</option>
                <option value="range">Custom Range</option>
            </select>
            <div id="dateRange" style="display: none;">
                <label for="startDate">Start Date:</label>
                <input type="date" id="startDate" max="<?= date('Y-m-d'); ?>">
                <label for="endDate">End Date:</label>
                <input type="date" id="endDate" max="<?= date('Y-m-d'); ?>">
            </div>
            <label for="sortBy">Sort By:</label>
            <select id="sortBy" onchange="sortTable()">
                <option value="newest">Newest First</option>
                <option value="oldest">Oldest First</option>
            </select>
            <label for="filterDirection">Filter Direction:</label>
            <select id="filterDirection" onchange="filterTable()">
                <option value="all">All</option>
                <option value="Sent">Sent</option>
                <option value="Received">Received</option>
                <option value="Requested">Requested</option>
                <option value="Request Approved">Request Approved</option>
                <option value="Request Denied">Request Denied</option>
            </select>
        </div>

        <div class="chart-container">
            <canvas id="chartCanvas"></canvas>
        </div>

        <div class="loading-spinner" id="loadingSpinner"></div>

        <h3>File Activity</h3>
        <div class="files-table" id="filesTable">
            <table>
                <thead>
                    <tr>
                        <th>File Name</th>
                        <th>Document Type</th>
                        <th>Date</th>
                        <th>Department</th>
                        <th>Uploader</th>
                        <th>Direction</th>
                    </tr>
                </thead>
                <tbody id="fileTableBody"></tbody>
            </table>
        </div>

        <h3>Document Copies</h3>
        <div class="copies-table" id="copiesTable">
            <table>
                <thead>
                    <tr>
                        <th>File Name</th>
                        <th>Copy Type</th>
                        <th>Physical Storage</th>
                        <th>Departments with Copy</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($documentCopies as $copy): ?>
                        <tr>
                            <td><?= htmlspecialchars($copy['file_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?= htmlspecialchars($copy['copy_type'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?= htmlspecialchars($copy['physical_storage'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?= htmlspecialchars($copy['departments_with_copy'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="custom-alert" id="customAlert" style="display: none;">
        <p id="alertMessage"></p>
        <button onclick="hideAlert()">OK</button>
    </div>

    <script>
        const state = {
            chart: null,
            tableData: [],
            isLoading: false
        };

        const elements = {
            chartCanvas: document.getElementById('myChart'),
            fileTableBody: document.getElementById('fileTableBody'),
            filesTable: document.getElementById('filesTable'),
            copiesTable: document.getElementById('copiesTable'),
            printHeader: document.getElementById('printHeader'),
            departmentSelect: document.getElementById('departmentSelect'),
            intervalSelect: document.getElementById('interval'),
            startDate: document.getElementById('startDate'),
            endDate: document.getElementById('endDate'),
            loadingSpinner: document.querySelector('.loading-spinner')
        };

        const setLoadingState = (isLoading) => {
            state.isLoading = isLoading;
            elements.loadingSpinner.classList.toggle('report-loading', isLoading);
        };

        const showAlert = (message, type) => {
            const alert = document.createElement('div');
            alert.className = `custom-alert ${type}`;
            alert.innerHTML = `<p>${message}</p>`;
            document.body.appendChild(alert);
            setTimeout(() => alert.remove(), 3000);
        };

        const formatDate = (dateStr) => {
            if (!dateStr) return 'N/A';
            const date = new Date(dateStr);
            return isNaN(date) ? 'N/A' : date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        };

        const updateChart = () => {
            setLoadingState(true);
            const interval = elements.intervalSelect.value;
            const params = new URLSearchParams({
                interval,
                _csrf: '<?= $csrfToken ?>'
            });
            if (interval === 'range') {
                const startDate = elements.startDate.value;
                const endDate = elements.endDate.value;
                if (startDate && endDate) {
                    params.append('startDate', startDate);
                    params.append('endDate', endDate);
                }
            }

            fetch('fetch_incoming_outgoing.php?' + params.toString(), {
                headers: {
                    'X-CSRF-Token': '<?= $csrfToken ?>'
                }
            }).then(response => {
                if (!response.ok) throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                return response.json();
            }).then(data => {
                if (!data || !data.labels || !data.datasets) {
                    showAlert('No data available for the selected period.', 'error');
                    return;
                }

                if (state.chart) state.chart.destroy();
                state.chart = new Chart(elements.chartCanvas, {
                    type: 'line',
                    data: {
                        labels: data.labels || ['No Data'],
                        datasets: [{
                                label: 'Files Sent',
                                data: data.datasets.files_sent || [0],
                                borderColor: '#34d058',
                                backgroundColor: 'rgba(52, 208, 88, 0.2)',
                                fill: true
                            },
                            {
                                label: 'Files Received',
                                data: data.datasets.files_received || [0],
                                borderColor: '#2c3e50',
                                backgroundColor: 'rgba(44, 62, 80, 0.2)',
                                fill: true
                            },
                            {
                                label: 'Files Requested',
                                data: data.datasets.files_requested || [0],
                                borderColor: '#e74c3c',
                                backgroundColor: 'rgba(231, 76, 60, 0.2)',
                                fill: true
                            },
                            {
                                label: 'Files Received from Request',
                                data: data.datasets.files_received_from_request || [0],
                                borderColor: '#f1c40f',
                                backgroundColor: 'rgba(241, 196, 15, 0.2)',
                                fill: true
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                position: 'top'
                            },
                            title: {
                                display: true,
                                text: 'File Activity Report'
                            }
                        }
                    }
                });

                state.tableData = data.tableData || [];
                updateTable();
            }).catch(err => {
                console.error('Fetch error:', err);
                showAlert('Failed to load report data: ' + err.message, 'error');
            }).finally(() => setLoadingState(false));
        };

        const updateTable = () => {
            elements.fileTableBody.innerHTML = '';
            if (!Array.isArray(state.tableData) || state.tableData.length === 0) {
                elements.fileTableBody.innerHTML = '<tr><td colspan="6">No file activity data available.</td></tr>';
                return;
            }
            state.tableData.forEach(file => {
                const tr = document.createElement('tr');
                tr.dataset.direction = file.direction || '';
                tr.dataset.date = file.upload_date || '';
                tr.innerHTML = `
                <td>${escapeHtml(file.file_name || 'N/A')}</td>
                <td>${escapeHtml(file.document_type || 'N/A')}</td>
                <td data-date="${escapeHtml(file.upload_date || '')}">${formatDate(file.upload_date)}</td>
                <td>${escapeHtml(file.department_name || 'N/A')}</td>
                <td>${escapeHtml(file.uploader || 'N/A')}</td>
                <td>${escapeHtml(file.direction || 'N/A')}</td>
            `;
                elements.fileTableBody.appendChild(tr);
            });
            sortTable();
            filterTable();
        };

        const escapeHtml = (str) => {
            const d = document.createElement('div');
            d.textContent = String(str || '');
            return d.innerHTML;
        };

        const sortTable = () => {
            const sortBy = document.getElementById('sortBy')?.value || 'newest';
            const rows = Array.from(elements.fileTableBody.querySelectorAll('tr'));
            rows.sort((a, b) => {
                const da = new Date(a.dataset.date || '1970-01-01');
                const db = new Date(b.dataset.date || '1970-01-01');
                return sortBy === 'oldest' ? da - db : db - da;
            });
            elements.fileTableBody.innerHTML = '';
            rows.forEach(r => elements.fileTableBody.appendChild(r));
        };

        const filterTable = () => {
            const filter = document.getElementById('filterDirection')?.value || 'all';
            Array.from(elements.fileTableBody.querySelectorAll('tr')).forEach(row => {
                row.style.display = (filter === 'all' || row.dataset.direction === filter) ? '' : 'none';
            });
        };

        const downloadChart = async () => {
            if (state.isLoading) return;
            setLoadingState(true);
            try {
                if (!window.jspdf || !window.html2canvas) {
                    throw new Error('Required libraries (jsPDF or html2canvas) not loaded.');
                }
                const {
                    jsPDF
                } = window.jspdf;
                elements.printHeader.style.display = 'block';
                const headerImg = await html2canvas(elements.printHeader, {
                    scale: 2
                });
                const chartImg = await html2canvas(elements.chartCanvas, {
                    scale: 2
                });
                const fileTableImg = await html2canvas(elements.filesTable, {
                    scale: 2
                });
                const copiesTableImg = await html2canvas(elements.copiesTable, {
                    scale: 2
                });
                elements.printHeader.style.display = 'none';

                const pdf = new jsPDF('p', 'pt', 'a4');
                pdf.addImage(headerImg.toDataURL('image/png'), 'PNG', 20, 20, 555, (555 * headerImg.height / headerImg.width));
                pdf.addPage();
                pdf.addImage(chartImg.toDataURL('image/png'), 'PNG', 20, 20, 555, (555 * chartImg.height / chartImg.width));
                pdf.addPage();
                pdf.addImage(fileTableImg.toDataURL('image/png'), 'PNG', 20, 20, 555, (555 * fileTableImg.height / fileTableImg.width));
                pdf.addPage();
                pdf.addImage(copiesTableImg.toDataURL('image/png'), 'PNG', 20, 20, 555, (555 * copiesTableImg.height / copiesTableImg.width));
                pdf.save('file-activity-report.pdf');
            } catch (err) {
                console.error('Download error:', err);
                showAlert('Failed to prepare PDF: ' + err.message, 'error');
            } finally {
                elements.printHeader.style.display = 'none';
                setLoadingState(false);
            }
        };

        const printChart = () => {
            elements.printHeader.style.display = 'block';
            window.print();
            elements.printHeader.style.display = 'none';
        };

        document.addEventListener('DOMContentLoaded', () => {
            updateChart();
        });
    </script>
</body>

</html>