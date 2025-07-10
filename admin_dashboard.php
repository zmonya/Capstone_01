<?php
session_start();
require 'db_connection.php'; // Defines $pdo (PDO connection)

// Redirect if not authenticated or not admin
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
if ($_SESSION['role'] !== 'admin') {
    header('Location: unauthorized.php');
    exit();
}

$userId = $_SESSION['user_id'];

// Fetch admin details
$stmt = $pdo->prepare("SELECT * FROM users WHERE User_id = ?");
$stmt->execute([$userId]);
$admin = $stmt->fetch();
if (!$admin) {
    die("No user found with User_id: " . htmlspecialchars($userId));
}

// Fetch system statistics
$totalUsers = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalFiles = $pdo->query("SELECT COUNT(*) FROM files WHERE is_deleted = 0")->fetchColumn();
$pendingRequests = $pdo->query("SELECT COUNT(*) FROM access_requests WHERE status = 'pending'")->fetchColumn();
$incomingFiles = $pdo->query("SELECT COUNT(*) FROM file_transfers WHERE recipient_id = ? AND status = 'pending'")->fetchColumn([$userId]);
$outgoingFiles = $pdo->query("SELECT COUNT(*) FROM file_transfers WHERE sender_id = ? AND status = 'pending'")->fetchColumn([$userId]);

// File upload trends (last 7 days)
$fileUploadTrends = $pdo->query("
    SELECT 
        f.file_name AS document_name,
        dt.name AS document_type,
        f.upload_date AS upload_date,
        u.full_name AS uploader_name,
        d.name AS uploader_department
    FROM files f
    LEFT JOIN document_types dt ON f.document_type_id = dt.id
    LEFT JOIN users u ON f.user_id = u.User_id
    LEFT JOIN user_department_affiliations uda ON u.User_id = uda.user_id
    LEFT JOIN departments d ON uda.department_id = d.id
    WHERE f.upload_date >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND f.is_deleted = 0
    ORDER BY f.upload_date ASC
")->fetchAll();

// File distribution by document type
$fileDistributionByType = $pdo->query("
    SELECT 
        f.file_name AS document_name,
        dt.name AS document_type,
        u.full_name AS uploader_name,
        d.name AS department_name
    FROM files f
    JOIN document_types dt ON f.document_type_id = dt.id
    LEFT JOIN users u ON f.user_id = u.User_id
    LEFT JOIN user_department_affiliations uda ON u.User_id = uda.user_id
    LEFT JOIN departments d ON uda.department_id = d.id
    WHERE f.is_deleted = 0
    GROUP BY f.id
")->fetchAll();

// Users per department
$usersPerDepartment = $pdo->query("
    SELECT 
        d.name AS department_name,
        COUNT(DISTINCT uda.user_id) AS user_count
    FROM departments d
    LEFT JOIN user_department_affiliations uda ON d.id = uda.department_id
    GROUP BY d.name
")->fetchAll();
$departmentLabels = array_column($usersPerDepartment, 'department_name');
$departmentData = array_column($usersPerDepartment, 'user_count');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.css">
    <script src="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
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
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
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
        .popup-table th, .popup-table td {
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
            padding: 20px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-align: center;
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
        .admin-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 20px;
            margin: 20px;
        }
        .chart-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin: 20px;
        }
        .sidebar {
            width: 250px;
            background: #f4f4f4;
            padding: 10px;
        }
        .sidebar a {
            display: block;
            padding: 10px;
            text-decoration: none;
            color: #333;
        }
        .sidebar a.active {
            background: #50c878;
            color: white;
        }
        .main-content {
            margin-left: 270px;
            padding: 20px;
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <button class="toggle-btn"><i class="fas fa-bars"></i></button>
        <h2>Admin Panel</h2>
        <a href="dashboard.php"><i class="fas fa-exchange-alt"></i> Switch to Client View</a>
        <a href="admin_dashboard.php" class="active"><i class="fas fa-home"></i> Dashboard</a>
        <a href="admin_search.php"><i class="fas fa-search"></i> View All Files</a>
        <a href="user_management.php"><i class="fas fa-users"></i> User Management</a>
        <a href="department_management.php"><i class="fas fa-building"></i> Department Management</a>
        <a href="physical_storage_management.php"><i class="fas fa-archive"></i> Physical Storage</a>
        <a href="document_type_management.php"><i class="fas fa-file-alt"></i> Document Type Management</a>
        <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>

    <div class="main-content">
        <div class="admin-stats">
            <div class="stat-card"><h3>Total Users</h3><p><?= htmlspecialchars($totalUsers) ?></p></div>
            <div class="stat-card"><h3>Total Files</h3><p><?= htmlspecialchars($totalFiles) ?></p></div>
            <div class="stat-card"><h3>Pending Requests</h3><p><?= htmlspecialchars($pendingRequests) ?></p></div>
            <div class="stat-card"><h3>Incoming Files</h3><p><?= htmlspecialchars($incomingFiles) ?></p></div>
            <div class="stat-card"><h3>Outgoing Files</h3><p><?= htmlspecialchars($outgoingFiles) ?></p></div>
        </div>

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
        let fileUploadChart, fileDistributionChart, usersPerDepartmentChart, popupChartInstance;
        let currentChartType;

        function initCharts() {
            const fileUploadTrends = <?= json_encode($fileUploadTrends) ?>;
            const uploadLabels = fileUploadTrends.map(entry => new Date(entry.upload_date).toLocaleDateString());
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
                    scales: { y: { beginAtZero: true } }
                }
            });

            const fileDistributionByType = <?= json_encode($fileDistributionByType) ?>;
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
                options: { responsive: true, maintainAspectRatio: false }
            });

            usersPerDepartmentChart = new Chart(document.getElementById('usersPerDepartmentChart'), {
                type: 'bar',
                data: {
                    labels: <?= json_encode($departmentLabels) ?>,
                    datasets: [{
                        label: 'Users',
                        data: <?= json_encode($departmentData) ?>,
                        backgroundColor: '#50c878',
                        borderColor: '#50c878',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: { beginAtZero: true, title: { display: true, text: 'Number of Users' } },
                        x: { title: { display: true, text: 'Departments' } }
                    },
                    plugins: { legend: { display: false } }
                }
            });
        }

        function openPopup(chartId, title, chartType) {
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
                            <th>Upload Date/Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${chartData.map(entry => `
                            <tr>
                                <td>${entry.document_name || 'N/A'}</td>
                                <td>${entry.document_type || 'N/A'}</td>
                                <td>${entry.uploader_name || 'N/A'}</td>
                                <td>${entry.uploader_department || 'N/A'}</td>
                                <td>${new Date(entry.upload_date).toLocaleString()}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                `;
            } else if (chartType === 'FileDistribution') {
                tableHTML = `
                    <thead>
                        <tr>
                            <th>File Name</th>
                            <th>Document Type</th>
                            <th>Uploader</th>
                            <th>Department</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${chartData.map(entry => `
                            <tr>
                                <td>${entry.document_name || 'N/A'}</td>
                                <td>${entry.document_type || 'N/A'}</td>
                                <td>${entry.uploader_name || 'N/A'}</td>
                                <td>${entry.department_name || 'N/A'}</td>
                            </tr>
                        `).join('')}
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
                                <td>${label}</td>
                                <td>${chartData.data[index]}</td>
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
                return <?= json_encode($fileUploadTrends) ?>;
            } else if (chartType === 'FileDistribution') {
                return <?= json_encode($fileDistributionByType) ?>;
            } else if (chartType === 'UsersPerDepartment') {
                return {
                    labels: <?= json_encode($departmentLabels) ?>,
                    data: <?= json_encode($departmentData) ?>
                };
            }
            return [];
        }

        function downloadChart() {
            const chartData = getChartData(currentChartType);
            const { jsPDF } = window.jspdf;
            const pdf = new jsPDF('p', 'mm', 'a4');
            const pageWidth = pdf.internal.pageSize.getWidth();
            const pageHeight = pdf.internal.pageSize.getHeight();
            const margin = 5;
            const maxWidth = pageWidth - 2 * margin;
            let yPos = 10;

            pdf.setFontSize(16);
            pdf.setTextColor(33, 33, 33);
            pdf.text(`Report: ${currentChartType}`, margin, yPos);
            yPos += 8;

            const chartCanvas = document.getElementById('popupChart');
            html2canvas(chartCanvas, { scale: 2 }).then(canvas => {
                const chartImage = canvas.toDataURL('image/png');
                const imgProps = pdf.getImageProperties(chartImage);
                const chartWidth = maxWidth;
                const chartHeight = (imgProps.height * chartWidth) / imgProps.width;
                pdf.addImage(chartImage, 'PNG', margin, yPos, chartWidth, chartHeight);
                yPos += chartHeight + 8;

                pdf.setFontSize(12);
                pdf.text('Data Table', margin, yPos);
                yPos += 6;

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
                    const columnWidths = [50, 30, 30, 50, 40];
                    let xPos = startX;
                    for (let i = 0; i < 5; i++) {
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
                    drawText(xPos, yPos, 'Upload Date', columnWidths[4], lineHeight + 2);
                    yPos += lineHeight + 3;
                    pdf.setFont('helvetica', 'normal');

                    chartData.forEach(entry => {
                        const texts = [
                            entry.document_name || 'N/A',
                            entry.document_type || 'N/A',
                            entry.uploader_name || 'N/A',
                            entry.uploader_department || 'N/A',
                            new Date(entry.upload_date).toLocaleString()
                        ];
                        const maxLines = getMaxLines(texts, columnWidths);
                        const rowHeight = maxLines * lineHeight + 2;

                        if (yPos + rowHeight > pageHeight - margin) {
                            pdf.addPage();
                            yPos = margin;
                            xPos = startX;
                            for (let i = 0; i < 5; i++) {
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
                            drawText(xPos, yPos, 'Upload Date', columnWidths[4], lineHeight + 2);
                            yPos += lineHeight + 3;
                            pdf.setFont('helvetica', 'normal');
                        }

                        xPos = startX;
                        for (let i = 0; i < 5; i++) {
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
                    const columnWidths = [50, 30, 30, 90];
                    let xPos = startX;
                    for (let i = 0; i < 4; i++) {
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
                    yPos += lineHeight + 3;
                    pdf.setFont('helvetica', 'normal');

                    chartData.forEach(entry => {
                        const texts = [
                            entry.document_name || 'N/A',
                            entry.document_type || 'N/A',
                            entry.uploader_name || 'N/A',
                            entry.department_name || 'N/A'
                        ];
                        const maxLines = getMaxLines(texts, columnWidths);
                        const rowHeight = maxLines * lineHeight + 2;

                        if (yPos + rowHeight > pageHeight - margin) {
                            pdf.addPage();
                            yPos = margin;
                            xPos = startX;
                            for (let i = 0; i < 4; i++) {
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
                            yPos += lineHeight + 3;
                            pdf.setFont('helvetica', 'normal');
                        }

                        xPos = startX;
                        for (let i = 0; i < 4; i++) {
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
                alert('Failed to generate PDF. Please try again.');
            });
        }

        function printChart() {
            const chartData = getChartData(currentChartType);
            const chartCanvas = document.getElementById('popupChart');

            html2canvas(chartCanvas, { scale: 2 }).then(canvas => {
                const chartImage = canvas.toDataURL('image/png');
                const printWindow = window.open('', '_blank');
                let tableRows = '';

                if (currentChartType === 'FileUploadTrends') {
                    tableRows = chartData.map(entry => `
                        <tr>
                            <td>${entry.document_name || 'N/A'}</td>
                            <td>${entry.document_type || 'N/A'}</td>
                            <td>${entry.uploader_name || 'N/A'}</td>
                            <td>${entry.uploader_department || 'N/A'}</td>
                            <td>${new Date(entry.upload_date).toLocaleString()}</td>
                        </tr>
                    `).join('');
                } else if (currentChartType === 'FileDistribution') {
                    tableRows = chartData.map(entry => `
                        <tr>
                            <td>${entry.document_name || 'N/A'}</td>
                            <td>${entry.document_type || 'N/A'}</td>
                            <td>${entry.uploader_name || 'N/A'}</td>
                            <td>${entry.department_name || 'N/A'}</td>
                        </tr>
                    `).join('');
                } else if (currentChartType === 'UsersPerDepartment') {
                    tableRows = chartData.labels.map((label, index) => `
                        <tr>
                            <td>${label}</td>
                            <td>${chartData.data[index]}</td>
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
                                td:nth-child(2), td:nth-child(4) { background-color: transparent !important; }
                                @media print { 
                                    body { margin: 0; } 
                                    img { max-width: 100%; } 
                                    table { font-size: 8pt; }
                                    th { background-color: #f0f0f0 !important; -webkit-print-color-adjust: exact; color-adjust: exact; }
                                    td:nth-child(1), td:nth-child(3), td:nth-child(5) { background-color: #e6f4ea !important; -webkit-print-color-adjust: exact; color-adjust: exact; }
                                    td:nth-child(2), td:nth-child(4) { background-color: transparent !important; -webkit-print-color-adjust: exact; color-adjust: exact; }
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
                                                <th>Upload Date/Time</th>
                                            ` : currentChartType === 'FileDistribution' ? `
                                                <th>File Name</th>
                                                <th>Document Type</th>
                                                <th>Uploader</th>
                                                <th>Department</th>
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
                alert('Failed to generate print preview. Please try again.');
            });
        }

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
    </script>
</body>
</html>