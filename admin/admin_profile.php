<?php
// admin/admin_profile.php
session_start();
require_once '../config/db_connect.php';

// Check if user is logged in and is an Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$ui_message = '';

// --- FORM HANDLING: EDIT PROFILE, CHANGE PASSWORD & PHOTO UPLOAD ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. EDIT PROFILE LOGIC
    if (isset($_POST['update_profile'])) {
        $new_username = trim($_POST['username'] ?? '');
        $new_fname = trim($_POST['first_name'] ?? '');
        $new_lname = trim($_POST['last_name'] ?? '');
        $new_email = trim($_POST['email'] ?? '');
        
        try {
            $update_stmt = $pdo->prepare("UPDATE users SET username = :uname, first_name = :fname, last_name = :lname, email = :email WHERE id = :id");
            $update_stmt->execute([
                ':uname' => $new_username,
                ':fname' => $new_fname,
                ':lname' => $new_lname,
                ':email' => $new_email,
                ':id' => $user_id
            ]);
            
            // Update session so sidebar reflects the new name instantly
            $_SESSION['username'] = !empty($new_fname) ? trim("$new_fname $new_lname") : $new_username;
            
            $ui_message = "<div class='bg-green-100 text-green-700 px-4 py-3 rounded-xl mb-6 shadow-sm font-bold border border-green-300'><i class='fas fa-check-circle mr-2'></i>Admin profile updated successfully!</div>";
        } catch (PDOException $e) {
            $ui_message = "<div class='bg-red-100 text-red-700 px-4 py-3 rounded-xl mb-6 shadow-sm font-bold border border-red-300'><i class='fas fa-exclamation-triangle mr-2'></i>Error updating profile: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }

    // 2. CHANGE PASSWORD LOGIC
    if (isset($_POST['change_password'])) {
        $current_pass = $_POST['current_password'];
        $new_pass = $_POST['new_password'];
        $confirm_pass = $_POST['confirm_password'];

        if ($new_pass !== $confirm_pass) {
            $ui_message = "<div class='bg-red-100 text-red-700 px-4 py-3 rounded-xl mb-6 shadow-sm font-bold border border-red-300'><i class='fas fa-exclamation-circle mr-2'></i>New passwords do not match!</div>";
        } else {
            try {
                $pass_stmt = $pdo->prepare("SELECT password FROM users WHERE id = :id");
                $pass_stmt->execute([':id' => $user_id]);
                $user_record = $pass_stmt->fetch();

                if ($user_record && password_verify($current_pass, $user_record['password'])) {
                    $hashed_pass = password_hash($new_pass, PASSWORD_DEFAULT);
                    $update_pass = $pdo->prepare("UPDATE users SET password = :pass WHERE id = :id");
                    $update_pass->execute([':pass' => $hashed_pass, ':id' => $user_id]);
                    
                    $ui_message = "<div class='bg-green-100 text-green-700 px-4 py-3 rounded-xl mb-6 shadow-sm font-bold border border-green-300'><i class='fas fa-check-circle mr-2'></i>Password changed successfully!</div>";
                } else {
                    $ui_message = "<div class='bg-red-100 text-red-700 px-4 py-3 rounded-xl mb-6 shadow-sm font-bold border border-red-300'><i class='fas fa-times-circle mr-2'></i>Incorrect current password!</div>";
                }
            } catch (PDOException $e) {
                $ui_message = "<div class='bg-red-100 text-red-700 px-4 py-3 rounded-xl mb-6 shadow-sm font-bold border border-red-300'><i class='fas fa-exclamation-triangle mr-2'></i>Database Error: " . htmlspecialchars($e->getMessage()) . "</div>";
            }
        }
    }

    // 3. PROFILE PHOTO UPLOAD LOGIC
    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
        $supabase_url = 'https://bqzamfwgqfxdqadrqorl.supabase.co'; 
        $supabase_key = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImJxemFtZndncWZ4ZHFhZHJxb3JsIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NzQyNjc0MDEsImV4cCI6MjA4OTg0MzQwMX0.9ENo40zPNKeP7AYNzK8XFEIQT-YvIJXYtTpQUgaQ_J0';
        $bucket_name  = 'kares-uploads';

        $file_tmp_path = $_FILES['profile_photo']['tmp_name'];
        $file_type = $_FILES['profile_photo']['type'];
        $clean_name = preg_replace("/[^a-zA-Z0-9.]/", "_", basename($_FILES['profile_photo']['name']));
        $unique_filename = time() . '_admin_profile_' . $user_id . '_' . $clean_name;
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
            $photo_url = $supabase_url . '/storage/v1/object/public/' . $bucket_name . '/' . $unique_filename;
            try {
                $update_photo = $pdo->prepare("UPDATE users SET profile_image = :photo WHERE id = :id");
                $update_photo->execute([':photo' => $photo_url, ':id' => $user_id]);
                $ui_message = "<div class='bg-green-100 text-green-700 px-4 py-3 rounded-xl mb-6 shadow-sm font-bold border border-green-300'><i class='fas fa-check-circle mr-2'></i>Profile photo updated!</div>";
            } catch (PDOException $e) {
                $ui_message = "<div class='bg-red-100 text-red-700 px-4 py-3 rounded-xl mb-6 shadow-sm font-bold border border-red-300'><i class='fas fa-exclamation-triangle mr-2'></i>Database Error saving photo URL.</div>";
            }
        } else {
            $ui_message = "<div class='bg-red-100 text-red-700 px-4 py-3 rounded-xl mb-6 shadow-sm font-bold border border-red-300'><i class='fas fa-times-circle mr-2'></i>Failed to upload photo to server.</div>";
        }
    }
} 

// --- FETCH ADMIN DATA ---
try {
    $user_stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
    $user_stmt->execute([':id' => $user_id]);
    $admin = $user_stmt->fetch();
    
    $username = $admin['username'] ?? '';
    $first_name = $admin['first_name'] ?? '';
    $last_name = $admin['last_name'] ?? '';
    $email = $admin['email'] ?? '';
    $profile_image_url = $admin['profile_image'] ?? '';
    
    // Determine display name
    $display_name = trim("$first_name $last_name");
    if (empty($display_name)) {
        $display_name = $username ?: 'Administrator';
    }
    
    $avatar_letter = strtoupper(substr($display_name, 0, 1));

    // --- FETCH ADMIN STATS ---
    $pending_reqs = $pdo->query("SELECT COUNT(*) FROM assistance_requests WHERE status = 'Submitted'")->fetchColumn();
    $pending_verifs = $pdo->query("SELECT COUNT(*) FROM user_verifications WHERE status = 'Pending'")->fetchColumn();
    $total_citizens = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'Citizen'")->fetchColumn();

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Profile - KARES</title>
    <link rel="icon" type="image/png" href="../assets/images/kareslogo.png" />
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style> 
        * { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; } 
        .custom-input { background-color: #f3f4f6; color: #374151; font-weight: 600; padding: 10px 16px; border-radius: 50px; width: 100%; border: 1px solid #e5e7eb; outline: none; transition: 0.3s; }
        .custom-input:focus { border-color: #5b8fb0; box-shadow: 0 0 0 2px rgba(91, 143, 176, 0.2); background-color: #ffffff; }
        .custom-label { font-size: 13px; font-weight: 700; color: #6b7280; margin-bottom: 4px; display: block; padding-left: 10px; text-transform: uppercase; letter-spacing: 0.05em;}
    </style>
</head>
<body class="bg-gray-50 flex"> 
    
    <?php include 'includes/sidebar.php'; ?>

    <div class="flex-1 ml-64 p-10 min-h-screen">

        <?= $ui_message ?>

        <div class="bg-white rounded-[30px] shadow-sm p-8 flex flex-col md:flex-row items-center gap-8 mb-8 border border-gray-100 relative overflow-hidden">
            <div class="absolute top-0 right-0 p-6 opacity-5 pointer-events-none"><i class="fas fa-user-shield text-9xl text-[#3d143e]"></i></div>
            
            <div class="flex items-center gap-6 relative z-10">
                <div class="w-32 h-32 bg-[#c6943a] rounded-full flex items-center justify-center text-5xl font-black text-white relative shadow-inner overflow-hidden border-4 border-white ring-4 ring-gray-50">
                    <?php if (!empty($profile_image_url)): ?>
                        <img src="<?= htmlspecialchars($profile_image_url) ?>" class="w-full h-full object-cover" alt="Profile Photo">
                    <?php else: ?>
                        <?= $avatar_letter ?>
                    <?php endif; ?>
                    
                    <form action="admin_profile.php" method="POST" enctype="multipart/form-data" id="photo-upload-form" class="absolute bottom-0 w-full h-10 bg-black/60 hover:bg-black/80 transition flex items-center justify-center cursor-pointer">
                        <input type="file" id="profile-photo-input" name="profile_photo" class="hidden" accept="image/*" onchange="document.getElementById('photo-upload-form').submit();">
                        <label for="profile-photo-input" class="w-full h-full flex items-center justify-center cursor-pointer">
                            <i class="fas fa-camera text-white text-sm"></i>
                        </label>
                    </form>
                </div>
                <div>
                    <h2 class="text-3xl font-black text-[#3d143e] capitalize tracking-tight"><?= htmlspecialchars($display_name) ?></h2>
                    <p class="text-[#c6943a] font-bold text-sm uppercase tracking-widest mt-1 mb-2">System Administrator</p>
                    <p class="text-gray-500 font-medium text-sm flex items-center gap-2"><i class="fas fa-envelope text-gray-400"></i> <?= htmlspecialchars($email) ?: 'No email set' ?></p>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-white rounded-[30px] shadow-sm p-6 flex items-center gap-4 border border-gray-100 hover:-translate-y-1 transition transform duration-300">
                <div class="w-14 h-14 bg-blue-50 text-blue-500 rounded-full flex items-center justify-center text-2xl shadow-inner shrink-0">
                    <i class="fas fa-file-invoice"></i>
                </div>
                <div>
                    <h3 class="text-2xl font-black text-gray-800 leading-none"><?= $pending_reqs ?></h3>
                    <p class="text-gray-500 font-medium text-xs uppercase tracking-wider mt-1">Pending Requests</p>
                </div>
            </div>

            <div class="bg-white rounded-[30px] shadow-sm p-6 flex items-center gap-4 border border-gray-100 hover:-translate-y-1 transition transform duration-300">
                <div class="w-14 h-14 bg-yellow-50 text-yellow-600 rounded-full flex items-center justify-center text-2xl shadow-inner shrink-0">
                    <i class="fas fa-id-card-alt"></i>
                </div>
                <div>
                    <h3 class="text-2xl font-black text-gray-800 leading-none"><?= $pending_verifs ?></h3>
                    <p class="text-gray-500 font-medium text-xs uppercase tracking-wider mt-1">Pending Verifications</p>
                </div>
            </div>

            <div class="bg-white rounded-[30px] shadow-sm p-6 flex items-center gap-4 border border-gray-100 hover:-translate-y-1 transition transform duration-300">
                <div class="w-14 h-14 bg-green-50 text-green-500 rounded-full flex items-center justify-center text-2xl shadow-inner shrink-0">
                    <i class="fas fa-users"></i>
                </div>
                <div>
                    <h3 class="text-2xl font-black text-gray-800 leading-none"><?= $total_citizens ?></h3>
                    <p class="text-gray-500 font-medium text-xs uppercase tracking-wider mt-1">Total Citizens</p>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-10">
            
            <div class="bg-white rounded-[30px] shadow-sm border border-gray-100 p-8 relative overflow-hidden">
                <h3 class="text-xl font-black text-[#3d143e] mb-6 pb-4 border-b border-gray-100 flex items-center gap-3">
                    <i class="fas fa-user-edit text-[#c6943a]"></i> Edit Information
                </h3>
                
                <form action="admin_profile.php" method="POST" class="space-y-4">
                    <div>
                        <label class="custom-label">Username</label>
                        <input type="text" name="username" value="<?= htmlspecialchars($username) ?>" class="custom-input" placeholder="Admin Username" required>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="custom-label">First Name</label>
                            <input type="text" name="first_name" value="<?= htmlspecialchars($first_name) ?>" class="custom-input" placeholder="Optional">
                        </div>
                        <div>
                            <label class="custom-label">Last Name</label>
                            <input type="text" name="last_name" value="<?= htmlspecialchars($last_name) ?>" class="custom-input" placeholder="Optional">
                        </div>
                    </div>
                    <div>
                        <label class="custom-label">Email Address</label>
                        <input type="email" name="email" value="<?= htmlspecialchars($email) ?>" class="custom-input" placeholder="admin@example.com">
                    </div>
                    
                    <div class="pt-4">
                        <button type="submit" name="update_profile" class="w-full bg-[#3d143e] text-white py-3 rounded-full font-bold shadow-md hover:bg-purple-900 transition flex items-center justify-center gap-2">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>

            <div class="bg-white rounded-[30px] shadow-sm border border-gray-100 p-8 relative overflow-hidden">
                <h3 class="text-xl font-black text-[#3d143e] mb-6 pb-4 border-b border-gray-100 flex items-center gap-3">
                    <i class="fas fa-lock text-[#c6943a]"></i> Change Password
                </h3>
                
                <form action="admin_profile.php" method="POST" class="space-y-4">
                    <div>
                        <label class="custom-label">Current Password</label>
                        <input type="password" name="current_password" required class="custom-input" placeholder="Enter current password">
                    </div>
                    <div>
                        <label class="custom-label">New Password</label>
                        <input type="password" name="new_password" required class="custom-input" placeholder="Enter new password">
                    </div>
                    <div>
                        <label class="custom-label">Confirm New Password</label>
                        <input type="password" name="confirm_password" required class="custom-input" placeholder="Verify new password">
                    </div>
                    
                    <div class="pt-4">
                        <button type="submit" name="update_password" class="w-full bg-[#3d143e] text-white font-bold py-3 rounded-full shadow-md hover:bg-purple-900 transition flex items-center justify-center gap-2">
                        <i class="fas fa-key"></i> Update Password
                        </button>
                    </div>
                </form>
            </div>

        </div>
    </div>

</body>
</html>