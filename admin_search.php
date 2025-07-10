<?php
session_start();
require 'db_connection.php';

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
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch all files with relevant details using the normalized structure
$filesStmt = $pdo->query("
    SELECT 
        f.id, 
        f.file_name, 
        f.upload_date, 
        f.hard_copy_available,
        dt.name AS document_type, 
        u.username AS uploaded_by,
        d.name AS department_name
    FROM files f
    LEFT JOIN document_types dt ON f.document_type_id = dt.id
    LEFT JOIN users u ON f.user_id = u.id
    LEFT JOIN user_department_affiliations uda ON u.id = uda.user_id
    LEFT JOIN departments d ON uda.department_id = d.id
    WHERE f.is_deleted = 0
    ORDER BY f.upload_date DESC
");
$allFiles = $filesStmt->fetchAll(PDO::FETCH_ASSOC);

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
    <title>Admin - Filter Files</title>
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="admin-sidebar.css">
    <link rel="stylesheet" href="admin-interface.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js"></script>
</head>

<body>
    <div class="sidebar">
        <button class="toggle-btn" title="Toggle Sidebar"><i class="fas fa-bars"></i></button>
        <h2 class="sidebar-title">Admin Panel</h2>
        <a href="index.php" class="client-btn"><i class="fas fa-exchange-alt"></i><span class="link-text">Switch to Client View</span></a>
        <a href="admin_dashboard.php"><i class="fas fa-home"></i><span class="link-text">Dashboard</span></a>
        <a href="admin_search.php" class="active"><i class="fas fa-search"></i><span class="link-text">View All Files</span></a>
        <a href="user_management.php"><i class="fas fa-users"></i><span class="link-text">User Management</span></a>
        <a href="department_management.php"><i class="fas fa-building"></i><span class="link-text">Department Management</span></a>
        <a href="physical_storage_management.php"><i class="fas fa-archive"></i><span class="link-text">Physical Storage</span></a>
        <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i><span class="link-text">Logout</span></a>
    </div>

    <div class="main-content sidebar-expanded">
        <h2>Filter Files</h2>

        <div class="stat-cards" id="statCards"></div>

        <div class="filter-section">
            <div class="search-container">
                <input type="text" id="filterSearch" placeholder="Filter by file name..." autocomplete="off">
                <select id="documentType">
                    <option value="">All Document Types</option>
                    <option value="memo">Memo</option>
                    <option value="letter">Letter</option>
                    <option value="notice">Notice</option>
                    <option value="announcement">Announcement</option>
                    <option value="invitation">Invitation</option>
                </select>
                <select id="department">
                    <option value="">All Departments</option>
                    <?php foreach ($pdo->query("SELECT * FROM departments")->fetchAll(PDO::FETCH_ASSOC) as $dept): ?>
                        <option value="<?= htmlspecialchars($dept['name']) ?>"><?= htmlspecialchars($dept['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="uploader">
                    <option value="">All Uploaders</option>
                    <?php foreach ($pdo->query("SELECT DISTINCT username FROM users")->fetchAll(PDO::FETCH_ASSOC) as $uploader): ?>
                        <option value="<?= htmlspecialchars($uploader['username']) ?>"><?= htmlspecialchars($uploader['username']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-buttons">
                <span class="filter-button active" data-hardcopy="all">All</span>
                <span class="filter-button" data-hardcopy="hardcopy">Hardcopy</span>
                <span class="filter-button" data-hardcopy="softcopy">Softcopy</span>
            </div>
        </div>

        <div class="file-table-container">
            <table class="file-table" id="fileTable">
                <thead>
                    <tr>
                        <th><span class="sort-link" data-sort="file_name">File Name <i class="fas fa-sort"></i></span></th>
                        <th><span class="sort-link" data-sort="document_type">Document Type <i class="fas fa-sort"></i></span></th>
                        <th><span class="sort-link" data-sort="upload_date">Upload Date <i class="fas fa-sort"></i></span></th>
                        <th>Uploaded By</th>
                        <th>Department</th>
                        <th>Hard Copy</th>
                    </tr>
                </thead>
                <tbody id="fileTableBody"></tbody>
            </table>
        </div>

        <div class="pagination" id="pagination"></div>
    </div>

    <script>
        const allFiles = <?= json_encode($allFiles) ?>;
        let currentPage = 1;
        const limit = 20;
        let currentSort = {
            field: 'upload_date',
            direction: 'desc'
        };

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

            // Initial render
            filterAndRender();

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
                currentPage = parseInt($(this).text());
                renderTable();
            });

            // Autocomplete setup
            $("#filterSearch").autocomplete({
                source: function(request, response) {
                    $.ajax({
                        url: "autocomplete.php",
                        dataType: "json",
                        data: {
                            term: request.term
                        },
                        success: function(data) {
                            response(data);
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
                    .append(`<div>${item.label}</div>`)
                    .appendTo(ul);
            };
        });

        function filterAndRender() {
            const search = $('#filterSearch').val().toLowerCase();
            const documentType = $('#documentType').val().toLowerCase();
            const department = $('#department').val().toLowerCase();
            const uploader = $('#uploader').val().toLowerCase();
            const hardcopy = $('.filter-button.active').data('hardcopy');

            // Filter files
            const filteredFiles = allFiles.filter(file => {
                const matchesSearch = !search || file.file_name.toLowerCase().includes(search);
                const matchesDocType = !documentType || file.document_type.toLowerCase() === documentType;
                const matchesDept = !department || (file.department_name && file.department_name.toLowerCase() === department);
                const matchesUploader = !uploader || (file.uploaded_by && file.uploaded_by.toLowerCase() === uploader);
                const matchesHardcopy = hardcopy === 'all' ||
                    (hardcopy === 'hardcopy' && file.hard_copy_available) ||
                    (hardcopy === 'softcopy' && !file.hard_copy_available);
                return matchesSearch && matchesDocType && matchesDept && matchesUploader && matchesHardcopy;
            });

            // Sort files
            filteredFiles.sort((a, b) => {
                const aValue = a[currentSort.field] || '';
                const bValue = b[currentSort.field] || '';
                const comparison = aValue.localeCompare(bValue, undefined, {
                    numeric: true
                });
                return currentSort.direction === 'asc' ? comparison : -comparison;
            });

            // Update stats and table
            updateStats(filteredFiles);
            renderTable(filteredFiles);
        }

        function updateStats(files) {
            const totalFiles = files.length;
            const hardCopyFiles = files.filter(file => file.hard_copy_available).length;
            $('#statCards').html(`
                <div class="stat-card">
                    <h3>Total Files</h3>
                    <p>${totalFiles}</p>
                </div>
                <div class="stat-card">
                    <h3>Hard Copy Files</h3>
                    <p>${hardCopyFiles}</p>
                </div>
            `);
        }

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
                    tbody.append(`
                        <tr>
                            <td><i class="${getFileIcon(file.file_name)} file-icon"></i>${escapeHtml(file.file_name)}</td>
                            <td>${escapeHtml(file.document_type)}</td>
                            <td>${escapeHtml(file.upload_date)}</td>
                            <td>${escapeHtml(file.uploaded_by)}</td>
                            <td>${escapeHtml(file.department_name || 'N/A')}</td>
                            <td>${file.hard_copy_available ? 'Yes' : 'No'}</td>
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

        function updateSortIcons() {
            $('.sort-link').each(function() {
                const field = $(this).data('sort');
                $(this).removeClass('asc desc');
                if (field === currentSort.field) {
                    $(this).addClass(currentSort.direction);
                }
            });
        }

        function escapeHtml(text) {
            return $('<div/>').text(text).html();
        }

        function getFileIcon(fileName) {
            const extension = fileName.split('.').pop().toLowerCase();
            return {
                'pdf': 'fas fa-file-pdf',
                'doc': 'fas fa-file-word',
                'docx': 'fas fa-file-word',
                'xls': 'fas fa-file-excel',
                'xlsx': 'fas fa-file-excel',
                'jpg': 'fas fa-file-image',
                'png': 'fas fa-file-image',
                'jpeg': 'fas fa-file-image',
                'gif': 'fas fa-file-image'
            } [extension] || 'fas fa-file';
        }

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