<?php
// processors/toggle_email_alerts.php
session_start();
require_once '../config/db_connect.php';

if (!isset($_SESSION['user_id'])) {
    exit('Unauthorized');
}

// Get the JSON data sent from Javascript
$data = json_decode(file_get_contents('php://input'), true);

if (isset($data['enabled'])) {
    $is_enabled = $data['enabled'] ? 'TRUE' : 'FALSE';
    
    try {
        // Update the user's preference in the database
        $stmt = $pdo->prepare("UPDATE users SET email_alerts = $is_enabled WHERE id = :uid");
        $stmt->execute([':uid' => $_SESSION['user_id']]);
        echo "Success";
    } catch (PDOException $e) {
        echo "Error";
    }
} else {
    echo "Invalid data";
}
?>