<?php
session_start();
require 'db_connection.php';
require 'log_activity.php';
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
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code($statusCode);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}

/**
 * Validates user session and regenerates session ID.
 *
 * @return array User ID and role
 * @throws Exception If user is not authenticated
 */
function validateSession(): array
{
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        throw new Exception('Unauthorized access: Please log in.', 401);
    }
    session_regenerate_id(true);
    return ['user_id' => (int)$_SESSION['user_id'], 'role' => $_SESSION['role']];
}

/**
 * Fetches user departments.
 *
 * @param PDO $pdo
 * @param int $userId
 * @return array
 */
function fetchUserDepartments(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare("
        SELECT d.Department_id AS id, d.Department_name AS name
        FROM departments d
        JOIN users_department ud ON d.Department_id = ud.Department_id
        WHERE ud.User_id = ?
        ORDER BY d.Department_name
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fetches user details.
 *
 * @param PDO $pdo
 * @param int $userId
 * @return array
 */
function fetchUserDetails(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare("
        SELECT u.Username AS full_name, GROUP_CONCAT(d.Department_name SEPARATOR ', ') AS department_names
        FROM users u
        LEFT JOIN users_department ud ON u.User_id = ud.User_id
        LEFT JOIN departments d ON ud.Department_id = d.Department_id
        WHERE u.User_id = ?
        GROUP BY u.User_id
    ");
    $stmt->execute([$userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

try {
    $session = validateSession();
    $userId = $session['user_id'];
    $userRole = $session['role'];

    // Generate CSRF token
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $csrfToken = $_SESSION['csrf_token'];

    global $pdo;
    $userDepartments = fetchUserDepartments($pdo, $userId);
    $user = fetchUserDetails($pdo, $userId);

    if (empty($user)) {
        throw new Exception("User not found for ID $userId.", 404);
    }

    // Log page access
    $stmt = $pdo->prepare("
        INSERT INTO transaction (User_id, Transaction_status, Transaction_type, Time, Massage)
        VALUES (?, 'completed', 19, NOW(), ?)
    ");
    $stmt->execute([$userId, 'Accessed my-report page']);
} catch (Exception $e) {
    error_log("Error in my-report.php: " . $e->getMessage());
    sendJsonResponse(false, 'Server error: ' . $e->getMessage(), [], $e->getCode() ?: 500);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-Content-Type-Options" content="nosniff">
    <meta http-equiv="X-Frame-Options" content="DENY">
    <meta http-equiv="X-XSS-Protection" content="1; mode=block">
    <meta name="description" content="Document archival system report for <?= htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8'); ?>">
    <title>My Report - Document Archival</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style/client-sidebar.css">
    <link rel="stylesheet" href="style/my-report.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
</head>

<body>
    <div class="top-nav">
        <button class="toggle-btn" title="Toggle Sidebar" aria-label="Toggle Sidebar"><i class="fas fa-bars"></i></button>
        <h2>Generate Report</h2>
        <div class="filter-container">
            <select id="interval" aria-label="Select time interval for report">
                <option value="day">Daily</option>
                <option value="week">Weekly</option>
                <option value="month">Monthly</option>
                <option value="range">Custom Range</option>
            </select>
            <div id="dateRange" style="display: none;">
                <label for="startDate">Start Date:</label>
                <input type="date" id="startDate" aria-label="Select start date">
                <label for="endDate">End Date:</label>
                <input type="date" id="endDate" aria-label="Select end date">
            </div>
            <button onclick="updateChart()" aria-label="Apply filters">Apply</button>
        </div>
    </div>

    <div class="sidebar">
        <h2 class="sidebar-title">Document Archival</h2>
        <?php if ($userRole === 'admin'): ?>
            <a href="admin_dashboard.php" data-tooltip="Admin Dashboard" class="<?= basename($_SERVER['PHP_SELF']) == 'admin_dashboard.php' ? 'active' : '' ?>">
                <i class="fas fa-user-shield"></i><span class="link-text">Admin Dashboard</span>
            </a>
        <?php endif; ?>
        <a href="dashboard.php" data-tooltip="Dashboard" class="<?= basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : '' ?>">
            <i class="fas fa-home"></i><span class="link-text">Dashboard</span>
        </a>
        <a href="my-report.php" data-tooltip="My Report" class="active">
            <i class="fas fa-chart-bar"></i><span class="link-text">My Report</span>
        </a>
        <a href="my-folder.php" data-tooltip="My Folder" class="<?= basename($_SERVER['PHP_SELF']) == 'my-folder.php' ? 'active' : '' ?>">
            <i class="fas fa-folder"></i><span class="link-text">My Folder</span>
        </a>
        <?php foreach ($userDepartments as $dept): ?>
            <a href="department_folder.php?department_id=<?= htmlspecialchars($dept['id'], ENT_QUOTES, 'UTF-8') ?>"
                data-tooltip="<?= htmlspecialchars($dept['name'], ENT_QUOTES, 'UTF-8') ?>"
                class="<?= $dept['id'] == ($_GET['department_id'] ?? 0) ? 'active' : '' ?>">
                <i class="fas fa-folder"></i><span class="link-text"><?= htmlspecialchars($dept['name'], ENT_QUOTES, 'UTF-8') ?></span>
            </a>
        <?php endforeach; ?>
        <a href="logout.php" class="logout-btn" data-tooltip="Logout">
            <i class="fas fa-sign-out-alt"></i><span class="link-text">Logout</span>
        </a>
    </div>

    <main class="main-content">
        <h2>My Report</h2>
        <div id="print-header" style="display: none;">
            <h2>User: <?= htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8') ?></h2>
            <h2>Department: <?= htmlspecialchars($user['department_names'] ?: 'None', ENT_QUOTES, 'UTF-8') ?></h2>
            <h2>Report Date Range: <span id="report-date-range"></span></h2>
        </div>

        <div class="chart-container">
            <div class="loading-overlay" style="display: none;">
                <i class="fas fa-spinner fa-spin"></i> Loading...
            </div>
            <canvas id="fileActivityChart" aria-label="File activity chart"></canvas>
            <div class="chart-actions">
                <button onclick="downloadChart()" aria-label="Download report as PDF"><i class="fas fa-download"></i> Download PDF</button>
                <button onclick="printChart()" aria-label="Print report"><i class="fas fa-print"></i> Print</button>
            </div>
        </div>

        <div class="files-table">
            <h3>File Details</h3>
            <div class="table-controls">
                <select id="sortBy" onchange="sortTable()" aria-label="Sort table by date">
                    <option value="newest">Sort by Newest</option>
                    <option value="oldest">Sort by Oldest</option>
                </select>
                <select id="filterDirection" onchange="filterTable()" aria-label="Filter table by direction">
                    <option value="all">All Directions</option>
                    <option value="Sent">Sent</option>
                    <option value="Received">Received</option>
                    <option value="Received (Department)">Received (Department)</option>
                    <option value="Requested">Requested</option>
                    <option value="Request Approved">Request Approved</option>
                    <option value="Request Denied">Request Denied</option>
                </select>
            </div>
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
                <tbody id="fileTableBody" aria-live="polite"></tbody>
            </table>
        </div>
    </main>

    <script>
        // State management
        const state = {
            isLoading: false,
            fileActivityChart: null,
            tableData: []
        };

        // DOM elements
        const elements = {
            interval: document.getElementById('interval'),
            dateRange: document.getElementById('dateRange'),
            startDate: document.getElementById('startDate'),
            endDate: document.getElementById('endDate'),
            reportDateRange: document.getElementById('report-date-range'),
            fileTableBody: document.getElementById('fileTableBody'),
            printHeader: document.getElementById('print-header'),
            chartCanvas: document.getElementById('fileActivityChart'),
            chartContainer: document.querySelector('.chart-container'),
            loadingOverlay: document.querySelector('.chart-container .loading-overlay'),
            filesTable: document.querySelector('.files-table')
        };

        // Utility functions
        const formatDate = (dateStr) => {
            if (!dateStr) return 'N/A';
            const date = new Date(dateStr);
            return isNaN(date) ? 'N/A' : date.toLocaleString('en-US', {
                year: 'numeric',
                month: 'short',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
        };

        const showAlert = (message, type, callback = null) => {
            if (state.isLoading) return;
            const alertDiv = document.createElement('div');
            alertDiv.className = `custom-alert ${type}`;
            alertDiv.setAttribute('role', 'alert');
            alertDiv.innerHTML = `
                <p>${message}</p>
                <button onclick="this.parentElement.remove(); ${callback ? 'callback()' : ''}" aria-label="Close alert">OK</button>
            `;
            document.body.appendChild(alertDiv);
            setTimeout(() => alertDiv.remove(), 5000);
        };

        const setLoadingState = (isLoading) => {
            state.isLoading = isLoading;
            elements.loadingOverlay.style.display = isLoading ? 'flex' : 'none';
            elements.filesTable.style.opacity = isLoading ? '0.5' : '1';
            document.body.style.cursor = isLoading ? 'progress' : 'default';
        };

        // Chart and table updates
        const updateChart = () => {
            if (state.isLoading) return;
            setLoadingState(true);

            const interval = elements.interval.value;
            const startDate = elements.startDate.value;
            const endDate = elements.endDate.value;
            elements.dateRange.style.display = interval === 'range' ? 'flex' : 'none';

            if (interval === 'range' && (!startDate || !endDate)) {
                showAlert('Please select both start and end dates.', 'error');
                setLoadingState(false);
                return;
            }

            if (interval === 'range' && startDate && endDate && new Date(startDate) > new Date(endDate)) {
                showAlert('Start date must be before end date.', 'error');
                setLoadingState(false);
                return;
            }

            let url = `fetch_incoming_outgoing.php?interval=${encodeURIComponent(interval)}&csrf_token=<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>`;
            if (interval === 'range' && startDate && endDate) {
                url += `&startDate=${encodeURIComponent(startDate)}&endDate=${encodeURIComponent(endDate)}`;
                elements.reportDateRange.textContent = `${formatDate(startDate)} to ${formatDate(endDate)}`;
            } else {
                elements.reportDateRange.textContent = interval.charAt(0).toUpperCase() + interval.slice(1);
            }

            fetch(url, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {
                        'X-CSRF-Token': '<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>'
                    }
                })
                .then(response => {
                    if (!response.ok) {
                        return response.json().then(err => {
                            throw new Error(err.error || `HTTP error! Status: ${response.status}`);
                        });
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.error) {
                        showAlert(data.error, 'error');
                        return;
                    }

                    // Update chart
                    const labels = data.labels || [];
                    const datasets = {
                        filesSent: (data.datasets?.files_sent || []).map(val => parseInt(val) || 0),
                        filesReceived: (data.datasets?.files_received || []).map(val => parseInt(val) || 0),
                        filesRequested: (data.datasets?.files_requested || []).map(val => parseInt(val) || 0),
                        filesReceivedFromRequest: (data.datasets?.files_received_from_request || []).map(val => parseInt(val) || 0)
                    };

                    if (state.fileActivityChart) state.fileActivityChart.destroy();
                    state.fileActivityChart = new Chart(elements.chartCanvas.getContext('2d'), {
                        type: 'bar',
                        data: {
                            labels: labels,
                            datasets: [{
                                    label: 'Files Sent',
                                    data: datasets.filesSent,
                                    backgroundColor: 'rgba(64, 168, 103, 0.8)',
                                    borderColor: 'rgba(64, 168, 103, 1)',
                                    borderWidth: 1,
                                    stack: 'files'
                                },
                                {
                                    label: 'Files Received',
                                    data: datasets.filesReceived,
                                    backgroundColor: 'rgba(255, 99, 132, 0.8)',
                                    borderColor: 'rgba(255, 99, 132, 1)',
                                    borderWidth: 1,
                                    stack: 'files'
                                },
                                {
                                    label: 'Files Requested',
                                    data: datasets.filesRequested,
                                    backgroundColor: 'rgba(255, 206, 86, 0.8)',
                                    borderColor: 'rgba(255, 206, 86, 1)',
                                    borderWidth: 1,
                                    stack: 'files'
                                },
                                {
                                    label: 'Files Received (Request)',
                                    data: datasets.filesReceivedFromRequest,
                                    backgroundColor: 'rgba(75, 192, 192, 0.8)',
                                    borderColor: 'rgba(75, 192, 192, 1)',
                                    borderWidth: 1,
                                    stack: 'files'
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'top',
                                    labels: {
                                        font: {
                                            family: 'Montserrat',
                                            size: 14
                                        },
                                        padding: 20,
                                        color: '#333333'
                                    }
                                },
                                title: {
                                    display: true,
                                    text: `File Activity (${interval.charAt(0).toUpperCase() + interval.slice(1)})`,
                                    font: {
                                        family: 'Montserrat',
                                        size: 18,
                                        weight: '600'
                                    },
                                    color: '#34495e',
                                    padding: {
                                        top: 10,
                                        bottom: 20
                                    }
                                },
                                tooltip: {
                                    mode: 'index',
                                    intersect: false,
                                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                    titleFont: {
                                        family: 'Montserrat',
                                        size: 14
                                    },
                                    bodyFont: {
                                        family: 'Montserrat',
                                        size: 12
                                    },
                                    padding: 10
                                }
                            },
                            scales: {
                                x: {
                                    stacked: true,
                                    title: {
                                        display: true,
                                        text: interval === 'range' ? 'Date' : interval.charAt(0).toUpperCase() + interval.slice(1),
                                        font: {
                                            family: 'Montserrat',
                                            size: 14,
                                            weight: '500'
                                        },
                                        color: '#34495e'
                                    },
                                    grid: {
                                        display: false
                                    },
                                    ticks: {
                                        font: {
                                            family: 'Montserrat',
                                            size: 12
                                        }
                                    }
                                },
                                y: {
                                    stacked: true,
                                    title: {
                                        display: true,
                                        text: 'Number of Files',
                                        font: {
                                            family: 'Montserrat',
                                            size: 14,
                                            weight: '500'
                                        },
                                        color: '#34495e'
                                    },
                                    beginAtZero: true,
                                    ticks: {
                                        stepSize: 1,
                                        font: {
                                            family: 'Montserrat',
                                            size: 12
                                        },
                                        color: '#333333'
                                    },
                                    grid: {
                                        color: 'rgba(0, 0, 0, 0.1)'
                                    }
                                }
                            },
                            animation: {
                                duration: 1000,
                                easing: 'easeOutQuart'
                            }
                        }
                    });

                    // Update table
                    state.tableData = data.tableData || [];
                    updateTable();
                })
                .catch(error => {
                    console.error('Fetch error:', error);
                    showAlert(`Failed to load report data: ${error.message}`, 'error');
                })
                .finally(() => setLoadingState(false));
        };

        const updateTable = () => {
            elements.fileTableBody.innerHTML = '';
            if (!Array.isArray(state.tableData) || state.tableData.length === 0) {
                elements.fileTableBody.innerHTML = '<tr><td colspan="6">No file activity data available.</td></tr>';
                return;
            }

            state.tableData.forEach(file => {
                const row = document.createElement('tr');
                row.dataset.direction = file.direction || 'N/A';
                row.dataset.date = file.upload_date || '';
                row.innerHTML = `
                    <td>${htmlEscape(file.file_name || 'N/A')}</td>
                    <td>${htmlEscape(file.document_type || 'N/A')}</td>
                    <td data-date="${htmlEscape(file.upload_date || '')}">${formatDate(file.upload_date)}</td>
                    <td>${htmlEscape(file.department_name || 'N/A')}</td>
                    <td>${htmlEscape(file.uploader || 'N/A')}</td>
                    <td>${htmlEscape(file.direction || 'N/A')}</td>
                `;
                elements.fileTableBody.appendChild(row);
            });

            sortTable();
            filterTable();
        };

        const htmlEscape = (str) => {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        };

        const sortTable = () => {
            const sortBy = document.getElementById('sortBy').value;
            const rows = Array.from(elements.fileTableBody.getElementsByTagName('tr'));

            rows.sort((a, b) => {
                const dateA = new Date(a.dataset.date || '0');
                const dateB = new Date(b.dataset.date || '0');
                return sortBy === 'oldest' ? dateA - dateB : dateB - dateA;
            });

            elements.fileTableBody.innerHTML = '';
            rows.forEach(row => elements.fileTableBody.appendChild(row));
        };

        const filterTable = () => {
            const filterDirection = document.getElementById('filterDirection').value;
            const rows = elements.fileTableBody.getElementsByTagName('tr');

            Array.from(rows).forEach(row => {
                row.style.display = (filterDirection === 'all' || row.dataset.direction === filterDirection) ? '' : 'none';
            });
        };

        const downloadChart = async () => {
            if (state.isLoading) return;
            setLoadingState(true);

            try {
                const {
                    jsPDF
                } = window.jspdf;
                elements.printHeader.style.display = 'block';
                const [headerImg, chartImg, tableImg] = await Promise.all([
                    html2canvas(elements.printHeader, {
                        scale: 2
                    }),
                    html2canvas(elements.chartCanvas, {
                        scale: 2
                    }),
                    html2canvas(elements.filesTable, {
                        scale: 2
                    })
                ]);
                elements.printHeader.style.display = 'none';

                const pdf = new jsPDF('p', 'mm', 'a4');
                const width = pdf.internal.pageSize.getWidth();
                const headerHeight = (headerImg.height * (width - 20)) / headerImg.width;
                const chartHeight = (chartImg.height * (width - 20)) / chartImg.width;
                const tableHeight = (tableImg.height * (width - 20)) / tableImg.width;

                let yPos = 10;
                pdf.addImage(headerImg.toDataURL('image/png'), 'PNG', 10, yPos, width - 20, headerHeight);
                yPos += headerHeight + 10;
                pdf.addImage(chartImg.toDataURL('image/png'), 'PNG', 10, yPos, width - 20, chartHeight);
                yPos += chartHeight + 10;

                if (yPos + tableHeight > pdf.internal.pageSize.getHeight()) {
                    pdf.addPage();
                    yPos = 10;
                }
                pdf.addImage(tableImg.toDataURL('image/png'), 'PNG', 10, yPos, width - 20, tableHeight);

                pdf.save('FileActivityReport.pdf');
            } catch (error) {
                console.error('PDF generation error:', error);
                showAlert('Failed to generate PDF: ' + error.message, 'error');
            } finally {
                setLoadingState(false);
            }
        };

        const printChart = async () => {
            if (state.isLoading) return;
            setLoadingState(true);

            try {
                elements.printHeader.style.display = 'block';
                const [headerImg, chartImg, tableImg] = await Promise.all([
                    html2canvas(elements.printHeader, {
                        scale: 2
                    }),
                    html2canvas(elements.chartCanvas, {
                        scale: 2
                    }),
                    html2canvas(elements.filesTable, {
                        scale: 2
                    })
                ]);
                elements.printHeader.style.display = 'none';

                const printWindow = window.open('', '_blank');
                printWindow.document.write(`
                    <html>
                        <head>
                            <title>File Activity Report</title>
                            <style>
                                body { font-family: 'Montserrat', sans-serif; margin: 20px; }
                                img { max-width: 100%; page-break-inside: avoid; }
                                table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                                th, td { border: 1px solid #000; padding: 8px; text-align: left; }
                                h2, h3 { margin: 10px 0; color: #34495e; }
                            </style>
                        </head>
                        <body>
                            <div>${elements.printHeader.innerHTML}</div>
                            <img src="${chartImg.toDataURL('image/png')}" alt="File Activity Chart">
                            <h3>File Details</h3>
                            <img src="${tableImg.toDataURL('image/png')}" alt="File Details Table">
                        </body>
                    </html>
                `);
                printWindow.document.close();
                setTimeout(() => printWindow.print(), 500);
            } catch (error) {
                console.error('Print error:', error);
                showAlert('Failed to print report: ' + error.message, 'error');
            } finally {
                setLoadingState(false);
            }
        };

        // Initialize event listeners
        const initializeEventListeners = () => {
            document.querySelector('.toggle-btn').addEventListener('click', () => {
                const sidebar = document.querySelector('.sidebar');
                const topNav = document.querySelector('.top-nav');
                const mainContent = document.querySelector('.main-content');
                sidebar.classList.toggle('minimized');
                topNav.classList.toggle('resized', sidebar.classList.contains('minimized'));
                mainContent.classList.toggle('resized', sidebar.classList.contains('minimized'));
            });

            elements.interval.addEventListener('change', updateChart);
            document.querySelector('.filter-container button').addEventListener('click', updateChart);

            // Keyboard accessibility
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    elements.dateRange.style.display = 'none';
                }
                if (e.key === 'Enter' && e.target.classList.contains('chart-actions')) {
                    e.target.click();
                }
            });

            // Add tooltips for buttons
            document.querySelectorAll('.chart-actions button, .filter-container button').forEach(btn => {
                btn.addEventListener('mouseenter', () => {
                    const tooltip = document.createElement('div');
                    tooltip.className = 'tooltip';
                    tooltip.textContent = btn.getAttribute('aria-label');
                    btn.appendChild(tooltip);
                });
                btn.addEventListener('mouseleave', () => {
                    const tooltip = btn.querySelector('.tooltip');
                    if (tooltip) tooltip.remove();
                });
            });
        };

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', () => {
            initializeEventListeners();
            updateChart();
        });
    </script>
</body>

</html>