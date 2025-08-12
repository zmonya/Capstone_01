<?php
// Simple test script to verify upload functionality
session_start();

// Mock session for testing
$_SESSION['user_id'] = 1;
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Test form
?>
<!DOCTYPE html>
<html>
<head>
    <title>Upload Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .upload-form { max-width: 500px; margin: 0 auto; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; }
        input[type="file"], input[type="text"], select { width: 100%; padding: 8px; }
        button { padding: 10px 20px; background: #007bff; color: white; border: none; cursor: pointer; }
        button:hover { background: #0056b3; }
        .error { color: red; }
        .success { color: green; }
    </style>
</head>
<body>
    <h2>File Upload Test</h2>
    
    <form action="upload_handler.php" method="post" enctype="multipart/form-data" class="upload-form">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        
        <div class="form-group">
            <label for="document_type">Document Type:</label>
            <select name="document_type" id="document_type" required>
                <option value="">Select Type</option>
                <option value="pdf">PDF</option>
                <option value="doc">Word Document</option>
                <option value="jpg">JPEG Image</option>
                <option value="png">PNG Image</option>
            </select>
        </div>
        
        <div class="form-group">
            <label for="file">Choose File:</label>
            <input type="file" name="file" id="file" required 
                   accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.txt,.zip">
        </div>
        
        <div class="form-group">
            <label for="hard_copy_available">Hard Copy Available:</label>
            <select name="hard_copy_available" id="hard_copy_available">
                <option value="0">No</option>
                <option value="1">Yes</option>
            </select>
        </div>
        
        <div class="form-group">
            <label for="cabinet">Cabinet/Location:</label>
            <input type="text" name="cabinet" id="cabinet" placeholder="e.g., Main Cabinet">
        </div>
        
        <button type="submit">Upload File</button>
    </form>
    
    <div id="upload-result"></div>
    
    <script>
        document.querySelector('form').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const resultDiv = document.getElementById('upload-result');
            
            fetch('upload_handler.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    resultDiv.innerHTML = '<div class="success">✓ ' + data.message + '</div>';
                    this.reset();
                } else {
                    resultDiv.innerHTML = '<div class="error">✗ ' + data.message + '</div>';
                }
            })
            .catch(error => {
                resultDiv.innerHTML = '<div class="error">✗ Upload failed: ' + error.message + '</div>';
            });
        });
    </script>
</body>
</html>
<?php
// Cleanup test session
unset($_SESSION['user_id']);
unset($_SESSION['csrf_token']);
?>
