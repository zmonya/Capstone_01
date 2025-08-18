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
 * Validate session and return user info
 *
 * @return array ['user_id'=>int, 'role'=>string] or null on failure
 */
function validateSession(): ?array
{
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        error_log("Unauthorized access attempt in my-report.php: Session invalid.");
        return null;
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
    try {
        $stmt = $pdo->prepare("
            SELECT d.department_id AS id, d.department_name AS name, ud.users_department_id
            FROM departments d
            JOIN users_department ud ON d.department_id = ud.department_id
            WHERE ud.user_id = ?
            ORDER BY d.department_name
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error fetching departments in my-report.php: " . $e->getMessage());
        return [];
    }
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
    try {
        $stmt = $pdo->prepare("
            SELECT u.username AS full_name, GROUP_CONCAT(d.department_name SEPARATOR ', ') AS department_names
            FROM users u
            LEFT JOIN users_department ud ON u.user_id = ud.user_id
            LEFT JOIN departments d ON ud.department_id = d.department_id
            WHERE u.user_id = ?
            GROUP BY u.user_id
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['full_name' => 'Unknown', 'department_names' => 'None'];
    } catch (Exception $e) {
        error_log("Error fetching user details in my-report.php: " . $e->getMessage());
        return ['full_name' => 'Unknown', 'department_names' => 'None'];
    }
}

/**
 * Fetch document copy details (documents that the user is involved with via upload)
 *
 * @param PDO $pdo
 * @param int $userId
 * @return array
 */
function fetchDocumentCopyDetails(PDO $pdo, int $userId): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT f.file_id, f.file_name, f.copy_type, f.physical_storage_path AS physical_storage,
                   GROUP_CONCAT(DISTINCT d.department_name SEPARATOR ', ') AS departments_with_copy
            FROM files f
            LEFT JOIN transactions t ON f.file_id = t.file_id
            LEFT JOIN users_department ud ON t.users_department_id = ud.users_department_id
            LEFT JOIN departments d ON ud.department_id = d.department_id
            WHERE t.user_id = ? AND t.transaction_type = 'upload'
            GROUP BY f.file_id, f.file_name, f.copy_type, f.physical_storage_path
            ORDER BY f.file_name
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error fetching document copies in my-report.php: " . $e->getMessage());
        return [];
    }
}

// Initialize variables
$errorMessage = '';
$userId = null;
$userRole = 'user';
$user = ['full_name' => 'Unknown', 'department_names' => 'None'];
$userDepartments = [];
$documentCopies = [];
$csrfToken = bin2hex(random_bytes(32));
$usersDepartmentId = null;

// Check database connection
if (!isset($pdo) || !$pdo instanceof PDO) {
    $errorMessage = 'Database connection not available. Please try again later.';
    error_log("Database connection not available in my-report.php.");
} else {
    // Validate session
    $session = validateSession();
    if ($session === null) {
        header('Location: login.php');
        exit;
    }
    $userId = $session['user_id'];
    $userRole = $session['role'];

    // Generate CSRF token if missing
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = $csrfToken;
    } else {
        $csrfToken = $_SESSION['csrf_token'];
    }

    // Fetch data for the page
    $userDepartments = fetchUserDepartments($pdo, $userId);
    $user = fetchUserDetails($pdo, $userId);
    $documentCopies = fetchDocumentCopyDetails($pdo, $userId);

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
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    
    <title>My Report - Document Archival</title>

    <?php
    include 'user_head.php';
    ?>

</head>

<body>
    <?php if ($errorMessage): ?>
        <div class="error-message" style="color: red; padding: 20px; text-align: center;">
            <p><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
    <?php else: ?>
    
    <?php 
    include 'user_menu.php'; 
    ?>
    
            <?php foreach ($userDepartments as $dept): ?>
                <a href="department_folder.php?department_id=<?= htmlspecialchars($dept['id'], ENT_QUOTES, 'UTF-8') ?>"
                    class="<?= $dept['id'] == $departmentId ? 'active' : '' ?>"
                    data-tooltip="<?= htmlspecialchars($dept['name'], ENT_QUOTES, 'UTF-8') ?>">
                    <i class="fas fa-folder"></i><span class="link-text"><?= htmlspecialchars($dept['name'], ENT_QUOTES, 'UTF-8') ?></span>
                </a>
            <?php endforeach; ?>

            <a href="logout.php" class="logout-btn" data-tooltip="Logout" aria-label="Logout">
            <i class="fas fa-sign-out-alt"></i><span class="link-text">Logout</span>
        </a>
    </aside>

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
                            <th>Physical Storage Path</th>
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
    <?php endif; ?>

    <script>
        const state = {
            chart: null,
            tableData: [],
            isLoading: false
        };

        const elements = {
            chartCanvas: document.getElementById('chartCanvas'),
            fileTableBody: document.getElementById('fileTableBody'),
            filesTable: document.getElementById('filesTable'),
            copiesTable: document.getElementById('copiesTable'),
            printHeader: document.getElementById('printHeader'),
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

        const updateTable = () => {
            elements.fileTableBody.innerHTML = '';
            const sortBy = document.getElementById('sortBy')?.value || 'newest';
            const filter = document.getElementById('filterDirection')?.value || 'all';

            const sortedData = [...state.tableData].sort((a, b) => {
                const dateA = new Date(a.date);
                const dateB = new Date(b.date);
                return sortBy === 'newest' ? dateB - dateA : dateA - dateB;
            });

            sortedData.forEach(row => {
                if (filter === 'all' || row.direction === filter) {
                    const tr = document.createElement('tr');
                    tr.dataset.direction = row.direction;
                    tr.innerHTML = `
                        <td>${row.file_name || 'N/A'}</td>
                        <td>${row.document_type || 'N/A'}</td>
                        <td>${formatDate(row.date)}</td>
                        <td>${row.department || 'N/A'}</td>
                        <td>${row.uploader || 'N/A'}</td>
                        <td>${row.direction || 'N/A'}</td>
                    `;
                    elements.fileTableBody.appendChild(tr);
                }
            });
        };

        const updateChart = () => {
            setLoadingState(true);
            const interval = elements.intervalSelect.value;
            const params = new URLSearchParams({
                interval,
                _csrf: '<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>'
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
                    'X-CSRF-Token': '<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>'
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
                        }, {
                            label: 'Files Received',
                            data: data.datasets.files_received || [0],
                            borderColor: '#2c3e50',
                            backgroundColor: 'rgba(44, 62, 80, 0.2)',
                            fill: true
                        }]
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

        const sortTable = () => {
            updateTable();
        };

        const filterTable = () => {
            updateTable();
        };

        const downloadChart = async () => {
            if (state.isLoading) return;
            setLoadingState(true);
            try {
                if (!window.jspdf || !window.html2canvas) {
                    throw new Error('Required libraries (jsPDF or html2canvas) not loaded.');
                }
                const { jsPDF } = window.jspdf;
                elements.printHeader.style.display = 'block';
                const headerImg = await html2canvas(elements.printHeader, { scale: 2 });
                const chartImg = await html2canvas(elements.chartCanvas, { scale: 2 });
                const fileTableImg = await html2canvas(elements.filesTable, { scale: 2 });
                const copiesTableImg = await html2canvas(elements.copiesTable, { scale: 2 });
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

        const hideAlert = () => {
            document.getElementById('customAlert').style.display = 'none';
        };

        document.addEventListener('DOMContentLoaded', () => {
            // Toggle sidebar
            document.getElementById('toggleSidebar').addEventListener('click', () => {
                document.getElementById('sidebar').classList.toggle('minimized');
                document.getElementById('topNav').classList.toggle('shifted');
                document.querySelector('.main-content').classList.toggle('shifted');
            });

            document.getElementById('toggleNavSidebar').addEventListener('click', () => {
                document.getElementById('sidebar').classList.toggle('minimized');
                document.getElementById('topNav').classList.toggle('shifted');
                document.querySelector('.main-content').classList.toggle('shifted');
            });

            // Handle date range visibility
            document.getElementById('interval').addEventListener('change', () => {
                document.getElementById('dateRange').style.display = elements.intervalSelect.value === 'range' ? 'block' : 'none';
            });

            updateChart();
        });
    </script>












</body>

</html>