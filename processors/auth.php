<?php
// processors/auth.php
session_start();
require_once '../config/db_connect.php'; 

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // If fields are empty, send back the error AND whatever username they typed
    if (empty($username) || empty($password)) {
        header("Location: ../login.php?error=Please fill in all fields.&username=" . urlencode($username));
        exit();
    }

    try {
        $stmt = $pdo->prepare("SELECT id, username, password_hash, role FROM users WHERE username = :username LIMIT 1");
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];

            if ($user['role'] === 'Admin') {
                header("Location: ../dashboard.php"); 
            } else {
                header("Location: ../user_home.php"); 
            }
            exit();
        } else {
            // Failed login: Send back the error AND the username
            header("Location: ../login.php?error=Invalid username or password.&username=" . urlencode($username)); 
            exit();
        }

    } catch (PDOException $e) {
        die("Database error: " . $e->getMessage());
    }
} else {
    header("Location: ../login.php");
    exit();
}
?>