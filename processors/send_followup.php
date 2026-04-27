<?php
// processors/send_followup.php
session_start();
require_once '../config/db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit();
}

$user_id = $_SESSION['user_id'];
$request_id = $_POST['request_id'] ?? '';

if (empty($request_id)) {
    echo json_encode(['success' => false, 'message' => 'Missing Request ID.']);
    exit();
}

try {
    // 1. Verify the request actually exists and belongs to this user
    // FIXED: Added assistance_type to the SELECT query
    $check_stmt = $pdo->prepare("SELECT status, assistance_type FROM assistance_requests WHERE request_id = ? AND user_id = ?");
    $check_stmt->execute([$request_id, $user_id]);
    $req = $check_stmt->fetch();

    if (!$req) {
        echo json_encode(['success' => false, 'message' => 'Request not found.']);
        exit();
    }

    // 2. Prevent following up on already released or declined requests
    if ($req['status'] === 'Released' || $req['status'] === 'Declined') {
        echo json_encode(['success' => false, 'message' => 'This request is already ' . $req['status'] . '.']);
        exit();
    }

    // 3. Prevent spamming: Check if they already sent a follow-up in the last 24 hours
    $spam_check = $pdo->prepare("SELECT created_at FROM admin_notifications WHERE request_id = ? ORDER BY created_at DESC LIMIT 1");
    $spam_check->execute([$request_id]);
    if ($last_notif = $spam_check->fetch()) {
        $last_time = strtotime($last_notif['created_at']);
        if ((time() - $last_time) < 86400) { // 86400 seconds = 24 hours
            echo json_encode(['success' => false, 'message' => 'You already sent a follow-up recently. Please wait 24 hours.']);
            exit();
        }
    }

    // 4. Fetch the user's email and insert the notification
    $email_stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
    $email_stmt->execute([$user_id]);
    $user_email = $email_stmt->fetchColumn() ?: 'A citizen'; 

    // FIXED: Formatted the message to clearly include the Assistance Type
    $aid_type = htmlspecialchars($req['assistance_type']);
    $msg = "{$user_email} is requesting a follow-up regarding their [{$aid_type}] application.";
    
    $insert = $pdo->prepare("INSERT INTO admin_notifications (request_id, user_id, message) VALUES (?, ?, ?)");
    $insert->execute([$request_id, $user_id, $msg]);

    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
?>