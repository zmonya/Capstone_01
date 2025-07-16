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

// Fetch all files with relevant details
$filesStmt = executeQuery($pdo, "
    SELECT 
        f.File_id, 
        f.File_name, 
        f.Upload_date, 
        f.Copy_type,
        dt.Field_label AS document_type, 
        u.Username AS uploaded_by,
        d.Department_name AS department_name,
        sd.Department_name AS sub_department_name
    FROM files f
    LEFT JOIN documents_type_fields dt ON f.Document_type_id = dt.Document_type_id
    LEFT JOIN users u ON f.User_id = u.User_id
    LEFT JOIN users_department uda ON u.User_id = uda.User_id
    LEFT JOIN departments d ON uda.Department_id = d.Department_id
    LEFT JOIN departments sd ON d.Department_id = sd.Department_id AND sd.Department_type = 'sub_department'
    WHERE f.File_status != 'deleted'
    ORDER BY f.Upload_date DESC");
$allFiles = $filesStmt ? $filesStmt->fetchAll(PDO::FETCH_ASSOC) : [];

// Fetch document types for filter
$docTypesStmt = executeQuery($pdo, "SELECT Field_label FROM documents_type_fields");
$documentTypes = $docTypesStmt ? $docTypesStmt->fetchAll(PDO::FETCH_COLUMN) : [];

// Fetch departments for filter
$departmentsStmt = executeQuery($pdo, "SELECT Department_name FROM departments WHERE Department_type IN ('college', 'office')");
$departments = $departmentsStmt ? $departmentsStmt->fetchAll(PDO::FETCH_COLUMN) : [];

// Fetch uploaders for filter
$uploadersStmt = executeQuery($pdo, "SELECT DISTINCT Username FROM users");
$uploaders = $uploadersStmt ? $uploadersStmt->fetchAll(PDO::FETCH_COLUMN) : [];

// Function to get file icon based on extension
function getFileIcon($fileName)
{
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    return match ($extension) {
        'pdf' => 'fas fa-file-pdf',
        'doc', 'docx' => 'fas fa-file-word',
        'xls', 'xlsx' => 'fas fa-file-excel',
        'jpg', 'png', 'jpeg', 'gif' => 'fas fa-file-image',
        default => 'fas fa-file'
    };
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Admin - Filter Files - Arc-Hive</title>
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css" integrity="sha256-wN7i5z9q4rU1uMkp2S2e0B0nqD0qH0qC4Y0lA/6z7g=" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" crossorigin="anonymous">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.css">
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="admin-sidebar.css">
    <link rel="stylesheet" href="admin-interface.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js" integrity="sha256-VazP97ZCwtekAsvgPBSUwPFKdrwD3unUfSGVYrahUqU=" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.js"></script>
    <style>
        .filter-section {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 20px;
            align-items: center;
        }

        .search-container {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            flex: 1;
        }

        .search-container input,
        .search-container select {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            min-width: 150px;
        }

        .filter-buttons {
            display: flex;
            gap: 10px;
        }

        .filter-button {
            padding: 8px 16px;
            border: 1px solid #ddd;
            border-radius: 4px;
            cursor: pointer;
            background-color: #f9f9f9;
            transition: background-color 0.2s, color 0.2s;
        }

        .filter-button.active,
        .filter-button:hover {
            background-color: #50c878;
            color: white;
        }

        .file-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .file-table th,
        .file-table td {
            border: 1px solid #ddd;
            padding: 12px;
            text-align: left;
        }

        .file-table th {
            background-color: #f8f8f8;
            font-weight: bold;
            color: #333;
        }

        .file-table td:nth-child(odd) {
            background-color: #e6f4ea;
        }

        .sort-link {
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .sort-link.asc i::before {
            content: '\f0de';
        }

        .sort-link.desc i::before {
            content: '\f0dd';
        }

        .pagination {
            margin-top: 20px;
            display: flex;
            gap: 5px;
            justify-content: center;
        }

        .pagination a {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-decoration: none;
            color: #333;
            transition: background-color 0.2s;
        }

        .pagination a.active,
        .pagination a:hover {
            background-color: #50c878;
            color: white;
        }

        .stat-card {
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
    </style>
</head>

<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <button class="toggle-btn" title="Toggle Sidebar"><i class="fas fa-bars"></i></button>
        <h2 class="sidebar-title">Admin Panel</h2>
        <a href="dashboard.php" class="client-btn"><i class="fas fa-exchange-alt"></i><span class="link-text">Switch to Client View</span></a>
        <a href="admin_dashboard.php"><i class="fas fa-home"></i><span class="link-text">Dashboard</span></a>
        <a href="admin_search.php" class="active"><i class="fas fa-search"></i><span class="link-text">View All Files</span></a>
        <a href="user_management.php"><i class="fas fa-users"></i><span class="link-text">User Management</span></a>
        <a href="department_management.php"><i class="fas fa-building"></i><span class="link-text">Department Management</span></a>
        <a href="physical_storage_management.php"><i class="fas fa-archive"></i><span class="link-text">Physical Storage</span></a>
        <a href="document_type_management.php"><i class="fas fa-file-alt"></i><span class="link-text">Document Type Management</span></a>
        <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i><span class="link-text">Logout</span></a>
    </div>

    <!-- Main Content -->
    <div class="main-content sidebar-expanded">
        <!-- CSRF Token -->
        <input type="hidden" id="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">

        <h2>Filter Files</h2>

        <!-- Statistics -->
        <div class="stat-cards" id="statCards"></div>

        <!-- Filter Section -->
        <div class="filter-section">
            <div class="search-container">
                <input type="text" id="filterSearch" placeholder="Filter by file name..." autocomplete="off">
                <select id="documentType">
                    <option value="">All Document Types</option>
                    <?php foreach ($documentTypes as $type): ?>
                        <option value="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="department">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?= htmlspecialchars($dept, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($dept, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="uploader">
                    <option value="">All Uploaders</option>
                    <?php foreach ($uploaders as $uploader): ?>
                        <option value="<?= htmlspecialchars($uploader, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($uploader, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-buttons">
                <span class="filter-button active" data-hardcopy="all">All</span>
                <span class="filter-button" data-hardcopy="hard_copy">Hardcopy</span>
                <span class="filter-button" data-hardcopy="soft_copy">Softcopy</span>
            </div>
        </div>

        <!-- File Table -->
        <div class="file-table-container">
            <table class="file-table" id="fileTable">
                <thead>
                    <tr>
                        <th><span class="sort-link" data-sort="File_name">File Name <i class="fas fa-sort"></i></span></th>
                        <th><span class="sort-link" data-sort="document_type">Document Type <i class="fas fa-sort"></i></span></th>
                        <th><span class="sort-link" data-sort="Upload_date">Upload Date <i class="fas fa-sort"></i></span></th>
                        <th>Uploaded By</th>
                        <th>Department</th>
                        <th>Copy Type</th>
                    </tr>
                </thead>
                <tbody id="fileTableBody"></tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="pagination" id="pagination"></div>
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

        // File data
        const allFiles = <?= json_encode($allFiles, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        let currentPage = 1;
        const limit = 20;
        let currentSort = {
            field: 'Upload_date',
            direction: 'desc'
        };

        // Initialize on DOM load
        document.addEventListener('DOMContentLoaded', () => {
            const sidebar = document.querySelector('.sidebar');
            const mainContent = document.querySelector('.main-content');
            const toggleBtn = document.querySelector('.toggle-btn');

            // Sidebar toggle
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

            // Event listeners for filters
            $('#filterSearch').on('input', debounce(filterAndRender, 300));
            $('#documentType, #department, #uploader').on('change', filterAndRender);
            $('.filter-button').on('click', function() {
                $('.filter-button').removeClass('active');
                $(this).addClass('active');
                filterAndRender();
            });
            $('.sort-link').on('click', function() {
                const field = $(this).data('sort');
                currentSort.direction = (currentSort.field === field && currentSort.direction === 'asc') ? 'desc' : 'asc';
                currentSort.field = field;
                updateSortIcons();
                filterAndRender();
            });
            $('#pagination').on('click', 'a', function(e) {
                e.preventDefault();
                if (!validateCsrfToken(csrfToken)) {
                    notyf.error('Invalid CSRF token');
                    return;
                }
                currentPage = parseInt($(this).text());
                renderTable();
            });

            // Autocomplete setup
            $("#filterSearch").autocomplete({
                source: function(request, response) {
                    $.ajax({
                        url: "autocomplete.php",
                        method: 'POST',
                        dataType: "json",
                        data: {
                            term: request.term,
                            csrf_token: csrfToken
                        },
                        success: function(data) {
                            if (data.error) {
                                notyf.error(data.error);
                                return;
                            }
                            response(data.map(item => ({
                                label: item.File_name,
                                value: item.File_name,
                                document_type: item.document_type,
                                uploaded_by: item.uploaded_by,
                                department_name: item.department_name
                            })));
                        },
                        error: function() {
                            notyf.error('Failed to fetch autocomplete data');
                        }
                    });
                },
                minLength: 2,
                select: function(event, ui) {
                    $("#filterSearch").val(ui.item.value);
                    $("#documentType").val(ui.item.document_type || '');
                    $("#uploader").val(ui.item.uploaded_by || '');
                    $("#department").val(ui.item.department_name || '');
                    filterAndRender();
                    return false;
                }
            }).autocomplete("instance")._renderItem = function(ul, item) {
                return $("<li>")
                    .append(`<div>${sanitizeHTML(item.label)}</div>`)
                    .appendTo(ul);
            };

            // Initial render
            filterAndRender();
        });

        // Filter and render table
        function filterAndRender() {
            const search = $('#filterSearch').val().toLowerCase();
            const documentType = $('#documentType').val().toLowerCase();
            const department = $('#department').val().toLowerCase();
            const uploader = $('#uploader').val().toLowerCase();
            const hardcopy = $('.filter-button.active').data('hardcopy');

            // Filter files
            const filteredFiles = allFiles.filter(file => {
                const matchesSearch = !search || (file.File_name && file.File_name.toLowerCase().includes(search));
                const matchesDocType = !documentType || (file.document_type && file.document_type.toLowerCase() === documentType);
                const matchesDept = !department || (file.department_name && file.department_name.toLowerCase() === department);
                const matchesUploader = !uploader || (file.uploaded_by && file.uploaded_by.toLowerCase() === uploader);
                const matchesHardcopy = hardcopy === 'all' ||
                    (hardcopy === 'hard_copy' && file.Copy_type === 'hard_copy') ||
                    (hardcopy === 'soft_copy' && file.Copy_type === 'soft_copy');
                return matchesSearch && matchesDocType && matchesDept && matchesUploader && matchesHardcopy;
            });

            // Sort files
            filteredFiles.sort((a, b) => {
                const aValue = a[currentSort.field] || '';
                const bValue = b[currentSort.field] || '';
                if (currentSort.field === 'Upload_date') {
                    return currentSort.direction === 'asc' ?
                        new Date(aValue) - new Date(bValue) :
                        new Date(bValue) - new Date(aValue);
                }
                const comparison = aValue.localeCompare(bValue, undefined, {
                    numeric: true
                });
                return currentSort.direction === 'asc' ? comparison : -comparison;
            });

            // Update stats and table
            updateStats(filteredFiles);
            renderTable(filteredFiles);
        }

        // Update statistics
        function updateStats(files) {
            const totalFiles = files.length;
            const hardCopyFiles = files.filter(file => file.Copy_type === 'hard_copy').length;
            $('#statCards').html(`
                <div class="stat-card">
                    <h3>Total Files</h3>
                    <p>${sanitizeHTML(String(totalFiles))}</p>
                </div>
                <div class="stat-card">
                    <h3>Hard Copy Files</h3>
                    <p>${sanitizeHTML(String(hardCopyFiles))}</p>
                </div>
            `);
        }

        // Render table with pagination
        function renderTable(files = allFiles) {
            const tbody = $('#fileTableBody');
            tbody.empty();

            // Pagination logic
            const totalRecords = files.length;
            const totalPages = Math.ceil(totalRecords / limit);
            const start = (currentPage - 1) * limit;
            const paginatedFiles = files.slice(start, start + limit);

            if (paginatedFiles.length === 0) {
                tbody.append('<tr><td colspan="6" style="text-align: center; padding: 20px;">No files found.</td></tr>');
            } else {
                paginatedFiles.forEach(file => {
                    const department = file.department_name ?
                        `${sanitizeHTML(file.department_name)}${file.sub_department_name ? ' - ' + sanitizeHTML(file.sub_department_name) : ''}` :
                        'N/A';
                    tbody.append(`
                        <tr>
                            <td><i class="${getFileIcon(file.File_name)} file-icon"></i>${sanitizeHTML(file.File_name)}</td>
                            <td>${sanitizeHTML(file.document_type)}</td>
                            <td>${sanitizeHTML(new Date(file.Upload_date).toLocaleString())}</td>
                            <td>${sanitizeHTML(file.uploaded_by)}</td>
                            <td>${department}</td>
                            <td>${sanitizeHTML(file.Copy_type === 'hard_copy' ? 'Hard Copy' : 'Soft Copy')}</td>
                        </tr>
                    `);
                });
            }

            // Update pagination
            const pagination = $('#pagination');
            pagination.empty();
            for (let i = 1; i <= totalPages; i++) {
                pagination.append(`<a href="#" class="${i === currentPage ? 'active' : ''}">${i}</a>`);
            }
        }

        // Update sort icons
        function updateSortIcons() {
            $('.sort-link').each(function() {
                const field = $(this).data('sort');
                $(this).removeClass('asc desc');
                if (field === currentSort.field) {
                    $(this).addClass(currentSort.direction);
                }
            });
        }

        // Sanitize HTML to prevent XSS
        function sanitizeHTML(str) {
            const div = document.createElement('div');
            div.textContent = str || '';
            return div.innerHTML;
        }

        // Debounce function
        function debounce(func, wait) {
            let timeout;
            return function(...args) {
                clearTimeout(timeout);
                timeout = setTimeout(() => func.apply(this, args), wait);
            };
        }
    </script>
</body>

</html>