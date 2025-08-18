<?php
session_start();
require 'db_connection.php';

// Configure error handling
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('error_log', __DIR__ . '/logs/error_log.log');
error_reporting(E_ALL);

/**
 * Sends a JSON response with HTTP status code.
 *
 * @param bool $success
 * @param string $message
 * @param array $data
 * @param int $statusCode
 * @return void
 */
function sendJsonResponse(bool $success, string $message, array $data, int $statusCode): void
{
    header('Content-Type: application/json');
    http_response_code($statusCode);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}

/**
 * Validates user session and retrieves user details.
 *
 * @return array{user_id: int, role: string, username: string}
 * @throws Exception
 */
function validateUserSession(): array
{
    global $pdo;
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        error_log("Session validation failed: user_id or role not set.");
        throw new Exception("Session validation failed.", 401);
    }
    $stmt = $pdo->prepare("SELECT username FROM users WHERE user_id = ?");
    $stmt->execute([(int)$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user || !$user['username']) {
        error_log("Username not found for user_id: {$_SESSION['user_id']}");
        throw new Exception("Username not found.", 401);
    }
    return [
        'user_id' => (int)$_SESSION['user_id'],
        'role' => (string)$_SESSION['role'],
        'username' => $user['username']
    ];
}

// Handle AJAX request to fetch extracted text
if (isset($_GET['action']) && $_GET['action'] === 'view_text' && isset($_GET['file_id'])) {
    try {
        validateUserSession();
        $fileId = filter_var($_GET['file_id'], FILTER_VALIDATE_INT);
        if (!$fileId) {
            error_log("Invalid file ID provided for view_text: {$_GET['file_id']}");
            sendJsonResponse(false, 'Invalid file ID.', [], 400);
        }
        $stmt = $pdo->prepare("
            SELECT tr.extracted_text, f.file_status, f.file_name
            FROM text_repository tr
            JOIN files f ON tr.file_id = f.file_id
            WHERE tr.file_id = ?
        ");
        $stmt->execute([$fileId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$result) {
            error_log("No text_repository entry found for file_id: $fileId");
            sendJsonResponse(false, 'No text repository entry found for this file.', [], 404);
        }
        $responseData = [
            'extracted_text' => $result['extracted_text'] ?? '',
            'file_status' => $result['file_status'],
            'file_name' => $result['file_name']
        ];
        if ($result['file_status'] === 'pending_ocr') {
            sendJsonResponse(false, 'Text extraction is still in progress. Please try again later.', $responseData, 202);
        } elseif ($result['file_status'] === 'ocr_failed') {
            sendJsonResponse(false, 'Text extraction failed. Click "Retry OCR" to try again.', $responseData, 422);
        } elseif (empty($result['extracted_text'])) {
            sendJsonResponse(false, 'No text extracted for this file. It may not contain extractable content.', $responseData, 200);
        }
        error_log("View text for file_id: $fileId, file_status: {$result['file_status']}, extracted_text length: " . strlen($result['extracted_text'] ?? ''));
        sendJsonResponse(true, 'Text retrieved successfully.', $responseData, 200);
    } catch (Exception $e) {
        error_log("Error fetching text for file_id {$_GET['file_id']}: " . $e->getMessage() . " | Line: " . $e->getLine());
        sendJsonResponse(false, 'Server error: Unable to retrieve text: ' . $e->getMessage(), [], $e->getCode() ?: 500);
    }
}

// Handle AJAX request to retry OCR
if (isset($_GET['action']) && $_GET['action'] === 'retry_ocr' && isset($_GET['file_id'])) {
    try {
        validateUserSession();
        $fileId = filter_var($_GET['file_id'], FILTER_VALIDATE_INT);
        if (!$fileId) {
            error_log("Invalid file ID provided for retry_ocr: {$_GET['file_id']}");
            sendJsonResponse(false, 'Invalid file ID.', [], 400);
        }
        $stmt = $pdo->prepare("UPDATE files SET file_status = 'pending_ocr' WHERE file_id = ? AND file_status = 'ocr_failed'");
        $stmt->execute([$fileId]);
        if ($stmt->rowCount() === 0) {
            error_log("No file found or not in ocr_failed status for file_id: $fileId");
            sendJsonResponse(false, 'File not eligible for OCR retry.', [], 400);
        }
        // Trigger background OCR processing
        $logFile = __DIR__ . '/logs/ocr_processor.log';
        $command = escapeshellcmd("php " . __DIR__ . "/ocr_processor.php $fileId >> $logFile 2>&1");
        $output = [];
        $returnCode = 0;
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            exec("start /B $command 2>&1", $output, $returnCode);
        } else {
            exec("$command &", $output, $returnCode);
        }
        if ($returnCode !== 0) {
            error_log("Failed to start OCR retry for file ID $fileId: " . implode("\n", $output), 3, $logFile);
            sendJsonResponse(false, 'Failed to schedule OCR retry.', [], 500);
        }
        $stmt = $pdo->prepare("
            INSERT INTO transactions (user_id, file_id, transaction_type, transaction_status, transaction_time, description)
            VALUES (?, ?, 'ocr_retry', 'scheduled', NOW(), ?)
        ");
        $stmt->execute([$_SESSION['user_id'], $fileId, "Retrying OCR processing for file ID $fileId"]);
        sendJsonResponse(true, 'OCR retry scheduled successfully.', [], 200);
    } catch (Exception $e) {
        error_log("Error retrying OCR for file_id {$_GET['file_id']}: " . $e->getMessage() . " | Line: " . $e->getLine());
        sendJsonResponse(false, 'Server error: Unable to retry OCR: ' . $e->getMessage(), [], $e->getCode() ?: 500);
    }
}

try {
    // Validate session
    $user = validateUserSession();
    $userId = $user['user_id'];

    // Fetch user departments
    $stmt = $pdo->prepare("
        SELECT d.department_id
        FROM departments d
        JOIN users_department ud ON d.department_id = ud.department_id
        WHERE ud.user_id = ?
    ");
    $stmt->execute([$userId]);
    $userDepartments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $departmentIds = array_column($userDepartments, 'department_id');

    // Get search query
    $searchQuery = filter_input(INPUT_GET, 'q', FILTER_SANITIZE_SPECIAL_CHARS) ?? '';
    $files = [];

    if (!empty($searchQuery)) {
        // Build SQL query to search file contents using FULLTEXT index
        $sql = "
            SELECT f.file_id, f.file_name, f.file_type, f.upload_date, 
                   COALESCE(dt.type_name, 'Unknown') AS document_type,
                   COALESCE(f.copy_type, 'softcopy') AS copy_type,
                   f.file_status
            FROM files f
            JOIN text_repository tr ON f.file_id = tr.file_id
            LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
            LEFT JOIN users u ON f.user_id = u.user_id
            LEFT JOIN users_department ud ON u.user_id = ud.user_id
            WHERE MATCH(tr.extracted_text) AGAINST (? IN BOOLEAN MODE)
            AND (f.user_id = ? OR ud.department_id IN (" .
            (empty($departmentIds) ? '0' : implode(',', array_fill(0, count($departmentIds), '?')))
            . "))
        ";
        $params = [$searchQuery, $userId];
        if (!empty($departmentIds)) {
            $params = array_merge($params, $departmentIds);
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log("Error in test_search.php: " . $e->getMessage() . " | Line: " . $e->getLine());
    sendJsonResponse(false, 'Server error: Unable to process search: ' . $e->getMessage(), [], 500);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Content Search - Arc Hive</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
        }

        .search-form {
            margin-bottom: 20px;
        }

        .search-form input[type="text"] {
            padding: 8px;
            width: 300px;
        }

        .search-form button {
            padding: 8px 16px;
        }

        .results {
            margin-top: 20px;
        }

        .file-item {
            border: 1px solid #ddd;
            padding: 10px;
            margin-bottom: 10px;
        }

        .error {
            color: red;
        }

        .view-button,
        .retry-button {
            padding: 6px 12px;
            color: white;
            border: none;
            cursor: pointer;
            border-radius: 4px;
            margin-right: 5px;
        }

        .view-button {
            background-color: #4CAF50;
        }

        .view-button:hover {
            background-color: #45a049;
        }

        .retry-button {
            background-color: #2196F3;
        }

        .retry-button:hover {
            background-color: #1e88e5;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }

        .modal-content {
            background-color: white;
            padding: 20px;
            border-radius: 5px;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
            position: relative;
        }

        .close-button {
            position: absolute;
            top: 10px;
            right: 10px;
            font-size: 20px;
            cursor: pointer;
        }

        .highlight {
            background-color: yellow;
            font-weight: bold;
        }

        .no-text {
            color: #555;
            font-style: italic;
        }
    </style>
</head>

<body>
    <h2>Test File Content Search</h2>
    <form class="search-form" method="GET">
        <input type="text" name="q" placeholder="Search file contents..." value="<?php echo htmlspecialchars($searchQuery); ?>">
        <button type="submit">Search</button>
    </form>
    <div class="results">
        <?php if (!empty($searchQuery)): ?>
            <?php if (empty($files)): ?>
                <p>No files found with content matching "<?php echo htmlspecialchars($searchQuery); ?>". This may be due to files not being OCR-processed or no matching content.</p>
            <?php else: ?>
                <h3>Search Results</h3>
                <?php foreach ($files as $file): ?>
                    <div class="file-item">
                        <p><strong>File Name:</strong> <?php echo htmlspecialchars($file['file_name']); ?></p>
                        <p><strong>Document Type:</strong> <?php echo htmlspecialchars($file['document_type']); ?></p>
                        <p><strong>File Type:</strong> <?php echo htmlspecialchars($file['file_type']); ?></p>
                        <p><strong>Copy Type:</strong> <?php echo htmlspecialchars($file['copy_type']); ?></p>
                        <p><strong>Upload Date:</strong> <?php echo htmlspecialchars($file['upload_date']); ?></p>
                        <button class="view-button" data-file-id="<?php echo $file['file_id']; ?>" data-search-query="<?php echo htmlspecialchars($searchQuery); ?>">View Contents</button>
                        <?php if ($file['file_status'] === 'ocr_failed'): ?>
                            <button class="retry-button" data-file-id="<?php echo $file['file_id']; ?>" data-retry-enabled="true">Retry OCR</button>
                        <?php else: ?>
                            <button class="retry-button" data-file-id="<?php echo $file['file_id']; ?>" style="display: none;" data-retry-enabled="false">Retry OCR</button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php else: ?>
            <p>Enter a search query to find files by content.</p>
        <?php endif; ?>
    </div>

    <!-- Modal for displaying extracted text -->
    <div id="textModal" class="modal">
        <div class="modal-content">
            <span class="close-button">&times;</span>
            <h3>Extracted Text</h3>
            <p><strong>File:</strong> <span id="modalFileName"></span></p>
            <p><strong>Status:</strong> <span id="modalFileStatus"></span></p>
            <div id="extractedText" class="no-text">Loading...</div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const modal = document.getElementById('textModal');
            const closeButton = document.querySelector('.close-button');
            const extractedTextDiv = document.getElementById('extractedText');
            const modalFileName = document.getElementById('modalFileName');
            const modalFileStatus = document.getElementById('modalFileStatus');

            // Close modal when clicking the close button
            closeButton.addEventListener('click', () => {
                modal.style.display = 'none';
                extractedTextDiv.innerHTML = '<div class="no-text">Loading...</div>';
                modalFileName.textContent = '';
                modalFileStatus.textContent = '';
            });

            // Close modal when clicking outside
            window.addEventListener('click', (event) => {
                if (event.target === modal) {
                    modal.style.display = 'none';
                    extractedTextDiv.innerHTML = '<div class="no-text">Loading...</div>';
                    modalFileName.textContent = '';
                    modalFileStatus.textContent = '';
                }
            });

            // Handle view button clicks
            document.querySelectorAll('.view-button').forEach(button => {
                button.addEventListener('click', () => {
                    const fileId = button.getAttribute('data-file-id');
                    const searchQuery = button.getAttribute('data-search-query');
                    const retryButton = button.parentElement.querySelector('.retry-button');

                    // Limit search query length to prevent regex issues
                    const maxQueryLength = 100;
                    if (searchQuery.length > maxQueryLength) {
                        extractedTextDiv.innerHTML = '<div class="no-text">Search query too long. Please shorten it.</div>';
                        modalFileName.textContent = 'Unknown';
                        modalFileStatus.textContent = 'Unknown';
                        modal.style.display = 'flex';
                        retryButton.style.display = 'none';
                        return;
                    }

                    // Fetch extracted text via AJAX
                    fetch(`?action=view_text&file_id=${fileId}`, {
                            method: 'GET',
                            headers: {
                                'Accept': 'application/json'
                            }
                        })
                        .then(response => {
                            if (!response.ok) {
                                throw new Error(`HTTP error! Status: ${response.status}`);
                            }
                            return response.json();
                        })
                        .then(data => {
                            extractedTextDiv.innerHTML = ''; // Clear loading message
                            modalFileName.textContent = data.file_name || 'Unknown';
                            modalFileStatus.textContent = data.file_status || 'Unknown';
                            retryButton.style.display = data.file_status === 'ocr_failed' ? 'inline-block' : 'none';
                            retryButton.dataset.retryEnabled = data.file_status === 'ocr_failed' ? 'true' : 'false';

                            if (data.success && data.extracted_text) {
                                // Escape HTML to prevent XSS
                                const escapedText = data.extracted_text.replace(/</g, '&lt;').replace(/>/g, '&gt;');
                                // Highlight search term (case-insensitive)
                                const escapedQuery = searchQuery.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                                const regex = new RegExp(escapedQuery, 'gi');
                                const highlightedText = escapedText.replace(regex, match => `<span class="highlight">${match}</span>`);
                                extractedTextDiv.innerHTML = highlightedText.replace(/\n/g, '<br>');
                            } else {
                                let message = data.message || 'No text available for this file.';
                                if (data.file_status === 'pending_ocr') {
                                    message = 'Text extraction is still in progress. Please try again later.';
                                } else if (data.file_status === 'ocr_failed') {
                                    message = 'Text extraction failed. Click "Retry OCR" to try again.';
                                } else if (!data.extracted_text) {
                                    message = 'No text available for this file. It may not contain extractable text.';
                                }
                                extractedTextDiv.innerHTML = `<div class="no-text">${message} (Status: ${data.file_status})</div>`;
                            }
                            modal.style.display = 'flex';
                        })
                        .catch(error => {
                            console.error('Error fetching text for file_id ' + fileId + ':', error);
                            extractedTextDiv.innerHTML = `<div class="no-text">Failed to load text: ${error.message}. Please try again.</div>`;
                            modalFileName.textContent = 'Unknown';
                            modalFileStatus.textContent = 'Unknown';
                            retryButton.style.display = 'none';
                            modal.style.display = 'flex';
                        });
                });
            });

            // Handle retry OCR button clicks
            document.querySelectorAll('.retry-button').forEach(button => {
                button.addEventListener('click', () => {
                    const fileId = button.getAttribute('data-file-id');
                    const retryEnabled = button.getAttribute('data-retry-enabled') === 'true';
                    if (!retryEnabled) {
                        extractedTextDiv.innerHTML = '<div class="no-text">OCR retry not available for this file.</div>';
                        modalFileName.textContent = 'Unknown';
                        modalFileStatus.textContent = 'Unknown';
                        modal.style.display = 'flex';
                        return;
                    }

                    // Trigger OCR retry via AJAX
                    fetch(`?action=retry_ocr&file_id=${fileId}`, {
                            method: 'GET',
                            headers: {
                                'Accept': 'application/json'
                            }
                        })
                        .then(response => {
                            if (!response.ok) {
                                throw new Error(`HTTP error! Status: ${response.status}`);
                            }
                            return response.json();
                        })
                        .then(data => {
                            extractedTextDiv.innerHTML = `<div class="no-text">${data.message || 'OCR retry scheduled.'}</div>`;
                            modalFileName.textContent = data.file_name || 'Unknown';
                            modalFileStatus.textContent = 'pending_ocr';
                            button.style.display = 'none';
                            modal.style.display = 'flex';
                        })
                        .catch(error => {
                            console.error('Error retrying OCR for file_id ' + fileId + ':', error);
                            extractedTextDiv.innerHTML = `<div class="no-text">Failed to retry OCR: ${error.message}. Please try again.</div>`;
                            modal.style.display = 'flex';
                        });
                });
            });
        });
    </script>
</body>

</html>