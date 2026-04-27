<?php
// processors/submit_request.php
session_start();
require_once '../config/db_connect.php';

// Include PHPMailer classes manually for email sending
require_once '../vendor/Exception.php';
require_once '../vendor/PHPMailer.php';
require_once '../vendor/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Security check
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$supabase_url = 'https://bqzamfwgqfxdqadrqorl.supabase.co'; 
$supabase_key = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImJxemFtZndncWZ4ZHFhZHJxb3JsIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NzQyNjc0MDEsImV4cCI6MjA4OTg0MzQwMX0.9ENo40zPNKeP7AYNzK8XFEIQT-YvIJXYtTpQUgaQ_J0';
$bucket_name  = 'kares-uploads';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    $request_id = $_POST['ui_generated_id'] ?? 'REQ-' . time();
    $user_id = $_SESSION['user_id'];
    $citizen_email = trim($_POST['email'] ?? '');
    $assistance_type = trim($_POST['aid_type'] ?? 'Assistance');
    $first_name = trim($_POST['fname'] ?? 'Citizen');
    
    // Check if user wants email alerts
    $pref_stmt = $pdo->prepare("SELECT email_alerts FROM users WHERE id = :uid");
    $pref_stmt->execute([':uid' => $user_id]);
    $wants_emails = $pref_stmt->fetchColumn();
    
    function uploadToSupabase($file_field, $supabase_url, $supabase_key, $bucket_name) {
        if (isset($_FILES[$file_field]) && $_FILES[$file_field]['error'] === UPLOAD_ERR_OK) {
            $file_tmp_path = $_FILES[$file_field]['tmp_name'];
            $file_type = $_FILES[$file_field]['type'];
            $original_name = basename($_FILES[$file_field]['name']);
            $clean_name = preg_replace("/[^a-zA-Z0-9.]/", "_", $original_name);
            $unique_filename = time() . '_' . $file_field . '_' . $clean_name;
            $file_contents = file_get_contents($file_tmp_path);
            $endpoint = $supabase_url . '/storage/v1/object/' . $bucket_name . '/' . $unique_filename;
            
            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_POSTFIELDS, $file_contents);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $supabase_key,
                'Content-Type: ' . $file_type
            ]);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($http_code === 200) {
                return $supabase_url . '/storage/v1/object/public/' . $bucket_name . '/' . $unique_filename;
            } else {
                die("<h3>Supabase Upload Failed on field: <b>$file_field</b></h3>");
            }
        }
        return null;
    }

    $id_front_path = uploadToSupabase('id_front', $supabase_url, $supabase_key, $bucket_name);
    $id_back_path = uploadToSupabase('id_back', $supabase_url, $supabase_key, $bucket_name);
    $indigency_path = uploadToSupabase('indigency_cert', $supabase_url, $supabase_key, $bucket_name);
    $medical_path = uploadToSupabase('medical_cert', $supabase_url, $supabase_key, $bucket_name);
    $patient_path = uploadToSupabase('patient_id', $supabase_url, $supabase_key, $bucket_name);
    $claimant_path = uploadToSupabase('claimant_id', $supabase_url, $supabase_key, $bucket_name);
    $reseta_path = uploadToSupabase('reseta', $supabase_url, $supabase_key, $bucket_name);
    $social_case_path = uploadToSupabase('social_case', $supabase_url, $supabase_key, $bucket_name);
    $quotation_path = uploadToSupabase('quotation', $supabase_url, $supabase_key, $bucket_name);
    $endorsement_path = uploadToSupabase('endorsement', $supabase_url, $supabase_key, $bucket_name);
    $hospital_bill_path = uploadToSupabase('hospital_bill', $supabase_url, $supabase_key, $bucket_name);
    $promissory_note_path = uploadToSupabase('promissory_note', $supabase_url, $supabase_key, $bucket_name);
    $death_cert_path = uploadToSupabase('death_cert', $supabase_url, $supabase_key, $bucket_name);
    $funeral_contract_path = uploadToSupabase('funeral_contract', $supabase_url, $supabase_key, $bucket_name);

    try {
        $sql = "INSERT INTO assistance_requests (
            request_id, user_id, status, mobile_number, gcash_number, email, 
            first_name, middle_name, last_name, name_extension, civil_status, family_income, 
            region, city, barangay, house_no, street, 
            em_first_name, em_last_name, em_contact, em_relationship, 
            id_type, id_number, id_front_path, id_back_path, 
            assistance_type, description, 
            indigency_cert_path, medical_cert_path, patient_id_path, claimant_id_path,
            reseta_path, social_case_path, quotation_path, endorsement_path, 
            hospital_bill_path, promissory_note_path, death_cert_path, funeral_contract_path
        ) VALUES (
            :request_id, :user_id, 'Submitted', :mobile, :gcash, :email,
            :fname, :mname, :lname, :ext, :civil, :income,
            :region, :city, :brgy, :house, :street,
            :em_fname, :em_lname, :em_contact, :em_rel,
            :id_type, :id_number, :id_front, :id_back,
            :aid_type, :description,
            :indigency, :medical, :patient, :claimant,
            :reseta, :social_case, :quotation, :endorsement,
            :hospital_bill, :promissory_note, :death_cert, :funeral_contract
        )";

        $stmt = $pdo->prepare($sql);
        
        $stmt->execute([
            ':request_id' => $request_id, 
            ':user_id' => $user_id, 
            ':mobile' => $_POST['mobile'] ?? '', 
            ':gcash' => $_POST['gcash'] ?? '',
            ':email' => $citizen_email, 
            ':fname' => $first_name, 
            ':mname' => $_POST['mname'] ?? '',
            ':lname' => $_POST['lname'] ?? '', 
            ':ext' => $_POST['ext'] ?? '', 
            ':civil' => $_POST['civil_status'] ?? '', 
            ':income' => $_POST['income'] ?? '',
            ':region' => $_POST['region'] ?? '', 
            ':city' => $_POST['city'] ?? '', 
            ':brgy' => $_POST['brgy'] ?? '',
            ':house' => $_POST['house_no'] ?? '', 
            ':street' => $_POST['street'] ?? '', 
            ':em_fname' => $_POST['em_fname'] ?? '',
            ':em_lname' => $_POST['em_lname'] ?? '', 
            ':em_contact' => $_POST['em_contact'] ?? '', 
            ':em_rel' => $_POST['em_rel'] ?? '',
            ':id_type' => $_POST['id_type'] ?? '', 
            ':id_number' => $_POST['id_number'] ?? '', 
            ':aid_type' => $assistance_type,
            ':description' => $_POST['description'] ?? '', 
            ':id_front' => $id_front_path, 
            ':id_back' => $id_back_path,
            ':indigency' => $indigency_path, 
            ':medical' => $medical_path, 
            ':patient' => $patient_path, 
            ':claimant' => $claimant_path,
            ':reseta' => $reseta_path, 
            ':social_case' => $social_case_path, 
            ':quotation' => $quotation_path, 
            ':endorsement' => $endorsement_path,
            ':hospital_bill' => $hospital_bill_path, 
            ':promissory_note' => $promissory_note_path, 
            ':death_cert' => $death_cert_path, 
            ':funeral_contract' => $funeral_contract_path
        ]);

        // ALWAYS save in-app notification
        try {
            $notif_title = "Request Submitted";
            $notif_msg = "We have received your request for {$assistance_type} (ID: {$request_id}).";
            $notif_stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message) VALUES (:uid, :title, :msg)");
            $notif_stmt->execute([':uid' => $user_id, ':title' => $notif_title, ':msg' => $notif_msg]);
        } catch (Exception $e) {}

        // ONLY send email if they want it
        if (!empty($citizen_email) && $wants_emails) {
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = 'smtp.gmail.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = 'adminkares@gmail.com'; 
                $mail->Password   = 'vborsopcwunmcfxv'; 
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = 587;

                $mail->setFrom('adminkares@gmail.com', 'Barangay Kanluran KARES');
                $mail->addAddress($citizen_email);

                $mail->isHTML(true);
                $mail->Subject = "KARES Request Received - {$request_id}";
                $mail->Body    = "
                    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e0d5e8; border-radius: 10px;'>
                        <h2 style='color: #3d143e;'>Request Received</h2>
                        <p style='color: #555;'>Hello {$first_name},</p>
                        <p style='color: #555;'>We have successfully received your request for <strong>{$assistance_type}</strong>.</p>
                        <div style='background-color: #f4f4f4; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                            <p style='margin:0; color:#555;'><strong>Your Reference ID:</strong></p>
                            <h3 style='margin:5px 0 0 0; color: #c6943a; font-size:22px;'>{$request_id}</h3>
                        </div>
                        <p style='color: #555;'>Our Barangay Administrators are currently reviewing your submitted documents. You can track the status of your request at any time by logging into the KARES Portal.</p>
                        <p style='color: #999; font-size: 12px; margin-top:30px;'>Barangay Santo Rosario-Kanluran</p>
                    </div>
                ";
                $mail->AltBody = "Hello {$first_name}, we received your request for {$assistance_type}. Your Reference ID is {$request_id}. Please check the KARES portal for updates.";
                $mail->send();
            } catch (Exception $e) {}
        }

        header("Location: ../user/user_home.php?success=1&req_id=" . urlencode($request_id));
        exit();

    } catch (PDOException $e) { 
        die("Database Error: " . $e->getMessage()); 
    }
} else { 
    header("Location: ../user/user_home.php"); 
    exit(); 
}
?>