<?php
// processors/submit_verification.php
session_start();
require_once '../config/db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$supabase_url = 'https://bqzamfwgqfxdqadrqorl.supabase.co'; 
$supabase_key = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImJxemFtZndncWZ4ZHFhZHJxb3JsIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NzQyNjc0MDEsImV4cCI6MjA4OTg0MzQwMX0.9ENo40zPNKeP7AYNzK8XFEIQT-YvIJXYtTpQUgaQ_J0';
$bucket_name  = 'kares-uploads';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_id = $_SESSION['user_id'];
    
    function uploadToSupabase($file_field, $supabase_url, $supabase_key, $bucket_name) {
        if (isset($_FILES[$file_field]) && $_FILES[$file_field]['error'] === UPLOAD_ERR_OK) {
            $file_tmp_path = $_FILES[$file_field]['tmp_name'];
            $file_type = $_FILES[$file_field]['type'];
            $original_name = basename($_FILES[$file_field]['name']);
            $clean_name = preg_replace("/[^a-zA-Z0-9.]/", "_", $original_name);
            $unique_filename = time() . '_verif_' . $file_field . '_' . $clean_name;
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
                die("Upload Failed on field: $file_field. Code: $http_code. $response");
            }
        }
        return null;
    }

    $id_front_path = uploadToSupabase('v_id_front', $supabase_url, $supabase_key, $bucket_name);
    $id_back_path = uploadToSupabase('v_id_back', $supabase_url, $supabase_key, $bucket_name);

    try {
        $sql = "INSERT INTO user_verifications (
            user_id, first_name, last_name, contact_number, address, id_type, id_number, id_front_path, id_back_path, status,
            mobile_number, gcash_number, email, middle_name, name_extension, civil_status, family_income,
            region, city, barangay, house_no, street,
            em_first_name, em_last_name, em_middle_name, em_ext, em_contact, em_relationship
        ) VALUES (
            :uid, :fname, :lname, :contact, :address, :idtype, :idnum, :front, :back, 'Pending',
            :mobile, :gcash, :email, :mname, :ext, :civil, :income,
            :region, :city, :brgy, :house, :street,
            :em_fname, :em_lname, :em_mname, :em_ext, :em_emcontact, :em_rel
        )";

        // Combine house and street to satisfy the old required address column
        $house = trim($_POST['house_no'] ?? '');
        $street = trim($_POST['street'] ?? '');
        $full_address = trim("$house $street");
        if (empty($full_address)) {
            $full_address = 'N/A'; 
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':uid' => $user_id,
            ':fname' => $_POST['fname'] ?? '',
            ':lname' => $_POST['lname'] ?? '',
            ':contact' => $_POST['mobile'] ?? '', // Satisfies original NOT NULL constraint
            ':address' => $full_address,          // Satisfies original NOT NULL constraint
            ':idtype' => $_POST['id_type'] ?? '',
            ':idnum' => $_POST['id_number'] ?? '',
            ':front' => $id_front_path,
            ':back' => $id_back_path,
            ':mobile' => $_POST['mobile'] ?? '',
            ':gcash' => $_POST['gcash'] ?? '',
            ':email' => $_POST['email'] ?? '',
            ':mname' => $_POST['mname'] ?? '',
            ':ext' => $_POST['ext'] ?? '',
            ':civil' => $_POST['civil_status'] ?? '',
            ':income' => $_POST['income'] ?? '',
            ':region' => $_POST['region'] ?? 'NCR',
            ':city' => $_POST['city'] ?? 'Pateros',
            ':brgy' => $_POST['brgy'] ?? 'Sto. Rosario-Kanluran',
            ':house' => $_POST['house_no'] ?? '',
            ':street' => $_POST['street'] ?? '',
            ':em_fname' => $_POST['em_fname'] ?? '',
            ':em_lname' => $_POST['em_lname'] ?? '',
            ':em_mname' => $_POST['em_mname'] ?? '',
            ':em_ext' => $_POST['em_ext'] ?? '',
            ':em_emcontact' => $_POST['em_contact'] ?? '',
            ':em_rel' => $_POST['em_rel'] ?? ''
        ]);

        header("Location: ../user/user_home.php?vsuccess=1");
        exit();
    } catch (PDOException $e) {
        die("Database Error: " . $e->getMessage());
    }
}
?>