<?php
// processors/track_request_api.php
session_start();
require_once '../config/db_connect.php';

// Force PHP to use Manila time
date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$req_id = $_GET['req_id'] ?? '';

if (empty($req_id)) {
    echo json_encode(['error' => 'No Request ID provided']);
    exit();
}

try {
    // FIX 1: Use SELECT * to grab the name, contact, address, and description!
    $stmt = $pdo->prepare("SELECT * FROM assistance_requests WHERE request_id = :req_id LIMIT 1");
    $stmt->execute([':req_id' => $req_id]);
    $request = $stmt->fetch();

    if ($request) {
        // FIX 2: Convert Supabase UTC time to Local Manila Time
        $submitted = new DateTime($request['date_submitted'] . ' UTC');
        $submitted->setTimezone(new DateTimeZone('Asia/Manila'));
        $request['date_submitted_formatted'] = $submitted->format('m/d/Y h:i A');

        $updated = new DateTime($request['date_updated'] . ' UTC');
        $updated->setTimezone(new DateTimeZone('Asia/Manila'));
        $request['date_updated_formatted'] = $updated->format('m/d/Y h:i A');

        echo json_encode(['success' => true, 'data' => $request]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Request not found']);
    }
} catch (PDOException $e) {
    echo json_encode(['error' => 'Database error']);
}
?>