<?php
// processors/verify_user.php
session_start();
require_once '../config/db_connect.php';

// Security check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: ../auth/login.php"); exit();
}

$target_user_id = $_GET['id'] ?? '';
$action = $_GET['action'] ?? '';

if (!empty($target_user_id) && !empty($action)) {
    try {
        $is_verified = ($action === 'verify') ? 'TRUE' : 'FALSE';
        
        $sql = "UPDATE users SET is_verified = $is_verified WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $target_user_id]);

        header("Location: ../admin/users.php?msg=Success");
        exit();
    } catch (PDOException $e) {
        die("Database Error: " . $e->getMessage());
    }
} else {
    header("Location: ../admin/users.php");
    exit();
}
?>