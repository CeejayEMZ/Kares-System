<?php
// processors/mark_notifications_read.php
session_start();
require_once '../config/db_connect.php';

if (!isset($_SESSION['user_id'])) {
    exit('Unauthorized');
}

try {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = :uid AND is_read = FALSE");
    $stmt->execute([':uid' => $_SESSION['user_id']]);
    echo "Success";
} catch (PDOException $e) {
    echo "Error";
}
?>