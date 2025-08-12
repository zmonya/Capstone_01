 <?php
session_start();
require 'db_connection.php';

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];

/**
 * Fetch user details with position and departments
 */
function fetchUserDetails($pdo, $userId)
{
    $stmt = $pdo->prepare("
        SELECT u.*, GROUP_CONCAT(DISTINCT d.name) AS department_name
        FROM users u
        LEFT JOIN user_department_affiliations uda ON u.id = uda.user_id
        LEFT JOIN departments d ON uda.department_id = d.id
        WHERE u.id = ?
        GROUP BY u.id
    ");
    $stmt->execute([$userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Fetch user departments
 */
function fetchUserDepartments($pdo, $userId)
{
    $stmt = $pdo->prepare("
        SELECT d.id, d.name 
        FROM departments d
        JOIN user_department_affiliations uda ON d.id = uda.department_id 
        WHERE uda.user_id = ?
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fetch recent files
 */
function fetchRecentFiles($pdo, $userId)
{
    $stmt = $pdo->prepare("
        SELECT f.* 
        FROM files f
        WHERE f.user_id = ? AND f.is_deleted = 0
        ORDER BY f.upload_date DESC 
        LIMIT 5
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fetch notifications
 */
function fetchNotifications($pdo, $userId)
{
    $stmt = $pdo->prepare("
        SELECT * 
        FROM notifications 
        WHERE user_id = ? 
        ORDER BY timestamp DESC 
        LIMIT 5
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fetch activity logs
 */
function fetchActivityLogs($pdo, $userId)
{
    $stmt = $pdo->prepare("
        SELECT * 
        FROM activity_log 
        WHERE user_id = ? 
        ORDER BY timestamp DESC 
        LIMIT 5
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fetch all files for the file selection popup
 */
function fetchAllFiles($pdo, $userId)
{
    $stmt = $pdo->prepare("
        SELECT f.*, dt.name AS document_type 
        FROM files f
        LEFT JOIN document_types dt ON f.document_type_id = dt.id 
        WHERE f.user_id = ? AND f.is_deleted = 0
        ORDER BY f.upload_date DESC
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fetch inbound and outbound file activity for the chart
 */
function fetchFileActivity($pdo, $userId)
{
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(activity_date, CURDATE()) as activity_day,
            COALESCE(incoming_pending, 0) as incoming_pending,
            COALESCE(incoming_accepted, 0) as incoming_accepted,
            COALESCE(outgoing_pending, 0) as outgoing_pending,
            COALESCE(outgoing_accepted, 0) as outgoing_accepted
        FROM (
            SELECT 
                DATE(COALESCE(ft.time_sent, ft.time_accepted, ar.time_requested, NOW())) as activity_date,
                SUM(CASE WHEN ft.recipient_id = ? AND ft.status = 'pending' THEN 1 ELSE 0 END) as incoming_pending,
                SUM(CASE WHEN ft.recipient_id = ? AND ft.status = 'accepted' THEN 1 ELSE 0 END) as incoming_accepted,
                SUM(CASE WHEN ft.sender_id = ? AND ft.status = 'pending' THEN 1 ELSE 0 END) as outgoing_pending,
                SUM(CASE WHEN ft.sender_id = ? AND ft.status = 'accepted' THEN 1 ELSE 0 END) as outgoing_accepted
            FROM (
                SELECT CURDATE() - INTERVAL seq DAY as date_range
                FROM (
                    SELECT 0 as seq UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 
                    UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9
                    UNION SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13 UNION SELECT 14
                    UNION SELECT 15 UNION SELECT 16 UNION SELECT 17 UNION SELECT 18 UNION SELECT 19
                    UNION SELECT 20 UNION SELECT 21 UNION SELECT 22 UNION SELECT 23 UNION SELECT 24
                    UNION SELECT 25 UNION SELECT 26 UNION SELECT 27 UNION SELECT 28 UNION SELECT 29
                ) as days
            ) as date_series
            LEFT JOIN file_transfers ft ON DATE(ft.time_sent) = date_range OR DATE(ft.time_accepted) = date_range
            LEFT JOIN access_requests ar ON DATE(ar.time_requested) = date_range
            WHERE date_range >= CURDATE() - INTERVAL 30 DAY
            GROUP BY date_range
        ) as activity_data
        ORDER BY activity_day ASC
    ");
    $stmt->execute([$userId, $userId, $userId, $userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Calculate moving averages
 */
function calculateMovingAverage($data, $windowSize = 3)
{
    $movingAverages = [];
    for ($i = 0; $i < count($data); $i++) {
        $start = max(0, $i - $windowSize + 1);
        $slice = array_slice($data, $start, $windowSize);
        $movingAverages[] = array_sum($slice) / count($slice);
    }
    return $movingAverages;
}

/**
 * Get file icon based on file extension
 */
function getFileIcon($fileName)
{
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    switch ($extension) {
        case 'pdf':
            return 'fas fa-file-pdf';
        case 'doc':
        case 'docx':
            return 'fas fa-file-word';
        case 'xls':
        case 'xlsx':
            return 'fas fa-file-excel';
        case 'jpg':
        case 'png':
            return 'fas fa-file-image';
        default:
            return 'fas fa-file';
    }
}

// Handle request parameters
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$departmentId = isset($_GET['department_id']) ? intval($_GET['department_id']) : null;

// Validate dates
if (!strtotime($startDate) || !strtotime($endDate)) {
    $startDate = date('Y-m-d', strtotime('-30 days'));
    $endDate = date('Y-m-d');
}

// Fetch data with error handling
try {
    $user = fetchUserDetails($pdo, $userId);
    $userDepartments = fetchUserDepartments($pdo, $userId);
    $recentFiles = fetchRecentFiles($pdo, $userId);
    $notificationLogs = fetchNotifications($pdo, $userId);
    $activityLogs = fetchActivityLogs($pdo, $userId);
    $files = fetchAllFiles($pdo, $userId);
    $inboundFiles = fetchFileActivity($pdo, $userId);

    // Prepare chart data with fallback
    $chartLabels = [];
    $inboundPendingData = [];
    $inboundAcceptedData = [];
    $outboundPendingData = [];
    $outboundAcceptedData = [];

    if (!empty($inboundFiles)) {
        foreach ($inboundFiles as $file) {
            $chartLabels[] = $file['activity_day'];
            $inboundPendingData[] = $file['incoming_pending'];
            $inboundAcceptedData[] = $file['incoming_accepted'];
            $outboundPendingData[] = $file['outgoing_pending'];
            $outboundAcceptedData[] = $file['outgoing_accepted'];
        }
    } else {
        // Provide default data if no results
        $chartLabels = [date('Y-m-d')];
        $inboundPendingData = [0];
        $inboundAcceptedData = [0];
        $outboundPendingData = [0];
        $outboundAcceptedData = [0];
    }
} catch (Exception $e) {
    // Log error and provide fallback data
    error_log("Report generation error: " . $e->getMessage());
    
    // Provide empty/default data
    $chartLabels = [date('Y-m-d')];
    $inboundPendingData = [0];
    $inboundAcceptedData = [0];
    $outboundPendingData = [0];
    $outboundAcceptedData = [0];
}

$inboundMovingAverage = calculateMovingAverage(array_map(function ($a, $b) {
    return (int)$a + (int)$b;
}, $inboundPendingData, $inboundAcceptedData));

$outboundMovingAverage = calculateMovingAverage(array_map(function ($a, $b) {
    return (int)$a + (int)$b;
}, $outboundPendingData, $outboundAcceptedData));
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.css">
    <script src="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="client.css">
    <link rel="stylesheet" href="client-sidebar.css">
    <style>
        /* Chart Container Styles */
        .chart-container {
            background-color: #ffffff;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }

        .chart-container h3 {
            color: #34495e;
            margin-bottom: 15px;
            font-size: 18px;
        }

        .chart-actions {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }

        .chart-actions button {
            background-color: #50c878;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }

        .chart-actions button:hover {
            background-color: #3fa769;
        }
    </style>
</head>

<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <button class="toggle-btn" title="Toggle Sidebar">
            <i class="fas fa-bars"></i>
        </button>
        <h2 class="sidebar-title">Document Archival</h2>

        <!-- Hidden Admin Dashboard Button (Only visible to admins) -->
        <?php if ($userRole === 'admin'): ?>
            <a href="admin_dashboard.php" class="admin-dashboard-btn">
                <i class="fas fa-user-shield"></i>
                <span class="link-text">Admin Dashboard</span>
            </a>
        <?php endif; ?>

        <a href="index.php" class="<?= basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : '' ?>">
            <i class="fas fa-home"></i><span class="link-text"> Dashboard</span>
        </a>

        <a href="my-folder.php" class="<?= basename($_SERVER['PHP_SELF']) == 'my-folder.php' ? 'active' : '' ?>">
            <i class="fas fa-folder"></i><span class="link-text"> My Folder</span>
        </a>

        <?php if (!empty($userDepartments)): ?>
            <?php foreach ($userDepartments as $dept): ?>
                <a href="department_folder.php?department_id=<?= $dept['id'] ?>"
                    class="<?= isset($_GET['department_id']) && $_GET['department_id'] == $dept['id'] ? 'active' : '' ?>">
                    <i class="fas fa-folder"></i>
                    <span class="link-text"> <?= htmlspecialchars($dept['name']) ?></span>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>

        <a href="logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i>
            <span class="link-text">Logout</span>
        </a>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- File Activity Chart -->
        <div class="chart-container">
            <h3>File Activity (Inbound & Outbound)</h3>
            <div class="chart-actions">
                <button onclick="downloadChart('fileActivityChart', 'FileActivity')">Download PDF</button>
                <button onclick="printChart('fileActivityChart', 'FileActivity')">Print</button>
            </div>
            <canvas id="fileActivityChart"></canvas>
        </div>

        <!-- Other Dashboard Content -->
        <!-- ... (rest of the dashboard content can be added here if needed) ... -->
    </div>

    <!-- Hidden print container -->
    <div id="print-container"></div>

    <script>
        // File Activity Chart
        const fileActivityChart = new Chart(document.getElementById('fileActivityChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($chartLabels) ?>,
                datasets: [{
                        label: 'Inbound (Pending)',
                        data: <?= json_encode($inboundPendingData) ?>,
                        backgroundColor: 'rgba(255, 99, 132, 0.6)',
                        stack: 'Stack 0'
                    },
                    {
                        label: 'Inbound (Accepted)',
                        data: <?= json_encode($inboundAcceptedData) ?>,
                        backgroundColor: 'rgba(54, 162, 235, 0.6)',
                        stack: 'Stack 0'
                    },
                    {
                        label: 'Outbound (Pending)',
                        data: <?= json_encode($outboundPendingData) ?>,
                        backgroundColor: 'rgba(255, 206, 86, 0.6)',
                        stack: 'Stack 1'
                    },
                    {
                        label: 'Outbound (Accepted)',
                        data: <?= json_encode($outboundAcceptedData) ?>,
                        backgroundColor: 'rgba(75, 192, 192, 0.6)',
                        stack: 'Stack 1'
                    },
                    {
                        label: 'Inbound Moving Average',
                        data: <?= json_encode($inboundMovingAverage) ?>,
                        borderColor: 'rgba(255, 99, 132, 1)',
                        borderWidth: 2,
                        fill: false,
                        type: 'line'
                    },
                    {
                        label: 'Outbound Moving Average',
                        data: <?= json_encode($outboundMovingAverage) ?>,
                        borderColor: 'rgba(255, 206, 86, 1)',
                        borderWidth: 2,
                        fill: false,
                        type: 'line'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        stacked: true,
                        title: {
                            display: true,
                            text: 'Date'
                        }
                    },
                    y: {
                        stacked: true,
                        title: {
                            display: true,
                            text: 'Number of Files'
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });

        // Function to get chart data for download/print
        function getChartData(chartType) {
            if (chartType === 'FileActivity') {
                return <?= json_encode($inboundFiles) ?>;
            }
            return [];
        }

        // Download Chart as PDF
        function downloadChart(chartId, chartType) {
            const chartElement = document.getElementById(chartId);
            html2canvas(chartElement).then(canvas => {
                const chartImage = canvas.toDataURL('image/png');
                const chartData = getChartData(chartType);

                // Create a PDF
                const {
                    jsPDF
                } = window.jspdf;
                const pdf = new jsPDF();
                pdf.setFontSize(18);
                pdf.text(`Report: ${chartType}`, 10, 20);
                pdf.addImage(chartImage, 'PNG', 10, 30, 180, 100);

                // Add data table
                if (chartData) {
                    pdf.setFontSize(12);
                    pdf.text('Data Table:', 10, 150);
                    let yPos = 160;
                    chartData.forEach(entry => {
                        pdf.text(`${entry.activity_day}: Inbound Pending - ${entry.incoming_pending}, Inbound Accepted - ${entry.incoming_accepted}, Outbound Pending - ${entry.outgoing_pending}, Outbound Accepted - ${entry.outgoing_accepted}`, 10, yPos);
                        yPos += 10;
                    });
                }

                pdf.save(`${chartType}_Report.pdf`);
            });
        }

        // Print Chart
        function printChart(chartId, chartType) {
            const chartElement = document.getElementById(chartId);
            const chartData = getChartData(chartType);

            // Create a hidden print container
            const printContainer = document.getElementById('print-container');
            printContainer.innerHTML = `
                <h1>${chartType} Report</h1>
                <img src="${chartElement.toDataURL()}" style="width: 100%; max-width: 600px;">
                <h2>Data Table</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Inbound Pending</th>
                            <th>Inbound Accepted</th>
                            <th>Outbound Pending</th>
                            <th>Outbound Accepted</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${chartData.map(entry => `
                            <tr>
                                <td>${entry.activity_day}</td>
                                <td>${entry.incoming_pending}</td>
                                <td>${entry.incoming_accepted}</td>
                                <td>${entry.outgoing_pending}</td>
                                <td>${entry.outgoing_accepted}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            `;

            // Print the content
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                    <head>
                        <title>${chartType} Report</title>
                        <style>
                            body { font-family: Arial, sans-serif; }
                            h1 { font-size: 18px; }
                            table { border-collapse: collapse; width: 100%; }
                            th, td { border: 1px solid #000; padding: 8px; text-align: left; }
                        </style>
                    </head>
                    <body>
                        ${printContainer.innerHTML}
                    </body>
                </html>
            `);
            printWindow.document.close();
            printWindow.print();
        }
    </script>
</body>

</html>