<?php
// processors/process_register.php
session_start();
require_once '../config/db_connect.php'; // Updated path

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($username) || empty($email) || empty($password) || empty($confirm_password)) {
        header("Location: ../register.php?error=All fields are required.");
        exit();
    }

    if ($password !== $confirm_password) {
        header("Location: ../register.php?error=Passwords do not match.");
        exit();
    }

    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = :username OR email = :email LIMIT 1");
        $stmt->execute([':username' => $username, ':email' => $email]);
        
        if ($stmt->fetch()) {
            header("Location: ../register.php?error=Username or Email is already taken.");
            exit();
        }

        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        $insertStmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (:username, :email, :hash, 'User')");
        $insertStmt->execute([
            ':username' => $username,
            ':email' => $email,
            ':hash' => $hashed_password
        ]);

        header("Location: ../login.php?success=Account created successfully! Please log in."); // Updated path
        exit();

    } catch (PDOException $e) {
        header("Location: ../register.php?error=A database error occurred. Please try again.");
        exit();
    }
} else {
    header("Location: ../register.php");
    exit();
}
?>