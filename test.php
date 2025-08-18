<?php
// Database connection settings
$servername = "localhost";
$username = "root"; // Update with your DB username
$password = ""; // Update with your DB password
$dbname = "arc-hive-maindb";

// Create database connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Initialize variables for form handling
$success_message = "";
$error_message = "";
$username = "";
$email = "";
$role = "Client";
$department_id = "";
$position = 0;

// Fetch departments for the dropdown
$departments = [];
$sql = "SELECT department_id, department_name FROM departments";
$result = $conn->query($sql);
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $departments[] = $row;
    }
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);
    $role = $_POST['role'];
    $department_id = $_POST['department_id'];
    $position = (int)$_POST['position'];

    // Basic validation
    if (empty($username) || empty($email) || empty($password) || empty($department_id)) {
        $error_message = "All fields are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "Invalid email format.";
    } else {
        // Check if username or email already exists
        $sql = "SELECT user_id FROM users WHERE username = ? OR email = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ss", $username, $email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $error_message = "Username or email already exists.";
        } else {
            // Hash password
            $password_hash = password_hash($password, PASSWORD_BCRYPT);

            // Insert user into users table
            $sql = "INSERT INTO users (username, password_hash, email, role, position, created_at) VALUES (?, ?, ?, ?, ?, NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssssi", $username, $password_hash, $email, $role, $position);

            if ($stmt->execute()) {
                $user_id = $conn->insert_id;

                // Insert into users_department table
                $sql = "INSERT INTO users_department (user_id, department_id) VALUES (?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ii", $user_id, $department_id);

                if ($stmt->execute()) {
                    $success_message = "User created successfully and assigned to department.";
                } else {
                    $error_message = "Failed to assign user to department.";
                }
            } else {
                $error_message = "Failed to create user.";
            }
        }
        $stmt->close();
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 600px;
            margin: 20px auto;
            padding: 20px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        label {
            display: block;
            margin-bottom: 5px;
        }

        input,
        select {
            width: 100%;
            padding: 8px;
            margin-bottom: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }

        .error {
            color: red;
        }

        .success {
            color: green;
        }

        button {
            padding: 10px 15px;
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }

        button:hover {
            background-color: #0056b3;
        }
    </style>
</head>

<body>
    <h2>Create New User</h2>

    <?php if ($success_message): ?>
        <p class="success"><?php echo htmlspecialchars($success_message); ?></p>
    <?php endif; ?>
    <?php if ($error_message): ?>
        <p class="error"><?php echo htmlspecialchars($error_message); ?></p>
    <?php endif; ?>

    <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
        <div class="form-group">
            <label for="username">Username:</label>
            <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($username); ?>" required>
        </div>
        <div class="form-group">
            <label for="email">Email:</label>
            <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required>
        </div>
        <div class="form-group">
            <label for="password">Password:</label>
            <input type="password" id="password" name="password" required>
        </div>
        <div class="form-group">
            <label for="role">Role:</label>
            <select id="role" name="role" required>
                <option value="Client" <?php echo $role == 'Client' ? 'selected' : ''; ?>>Client</option>
                <option value="Admin" <?php echo $role == 'Admin' ? 'selected' : ''; ?>>Admin</option>
            </select>
        </div>
        <div class="form-group">
            <label for="department_id">Department:</label>
            <select id="department_id" name="department_id" required>
                <option value="">Select Department</option>
                <?php foreach ($departments as $dept): ?>
                    <option value="<?php echo $dept['department_id']; ?>" <?php echo $department_id == $dept['department_id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($dept['department_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="position">Position/Rank:</label>
            <input type="number" id="position" name="position" value="<?php echo htmlspecialchars($position); ?>" min="0" required>
        </div>
        <button type="submit">Create User</button>
    </form>
</body>

</html>