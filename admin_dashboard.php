<?php
session_start();
require 'db_connection.php';

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// CSRF token generation and validation
function generateCsrfToken()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Validate CSRF token
function validateCsrfToken($token)
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Redirect to login if not authenticated or not an admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: login.php');
    exit();
}

if ($_SESSION['role'] !== 'admin') {
    header('Location: unauthorized.php');
    exit();
}

$userId = filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT);
if ($userId === false) {
    session_destroy();
    header('Location: login.php');
    exit();
}

// Function to execute prepared queries safely
function executeQuery($pdo, $query, $params = [])
{
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        return false;
    }
}

// Fetch admin details
$adminStmt = executeQuery($pdo, "SELECT User_id, Username, Role FROM users WHERE User_id = ?", [$userId]);
$admin = $adminStmt ? $adminStmt->fetch(PDO::FETCH_ASSOC) : null;

if (!$admin) {
    session_destroy();
    header('Location: login.php');
    exit();
}

// Fetch system statistics
$totalUsersStmt = executeQuery($pdo, "SELECT COUNT(*) FROM users");
$totalUsers = $totalUsersStmt ? $totalUsersStmt->fetchColumn() : 0;

$totalFilesStmt = executeQuery($pdo, "SELECT COUNT(*) FROM files WHERE File_status != 'deleted'");
$totalFiles = $totalFilesStmt ? $totalFilesStmt->fetchColumn() : 0;

// Fetch pending access requests (assuming transaction table for access requests)
$pendingRequestsStmt = executeQuery($pdo, "SELECT COUNT(*) FROM transaction WHERE Transaction_status = 'pending' AND Transaction_type = 5");
$pendingRequests = $pendingRequestsStmt ? $pendingRequestsStmt->fetchColumn() : 0;

// Fetch incoming and outgoing files
$incomingFilesStmt = executeQuery($pdo, "
    SELECT COUNT(*) AS incoming_count 
    FROM transaction t
    JOIN files f ON t.File_id = f.File_id
    WHERE t.Users_Department_id IN (SELECT Department_id FROM users_department WHERE User_id = ?) 
    AND t.Transaction_status = 'pending' 
    AND t.Transaction_type = 4", [$userId]);
$incomingFiles = $incomingFilesStmt ? $incomingFilesStmt->fetchColumn() : 0;

$outgoingFilesStmt = executeQuery($pdo, "
    SELECT COUNT(*) AS outgoing_count 
    FROM transaction t
    JOIN files f ON t.File_id = f.File_id
    WHERE t.User_id = ? 
    AND t.Transaction_status = 'pending' 
    AND t.Transaction_type = 4", [$userId]);
$outgoingFiles = $outgoingFilesStmt ? $outgoingFilesStmt->fetchColumn() : 0;

// Fetch file upload trends (Last 7 Days)
$fileUploadTrendsStmt = executeQuery($pdo, "
    SELECT 
        f.File_name AS document_name,
        dt.Field_label AS document_type,
        f.Upload_date AS upload_date,
        u.Username AS uploader_name,
        d.Department_name AS uploader_department,
        sd.Department_name AS uploader_subdepartment,
        td.Department_name AS target_department_name
    FROM files f
    LEFT JOIN documents_type_fields dt ON f.Document_type_id = dt.Document_type_id
    LEFT JOIN users u ON f.User_id = u.User_id
    LEFT JOIN users_department uda ON u.User_id = uda.User_id
    LEFT JOIN departments d ON uda.Department_Absid = d.Department_id
    LEFT JOIN departments sd ON d.Department_id = sd.Department_id AND sd.Department_type = 'sub_department'
    LEFT JOIN transaction t ON f.File_id = t.File_id
    LEFT JOIN departments td ON t.Users_Department_id = td.Department_id
    WHERE f.Upload_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    AND f.File_status != 'deleted'
    ORDER BY f.Upload_date ASC");
$fileUploadTrends = $fileUploadTrendsStmt ? $fileUploadTrendsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

// Fetch file distribution by document type
$fileDistributionByTypeStmt = executeQuery($pdo, "
    SELECT 
        f.File_name AS document_name,
        dt.Field_label AS document_type,
        us.Username AS sender_name,
        ur.Username AS receiver_name,
        t.Time AS time_sent,
        t2.Time AS time_received,
        uq.Username AS requester_name,
        uo.Username AS owner_name,
        t3.Time AS time_requested,
        t4.Time AS time_approved,
        d.Department_name AS department_name,
        sd.Department_name AS sub_department_name
    FROM files f
    JOIN documents_type_fields dt ON f.Document_type_id = dt.Document_type_id
    LEFT JOIN transaction t ON f.File_id = t.File_id AND t.Transaction_type = 4
    LEFT JOIN users us ON t.User_id = us.User_id
    LEFT JOIN transaction t2 ON f.File_id = t2.File_id AND t2.Transaction_type = 6
    LEFT JOIN users ur ON t2.User_id = ur.User_id
    LEFT JOIN transaction t3 ON f.File_id = t3.File_id AND t3.Transaction_type = 5
    LEFT JOIN users uq ON t3.User_id = uq.User_id
    LEFT JOIN users uo ON f.User_id = uo.User_id
    LEFT JOIN users_department uda ON t.User_id = uda.User_id
    LEFT JOIN departments d ON uda.Department_id = d.Department_id
    LEFT JOIN departments sd ON d.Department_id = sd.Department_id AND sd.Department_type = 'sub_department'
    WHERE f.File_status != 'deleted'
    AND (t.Transaction_id IS NOT NULL OR t3.Transaction_id IS NOT NULL)
    GROUP BY f.File_id, t.Transaction_id, t3.Transaction_id");
$fileDistributionByType = $fileDistributionByTypeStmt ? $fileDistributionByTypeStmt->fetchAll(PDO::FETCH_ASSOC) : [];

// Fetch users per department
$usersPerDepartmentStmt = executeQuery($pdo, "
    SELECT 
        d.Department_name AS department_name,
        COUNT(DISTINCT uda.User_id) AS user_count
    FROM departments d
    LEFT JOIN users_department uda ON d.Department_id = uda.Department_id
    WHERE d.Department_type = 'college' OR d.Department_type = 'office'
    GROUP BY d.Department_name");
$usersPerDepartment = $usersPerDepartmentStmt ? $usersPerDepartmentStmt->fetchAll(PDO::FETCH_ASSOC) : [];

// Prepare data for charts
$departmentLabels = array_column($usersPerDepartment, 'department_name');
$departmentData = array_column($usersPerDepartment, 'user_count');
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Admin Dashboard - Arc-Hive</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.css">
    <script src="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" integrity="sha512-qZvrmS2ekKPF2mSznTQsxqPgnpkI4DNTlrdUmTzrDgektczlKNRRhy5X5AAOnx5S09ydFYWWNSfcEqDTTHgtNA==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js" integrity="sha512-BNaRQnYJYiPSqHHDb58B0yaPfCu+Wgds8Gp/gU33kqBtgNS4tSPHuGibyoeqMV/TJlSKda6FXzoEyYGjTe+vXA==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="admin-sidebar.css">
    <link rel="stylesheet" href="admin-interface.css">
    <style>
        .popup-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .popup-content {
            background: #fff;
            border-radius: 12px;
            padding: 25px;
            width: 90%;
            max-width: 950px;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.3);
            position: relative;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: scale(0.95);
            }

            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        .popup-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #e0e0e0;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        .popup-header h2 {
            margin: 0;
            font-size: 26px;
            color: #333;
            font-weight: 600;
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 28px;
            cursor: pointer;
            color: #888;
            transition: color 0.2s;
        }

        .close-btn:hover {
            color: #333;
        }

        .popup-actions {
            margin: 15px 0;
            text-align: right;
        }

        .popup-actions button {
            padding: 10px 20px;
            margin-left: 15px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            background-color: #50c878;
            color: white;
            font-weight: 500;
            transition: background-color 0.3s, transform 0.2s;
        }

        .popup-actions button:hover {
            background-color: #45b069;
            transform: translateY(-2px);
        }

        .popup-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            font-size: 13px;
        }

        .popup-table th,
        .popup-table td {
            border: 1px solid #ddd;
            padding: 12px;
            text-align: left;
        }

        .popup-table th {
            background-color: #f8f8f8;
            color: #444;
            font-weight: bold;
        }

        .popup-table td {
            color: #555;
        }

        .popup-table td:nth-child(odd) {
            background-color: #e6f4ea;
        }

        .stat-card {
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .chart-container {
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            border-radius: 8px;
            overflow: hidden;
            background: #fff;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .chart-container:hover {
            transform: scale(1.03);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .chart-container h3 {
            padding: 10px;
            margin: 0;
            background: #f9f9f9;
            font-size: 18px;
            color: #333;
        }
    </style>
</head>

<body>
    <!-- Admin Sidebar -->
    <div class="sidebar">
        <button class="toggle-btn" title="Toggle Sidebar">
            <i class="fas fa-bars"></i>
        </button>
        <h2 class="sidebar-title">Admin Panel</h2>
        <a href="dashboard.php" class="client-btn">
            <i class="fas fa-exchange-alt"></i>
            <span class="link-text">Switch to Client View</span>
        </a>
        <a href="admin_dashboard.php" class="active">
            <i class="fas fa-home"></i>
            <span class="link-text">Dashboard</span>
        </a>
        <a href="admin_search.php">
            <i class="fas fa-search"></i>
            <span class="link-text">View All Files</span>
        </a>
        <a href="user_management.php">
            <i class="fas fa-users"></i>
            <span class="link-text">User Management</span>
        </a>
        <a href="department_management.php">
            <i class="fas fa-building"></i>
            <span class="link-text">Department Management</span>
        </a>
        <a href="physical_storage_management.php">
            <i class="fas fa-archive"></i>
            <span class="link-text">Physical Storage</span>
        </a>
        <a href="document_type_management.php">
            <i class="fas fa-file-alt"></i>
            <span class="link-text">Document Type Management</span>
        </a>
        <a href="logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i>
            <span class="link-text">Logout</span>
        </a>
    </div>

    <!-- Main Content -->
    <div class="main-content sidebar-expanded">
        <!-- CSRF Token -->
        <input type="hidden" id="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">

        <!-- System Statistics -->
        <div class="admin-stats">
            <div class="stat-card">
                <h3>Total Users</h3>
                <p><?= htmlspecialchars($totalUsers, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div class="stat-card">
                <h3>Total Files</h3>
                <p><?= htmlspecialchars($totalFiles, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div class="stat-card">
                <h3>Pending Requests</h3>
                <p><?= htmlspecialchars($pendingRequests, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div class="stat-card">
                <h3>Incoming Files</h3>
                <p><?= htmlspecialchars($incomingFiles, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div class="stat-card">
                <h3>Outgoing Files</h3>
                <p><?= htmlspecialchars($outgoingFiles, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        </div>

        <!-- Charts Grid -->
        <div class="chart-grid">
            <div class="chart-container" onclick="openPopup('fileUploadChart', 'File Upload Trends (Last 7 Days)', 'FileUploadTrends')">
                <h3>File Upload Trends (Last 7 Days)</h3>
                <canvas id="fileUploadChart"></canvas>
            </div>
            <div class="chart-container" onclick="openPopup('fileDistributionChart', 'File Distribution by Document Type', 'FileDistribution')">
                <h3>File Distribution by Document Type</h3>
                <canvas id="fileDistributionChart"></canvas>
            </div>
            <div class="chart-container" onclick="openPopup('usersPerDepartmentChart', 'Users Per Department', 'UsersPerDepartment')">
                <h3>Users Per Department</h3>
                <canvas id="usersPerDepartmentChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Popup Overlay -->
    <div id="popupOverlay" class="popup-overlay">
        <div class="popup-content">
            <div class="popup-header">
                <h2 id="popupTitle"></h2>
                <button class="close-btn" onclick="closePopup()">×</button>
            </div>
            <canvas id="popupChart"></canvas>
            <div class="popup-actions">
                <button onclick="downloadChart()">Download PDF</button>
                <button onclick="printChart()">Print</button>
            </div>
            <table id="popupTable" class="popup-table"></table>
        </div>
    </div>

    <script>
        // CSRF Token
        const csrfToken = document.getElementById('csrf_token').value;

        // Initialize Notyf for notifications
        const notyf = new Notyf({
            duration: 5000,
            position: {
                x: 'right',
                y: 'top'
            },
            ripple: true
        });

        // Toggle Sidebar
        document.addEventListener('DOMContentLoaded', () => {
            const sidebar = document.querySelector('.sidebar');
            const mainContent = document.querySelector('.main-content');
            const toggleBtn = document.querySelector('.toggle-btn');
            const popupOverlay = document.getElementById('popupOverlay');

            if (sidebar.classList.contains('minimized')) {
                mainContent.classList.remove('sidebar-expanded');
                mainContent.classList.add('sidebar-minimized');
            } else {
                mainContent.classList.add('sidebar-expanded');
                mainContent.classList.remove('sidebar-minimized');
            }

            toggleBtn.addEventListener('click', () => {
                sidebar.classList.toggle('minimized');
                mainContent.classList.toggle('sidebar-expanded');
                mainContent.classList.toggle('sidebar-minimized');
            });

            popupOverlay.addEventListener('click', (e) => {
                if (e.target === popupOverlay) {
                    closePopup();
                }
            });

            initCharts();
        });

        // Chart instances
        let fileUploadChart, fileDistributionChart, usersPerDepartmentChart, popupChartInstance;

        function initCharts() {
            const fileUploadTrends = <?= json_encode($fileUploadTrends, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const uploadLabels = fileUploadTrends.map(entry => new Date(entry.upload_date).toLocaleDateString('en-US', {
                month: 'short',
                day: 'numeric'
            }));
            const uploadData = fileUploadTrends.map(() => 1);

            fileUploadChart = new Chart(document.getElementById('fileUploadChart'), {
                type: 'bar',
                data: {
                    labels: uploadLabels,
                    datasets: [{
                        label: 'File Uploads',
                        data: uploadData,
                        backgroundColor: 'rgba(80, 200, 120, 0.2)',
                        borderColor: '#50c878',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Number of Uploads'
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Date'
                            }
                        }
                    }
                }
            });

            const fileDistributionByType = <?= json_encode($fileDistributionByType, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const documentTypeLabels = [...new Set(fileDistributionByType.map(entry => entry.document_type))];
            const documentTypeData = documentTypeLabels.map(type =>
                fileDistributionByType.filter(entry => entry.document_type === type).length
            );

            fileDistributionChart = new Chart(document.getElementById('fileDistributionChart'), {
                type: 'pie',
                data: {
                    labels: documentTypeLabels,
                    datasets: [{
                        label: 'File Distribution',
                        data: documentTypeData,
                        backgroundColor: ['#50c878', '#34495e', '#dc3545', '#ffc107', '#17a2b8', '#6610f2']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });

            usersPerDepartmentChart = new Chart(document.getElementById('usersPerDepartmentChart'), {
                type: 'bar',
                data: {
                    labels: <?= json_encode($departmentLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                    datasets: [{
                        label: 'Users',
                        data: <?= json_encode($departmentData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                        backgroundColor: '#50c878',
                        borderColor: '#50c878',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Number of Users'
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Departments'
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        }
                    }
                }
            });
        }

        // Popup handling
        let currentChartType;

        function openPopup(chartId, title, chartType) {
            if (!validateCsrfToken(csrfToken)) {
                notyf.error('Invalid CSRF token');
                return;
            }

            currentChartType = chartType;
            const popupOverlay = document.getElementById('popupOverlay');
            const popupTitle = document.getElementById('popupTitle');
            const popupTable = document.getElementById('popupTable');
            const popupCanvas = document.getElementById('popupChart');

            if (popupChartInstance) popupChartInstance.destroy();

            popupTitle.textContent = title;
            popupOverlay.style.display = 'flex';

            let chartConfig;
            if (chartId === 'fileUploadChart') {
                chartConfig = fileUploadChart.config;
            } else if (chartId === 'fileDistributionChart') {
                chartConfig = fileDistributionChart.config;
            } else if (chartId === 'usersPerDepartmentChart') {
                chartConfig = usersPerDepartmentChart.config;
            }

            popupChartInstance = new Chart(popupCanvas, chartConfig);

            const chartData = getChartData(chartType);
            let tableHTML = '';

            if (chartType === 'FileUploadTrends') {
                tableHTML = `
                    <thead>
                        <tr>
                            <th>File Name</th>
                            <th>Document Type</th>
                            <th>Uploader</th>
                            <th>Uploader's Department</th>
                            <th>Intended Destination</th>
                            <th>Upload Date/Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${chartData.map(entry => {
                            const dept = entry.uploader_department ? `${entry.uploader_department}${entry.uploader_subdepartment ? ' - ' + entry.uploader_subdepartment : ''}` : 'Unknown';
                            const destination = entry.target_department_name ? entry.target_department_name : '(NONE) Personal Document';
                            return `
                                <tr>
                                    <td>${sanitizeHTML(entry.document_name)}</td>
                                    <td>${sanitizeHTML(entry.document_type)}</td>
                                    <td>${sanitizeHTML(entry.uploader_name)}</td>
                                    <td>${sanitizeHTML(dept)}</td>
                                    <td>${sanitizeHTML(destination)}</td>
                                    <td>${new Date(entry.upload_date).toLocaleString()}</td>
                                </tr>
                            `;
                        }).join('')}
                    </tbody>
                `;
            } else if (chartType === 'FileDistribution') {
                tableHTML = `
                    <thead>
                        <tr>
                            <th>File Name</th>
                            <th>Document Type</th>
                            <th>Sender</th>
                            <th>Recipient</th>
                            <th>Time Sent</th>
                            <th>Time Received</th>
                            <th>Department/Subdepartment</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${chartData.map(entry => {
                            const dept = entry.department_name ? `${entry.department_name}${entry.sub_department_name ? ' - ' + entry.sub_department_name : ''}` : 'None';
                            return `
                                <tr>
                                    <td>${sanitizeHTML(entry.document_name)}</td>
                                    <td>${sanitizeHTML(entry.document_type)}</td>
                                    <td>${sanitizeHTML(entry.sender_name || 'None')}</td>
                                    <td>${sanitizeHTML(entry.receiver_name || 'None')}</td>
                                    <td>${entry.time_sent ? new Date(entry.time_sent).toLocaleString() : 'Not Sent'}</td>
                                    <td>${entry.time_received ? new Date(entry.time_received).toLocaleString() : 'Not Received'}</td>
                                    <td>${sanitizeHTML(dept)}</td>
                                </tr>
                                ${entry.requester_name ? `
                                <tr>
                                    <td colspan="2">Access Request</td>
                                    <td>${sanitizeHTML(entry.requester_name)}</td>
                                    <td>${sanitizeHTML(entry.owner_name || 'None')}</td>
                                    <td>${entry.time_requested ? new Date(entry.time_requested).toLocaleString() : 'Not Requested'}</td>
                                    <td>${entry.time_approved ? new Date(entry.time_approved).toLocaleString() : 'Not Approved'}</td>
                                    <td>-</td>
                                </tr>
                                ` : ''}
                            `;
                        }).join('')}
                    </tbody>
                `;
            } else if (chartType === 'UsersPerDepartment') {
                tableHTML = `
                    <thead>
                        <tr>
                            <th>Department</th>
                            <th>Users</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${chartData.labels.map((label, index) => `
                            <tr>
                                <td>${sanitizeHTML(label)}</td>
                                <td>${sanitizeHTML(String(chartData.data[index]))}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                `;
            }

            popupTable.innerHTML = tableHTML;
        }

        function closePopup() {
            const popupOverlay = document.getElementById('popupOverlay');
            popupOverlay.style.display = 'none';
            if (popupChartInstance) {
                popupChartInstance.destroy();
                popupChartInstance = null;
            }
        }

        function getChartData(chartType) {
            if (chartType === 'FileUploadTrends') {
                return <?= json_encode($fileUploadTrends, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            } else if (chartType === 'FileDistribution') {
                return <?= json_encode($fileDistributionByType, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            } else if (chartType === 'UsersPerDepartment') {
                return {
                    labels: <?= json_encode($departmentLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                    data: <?= json_encode($departmentData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
                };
            }
            return [];
        }

        // Sanitize HTML to prevent XSS
        function sanitizeHTML(str) {
            const div = document.createElement('div');
            div.textContent = str || '';
            return div.innerHTML;
        }

        // Download Chart as PDF
        function downloadChart() {
            if (!validateCsrfToken(csrfToken)) {
                notyf.error('Invalid CSRF token');
                return;
            }

            const chartData = getChartData(currentChartType);
            const {
                jsPDF
            } = window.jspdf;
            const pdf = new jsPDF('p', 'mm', 'a4');
            const pageWidth = pdf.internal.pageSize.getWidth();
            const pageHeight = pdf.internal.pageSize.getHeight();
            const margin = 5;
            const maxWidth = pageWidth - 2 * margin;
            let yPos = 10;

            // Title
            pdf.setFontSize(16);
            pdf.setTextColor(33, 33, 33);
            pdf.text(`Report: ${currentChartType}`, margin, yPos);
            yPos += 8;

            // Chart Image
            const chartCanvas = document.getElementById('popupChart');
            html2canvas(chartCanvas, {
                scale: 2
            }).then(canvas => {
                const chartImage = canvas.toDataURL('image/png');
                const imgProps = pdf.getImageProperties(chartImage);
                const chartWidth = maxWidth;
                const chartHeight = (imgProps.height * chartWidth) / imgProps.width;
                pdf.addImage(chartImage, 'PNG', margin, yPos, chartWidth, chartHeight);
                yPos += chartHeight + 8;

                // Table Header
                pdf.setFontSize(12);
                pdf.text('Data Table', margin, yPos);
                yPos += 6;

                // Table Content
                pdf.setFontSize(8);
                const lineHeight = 4;
                const startX = margin;

                function getMaxLines(texts, widths) {
                    return Math.max(...texts.map((text, i) =>
                        pdf.splitTextToSize(text || '', widths[i] - 4).length));
                }

                function drawCell(x, y, width, height, isHeader = false, isOdd = false) {
                    if (isHeader) {
                        pdf.setFillColor(240, 240, 240);
                        pdf.rect(x, y, width, height, 'F');
                    } else if (isOdd) {
                        pdf.setFillColor(230, 244, 234);
                        pdf.rect(x, y, width, height, 'F');
                    }
                    pdf.setDrawColor(150, 150, 150);
                    pdf.rect(x, y, width, height);
                }

                function drawText(x, y, text, width, height) {
                    const lines = pdf.splitTextToSize(text || '', width - 4);
                    const textHeight = lines.length * lineHeight;
                    const yOffset = (height - textHeight) / 2 + lineHeight;
                    lines.forEach((line, j) => {
                        const textWidth = pdf.getTextWidth(line);
                        const xOffset = (width - textWidth) / 2;
                        pdf.text(line, x + xOffset, y + yOffset + j * lineHeight);
                    });
                }

                if (currentChartType === 'FileUploadTrends') {
                    const columnWidths = [40, 24, 24, 35, 40, 37];
                    let xPos = startX;
                    for (let i = 0; i < 6; i++) {
                        drawCell(xPos, yPos, columnWidths[i], lineHeight + 2, true);
                        xPos += columnWidths[i];
                    }
                    pdf.setTextColor(50, 50, 50);
                    pdf.setFont('helvetica', 'bold');
                    xPos = startX;
                    drawText(xPos, yPos, 'File Name', columnWidths[0], lineHeight + 2);
                    xPos += columnWidths[0];
                    drawText(xPos, yPos, 'Doc Type', columnWidths[1], lineHeight + 2);
                    xPos += columnWidths[1];
                    drawText(xPos, yPos, 'Uploader', columnWidths[2], lineHeight + 2);
                    xPos += columnWidths[2];
                    drawText(xPos, yPos, 'Dept', columnWidths[3], lineHeight + 2);
                    xPos += columnWidths[3];
                    drawText(xPos, yPos, 'Destination', columnWidths[4], lineHeight + 2);
                    xPos += columnWidths[4];
                    drawText(xPos, yPos, 'Upload Date', columnWidths[5], lineHeight + 2);
                    yPos += lineHeight + 3;
                    pdf.setFont('helvetica', 'normal');

                    chartData.forEach(entry => {
                        const dept = entry.uploader_department ? `${entry.uploader_department}${entry.uploader_subdepartment ? ' - ' + entry.uploader_subdepartment : ''}` : 'Unknown';
                        const destination = entry.target_department_name ? entry.target_department_name : '(NONE) Personal Document';
                        const texts = [
                            entry.document_name || '',
                            entry.document_type || '',
                            entry.uploader_name || '',
                            dept,
                            destination,
                            new Date(entry.upload_date).toLocaleString()
                        ];
                        const maxLines = getMaxLines(texts, columnWidths);
                        const rowHeight = maxLines * lineHeight + 2;

                        if (yPos + rowHeight > pageHeight - margin) {
                            pdf.addPage();
                            yPos = margin;
                            xPos = startX;
                            for (let i = 0; i < 6; i++) {
                                drawCell(xPos, yPos, columnWidths[i], lineHeight + 2, true);
                                xPos += columnWidths[i];
                            }
                            pdf.setTextColor(50, 50, 50);
                            pdf.setFont('helvetica', 'bold');
                            xPos = startX;
                            drawText(xPos, yPos, 'File Name', columnWidths[0], lineHeight + 2);
                            xPos += columnWidths[0];
                            drawText(xPos, yPos, 'Doc Type', columnWidths[1], lineHeight + 2);
                            xPos += columnWidths[1];
                            drawText(xPos, yPos, 'Uploader', columnWidths[2], lineHeight + 2);
                            xPos += columnWidths[2];
                            drawText(xPos, yPos, 'Dept', columnWidths[3], lineHeight + 2);
                            xPos += columnWidths[3];
                            drawText(xPos, yPos, 'Destination', columnWidths[4], lineHeight + 2);
                            xPos += columnWidths[4];
                            drawText(xPos, yPos, 'Upload Date', columnWidths[5], lineHeight + 2);
                            yPos += lineHeight + 3;
                            pdf.setFont('helvetica', 'normal');
                        }

                        xPos = startX;
                        for (let i = 0; i < 6; i++) {
                            drawCell(xPos, yPos, columnWidths[i], rowHeight, false, i % 2 === 0);
                            xPos += columnWidths[i];
                        }
                        pdf.setTextColor(0, 0, 0);
                        xPos = startX;
                        texts.forEach((text, i) => {
                            drawText(xPos, yPos, text, columnWidths[i], rowHeight);
                            xPos += columnWidths[i];
                        });
                        yPos += rowHeight;
                    });
                } else if (currentChartType === 'FileDistribution') {
                    const columnWidths = [29, 24, 29, 29, 33, 33, 23];
                    let xPos = startX;
                    for (let i = 0; i < 7; i++) {
                        drawCell(xPos, yPos, columnWidths[i], lineHeight + 2, true);
                        xPos += columnWidths[i];
                    }
                    pdf.setTextColor(50, 50, 50);
                    pdf.setFont('helvetica', 'bold');
                    xPos = startX;
                    drawText(xPos, yPos, 'File Name', columnWidths[0], lineHeight + 2);
                    xPos += columnWidths[0];
                    drawText(xPos, yPos, 'Doc Type', columnWidths[1], lineHeight + 2);
                    xPos += columnWidths[1];
                    drawText(xPos, yPos, 'Sender', columnWidths[2], lineHeight + 2);
                    xPos += columnWidths[2];
                    drawText(xPos, yPos, 'Recipient', columnWidths[3], lineHeight + 2);
                    xPos += columnWidths[3];
                    drawText(xPos, yPos, 'Sent', columnWidths[4], lineHeight + 2);
                    xPos += columnWidths[4];
                    drawText(xPos, yPos, 'Received', columnWidths[5], lineHeight + 2);
                    xPos += columnWidths[5];
                    drawText(xPos, yPos, 'Dept', columnWidths[6], lineHeight + 2);
                    yPos += lineHeight + 3;
                    pdf.setFont('helvetica', 'normal');

                    chartData.forEach(entry => {
                        const dept = entry.department_name ? `${entry.department_name}${entry.sub_department_name ? ' - ' + entry.sub_department_name : ''}` : 'None';
                        const mainTexts = [
                            entry.document_name || '',
                            entry.document_type || '',
                            entry.sender_name || 'None',
                            entry.receiver_name || 'None',
                            entry.time_sent ? new Date(entry.time_sent).toLocaleString() : 'Not Sent',
                            entry.time_received ? new Date(entry.time_received).toLocaleString() : 'Not Received',
                            dept
                        ];
                        const requestTexts = entry.requester_name ? [
                            'Access Request',
                            '',
                            entry.requester_name,
                            entry.owner_name || 'None',
                            entry.time_requested ? new Date(entry.time_requested).toLocaleString() : 'Not Requested',
                            entry.time_approved ? new Date(entry.time_approved).toLocaleString() : 'Not Approved',
                            '-'
                        ] : null;

                        const mainMaxLines = getMaxLines(mainTexts, columnWidths);
                        const requestMaxLines = requestTexts ? getMaxLines(requestTexts, columnWidths) : 0;
                        const rowHeight = (mainMaxLines + (requestTexts ? requestMaxLines : 0)) * lineHeight + 2;

                        if (yPos + rowHeight > pageHeight - margin) {
                            pdf.addPage();
                            yPos = margin;
                            xPos = startX;
                            for (let i = 0; i < 7; i++) {
                                drawCell(xPos, yPos, columnWidths[i], lineHeight + 2, true);
                                xPos += columnWidths[i];
                            }
                            pdf.setTextColor(50, 50, 50);
                            pdf.setFont('helvetica', 'bold');
                            xPos = startX;
                            drawText(xPos, yPos, 'File Name', columnWidths[0], lineHeight + 2);
                            xPos += columnWidths[0];
                            drawText(xPos, yPos, 'Doc Type', columnWidths[1], lineHeight + 2);
                            xPos += columnWidths[1];
                            drawText(xPos, yPos, 'Sender', columnWidths[2], lineHeight + 2);
                            xPos += columnWidths[2];
                            drawText(xPos, yPos, 'Recipient', columnWidths[3], lineHeight + 2);
                            xPos += columnWidths[3];
                            drawText(xPos, yPos, 'Sent', columnWidths[4], lineHeight + 2);
                            xPos += columnWidths[4];
                            drawText(xPos, yPos, 'Received', columnWidths[5], lineHeight + 2);
                            xPos += columnWidths[5];
                            drawText(xPos, yPos, 'Dept', columnWidths[6], lineHeight + 2);
                            yPos += lineHeight + 3;
                            pdf.setFont('helvetica', 'normal');
                        }

                        xPos = startX;
                        for (let i = 0; i < 7; i++) {
                            drawCell(xPos, yPos, columnWidths[i], mainMaxLines * lineHeight + 2, false, i % 2 === 0);
                            xPos += columnWidths[i];
                        }
                        pdf.setTextColor(0, 0, 0);
                        xPos = startX;
                        mainTexts.forEach((text, i) => {
                            drawText(xPos, yPos, text, columnWidths[i], mainMaxLines * lineHeight + 2);
                            xPos += columnWidths[i];
                        });
                        yPos += mainMaxLines * lineHeight + 1;

                        if (requestTexts) {
                            xPos = startX;
                            for (let i = 0; i < 7; i++) {
                                drawCell(xPos, yPos, columnWidths[i], requestMaxLines * lineHeight + 2, false, i % 2 === 0);
                                xPos += columnWidths[i];
                            }
                            xPos = startX;
                            requestTexts.forEach((text, i) => {
                                drawText(xPos, yPos, text, columnWidths[i], requestMaxLines * lineHeight + 2);
                                xPos += columnWidths[i];
                            });
                            yPos += requestMaxLines * lineHeight + 1;
                        }
                    });
                } else if (currentChartType === 'UsersPerDepartment') {
                    const columnWidths = [135, 65];
                    let xPos = startX;
                    for (let i = 0; i < 2; i++) {
                        drawCell(xPos, yPos, columnWidths[i], lineHeight + 2, true);
                        xPos += columnWidths[i];
                    }
                    pdf.setTextColor(50, 50, 50);
                    pdf.setFont('helvetica', 'bold');
                    xPos = startX;
                    drawText(xPos, yPos, 'Department', columnWidths[0], lineHeight + 2);
                    xPos += columnWidths[0];
                    drawText(xPos, yPos, 'Users', columnWidths[1], lineHeight + 2);
                    yPos += lineHeight + 3;
                    pdf.setFont('helvetica', 'normal');

                    chartData.labels.forEach((label, index) => {
                        const texts = [label, String(chartData.data[index])];
                        const maxLines = getMaxLines(texts, columnWidths);
                        const rowHeight = maxLines * lineHeight + 2;

                        if (yPos + rowHeight > pageHeight - margin) {
                            pdf.addPage();
                            yPos = margin;
                            xPos = startX;
                            for (let i = 0; i < 2; i++) {
                                drawCell(xPos, yPos, columnWidths[i], lineHeight + 2, true);
                                xPos += columnWidths[i];
                            }
                            pdf.setTextColor(50, 50, 50);
                            pdf.setFont('helvetica', 'bold');
                            xPos = startX;
                            drawText(xPos, yPos, 'Department', columnWidths[0], lineHeight + 2);
                            xPos += columnWidths[0];
                            drawText(xPos, yPos, 'Users', columnWidths[1], lineHeight + 2);
                            yPos += lineHeight + 3;
                            pdf.setFont('helvetica', 'normal');
                        }

                        xPos = startX;
                        for (let i = 0; i < 2; i++) {
                            drawCell(xPos, yPos, columnWidths[i], rowHeight, false, i % 2 === 0);
                            xPos += columnWidths[i];
                        }
                        pdf.setTextColor(0, 0, 0);
                        xPos = startX;
                        texts.forEach((text, i) => {
                            drawText(xPos, yPos, text, columnWidths[i], rowHeight);
                            xPos += columnWidths[i];
                        });
                        yPos += rowHeight;
                    });
                }

                pdf.save(`${currentChartType}_Report.pdf`);
            }).catch(error => {
                console.error('Error generating PDF:', error);
                notyf.error('Failed to generate PDF. Please try again.');
            });
        }

        // Print Chart
        function printChart() {
            if (!validateCsrfToken(csrfToken)) {
                notyf.error('Invalid CSRF token');
                return;
            }

            const chartData = getChartData(currentChartType);
            const chartCanvas = document.getElementById('popupChart');

            html2canvas(chartCanvas, {
                scale: 2
            }).then(canvas => {
                const chartImage = canvas.toDataURL('image/png');
                const printWindow = window.open('', '_blank');
                let tableRows = '';

                if (currentChartType === 'FileUploadTrends') {
                    tableRows = chartData.map(entry => {
                        const dept = entry.uploader_department ? `${entry.uploader_department}${entry.uploader_subdepartment ? ' - ' + entry.uploader_subdepartment : ''}` : 'Unknown';
                        const destination = entry.target_department_name ? entry.target_department_name : '(NONE) Personal Document';
                        return `
                            <tr>
                                <td>${sanitizeHTML(entry.document_name)}</td>
                                <td>${sanitizeHTML(entry.document_type)}</td>
                                <td>${sanitizeHTML(entry.uploader_name)}</td>
                                <td>${sanitizeHTML(dept)}</td>
                                <td>${sanitizeHTML(destination)}</td>
                                <td>${new Date(entry.upload_date).toLocaleString()}</td>
                            </tr>
                        `;
                    }).join('');
                } else if (currentChartType === 'FileDistribution') {
                    tableRows = chartData.map(entry => {
                        const dept = entry.department_name ? `${entry.department_name}${entry.sub_department_name ? ' - ' + entry.sub_department_name : ''}` : 'None';
                        return `
                            <tr>
                                <td>${sanitizeHTML(entry.document_name)}</td>
                                <td>${sanitizeHTML(entry.document_type)}</td>
                                <td>${sanitizeHTML(entry.sender_name || 'None')}</td>
                                <td>${sanitizeHTML(entry.receiver_name || 'None')}</td>
                                <td>${entry.time_sent ? new Date(entry.time_sent).toLocaleString() : 'Not Sent'}</td>
                                <td>${entry.time_received ? new Date(entry.time_received).toLocaleString() : 'Not Received'}</td>
                                <td>${sanitizeHTML(dept)}</td>
                            </tr>
                            ${entry.requester_name ? `
                            <tr>
                                <td colspan="2">Access Request</td>
                                <td>${sanitizeHTML(entry.requester_name)}</td>
                                <td>${sanitizeHTML(entry.owner_name || 'None')}</td>
                                <td>${entry.time_requested ? new Date(entry.time_requested).toLocaleString() : 'Not Requested'}</td>
                                <td>${entry.time_approved ? new Date(entry.time_approved).toLocaleString() : 'Not Approved'}</td>
                                <td>-</td>
                            </tr>
                            ` : ''}
                        `;
                    }).join('');
                } else if (currentChartType === 'UsersPerDepartment') {
                    tableRows = chartData.labels.map((label, index) => `
                        <tr>
                            <td>${sanitizeHTML(label)}</td>
                            <td>${sanitizeHTML(String(chartData.data[index]))}</td>
                        </tr>
                    `).join('');
                }

                printWindow.document.write(`
                    <html>
                        <head>
                            <title>${currentChartType} Report</title>
                            <style>
                                body { font-family: Arial, sans-serif; margin: 20px; }
                                h1 { font-size: 24px; text-align: center; margin-bottom: 10px; }
                                h2 { font-size: 18px; margin-top: 20px; }
                                img { max-width: 100%; height: auto; display: block; margin: 0 auto; }
                                table { width: 100%; max-width: 800px; border-collapse: collapse; margin: 20px auto; font-size: 8pt; }
                                th, td { border: 1px solid #969696; padding: 4px; text-align: center; }
                                th { background-color: #f0f0f0 !important; font-weight: bold; color: #323232; }
                                td { color: #000000; }
                                td:nth-child(1), td:nth-child(3), td:nth-child(5) { background-color: #e6f4ea !important; }
                                td:nth-child(2), td:nth-child(4), td:nth-child(6), td:nth-child(7) { background-color: transparent !important; }
                                @media print {
                                    body { margin: 0; }
                                    img { max-width: 100%; }
                                    table { font-size: 8pt; }
                                    th { background-color: #f0f0f0 !important; -webkit-print-color-adjust: exact; color-adjust: exact; }
                                    td:nth-child(1), td:nth-child(3), td:nth-child(5) { background-color: #e6f4ea !important; -webkit-print-color-adjust: exact; color-adjust: exact; }
                                    td:nth-child(2), td:nth-child(4), td:nth-child(6), td:nth-child(7) { background-color: transparent !important; -webkit-print-color-adjust: exact; color-adjust: exact; }
                                }
                            </style>
                        </head>
                        <body>
                            <h1>${currentChartType} Report</h1>
                            <img src="${chartImage}" alt="${currentChartType} Chart">
                            <h2>Data Table</h2>
                            <table>
                                <thead>
                                    <tr>
                                        ${
                                            currentChartType === 'FileUploadTrends' ? `
                                                <th>File Name</th>
                                                <th>Document Type</th>
                                                <th>Uploader</th>
                                                <th>Uploader's Department</th>
                                                <th>Intended Destination</th>
                                                <th>Upload Date/Time</th>
                                            ` : currentChartType === 'FileDistribution' ? `
                                                <th>File Name</th>
                                                <th>Document Type</th>
                                                <th>Sender</th>
                                                <th>Recipient</th>
                                                <th>Time Sent</th>
                                                <th>Time Received</th>
                                                <th>Department/Subdepartment</th>
                                            ` : `
                                                <th>Department</th>
                                                <th>Users</th>
                                            `
                                        }
                                    </tr>
                                </thead>
                                <tbody>${tableRows}</tbody>
                            </table>
                        </body>
                    </html>
                `);

                printWindow.document.close();
                printWindow.onload = function() {
                    printWindow.focus();
                    printWindow.print();
                };
            }).catch(error => {
                console.error('Error generating print content:', error);
                notyf.error('Failed to generate print preview. Please try again.');
            });
        }
    </script>
</body>

</html>