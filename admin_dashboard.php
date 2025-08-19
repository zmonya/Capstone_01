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

// Function to sanitize HTML output
function sanitizeHTML($data)
{
    return htmlspecialchars($data ?? '', ENT_QUOTES, 'UTF-8');
}

// Fetch admin details
$adminStmt = executeQuery($pdo, "SELECT user_id, Username, Role FROM users WHERE user_id = ?", [$userId]);
$admin = $adminStmt ? $adminStmt->fetch(PDO::FETCH_ASSOC) : null;

// Fetch system statistics
$totalUsersStmt = executeQuery($pdo, "SELECT COUNT(*) FROM users");
$totalUsers = $totalUsersStmt ? $totalUsersStmt->fetchColumn() : 0;

$totalFilesStmt = executeQuery($pdo, "SELECT COUNT(*) FROM files WHERE File_status != 'disposed'");
$totalFiles = $totalFilesStmt ? $totalFilesStmt->fetchColumn() : 0;

$pendingRequestsStmt = executeQuery($pdo, "SELECT COUNT(*) FROM transactions WHERE transaction_status = 'pending' AND transaction_type = 'request'");
$pendingRequests = $pendingRequestsStmt ? $pendingRequestsStmt->fetchColumn() : 0;

$incomingFilesStmt = executeQuery($pdo, "
    SELECT COUNT(*) AS incoming_count 
    FROM transactions t
    JOIN files f ON t.file_id = f.file_id
    WHERE t.users_department_id IN (SELECT users_department_id FROM users_department WHERE user_id = ?) 
    AND t.transaction_status = 'pending' 
    AND t.transaction_type = '2'", [$userId]);
$incomingFiles = $incomingFilesStmt ? $incomingFilesStmt->fetchColumn() : 0;

$outgoingFilesStmt = executeQuery($pdo, "
    SELECT COUNT(*) AS outgoing_count 
    FROM transactions t
    JOIN files f ON t.file_id = f.file_id
    WHERE t.user_id = ? 
    AND t.transaction_status = 'pending' 
    AND t.transaction_type = '2'", [$userId]);
$outgoingFiles = $outgoingFilesStmt ? $outgoingFilesStmt->fetchColumn() : 0;

// Fetch pending requests details
$pendingRequestsDetailsStmt = executeQuery($pdo, "
    SELECT t.transaction_id, f.file_name, u.username AS requester_name, 
           COALESCE(d2.department_name, d.department_name) AS requester_department,
           CASE WHEN d2.department_id IS NOT NULL THEN d.department_name ELSE NULL END AS requester_subdepartment,
           f.physical_storage
    FROM transactions t
    JOIN files f ON t.file_id = f.file_id
    JOIN users u ON t.user_id = u.user_id
    JOIN users_department ud ON u.user_id = ud.user_id
    JOIN departments d ON ud.department_id = d.department_id
    LEFT JOIN departments d2 ON d.parent_department_id = d2.department_id
    WHERE t.transaction_status = 'pending' AND t.transaction_type = '10'
    GROUP BY t.transaction_id");
$pendingRequestsDetails = $pendingRequestsDetailsStmt ? $pendingRequestsDetailsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

// Fetch file upload trends (Last 7 Days)
$fileUploadTrendsStmt = executeQuery($pdo, "
    SELECT 
        f.file_name AS document_name,
        dt.type_name AS document_type,
        f.upload_date AS upload_date,
        u.username AS uploader_name,
        COALESCE(d2.department_name, d.department_name) AS uploader_department,
        CASE WHEN d2.department_id IS NOT NULL THEN d.department_name ELSE NULL END AS uploader_subdepartment,
        td.department_name AS target_department_name
    FROM files f
    LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
    LEFT JOIN users u ON f.user_id = u.user_id
    LEFT JOIN users_department uda ON u.user_id = uda.user_id
    LEFT JOIN departments d ON uda.department_id = d.department_id
    LEFT JOIN departments d2 ON d.parent_department_id = d2.department_id
    LEFT JOIN transactions t ON f.file_id = t.file_id AND t.transaction_type = '2'
    LEFT JOIN users_department tud ON t.users_department_id = tud.users_department_id
    LEFT JOIN departments td ON tud.department_id = td.department_id
    WHERE f.upload_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    AND f.file_status != 'disposed'
    ORDER BY f.upload_date ASC");
$fileUploadTrends = $fileUploadTrendsStmt ? $fileUploadTrendsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

// Fetch file distribution by document type
$fileDistributionByTypeStmt = executeQuery($pdo, "
    SELECT 
        f.file_name AS document_name,
        dt.type_name AS document_type,
        us.username AS sender_name,
        ur.username AS receiver_name,
        t.transaction_time AS time_sent,
        t2.transaction_time AS time_received,
        uq.username AS requester_name,
        uo.username AS owner_name,
        COALESCE(d2.department_name, d.department_name) AS department_name,
        CASE WHEN d2.department_id IS NOT NULL THEN d.department_name ELSE NULL END AS sub_department_name
    FROM files f
    LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
    LEFT JOIN transactions t ON f.file_id = t.file_id AND t.transaction_type = '2'
    LEFT JOIN users us ON t.user_id = us.user_id
    LEFT JOIN transactions t2 ON f.file_id = t2.file_id AND t2.transaction_type = '12'
    LEFT JOIN users ur ON t2.user_id = ur.user_id
    LEFT JOIN transactions t3 ON f.file_id = t3.file_id AND t3.transaction_type = '10'
    LEFT JOIN users uq ON t3.user_id = uq.user_id
    LEFT JOIN transactions t4 ON f.file_id = t4.file_id AND t4.transaction_type = '12'
    LEFT JOIN users uo ON f.user_id = uo.user_id
    LEFT JOIN users_department ud ON uo.user_id = ud.user_id
    LEFT JOIN departments d ON ud.department_id = d.department_id
    LEFT JOIN departments d2 ON d.parent_department_id = d2.department_id");
$fileDistribution = $fileDistributionByTypeStmt ? $fileDistributionByTypeStmt->fetchAll(PDO::FETCH_ASSOC) : [];

// Fetch users per department
$usersPerDepartmentStmt = executeQuery($pdo, "
    SELECT 
        COALESCE(d2.Department_name, d.Department_name) AS department_name,
        COUNT(DISTINCT ud.User_id) AS user_count
    FROM departments d
    LEFT JOIN departments d2 ON d.Parent_department_id = d2.department_id
    LEFT JOIN users_department ud ON d.department_id = ud.Department_id
    WHERE d.Department_type IN ('college', 'office')
    GROUP BY d.department_id
    ORDER BY department_name");
$usersPerDepartment = $usersPerDepartmentStmt ? $usersPerDepartmentStmt->fetchAll(PDO::FETCH_ASSOC) : [];

// Fetch document copies details
$documentCopiesStmt = executeQuery($pdo, "
    SELECT 
        f.File_name,
        COUNT(DISTINCT c.file_id) AS copy_count,
        GROUP_CONCAT(DISTINCT COALESCE(d2.Department_name, d.Department_name)) AS offices_with_copy,
        GROUP_CONCAT(DISTINCT c.physical_storage) AS physical_duplicates
    FROM files f
    LEFT JOIN files c ON f.file_id = c.Parent_file_id
    LEFT JOIN transactions t ON c.file_id = t.file_id AND t.transaction_type IN ('send', 'accept')
    LEFT JOIN users_department ud ON t.users_department_id = ud.users_department_id
    LEFT JOIN departments d ON ud.Department_id = d.department_id
    LEFT JOIN departments d2 ON d.Parent_department_id = d2.department_id
    WHERE f.File_status != 'disposed'
    GROUP BY f.file_id");
$documentCopies = $documentCopiesStmt ? $documentCopiesStmt->fetchAll(PDO::FETCH_ASSOC) : [];

// Fetch retrieval history
$retrievalHistoryStmt = executeQuery($pdo, "
    SELECT 
        t.transaction_id,
        t.transaction_type AS type,
        t.transaction_status AS status,
        t.transaction_time AS time,
        u.Username AS user_name,
        f.File_name,
        COALESCE(d2.Department_name, d.Department_name) AS department_name,
        f.physical_storage
    FROM transactions t
    JOIN files f ON t.file_id = f.file_id
    JOIN users u ON t.user_id = u.user_id
    JOIN users_department ud ON t.users_department_id = ud.users_department_id
    JOIN departments d ON ud.Department_id = d.department_id
    LEFT JOIN departments d2 ON d.Parent_department_id = d2.department_id
    WHERE t.transaction_type IN ('request', 'send', 'accept')
    ORDER BY t.transaction_time DESC");
$retrievalHistory = $retrievalHistoryStmt ? $retrievalHistoryStmt->fetchAll(PDO::FETCH_ASSOC) : [];

// Fetch access history (only accept transactions)
$accessHistoryStmt = executeQuery($pdo, "
    SELECT 
        t.transaction_id,
        t.transaction_time AS time,
        u.Username AS user_name,
        f.File_name,
        t.transaction_type AS type,
        COALESCE(d2.Department_name, d.Department_name) AS department_name
    FROM transactions t
    JOIN files f ON t.file_id = f.file_id
    JOIN users u ON t.user_id = u.user_id
    JOIN users_department ud ON t.users_department_id = ud.users_department_id
    JOIN departments d ON ud.Department_id = d.department_id
    LEFT JOIN departments d2 ON d.Parent_department_id = d2.department_id
    WHERE t.transaction_type = 'accept'
    ORDER BY t.transaction_time DESC");
$accessHistory = $accessHistoryStmt ? $accessHistoryStmt->fetchAll(PDO::FETCH_ASSOC) : [];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title>Admin Dashboard - ArcHive</title>
    <?php
        include 'admin_head.php';
    ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
</head>   

<body class="admin-dashboard">
    <?php
        include 'admin_menu.php';
    ?>

    <div class="main-content">
        <h2>Welcome, <?php echo sanitizeHTML($admin['Username']); ?>!</h2>
        <div class="admin-stats">
            <div class="stat-card">
                <h3>Total Users</h3>
                <p><?php echo $totalUsers; ?></p>
            </div>
            <div class="stat-card">
                <h3>Total Files</h3>
                <p><?php echo $totalFiles; ?></p>
            </div>
            <div class="stat-card">
                <h3>Pending Requests</h3>
                <p><?php echo $pendingRequests; ?></p>
            </div>
            <div class="stat-card">
                <h3>Incoming Files</h3>
                <p><?php echo $incomingFiles; ?></p>
            </div>
            <div class="stat-card">
                <h3>Outgoing Files</h3>
                <p><?php echo $outgoingFiles; ?></p>
            </div>
        </div>
        <div class="chart-grid">
            <div class="chart-container" data-chart-type="FileUploadTrends">
                <h3>File Upload Trends (Last 7 Days)</h3>
                <canvas id="fileUploadTrendsChart"></canvas>
                <div class="chart-actions" style="text-align: right; margin-bottom: 10px;">
                    <button onclick="generateReport('FileUploadTrends')">Print Report</button>
                    <button onclick="openDownloadModal('FileUploadTrends')">Download Report</button>
                </div>
            </div>
            <div class="chart-container" data-chart-type="FileDistribution">
                <h3>File Distribution by Document Type</h3>
                <canvas id="fileDistributionChart"></canvas>
                <div class="chart-actions" style="text-align: right; margin-bottom: 10px;">
                    <button onclick="generateReport('FileDistribution')">Print Report</button>
                    <button onclick="openDownloadModal('FileDistribution')">Download Report</button>
                </div>
            </div>
            <div class="chart-container" data-chart-type="UsersPerDepartment">
                <h3>Users Per Department</h3>
                <canvas id="usersPerDepartmentChart"></canvas>
                <div class="chart-actions" style="text-align: right; margin-bottom: 10px;">
                    <button onclick="generateReport('UsersPerDepartment')">Print Report</button>
                    <button onclick="openDownloadModal('UsersPerDepartment')">Download Report</button>
                </div>
            </div>
            <div class="chart-container" data-chart-type="DocumentCopies">
                <h3>Document Copies Details</h3>
                <canvas id="documentCopiesChart"></canvas>
                <div class="chart-actions" style="text-align: right; margin-bottom: 10px;">
                    <button onclick="generateReport('DocumentCopies')">Print Report</button>
                    <button onclick="openDownloadModal('DocumentCopies')">Download Report</button>
                </div>
            </div>
            <div class="chart-container" data-chart-type="PendingRequests">
                <h3>Pending Requests</h3>
                <div class="chart-actions" style="text-align: right; margin-bottom: 10px;">
                    <button onclick="generateReport('PendingRequests')">Print Report</button>
                    <button onclick="openDownloadModal('PendingRequests')">Download Report</button>
                </div>
            </div>
            <div class="chart-container" data-chart-type="RetrievalHistory">
                <h3>Retrieval History</h3>
                <div class="chart-actions" style="text-align: right; margin-bottom: 10px;">
                    <button onclick="generateReport('RetrievalHistory')">Print Report</button>
                    <button onclick="openDownloadModal('RetrievalHistory')">Download Report</button>
                </div>
            </div>
            <div class="chart-container" data-chart-type="AccessHistory">
                <h3>Access History</h3>
                <div class="chart-actions" style="text-align: right; margin-bottom: 10px;">
                    <button onclick="generateReport('AccessHistory')">Print Report</button>
                    <button onclick="openDownloadModal('AccessHistory')">Download Report</button>
                </div>
            </div>

            <div class="modal-overlay" id="dataTableModal" style="display: none;">
                <div class="modal-content">
                    <button class="modal-close" onclick="closeModal()">×</button>
                    <h3 id="modalTitle"></h3>
                    <div class="pagination-controls" style="margin-bottom: 10px;">
                        <label for="itemsPerPage">Items per page:</label>
                        <select id="itemsPerPage" onchange="updatePagination()">
                            <option value="5">5</option>
                            <option value="10">10</option>
                            <option value="20">20</option>
                            <option value="all">All</option>
                        </select>
                        <button onclick="previousPage()" id="prevPage" disabled>Previous</button>
                        <span id="pageInfo"></span>
                        <button onclick="nextPage()" id="nextPage">Next</button>
                    </div>
                    <div id="modalTable" style="overflow-x: auto;"></div>
                </div>
            </div>

            <div class="modal-overlay" id="downloadFormatModal" style="display: none;">
                <div class="modal-content">
                    <button class="modal-close" onclick="closeDownloadModal()">×</button>
                    <h3 id="downloadModalTitle">Select Download Format</h3>
                    <div class="download-options" style="text-align: center; margin-top: 20px;">
                        <button onclick="downloadReport(currentChartType, 'csv')">Download as CSV</button>
                        <button onclick="downloadReport(currentChartType, 'pdf')">Download as PDF</button>
                    </div>
                </div>
            </div>
        </div>
        <script>
            // Pass PHP data to JavaScript
            const fileUploadTrends = <?php echo json_encode($fileUploadTrends); ?>;
            const fileDistribution = <?php echo json_encode($fileDistribution); ?>;
            const usersPerDepartment = <?php echo json_encode($usersPerDepartment); ?>;
            const documentCopies = <?php echo json_encode($documentCopies); ?>;
            const pendingRequestsDetails = <?php echo json_encode($pendingRequestsDetails); ?>;
            const retrievalHistory = <?php echo json_encode($retrievalHistory); ?>;
            const accessHistory = <?php echo json_encode($accessHistory); ?>;

            // Sanitize HTML output in JavaScript
            function sanitizeHTML(str) {
                const div = document.createElement('div');
                div.textContent = str ?? '';
                return div.innerHTML;
            }

            // Escape CSV field
            function escapeCsvField(str) {
                if (str === null || str === undefined) return '""';
                const stringified = String(str);
                if (stringified.includes('"') || stringified.includes(',') || stringified.includes('\n')) {
                    return `"${stringified.replace(/"/g, '""')}"`;
                }
                return `"${stringified}"`;
            }

            // Pagination variables
            let currentPage = 1;
            let itemsPerPage = 5;
            let currentData = [];
            let currentChartType = '';

            // Initialize charts
            function initializeCharts() {
                // File Upload Trends (Line Chart)
                if (fileUploadTrends.length > 0) {
                    const uploadDates = [...new Set(fileUploadTrends.map(item => new Date(item.upload_date).toLocaleDateString()))];
                    const uploadCounts = uploadDates.map(date =>
                        fileUploadTrends.filter(item => new Date(item.upload_date).toLocaleDateString() === date).length
                    );
                    new Chart(document.getElementById('fileUploadTrendsChart'), {
                        type: 'line',
                        data: {
                            labels: uploadDates,
                            datasets: [{
                                label: 'File Uploads',
                                data: uploadCounts,
                                borderColor: '#3498db',
                                backgroundColor: 'rgba(52, 152, 219, 0.2)',
                                fill: true,
                                tension: 0.4
                            }]
                        },
                        options: {
                            responsive: true,
                            plugins: {
                                legend: {
                                    position: 'bottom'
                                },
                                title: {
                                    display: true,
                                    text: 'File Upload Trends (Last 7 Days)'
                                }
                            },
                            scales: {
                                x: {
                                    title: {
                                        display: true,
                                        text: 'Date'
                                    }
                                },
                                y: {
                                    title: {
                                        display: true,
                                        text: 'Number of Uploads'
                                    },
                                    beginAtZero: true
                                }
                            }
                        }
                    });
                } else {
                    document.getElementById('fileUploadTrendsChart').parentElement.innerHTML += '<p>No data available for File Upload Trends.</p>';
                }

                // File Distribution (Bar Chart)
                if (fileDistribution.length > 0) {
                    const docTypes = [...new Set(fileDistribution.map(item => item.document_type))];
                    const docCounts = docTypes.map(type =>
                        fileDistribution.filter(item => item.document_type === type).length
                    );
                    new Chart(document.getElementById('fileDistributionChart'), {
                        type: 'bar',
                        data: {
                            labels: docTypes,
                            datasets: [{
                                label: 'Files by Document Type',
                                data: docCounts,
                                backgroundColor: '#2ecc71',
                                borderColor: '#27ae60',
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            plugins: {
                                legend: {
                                    position: 'bottom'
                                },
                                title: {
                                    display: true,
                                    text: 'File Distribution by Document Type'
                                }
                            },
                            scales: {
                                x: {
                                    title: {
                                        display: true,
                                        text: 'Document Type'
                                    }
                                },
                                y: {
                                    title: {
                                        display: true,
                                        text: 'Number of Files'
                                    },
                                    beginAtZero: true
                                }
                            }
                        }
                    });
                } else {
                    document.getElementById('fileDistributionChart').parentElement.innerHTML += '<p>No data available for File Distribution.</p>';
                }

                // Users Per Department (Bar Chart)
                if (usersPerDepartment.length > 0) {
                    new Chart(document.getElementById('usersPerDepartmentChart'), {
                        type: 'bar',
                        data: {
                            labels: usersPerDepartment.map(item => item.department_name),
                            datasets: [{
                                label: 'Users per Department',
                                data: usersPerDepartment.map(item => item.user_count),
                                backgroundColor: '#e74c3c',
                                borderColor: '#c0392b',
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            plugins: {
                                legend: {
                                    position: 'bottom'
                                },
                                title: {
                                    display: true,
                                    text: 'Users Per Department'
                                }
                            },
                            scales: {
                                x: {
                                    title: {
                                        display: true,
                                        text: 'Department'
                                    }
                                },
                                y: {
                                    title: {
                                        display: true,
                                        text: 'Number of Users'
                                    },
                                    beginAtZero: true
                                }
                            }
                        }
                    });
                } else {
                    document.getElementById('usersPerDepartmentChart').parentElement.innerHTML += '<p>No data available for Users Per Department.</p>';
                }

                // Document Copies (Bar Chart)
                if (documentCopies.length > 0) {
                    new Chart(document.getElementById('documentCopiesChart'), {
                        type: 'bar',
                        data: {
                            labels: documentCopies.map(item => item.file_name),
                            datasets: [{
                                label: 'Copy Count per File',
                                data: documentCopies.map(item => item.copy_count),
                                backgroundColor: '#f1c40f',
                                borderColor: '#f39c12',
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            plugins: {
                                legend: {
                                    position: 'bottom'
                                },
                                title: {
                                    display: true,
                                    text: 'Document Copies Details'
                                }
                            },
                            scales: {
                                x: {
                                    title: {
                                        display: true,
                                        text: 'File Name'
                                    }
                                },
                                y: {
                                    title: {
                                        display: true,
                                        text: 'Number of Copies'
                                    },
                                    beginAtZero: true
                                }
                            }
                        }
                    });
                } else {
                    document.getElementById('documentCopiesChart').parentElement.innerHTML += '<p>No data available for Document Copies.</p>';
                }
            }

            // Generate table content with pagination
            function generateTableContent(chartType, page = 1, itemsPerPage = 5) {
                let tableContent = '';
                let data;

                switch (chartType) {
                    case 'FileUploadTrends':
                        data = fileUploadTrends;
                        tableContent = data.length > 0 ? `
                            <table class="chart-data-table">
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
                                    ${data.slice((page - 1) * itemsPerPage, page * itemsPerPage).map(entry => `
                                        <tr>
                                            <td>${sanitizeHTML(entry.document_name)}</td>
                                            <td>${sanitizeHTML(entry.document_type)}</td>
                                            <td>${sanitizeHTML(entry.uploader_name)}</td>
                                            <td>${sanitizeHTML(entry.uploader_department || 'None')}${entry.uploader_subdepartment ? ' / ' + sanitizeHTML(entry.uploader_subdepartment) : ''}</td>
                                            <td>${sanitizeHTML(entry.target_department_name || 'None')}</td>
                                            <td>${new Date(entry.upload_date).toLocaleString()}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        ` : '<p>No data available.</p>';
                        break;
                    case 'FileDistribution':
                        data = fileDistribution;
                        tableContent = data.length > 0 ? `
                            <table class="chart-data-table">
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
                                    ${data.slice((page - 1) * itemsPerPage, page * itemsPerPage).map(entry => `
                                        <tr>
                                            <td>${sanitizeHTML(entry.document_name)}</td>
                                            <td>${sanitizeHTML(entry.document_type)}</td>
                                            <td>${sanitizeHTML(entry.sender_name || 'None')}</td>
                                            <td>${sanitizeHTML(entry.receiver_name || 'None')}</td>
                                            <td>${entry.time_sent ? new Date(entry.time_sent).toLocaleString() : 'N/A'}</td>
                                            <td>${entry.time_received ? new Date(entry.time_received).toLocaleString() : 'N/A'}</td>
                                            <td>${sanitizeHTML(entry.department_name || 'None')}${entry.sub_department_name ? ' / ' + sanitizeHTML(entry.sub_department_name) : ''}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        ` : '<p>No data available.</p>';
                        break;
                    case 'UsersPerDepartment':
                        data = usersPerDepartment;
                        tableContent = data.length > 0 ? `
                            <table class="chart-data-table">
                                <thead>
                                    <tr>
                                        <th>Department</th>
                                        <th>User Count</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${data.slice((page - 1) * itemsPerPage, page * itemsPerPage).map(entry => `
                                        <tr>
                                            <td>${sanitizeHTML(entry.department_name)}</td>
                                            <td>${sanitizeHTML(entry.user_count.toString())}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        ` : '<p>No data available.</p>';
                        break;
                    case 'DocumentCopies':
                        data = documentCopies;
                        tableContent = data.length > 0 ? `
                            <table class="chart-data-table">
                                <thead>
                                    <tr>
                                        <th>File Name</th>
                                        <th>Copy Count</th>
                                        <th>Offices with Copy</th>
                                        <th>Physical Duplicates</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${data.slice((page - 1) * itemsPerPage, page * itemsPerPage).map(entry => `
                                        <tr>
                                            <td>${sanitizeHTML(entry.file_name)}</td>
                                            <td>${sanitizeHTML(entry.copy_count.toString())}</td>
                                            <td>${sanitizeHTML(entry.offices_with_copy || 'None')}</td>
                                            <td>${sanitizeHTML(entry.physical_duplicates || 'None')}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        ` : '<p>No data available.</p>';
                        break;
                    case 'PendingRequests':
                        data = pendingRequestsDetails;
                        tableContent = data.length > 0 ? `
                            <table class="chart-data-table">
                                <thead>
                                    <tr>
                                        <th>File Name</th>
                                        <th>Requester</th>
                                        <th>Requester's Department</th>
                                        <th>Physical Storage</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${data.slice((page - 1) * itemsPerPage, page * itemsPerPage).map(entry => `
                                        <tr>
                                            <td>${sanitizeHTML(entry.file_name)}</td>
                                            <td>${sanitizeHTML(entry.requester_name)}</td>
                                            <td>${sanitizeHTML(entry.requester_department || 'None')}${entry.requester_subdepartment ? ' / ' + sanitizeHTML(entry.requester_subdepartment) : ''}</td>
                                            <td>${sanitizeHTML(entry.physical_storage || 'None')}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        ` : '<p>No data available.</p>';
                        break;
                    case 'RetrievalHistory':
                        data = retrievalHistory;
                        tableContent = data.length > 0 ? `
                            <table class="chart-data-table">
                                <thead>
                                    <tr>
                                        <th>Transaction ID</th>
                                        <th>Type</th>
                                        <th>Status</th>
                                        <th>Time</th>
                                        <th>User</th>
                                        <th>File Name</th>
                                        <th>Department</th>
                                        <th>Physical Storage</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${data.slice((page - 1) * itemsPerPage, page * itemsPerPage).map(entry => `
                                        <tr>
                                            <td>${sanitizeHTML(entry.transaction_id.toString())}</td>
                                            <td>${sanitizeHTML(entry.type)}</td>
                                            <td>${sanitizeHTML(entry.status)}</td>
                                            <td>${new Date(entry.time).toLocaleString()}</td>
                                            <td>${sanitizeHTML(entry.user_name)}</td>
                                            <td>${sanitizeHTML(entry.file_name)}</td>
                                            <td>${sanitizeHTML(entry.department_name || 'None')}</td>
                                            <td>${sanitizeHTML(entry.physical_storage || 'None')}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        ` : '<p>No data available.</p>';
                        break;
                    case 'AccessHistory':
                        data = accessHistory;
                        tableContent = data.length > 0 ? `
                            <table class="chart-data-table">
                                <thead>
                                    <tr>
                                        <th>Transaction ID</th>
                                        <th>Time</th>
                                        <th>User</th>
                                        <th>File Name</th>
                                        <th>Type</th>
                                        <th>Department</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${data.slice((page - 1) * itemsPerPage, page * itemsPerPage).map(entry => `
                                        <tr>
                                            <td>${sanitizeHTML(entry.transaction_id.toString())}</td>
                                            <td>${new Date(entry.time).toLocaleString()}</td>
                                            <td>${sanitizeHTML(entry.user_name)}</td>
                                            <td>${sanitizeHTML(entry.file_name)}</td>
                                            <td>${sanitizeHTML(entry.type)}</td>
                                            <td>${sanitizeHTML(entry.department_name || 'None')}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        ` : '<p>No data available.</p>';
                        break;
                }
                currentData = data;
                return tableContent;
            }

            // Update pagination controls
            function updatePagination() {
                const itemsPerPageSelect = document.getElementById('itemsPerPage');
                itemsPerPage = itemsPerPageSelect.value === 'all' ? currentData.length : parseInt(itemsPerPageSelect.value);
                currentPage = 1;
                renderTable();
            }

            // Go to previous page
            function previousPage() {
                if (currentPage > 1) {
                    currentPage--;
                    renderTable();
                }
            }

            // Go to next page
            function nextPage() {
                const maxPage = Math.ceil(currentData.length / itemsPerPage);
                if (currentPage < maxPage) {
                    currentPage++;
                    renderTable();
                }
            }

            // Render table with pagination
            function renderTable() {
                const modalTable = document.getElementById('modalTable');
                modalTable.innerHTML = generateTableContent(currentChartType, currentPage, itemsPerPage);

                const prevButton = document.getElementById('prevPage');
                const nextButton = document.getElementById('nextPage');
                const pageInfo = document.getElementById('pageInfo');

                const maxPage = Math.ceil(currentData.length / itemsPerPage);
                prevButton.disabled = currentPage === 1;
                nextButton.disabled = currentPage === maxPage || currentData.length === 0;
                pageInfo.textContent = `Page ${currentPage} of ${maxPage || 1}`;
            }

            // Open modal with table
            function openModal(container) {
                const chartType = container.getAttribute('data-chart-type');
                currentChartType = chartType;
                currentPage = 1;
                const modal = document.getElementById('dataTableModal');
                const modalTitle = document.getElementById('modalTitle');

                // Set title
                modalTitle.textContent = container.querySelector('h3').textContent;

                // Render table
                renderTable();

                // Show modal
                modal.style.display = 'flex';
            }

            // Close modal
            function closeModal() {
                const modal = document.getElementById('dataTableModal');
                modal.style.display = 'none';
                document.getElementById('modalTable').innerHTML = '';
            }

            // Open download format modal
            function openDownloadModal(chartType) {
                currentChartType = chartType;
                const modal = document.getElementById('downloadFormatModal');
                const modalTitle = document.getElementById('downloadModalTitle');
                modalTitle.textContent = `Select Download Format for ${chartType} Report`;
                modal.style.display = 'flex';
            }

            // Close download format modal
            function closeDownloadModal() {
                const modal = document.getElementById('downloadFormatModal');
                modal.style.display = 'none';
            }

            // Handle chart clicks to open modal
            document.querySelectorAll('.chart-container').forEach(container => {
                container.addEventListener('click', (e) => {
                    // Ignore clicks on buttons or within chart-actions
                    if (e.target.tagName === 'BUTTON' || e.target.closest('.chart-actions')) return;

                    // Open modal with table
                    openModal(container);
                });
            });

            // Generate report content
            function generateReportContent(chartType) {
                let data;
                let tableRows = '';
                let chartImage = '';

                switch (chartType) {
                    case 'FileUploadTrends':
                        data = fileUploadTrends;
                        tableRows = data.map(entry => `
                        <tr>
                            <td>${sanitizeHTML(entry.document_name)}</td>
                            <td>${sanitizeHTML(entry.document_type)}</td>
                            <td>${sanitizeHTML(entry.uploader_name)}</td>
                            <td>${sanitizeHTML(entry.uploader_department || 'None')}${entry.uploader_subdepartment ? ' / ' + sanitizeHTML(entry.uploader_subdepartment) : ''}</td>
                            <td>${sanitizeHTML(entry.target_department_name || 'None')}</td>
                            <td>${new Date(entry.upload_date).toLocaleString()}</td>
                        </tr>
                    `).join('');
                        chartImage = document.getElementById('fileUploadTrendsChart')?.toDataURL('image/png') || '';
                        break;
                    case 'FileDistribution':
                        data = fileDistribution;
                        tableRows = data.map(entry => `
                        <tr>
                            <td>${sanitizeHTML(entry.document_name)}</td>
                            <td>${sanitizeHTML(entry.document_type)}</td>
                            <td>${sanitizeHTML(entry.sender_name || 'None')}</td>
                            <td>${sanitizeHTML(entry.receiver_name || 'None')}</td>
                            <td>${entry.time_sent ? new Date(entry.time_sent).toLocaleString() : 'N/A'}</td>
                            <td>${entry.time_received ? new Date(entry.time_received).toLocaleString() : 'N/A'}</td>
                            <td>${sanitizeHTML(entry.department_name || 'None')}${entry.sub_department_name ? ' / ' + sanitizeHTML(entry.sub_department_name) : ''}</td>
                        </tr>
                    `).join('');
                        chartImage = document.getElementById('fileDistributionChart')?.toDataURL('image/png') || '';
                        break;
                    case 'UsersPerDepartment':
                        data = usersPerDepartment;
                        tableRows = data.map(entry => `
                        <tr>
                            <td>${sanitizeHTML(entry.department_name)}</td>
                            <td>${sanitizeHTML(entry.user_count.toString())}</td>
                        </tr>
                    `).join('');
                        chartImage = document.getElementById('usersPerDepartmentChart')?.toDataURL('image/png') || '';
                        break;
                    case 'DocumentCopies':
                        data = documentCopies;
                        tableRows = data.map(entry => `
                        <tr>
                            <td>${sanitizeHTML(entry.file_name)}</td>
                            <td>${sanitizeHTML(entry.copy_count.toString())}</td>
                            <td>${sanitizeHTML(entry.offices_with_copy || 'None')}</td>
                            <td>${sanitizeHTML(entry.physical_duplicates || 'None')}</td>
                        </tr>
                    `).join('');
                        chartImage = document.getElementById('documentCopiesChart')?.toDataURL('image/png') || '';
                        break;
                    case 'PendingRequests':
                        data = pendingRequestsDetails;
                        tableRows = data.map(entry => `
                        <tr>
                            <td>${sanitizeHTML(entry.file_name)}</td>
                            <td>${sanitizeHTML(entry.requester_name)}</td>
                            <td>${sanitizeHTML(entry.requester_department || 'None')}${entry.requester_subdepartment ? ' / ' + sanitizeHTML(entry.requester_subdepartment) : ''}</td>
                            <td>${sanitizeHTML(entry.physical_storage || 'None')}</td>
                        </tr>
                    `).join('');
                        break;
                    case 'RetrievalHistory':
                        data = retrievalHistory;
                        tableRows = data.map(entry => `
                        <tr>
                            <td>${sanitizeHTML(entry.transaction_id.toString())}</td>
                            <td>${sanitizeHTML(entry.type)}</td>
                            <td>${sanitizeHTML(entry.status)}</td>
                            <td>${new Date(entry.time).toLocaleString()}</td>
                            <td>${sanitizeHTML(entry.user_name)}</td>
                            <td>${sanitizeHTML(entry.file_name)}</td>
                            <td>${sanitizeHTML(entry.department_name || 'None')}</td>
                            <td>${sanitizeHTML(entry.physical_storage || 'None')}</td>
                        </tr>
                    `).join('');
                        break;
                    case 'AccessHistory':
                        data = accessHistory;
                        tableRows = data.map(entry => `
                        <tr>
                            <td>${sanitizeHTML(entry.transaction_id.toString())}</td>
                            <td>${new Date(entry.time).toLocaleString()}</td>
                            <td>${sanitizeHTML(entry.user_name)}</td>
                            <td>${sanitizeHTML(entry.file_name)}</td>
                            <td>${sanitizeHTML(entry.type)}</td>
                            <td>${sanitizeHTML(entry.department_name || 'None')}</td>
                        </tr>
                    `).join('');
                        break;
                }

                return `
                <html>
                    <head>
                        <title>${chartType} Report - ArcHive</title>
                        <style>
                            body { 
                                font-family: Arial, sans-serif; 
                                margin: 20px; 
                                color: #333; 
                            }
                            h1 { 
                                font-size: 24px; 
                                text-align: center; 
                                margin-bottom: 10px; 
                                color: #34495e; 
                            }
                            h2 { 
                                font-size: 18px; 
                                margin-top: 20px; 
                                color: #34495e; 
                            }
                            img { 
                                max-width: 600px; 
                                display: block; 
                                margin: 10px auto; 
                            }
                            table { 
                                width: 100%; 
                                max-width: 1000px; 
                                border-collapse: collapse; 
                                margin: 20px auto; 
                                font-size: 10pt; 
                                background-color: #fff; 
                                box-shadow: 0 2px 5px rgba(0,0,0,0.1); 
                            }
                            th, td { 
                                border: 1px solid #ddd; 
                                padding: 8px; 
                                text-align: left; 
                                word-wrap: break-word; 
                            }
                            th { 
                                background-color: #f0f0f0; 
                                font-weight: bold; 
                                color: #34495e; 
                                text-transform: uppercase; 
                            }
                            td { 
                                color: #333; 
                            }
                            tr:nth-child(even) { 
                                background-color: #f9f9f9; 
                            }
                            tr:hover { 
                                background-color: #f1f5f9; 
                            }
                            @media print {
                                body { margin: 0; }
                                table { font-size: 9pt; }
                                th { background-color: #f0f0f0 !important; -webkit-print-color-adjust: exact; }
                                tr:nth-child(even) { background-color: #f9f9f9 !important; -webkit-print-color-adjust: exact; }
                            }
                        </style>
                    </head>
                    <body>
                        <h1>${chartType} Report</h1>
                        ${chartImage ? `<img src="${chartImage}" alt="${chartType} Chart">` : ''}
                        <h2>Data Table</h2>
                        <table>
                            <thead>
                                <tr>
                                    ${
                                        chartType === 'FileUploadTrends' ? `
                                            <th>File Name</th>
                                            <th>Document Type</th>
                                            <th>Uploader</th>
                                            <th>Uploader's Department</th>
                                            <th>Intended Destination</th>
                                            <th>Upload Date/Time</th>
                                        ` : chartType === 'FileDistribution' ? `
                                            <th>File Name</th>
                                            <th>Document Type</th>
                                            <th>Sender</th>
                                            <th>Recipient</th>
                                            <th>Time Sent</th>
                                            <th>Time Received</th>
                                            <th>Department/Subdepartment</th>
                                        ` : chartType === 'UsersPerDepartment' ? `
                                            <th>Department</th>
                                            <th>User Count</th>
                                        ` : chartType === 'DocumentCopies' ? `
                                            <th>File Name</th>
                                            <th>Copy Count</th>
                                            <th>Offices with Copy</th>
                                            <th>Physical Duplicates</th>
                                        ` : chartType === 'PendingRequests' ? `
                                            <th>File Name</th>
                                            <th>Requester</th>
                                            <th>Requester's Department</th>
                                            <th>Physical Storage</th>
                                        ` : chartType === 'RetrievalHistory' ? `
                                            <th>Transaction ID</th>
                                            <th>Type</th>
                                            <th>Status</th>
                                            <th>Time</th>
                                            <th>User</th>
                                            <th>File Name</th>
                                            <th>Department</th>
                                            <th>Physical Storage</th>
                                        ` : chartType === 'AccessHistory' ? `
                                            <th>Transaction ID</th>
                                            <th>Time</th>
                                            <th>User</th>
                                            <th>File Name</th>
                                            <th>Type</th>
                                            <th>Department</th>
                                        ` : ''
                                    }
                                </tr>
                            </thead>
                            <tbody>${tableRows}</tbody>
                        </table>
                    </body>
                </html>
                `;
            }

            // Generate printable report
            function generateReport(chartType) {
                const reportContent = generateReportContent(chartType);
                const printWindow = window.open('', '_blank');
                printWindow.document.write(reportContent);
                printWindow.document.close();
                printWindow.onload = function() {
                    printWindow.focus();
                    printWindow.print();
                };
            }

            // Download report as CSV or PDF
            function downloadReport(chartType, format) {
                let data;
                let csvContent = '';

                if (format === 'csv') {
                    switch (chartType) {
                        case 'FileUploadTrends':
                            data = fileUploadTrends;
                            csvContent += 'File Name,Document Type,Uploader,Uploader\'s Department,Intended Destination,Upload Date/Time\n';
                            data.forEach(entry => {
                                csvContent += [
                                    escapeCsvField(entry.document_name),
                                    escapeCsvField(entry.document_type),
                                    escapeCsvField(entry.uploader_name),
                                    escapeCsvField(entry.uploader_department || 'None') + (entry.uploader_subdepartment ? ' / ' + escapeCsvField(entry.uploader_subdepartment) : ''),
                                    escapeCsvField(entry.target_department_name || 'None'),
                                    escapeCsvField(new Date(entry.upload_date).toLocaleString())
                                ].join(',') + '\n';
                            });
                            break;
                        case 'FileDistribution':
                            data = fileDistribution;
                            csvContent += 'File Name,Document Type,Sender,Recipient,Time Sent,Time Received,Department/Subdepartment\n';
                            data.forEach(entry => {
                                csvContent += [
                                    escapeCsvField(entry.document_name),
                                    escapeCsvField(entry.document_type),
                                    escapeCsvField(entry.sender_name || 'None'),
                                    escapeCsvField(entry.receiver_name || 'None'),
                                    escapeCsvField(entry.time_sent ? new Date(entry.time_sent).toLocaleString() : 'N/A'),
                                    escapeCsvField(entry.time_received ? new Date(entry.time_received).toLocaleString() : 'N/A'),
                                    escapeCsvField((entry.department_name || 'None') + (entry.sub_department_name ? ' / ' + entry.sub_department_name : ''))
                                ].join(',') + '\n';
                            });
                            break;
                        case 'UsersPerDepartment':
                            data = usersPerDepartment;
                            csvContent += 'Department,User Count\n';
                            data.forEach(entry => {
                                csvContent += [
                                    escapeCsvField(entry.department_name),
                                    escapeCsvField(entry.user_count)
                                ].join(',') + '\n';
                            });
                            break;
                        case 'DocumentCopies':
                            data = documentCopies;
                            csvContent += 'File Name,Copy Count,Offices with Copy,Physical Duplicates\n';
                            data.forEach(entry => {
                                csvContent += [
                                    escapeCsvField(entry.file_name),
                                    escapeCsvField(entry.copy_count),
                                    escapeCsvField(entry.offices_with_copy || 'None'),
                                    escapeCsvField(entry.physical_duplicates || 'None')
                                ].join(',') + '\n';
                            });
                            break;
                        case 'PendingRequests':
                            data = pendingRequestsDetails;
                            csvContent += 'File Name,Requester,Requester\'s Department,Physical Storage\n';
                            data.forEach(entry => {
                                csvContent += [
                                    escapeCsvField(entry.file_name),
                                    escapeCsvField(entry.requester_name),
                                    escapeCsvField((entry.requester_department || 'None') + (entry.requester_subdepartment ? ' / ' + entry.requester_subdepartment : '')),
                                    escapeCsvField(entry.physical_storage || 'None')
                                ].join(',') + '\n';
                            });
                            break;
                        case 'RetrievalHistory':
                            data = retrievalHistory;
                            csvContent += 'Transaction ID,Type,Status,Time,User,File Name,Department,Physical Storage\n';
                            data.forEach(entry => {
                                csvContent += [
                                    escapeCsvField(entry.transaction_id),
                                    escapeCsvField(entry.type),
                                    escapeCsvField(entry.status),
                                    escapeCsvField(new Date(entry.time).toLocaleString()),
                                    escapeCsvField(entry.user_name),
                                    escapeCsvField(entry.file_name),
                                    escapeCsvField(entry.department_name || 'None'),
                                    escapeCsvField(entry.physical_storage || 'None')
                                ].join(',') + '\n';
                            });
                            break;
                        case 'AccessHistory':
                            data = accessHistory;
                            csvContent += 'Transaction ID,Time,User,File Name,Type,Department\n';
                            data.forEach(entry => {
                                csvContent += [
                                    escapeCsvField(entry.transaction_id),
                                    escapeCsvField(new Date(entry.time).toLocaleString()),
                                    escapeCsvField(entry.user_name),
                                    escapeCsvField(entry.file_name),
                                    escapeCsvField(entry.type),
                                    escapeCsvField(entry.department_name || 'None')
                                ].join(',') + '\n';
                            });
                            break;
                        default:
                            alert('Download not implemented for this report type.');
                            closeDownloadModal();
                            return;
                    }

                    const blob = new Blob([csvContent], {
                        type: 'text/csv;charset=utf-8;'
                    });
                    const url = URL.createObjectURL(blob);
                    const link = document.createElement('a');
                    link.setAttribute('href', url);
                    link.setAttribute('download', `${chartType}_Report.csv`);
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                    URL.revokeObjectURL(url);
                } else if (format === 'pdf') {
                    const reportContent = generateReportContent(chartType);
                    const tempDiv = document.createElement('div');
                    tempDiv.innerHTML = reportContent;
                    tempDiv.style.position = 'absolute';
                    tempDiv.style.left = '-9999px';
                    document.body.appendChild(tempDiv);
                    const opt = {
                        margin: 0.5,
                        filename: `${chartType}_Report.pdf`,
                        image: { type: 'png', quality: 0.98 },
                        html2canvas: { scale: 2 },
                        jsPDF: { unit: 'in', format: 'letter', orientation: 'portrait' }
                    };
                    html2pdf().from(tempDiv).set(opt).save().then(() => {
                        document.body.removeChild(tempDiv);
                    });
                } else {
                    alert('Invalid format selected.');
                    closeDownloadModal();
                    return;
                }
                closeDownloadModal();
            }

            // Sidebar toggle function
            function toggleSidebar() {
                const sidebar = document.querySelector('.sidebar');
                const mainContent = document.querySelector('.main-content');
                sidebar.classList.toggle('minimized');
                mainContent.classList.toggle('sidebar-expanded');
                mainContent.classList.toggle('sidebar-minimized');
            }

            // Initialize charts on page load
            document.addEventListener('DOMContentLoaded', () => {
                initializeCharts();
                // Update main-content class based on sidebar state
                const sidebar = document.querySelector('.sidebar');
                const mainContent = document.querySelector('.main-content');
                mainContent.classList.add(sidebar.classList.contains('minimized') ? 'sidebar-minimized' : 'sidebar-expanded');
            });
        </script>
        <style>
            .chart-container {
                cursor: pointer;
                position: relative;
                padding: 15px;
                border: 1px solid #ddd;
                border-radius: 5px;
                margin-bottom: 20px;
                background-color: #fff;
            }

            .chart-data-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 10pt;
                background-color: #fff;
                box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            }

            .chart-data-table th,
            .chart-data-table td {
                border: 1px solid #e0e0e0;
                padding: 10px;
                text-align: left;
                word-wrap: break-word;
            }

            .chart-data-table th {
                background-color: #f0f0f0;
                font-weight: 600;
                color: #34495e;
                text-transform: uppercase;
                font-size: 9pt;
            }

            .chart-data-table td {
                color: #333;
                font-size: 9pt;
            }

            .chart-data-table tr:nth-child(even) {
                background-color: #f9f9f9;
            }

            .chart-data-table tr:hover {
                background-color: #f1f5f9;
            }

            .chart-actions {
                margin-top: 10px;
                text-align: center;
            }

            .chart-actions button {
                padding: 8px 16px;
                background-color: #34495e;
                color: white;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                font-size: 10pt;
            }

            .chart-actions button:hover {
                background-color: #2c3e50;
            }

            .modal-overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.5);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 1000;
                padding-top: 20px;
            }

            .modal-content {
                background: #fff;
                padding: 20px;
                border-radius: 5px;
                max-width: 50%;
                max-height: 80%;
                overflow-y: auto;
                position: relative;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
            }

            .modal-close {
                position: absolute;
                top: 10px;
                right: 10px;
                background: #34495e;
                color: white;
                border: none;
                border-radius: 50%;
                width: 30px;
                height: 30px;
                font-size: 16px;
                cursor: pointer;
                line-height: 30px;
                text-align: center;
            }

            .modal-close:hover {
                background: #2c3e50;
            }

            .pagination-controls {
                display: flex;
                align-items: center;
                gap: 10px;
                margin-bottom: 15px;
                font-size: 10pt;
                color: #34495e;
            }

            .pagination-controls select {
                padding: 5px;
                border: 1px solid #ddd;
                border-radius: 4px;
                cursor: pointer;
            }

            .pagination-controls button {
                padding: 6px 12px;
                background-color: #34495e;
                color: white;
                border: none;
                border-radius: 4px;
                cursor: pointer;
            }

            .pagination-controls button:disabled {
                background-color: #95a5a6;
                cursor: not-allowed;
            }

            .pagination-controls button:hover:not(:disabled) {
                background-color: #2c3e50;
            }

            .pagination-controls span {
                font-weight: 500;
            }

            .download-options button {
                padding: 10px 20px;
                margin: 0 10px;
                background-color: #34495e;
                color: white;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                font-size: 12pt;
            }

            .download-options button:hover {
                background-color: #2c3e50;
            }
        </style>
</body>

</html>