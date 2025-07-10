<?php
session_start();
require 'db_connection.php'; // Assumes $conn is initialized with MySQLi connection

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Initialize variables
$error = '';
$csrf_token = '';

// Generate CSRF token if not set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

/**
 * Log login attempt to transaction table
 * @param mysqli $conn Database connection
 * @param int|null $user_id User ID or null if failed
 * @param string $status Success or Failure
 * @param string $message Log message
 */
function logLoginAttempt($conn, $user_id, $status, $message)
{
    $stmt = $conn->prepare("
        INSERT INTO transaction (User_id, Transaction_status, Transaction_type, Time, Massage)
        VALUES (?, ?, ?, NOW(), ?)
    ");
    if (!$stmt) {
        error_log("Failed to prepare log statement: " . $conn->error, 3, 'error_log.log');
        return;
    }
    // Transaction_type: 1 for success, 2 for failure
    $transaction_type = ($status === 'Success') ? 1 : 2;
    // Handle null User_id
    $null = null;
    $stmt->bind_param("isss", $user_id ?? $null, $status, $transaction_type, $message);
    if (!$stmt->execute()) {
        error_log("Failed to log login attempt: " . $stmt->error, 3, 'error_log.log');
    }
    $stmt->close();
}

/**
 * Fetch user's department affiliations
 * @param mysqli $conn Database connection
 * @param int $user_id User ID
 * @return array Department IDs
 */
function getUserDepartments($conn, $user_id)
{
    $stmt = $conn->prepare("
        SELECT Department_id 
        FROM users_department 
        WHERE User_id = ?
    ");
    if (!$stmt) {
        error_log("Failed to prepare department query: " . $conn->error, 3, 'error_log.log');
        return [];
    }
    $stmt->bind_param("i", $user_id);
    if (!$stmt->execute()) {
        error_log("Failed to fetch departments: " . $stmt->error, 3, 'error_log.log');
        $stmt->close();
        return [];
    }
    $result = $stmt->get_result();
    $departments = array_column($result->fetch_all(MYSQLI_ASSOC), 'Department_id');
    $stmt->close();
    return $departments;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Validate CSRF token
    $posted_csrf = filter_input(INPUT_POST, 'csrf_token', FILTER_SANITIZE_STRING) ?? '';
    if ($posted_csrf !== $_SESSION['csrf_token']) {
        $error = "Invalid CSRF token.";
        logLoginAttempt($conn, null, 'Failure', 'Invalid CSRF token for username: ' . ($_POST['username'] ?? 'unknown'));
    } else {
        // Sanitize and validate inputs
        $username = trim(filter_input(INPUT_POST, 'username', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '');
        $password = trim(filter_input(INPUT_POST, 'password', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '');

        if (empty($username) || empty($password)) {
            $error = "Username and password are required.";
            logLoginAttempt($conn, null, 'Failure', 'Empty username or password');
        } else {
            // Fetch user from database
            $stmt = $conn->prepare("
                SELECT User_id, Username, Password, Role, Position 
                FROM users 
                WHERE Username = ?
            ");
            if (!$stmt) {
                error_log("Failed to prepare user query: " . $conn->error, 3, 'error_log.log');
                $error = "An error occurred. Please try again later.";
            } else {
                $stmt->bind_param("s", $username);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    $user = $result->fetch_assoc();
                    $stmt->close();

                    if ($user && password_verify($password, $user['Password'])) {
                        // Regenerate session ID for security
                        session_regenerate_id(true);

                        // Set session variables
                        $_SESSION['user_id'] = $user['User_id'];
                        $_SESSION['username'] = $user['Username'];
                        $_SESSION['role'] = $user['Role'];
                        $_SESSION['position'] = $user['Position'];
                        $_SESSION['departments'] = getUserDepartments($conn, $user['User_id']);
                        // No sub_departments table in schema, set as empty
                        $_SESSION['sub_departments'] = [];

                        // Log successful login
                        logLoginAttempt($conn, $user['User_id'], 'Success', 'User logged in successfully');

                        // Redirect based on role
                        $redirect = ($user['Role'] === 'admin') ? 'admin_dashboard.php' : 'Dashboard.php';
                        header("Location: $redirect");
                        exit();
                    } else {
                        $error = "Invalid username or password.";
                        logLoginAttempt($conn, null, 'Failure', "Invalid login attempt for username: $username");
                    }
                } else {
                    error_log("Database error: " . $stmt->error, 3, 'error_log.log');
                    $error = "An error occurred. Please try again later.";
                    $stmt->close();
                }
            }
        }
    }
}

// Disable error display in production
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Montserrat', Arial, sans-serif;
            background-color: #f4f7f9;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }

        .login-container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            width: 350px;
            text-align: center;
        }

        .login-container h2 {
            margin-bottom: 20px;
            color: #34495e;
        }

        .login-container input {
            width: 100%;
            padding: 10px;
            margin: 10px 0;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 14px;
            box-sizing: border-box;
        }

        .login-container button {
            width: 100%;
            padding: 10px;
            background: #34495e;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            cursor: pointer;
            transition: background 0.3s;
        }

        .login-container button:hover {
            background: #50c878;
        }

        .login-container p {
            margin-top: 15px;
            font-size: 14px;
        }

        .login-container a {
            color: #34495e;
            text-decoration: none;
            font-weight: bold;
        }

        .login-container a:hover {
            color: #50c878;
        }

        .error {
            color: #cc0000;
            font-size: 14px;
            margin-bottom: 10px;
        }
    </style>
</head>

<body>
    <div class="login-container">
        <h2>Login</h2>
        <?php if (!empty($error)): ?>
            <p class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
        <form action="login.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="text" name="username" placeholder="Username" value="<?php echo isset($username) ? htmlspecialchars($username, ENT_QUOTES, 'UTF-8') : ''; ?>" required>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit">Login</button>
        </form>
    </div>
</body>

</html>