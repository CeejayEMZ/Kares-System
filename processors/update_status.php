<?php
// processors/update_status.php
session_start();
require_once '../config/db_connect.php';

// Security check: Only Admins can update statuses
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: ../auth/login.php");
    exit();
}

// Use $_REQUEST to catch the data regardless of how the browser sends it
$request_id = $_REQUEST['id'] ?? '';
$new_status = $_REQUEST['status'] ?? '';
$reject_reason = isset($_REQUEST['reject_reason']) ? trim($_REQUEST['reject_reason']) : '';

if (empty($request_id) || empty($new_status)) {
    die("Error: Missing Request ID or Status.");
}

$allowed_statuses = ['Approved', 'Declined', 'Released'];
if (!in_array($new_status, $allowed_statuses)) {
    die("Error: Invalid Status value provided.");
}

// Fallback just in case the text area is submitted completely empty
if ($new_status === 'Declined' && empty($reject_reason)) {
    $reject_reason = "Incomplete or invalid documents. Please review the requirements and submit a new request.";
}

try {
    // 1. Fetch details AND join the users table to check if they want email alerts!
    $fetch_sql = "
        SELECT a.user_id, a.email, a.first_name, a.assistance_type, u.email_alerts 
        FROM assistance_requests a 
        JOIN users u ON a.user_id = u.id 
        WHERE a.request_id = :id
    ";
    $fetch_stmt = $pdo->prepare($fetch_sql);
    $fetch_stmt->execute([':id' => $request_id]);
    $request_details = $fetch_stmt->fetch(PDO::FETCH_ASSOC);

    // 2. Update the status in the database
    if ($new_status === 'Declined') {
        $sql = "UPDATE assistance_requests SET status = :status, rejection_reason = :reason, date_updated = CURRENT_TIMESTAMP WHERE request_id = :id";
        $stmt = $pdo->prepare($sql);
        $success = $stmt->execute([
            ':status' => $new_status,
            ':reason' => $reject_reason,
            ':id' => $request_id
        ]);
    } else {
        $sql = "UPDATE assistance_requests SET status = :status, date_updated = CURRENT_TIMESTAMP WHERE request_id = :id";
        $stmt = $pdo->prepare($sql);
        $success = $stmt->execute([
            ':status' => $new_status,
            ':id' => $request_id
        ]);
    }

    if ($success) {
        
        if ($request_details && !empty($request_details['email'])) {
            $citizen_email = $request_details['email'];
            $first_name = $request_details['first_name'];
            $aid_type = $request_details['assistance_type'];
            $citizen_id = $request_details['user_id'];
            $wants_emails = $request_details['email_alerts']; 
            
            $status_color = "#c6943a"; 
            $status_msg = "Your request status has been updated.";
            $short_msg = "Your request for {$aid_type} has been updated to {$new_status}.";
            
            if ($new_status === 'Approved') {
                $status_color = "#22c55e"; 
                $status_msg = "Great news! Your request for <strong>{$aid_type}</strong> has been <strong>Approved</strong>. Please visit the Barangay Hall during office hours to receive further instructions on claiming your assistance.";
                $short_msg = "Your request for {$aid_type} has been Approved! Please visit the Barangay Hall.";
            } elseif ($new_status === 'Declined') {
                $status_color = "#ef4444"; 
                $status_msg = "We regret to inform you that your request for <strong>{$aid_type}</strong> has been <strong>Declined</strong> after review. <br><br><strong>Reason provided by Admin:</strong><br><em>\"" . htmlspecialchars($reject_reason) . "\"</em><br><br>Please ensure all your submitted documents are correct and valid before reapplying.";
                $short_msg = "Your request for {$aid_type} was Declined. Reason: " . htmlspecialchars($reject_reason);
            } elseif ($new_status === 'Released') {
                $status_color = "#a855f7"; 
                $status_msg = "Your assistance for <strong>{$aid_type}</strong> has been marked as <strong>Released</strong>. Thank you for utilizing the KARES system!";
                $short_msg = "Your assistance for {$aid_type} has been Released.";
            }

            // ALWAYS SAVE THE IN-APP NOTIFICATION
            try {
                $notif_title = "Request " . $new_status;
                $notif_stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message) VALUES (:uid, :title, :msg)");
                $notif_stmt->execute([':uid' => $citizen_id, ':title' => $notif_title, ':msg' => $short_msg]);
            } catch (PDOException $e) {}

            // ONLY SEND EMAIL IF email_alerts IS TRUE
            if ($wants_emails) {
                // Securely fetch the API Key from Railway
                $api_key = $_SERVER['BREVO_API_KEY'] ?? $_ENV['BREVO_API_KEY'] ?? getenv('BREVO_API_KEY') ?? '';

                if (!empty($api_key)) {
                    $htmlContent = "
                        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e0d5e8; border-radius: 10px;'>
                            <h2 style='color: #3d143e;'>Update on Request: {$request_id}</h2>
                            <p style='color: #555;'>Hello {$first_name},</p>
                            <div style='background-color: #f9f9f9; padding: 15px; border-left: 5px solid {$status_color}; margin: 20px 0;'>
                                <p style='margin:0; font-size: 16px; color:#333; line-height:1.5;'>{$status_msg}</p>
                            </div>
                            <p style='color: #555;'>You can view the full details of your request by logging into the KARES tracking portal.</p>
                            <p style='color: #999; font-size: 12px; margin-top:30px;'>Barangay Santo Rosario-Kanluran</p>
                        </div>
                    ";

                    $data = [
                        'sender' => ['name' => 'Barangay Kanluran KARES', 'email' => 'adminkares@gmail.com'],
                        'to' => [['email' => $citizen_email]],
                        'subject' => "KARES Request Update: {$new_status}",
                        'htmlContent' => $htmlContent
                    ];

                    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'accept: application/json',
                        'api-key: ' . $api_key,
                        'content-type: application/json'
                    ]);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

                    $response = curl_exec($ch);
                    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    // If API fails, log it but don't break the admin's redirect flow
                    if ($httpcode != 201 && $httpcode != 200 && $httpcode != 202) {
                        error_log("Brevo API Error in update_status.php: " . $response);
                    }
                } else {
                    error_log("Brevo API Error in update_status.php: API Key missing from environment.");
                }
            }
        }

        // Redirect based on the final status
        if ($new_status === 'Released') {
            header("Location: ../admin/released.php?msg=StatusUpdated");
        } else {
            header("Location: ../admin/approval.php?msg=StatusUpdated");
        }
        exit();
    } else {
        die("Error: Database failed to update the record.");
    }

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}
?>