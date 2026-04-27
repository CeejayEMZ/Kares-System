<?php
// user/profile.php
session_start();
require_once '../config/db_connect.php';

// Force PHP to use Manila time
date_default_timezone_set('Asia/Manila');

function formatManilaTimeShort($utc_string) {
    if (!$utc_string) return '';
    try {
        $date = new DateTime($utc_string, new DateTimeZone('UTC')); 
        $date->setTimezone(new DateTimeZone('Asia/Manila'));
        return $date->format('M j, g:i A'); 
    } catch (Exception $e) {
        return date('M j, g:i A', strtotime($utc_string)); 
    }
}

if (!isset($_SESSION['user_id'])) { 
    header("Location: ../auth/login.php"); 
    exit(); 
}
// Redirect admins away from the citizen profile
if ($_SESSION['role'] === 'Admin') { 
    header("Location: ../admin/dashboard.php"); 
    exit(); 
}

$user_id = $_SESSION['user_id'];
$citizen_name = $_SESSION['username'] ?? 'Citizen';
$citizen_email = '';
$mobile = 'Not provided yet';
$address = "Brgy. Sto. Rosario-Kanluran<br>Pateros, Metro Manila";
$created_at = 'Recently';
$profile_image_url = ''; // ADDED FOR PHOTO UPLOAD

// For the Edit Profile Form
$given_name = '';
$middle_name = '';
$surname = '';

$total_requests = 0;
$total_approved = 0;
$total_released = 0;
$recent_tx = [];
$actual_error_message = ''; 
$ui_message = ''; 

// Data for Verification View Modal
$v_id = ''; // Tracks specific verification ID
$v_fname = ''; $v_lname = ''; $v_mname = ''; $v_ext = '';
$v_civil = ''; $v_income = ''; $v_contact = ''; $v_gcash = '';
$v_house = ''; $v_street = ''; $v_brgy = ''; $v_city = '';
$v_em_fname = ''; $v_em_lname = ''; $v_em_contact = ''; $v_em_rel = '';
$v_id_type = ''; $v_id_number = '';

// --- FORM HANDLING: EDIT PROFILE, CHANGE PASSWORD, EDIT REQUEST, & PHOTO UPLOAD ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. EDIT PROFILE LOGIC
    if (isset($_POST['update_profile'])) {
        $new_given = trim($_POST['given_name'] ?? '');
        $new_middle = trim($_POST['middle_name'] ?? '');
        $new_surname = trim($_POST['surname'] ?? '');
        $new_email = trim($_POST['email'] ?? '');
        
        try {
            $update_stmt = $pdo->prepare("UPDATE users SET first_name = :fname, middle_name = :mname, last_name = :lname, email = :email WHERE id = :id");
            $update_stmt->execute([
                ':fname' => $new_given,
                ':mname' => $new_middle,
                ':lname' => $new_surname,
                ':email' => $new_email,
                ':id' => $user_id
            ]);
            
            if ($new_given || $new_surname) {
                $_SESSION['username'] = trim("$new_given $new_surname");
                $citizen_name = $_SESSION['username'];
            }
            
            $ui_message = "<div class='bg-green-100 text-green-700 px-4 py-3 rounded-xl mb-6 shadow-sm font-bold border border-green-300'><i class='fas fa-check-circle mr-2'></i>Profile updated successfully!</div>";
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

    // 3. EDIT VERIFICATION REQUEST LOGIC
    if (isset($_POST['submit_edit_request'])) {
        $reason = trim($_POST['edit_reason'] ?? '');
        $vid = $_POST['verif_id'] ?? '';
        try {
            $req_stmt = $pdo->prepare("UPDATE user_verifications SET edit_reason = :reason, submitted_at = CURRENT_TIMESTAMP WHERE id = :vid AND user_id = :uid AND status = 'Approved'");
            $req_stmt->execute([':uid' => $user_id, ':vid' => $vid, ':reason' => $reason]);
            $ui_message = "<div class='bg-blue-100 text-blue-700 px-4 py-3 rounded-xl mb-6 shadow-sm font-bold border border-blue-300'><i class='fas fa-info-circle mr-2'></i>Your request to edit verification details has been sent to the admin.</div>";
        } catch (PDOException $e) {
            $ui_message = "<div class='bg-red-100 text-red-700 px-4 py-3 rounded-xl mb-6 shadow-sm font-bold border border-red-300'><i class='fas fa-exclamation-triangle mr-2'></i>Database Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }

    // 4. PROFILE PHOTO UPLOAD LOGIC
    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
        $supabase_url = 'https://bqzamfwgqfxdqadrqorl.supabase.co'; 
        $supabase_key = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImJxemFtZndncWZ4ZHFhZHJxb3JsIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NzQyNjc0MDEsImV4cCI6MjA4OTg0MzQwMX0.9ENo40zPNKeP7AYNzK8XFEIQT-YvIJXYtTpQUgaQ_J0';
        $bucket_name  = 'kares-uploads';

        $file_tmp_path = $_FILES['profile_photo']['tmp_name'];
        $file_type = $_FILES['profile_photo']['type'];
        $clean_name = preg_replace("/[^a-zA-Z0-9.]/", "_", basename($_FILES['profile_photo']['name']));
        $unique_filename = time() . '_profile_' . $user_id . '_' . $clean_name;
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
// -----------------------------------------------------

try {
    // 1. Fetch basic account info
    $user_stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
    $user_stmt->execute([':id' => $user_id]);
    
    if ($user = $user_stmt->fetch()) {
        $citizen_email = $user['email'] ?? '';
        $created_at = !empty($user['created_at']) ? date('F j, Y', strtotime($user['created_at'])) : 'Recently';
        $profile_image_url = $user['profile_image'] ?? ''; 
        
        $given_name = $user['first_name'] ?? explode(' ', $citizen_name)[0];
        $middle_name = $user['middle_name'] ?? '';
        $surname = $user['last_name'] ?? '';
        
        $is_verified = filter_var($user['is_verified'], FILTER_VALIDATE_BOOLEAN);
        
        if ($given_name || $surname) {
            $citizen_name = trim("$given_name $surname");
        }
    }

    // Fetch dynamic avatar vars
    $citizen_avatar = $profile_image_url;
    $citizen_initial = strtoupper(substr($citizen_name, 0, 1));

    // Check if verification is currently pending
    $pending_verif_stmt = $pdo->prepare("SELECT status FROM user_verifications WHERE user_id = :uid ORDER BY submitted_at DESC LIMIT 1");
    $pending_verif_stmt->execute([':uid' => $user_id]);
    $verif_status = $pending_verif_stmt->fetchColumn();

    // If Verified, grab their details for the View Modal
    if ($is_verified) {
        $approved_verif_stmt = $pdo->prepare("SELECT * FROM user_verifications WHERE user_id = :uid AND status = 'Approved' ORDER BY submitted_at DESC LIMIT 1");
        $approved_verif_stmt->execute([':uid' => $user_id]);
        if ($verif_data = $approved_verif_stmt->fetch(PDO::FETCH_ASSOC)) {
            $v_id = $verif_data['id'] ?? ''; 
            $v_fname = $verif_data['first_name'] ?? '';
            $v_lname = $verif_data['last_name'] ?? '';
            $v_mname = $verif_data['middle_name'] ?? '';
            $v_ext = $verif_data['name_extension'] ?? '';
            $v_civil = $verif_data['civil_status'] ?? '';
            $v_income = $verif_data['family_income'] ?? '';
            $v_contact = $verif_data['mobile_number'] ?? '';
            $v_gcash = $verif_data['gcash_number'] ?? '';
            $v_house = $verif_data['house_no'] ?? '';
            $v_street = $verif_data['street'] ?? '';
            $v_brgy = $verif_data['barangay'] ?? 'Sto. Rosario-Kanluran';
            $v_city = $verif_data['city'] ?? 'Pateros';
            $v_em_fname = $verif_data['em_first_name'] ?? '';
            $v_em_lname = $verif_data['em_last_name'] ?? '';
            $v_em_contact = $verif_data['em_contact'] ?? '';
            $v_em_rel = $verif_data['em_relationship'] ?? '';
            $v_id_type = $verif_data['id_type'] ?? '';
            $v_id_number = $verif_data['id_number'] ?? '';
        }
    }

    // Fetch Notifications for Navbar
    $notif_stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = :uid ORDER BY created_at DESC LIMIT 10");
    $notif_stmt->execute([':uid' => $user_id]);
    $notifications = $notif_stmt->fetchAll();

    $unread_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND is_read = FALSE");
    $unread_stmt->execute([':uid' => $user_id]);
    $unread_count = $unread_stmt->fetchColumn();

    // 2. Fetch User Stats
    $stat_stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN status = 'Released' THEN 1 ELSE 0 END) as released
        FROM assistance_requests 
        WHERE user_id = :user_id
    ");
    $stat_stmt->execute([':user_id' => $user_id]);
    $stats = $stat_stmt->fetch();
    
    if ($stats) {
        $total_requests = $stats['total'] ?? 0;
        $total_approved = $stats['approved'] ?? 0;
        $total_released = $stats['released'] ?? 0;
    }

    // 3. Fetch the 3 most recent transactions
    $tx_stmt = $pdo->prepare("SELECT request_id, assistance_type, status, date_submitted FROM assistance_requests WHERE user_id = :user_id ORDER BY date_submitted DESC LIMIT 3");
    $tx_stmt->execute([':user_id' => $user_id]);
    $recent_tx = $tx_stmt->fetchAll();

} catch (PDOException $e) {
    $db_error = true;
    $actual_error_message = $e->getMessage(); 
}

$avatar_letter = strtoupper(substr($citizen_name, 0, 1));
?>
<!doctype html>
<html lang="en" class="h-full">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Profile - KARES</title>
  <link rel="icon" type="image/png" href="../assets/images/kareslogo.png" />
  <script src="https://cdn.tailwindcss.com/3.4.17"></script>
  <script>
    tailwind.config = { theme: { extend: { colors: { 'dark-violet': '#3d143e', 'gold': '#c6943a', 'off-white': '#e0d5e8', 'panel-blue': '#d1e3f0', 'header-blue': '#5b8fb0', 'card-beige': '#eee0c0', 'success-green': '#4ade80' } } } }
  </script>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
  <style> 
    * { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; } 
    .custom-input { background-color: #CCBFD5; color: #3d143e; font-weight: 600; padding: 6px 16px; border-radius: 50px; width: 100%; border: none; outline: none; transition: 0.3s; }
    .custom-input:focus { box-shadow: 0 0 0 2px #5b8fb0; }
    .custom-input[readonly] { opacity: 0.7; cursor: not-allowed; }
    .custom-label { font-size: 13px; font-weight: 500; color: #333; margin-bottom: 2px; display: block; padding-left: 10px;}
    
    /* Nav Styles */
    .nav-dropdown { display: none; } 
    .nav-item:hover .nav-dropdown { display: block; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    .animate-fade { animation: fadeIn 0.3s ease forwards; }

    /* Custom Scrollbar for Modals */
    .custom-scrollbar::-webkit-scrollbar { width: 6px; }
    .custom-scrollbar::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 8px; }
    .custom-scrollbar::-webkit-scrollbar-thumb { background: #c6943a; border-radius: 8px; }
    .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #a462a9; }
  </style>
</head>
<body class="bg-[#e0d5e8] min-h-screen flex flex-col">

    <nav class="bg-[#3d143e] shadow-lg sticky top-0 z-50">
     <div class="w-full px-4 lg:px-12 relative">
      <div class="flex justify-between items-center h-16">
       
       <div class="flex items-center gap-3 cursor-pointer" onclick="window.location.href='user_home.php'">
            <img src="../assets/images/navlogo.png" alt="Kanluran Logo" class="h-8 sm:h-10 w-auto object-contain drop-shadow-md" onerror="this.style.display='none'">
            <span class="text-white font-bold text-[13px] sm:text-lg tracking-wide truncate max-w-[120px] sm:max-w-none">Barangay Kanluran</span>
       </div>
       
       <div class="hidden lg:flex items-center gap-2 absolute left-1/2 transform -translate-x-1/2">
            <button onclick="window.location.href='user_home.php'" class="nav-btn text-white hover:text-[#c6943a] px-5 py-1.5 rounded-full transition-all font-medium flex items-center gap-2 hover:font-bold"><i class="fas fa-home"></i> Home</button>
            
            <div class="nav-item relative py-3">
             <button class="nav-btn text-white hover:text-[#c6943a] px-5 py-1.5 rounded-full transition-all font-medium flex items-center gap-2 hover:font-bold"><i class="fas fa-file-alt"></i> Request</button>
             <div class="nav-dropdown absolute top-full left-1/2 -translate-x-1/2 min-w-64 z-50 pt-2">
              <div class="bg-white rounded-2xl shadow-xl py-2 overflow-hidden border border-gray-200">
                  <button onclick="window.location.href='user_home.php?show_aid=true'" class="w-full text-left px-5 py-3 text-[#3d143e] hover:bg-[#d1e3f0] font-bold transition-all flex items-center gap-3"><i class="fas fa-hands-helping text-lg"></i> Social Welfare Assistance</button>
              </div>
             </div>
            </div>

            <button onclick="window.location.href='user_home.php?show_track=true'" class="nav-btn text-white hover:text-[#c6943a] px-5 py-1.5 rounded-full transition-all font-medium flex items-center gap-2 hover:font-bold"><i class="fas fa-search"></i> Track Request</button>
       </div>

       <div class="flex items-center gap-1 sm:gap-4">
           
           <div class="flex items-center">
               <?php if ($is_verified): ?>
                   <span class="bg-green-500/20 text-green-400 border border-green-500/30 px-2 py-1 md:px-4 md:py-1.5 rounded-full text-[10px] md:text-xs font-bold flex items-center gap-1 md:gap-2 shadow-sm">
                       <i class="fas fa-check-circle"></i> Verified
                   </span>
               <?php elseif ($verif_status === 'Pending'): ?>
                   <span class="bg-yellow-500/20 text-yellow-400 border border-yellow-500/30 px-2 py-1 md:px-4 md:py-1.5 rounded-full text-[10px] md:text-xs font-bold flex items-center gap-1 md:gap-2 shadow-sm">
                       <i class="fas fa-clock"></i> Pending
                   </span>
               <?php else: ?>
                   <button onclick="window.location.href='user_home.php'" class="bg-red-500/20 text-red-400 border border-red-500/30 hover:bg-red-500 hover:text-white transition px-2 py-1 md:px-4 md:py-1.5 rounded-full text-[10px] md:text-xs font-bold flex items-center gap-1 md:gap-2 shadow-sm animate-pulse">
                       <i class="fas fa-exclamation-circle"></i> Unverified
                   </button>
               <?php endif; ?>
           </div>

           <div class="relative py-3 cursor-pointer group">
               <button onclick="toggleNotifs()" class="relative text-white hover:text-[#c6943a] transition text-lg sm:text-xl p-1 sm:p-2 focus:outline-none">
                   <i class="fas fa-bell"></i>
                   <?php if ($unread_count > 0): ?>
                       <span id="notif-badge" class="absolute top-0 right-0 sm:top-1 sm:right-1 bg-red-500 text-white text-[9px] sm:text-[10px] font-bold w-3 h-3 sm:w-4 sm:h-4 rounded-full flex items-center justify-center border border-dark-violet">
                           <?= $unread_count ?>
                       </span>
                   <?php endif; ?>
               </button>
               
               <div id="notif-dropdown" class="hidden absolute top-full right-0 w-[260px] sm:w-80 bg-white rounded-2xl shadow-2xl border border-gray-200 overflow-hidden z-50 transform origin-top-right transition-all">
                   <div class="bg-[#5b8fb0] text-white px-4 py-3 flex justify-between items-center">
                       <h3 class="font-bold text-sm">Notifications</h3>
                       <?php if ($unread_count > 0): ?>
                           <span class="bg-white text-[#5b8fb0] text-xs font-bold px-2 py-0.5 rounded-full"><?= $unread_count ?> New</span>
                       <?php endif; ?>
                   </div>
                   <div class="max-h-80 overflow-y-auto bg-gray-50 flex flex-col">
                       <?php if (count($notifications) > 0): ?>
                           <?php foreach ($notifications as $notif): ?>
                               <div class="p-4 border-b border-gray-100 hover:bg-white transition <?= $notif['is_read'] ? 'opacity-70' : 'bg-blue-50/50' ?>">
                                   <div class="flex justify-between items-start mb-1">
                                       <h4 class="text-[#3d143e] font-bold text-sm"><?= htmlspecialchars($notif['title']) ?></h4>
                                       <span class="text-gray-400 text-[10px] whitespace-nowrap ml-2"><?= formatManilaTimeShort($notif['created_at']) ?></span>
                                   </div>
                                   <p class="text-gray-600 text-xs leading-relaxed"><?= htmlspecialchars($notif['message']) ?></p>
                               </div>
                           <?php endforeach; ?>
                       <?php else: ?>
                           <div class="p-6 text-center text-gray-400">
                               <i class="far fa-bell-slash text-3xl mb-2"></i>
                               <p class="text-sm font-medium">You have no notifications yet.</p>
                           </div>
                       <?php endif; ?>
                   </div>
               </div>
           </div>

           <div class="hidden lg:block nav-item relative py-3 cursor-pointer">
                <div class="flex items-center gap-2 text-white font-medium px-4 py-2 bg-white/10 rounded-full hover:bg-white/20 transition">
                    <?php if (!empty($citizen_avatar)): ?>
                        <img src="<?= htmlspecialchars($citizen_avatar) ?>" alt="Profile" class="w-7 h-7 rounded-full object-cover border border-white/50">
                    <?php else: ?>
                        <div class="w-7 h-7 rounded-full bg-gold flex items-center justify-center text-[#3d143e] font-bold text-xs shadow-sm">
                            <?= $citizen_initial ?>
                        </div>
                    <?php endif; ?>
                    Account <i class="fas fa-chevron-down text-xs"></i>
                </div>
                <div class="nav-dropdown absolute top-full right-0 min-w-48 z-50 pt-2">
                    <div class="bg-white rounded-2xl shadow-xl py-2 overflow-hidden border border-gray-200">
                        <button class="w-full text-left px-5 py-3 text-[#3d143e] bg-gray-50 font-bold transition-all flex items-center gap-3">
                            <i class="fas fa-id-badge text-lg"></i> My Profile
                        </button>
                        <a href="../processors/logout.php" class="w-full text-left px-5 py-3 text-red-500 hover:bg-red-50 font-bold transition-all flex items-center gap-3">
                            <i class="fas fa-sign-out-alt text-lg"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
            
            <button id="mobile-menu-btn" onclick="toggleMobileMenu()" class="lg:hidden text-white text-xl sm:text-2xl focus:outline-none p-1 sm:p-2 ml-1">
                 <i class="fas fa-bars"></i>
             </button>

        </div>

      </div>
     </div>

    <div id="mobile-menu" class="hidden lg:hidden bg-[#3d143e] border-t border-white/10 shadow-2xl pb-6 absolute w-full z-40 left-0 top-16 rounded-b-[30px] overflow-hidden">
         <div class="flex flex-col px-5 pt-4 space-y-2">

             <div class="pb-4 mb-2 border-b border-white/10 sm:hidden">
                 <?php if ($is_verified): ?>
                   <span class="bg-green-500/20 text-green-400 border border-green-500/30 px-4 py-3 rounded-xl text-sm font-bold flex items-center gap-3 justify-center w-full shadow-sm">
                       <i class="fas fa-check-circle text-lg"></i> Account Verified
                   </span>
                 <?php elseif ($verif_status === 'Pending'): ?>
                   <span class="bg-yellow-500/20 text-yellow-400 border border-yellow-500/30 px-4 py-3 rounded-xl text-sm font-bold flex items-center gap-3 justify-center w-full shadow-sm">
                       <i class="fas fa-clock text-lg"></i> Verification Pending
                   </span>
                 <?php else: ?>
                   <button onclick="window.location.href='user_home.php'" class="bg-red-500/20 text-red-400 border border-red-500/30 hover:bg-red-500 hover:text-white transition px-4 py-3 rounded-xl text-sm font-bold flex items-center gap-3 justify-center w-full shadow-sm">
                       <i class="fas fa-exclamation-circle text-lg"></i> Unverified - Click to Verify
                   </button>
                 <?php endif; ?>
             </div>

             <button onclick="window.location.href='user_home.php'" class="text-left text-white font-medium py-3 px-4 rounded-xl hover:bg-white/10 active:bg-white/20 transition flex items-center gap-4">
                 <div class="w-8 h-8 rounded-full bg-white/10 flex items-center justify-center text-white shrink-0"><i class="fas fa-home"></i></div>
                 Home
             </button>
             
             <button onclick="window.location.href='user_home.php?show_aid=true'" class="text-left text-white font-medium py-3 px-4 rounded-xl hover:bg-white/10 active:bg-white/20 transition flex items-center gap-4">
                 <div class="w-8 h-8 rounded-full bg-white/10 flex items-center justify-center text-white shrink-0"><i class="fas fa-hands-helping"></i></div>
                 Request Assistance
             </button>
             
             <button onclick="window.location.href='user_home.php?show_track=true'" class="text-left text-white font-medium py-3 px-4 rounded-xl hover:bg-white/10 active:bg-white/20 transition flex items-center gap-4">
                 <div class="w-8 h-8 rounded-full bg-white/10 flex items-center justify-center text-white shrink-0"><i class="fas fa-search"></i></div>
                 Track Request
             </button>
             
             <div class="h-px bg-white/10 my-2"></div> 
             
             <button onclick="toggleMobileMenu()" class="text-left text-gold font-bold py-3 px-4 rounded-xl hover:bg-white/10 active:bg-white/20 transition flex items-center gap-4">
                 <div class="w-8 h-8 rounded-full bg-gold/20 flex items-center justify-center text-gold shrink-0 overflow-hidden">
                    <?php if (!empty($citizen_avatar)): ?>
                        <img src="<?= htmlspecialchars($citizen_avatar) ?>" alt="Profile" class="w-full h-full object-cover">
                    <?php else: ?>
                        <div class="w-full h-full flex items-center justify-center text-sm"><?= $citizen_initial ?></div>
                    <?php endif; ?>
                 </div>
                 My Profile
             </button>
             
             <a href="../processors/logout.php" class="text-left text-red-400 font-bold py-3 px-4 rounded-xl hover:bg-white/10 active:bg-white/20 transition flex items-center gap-4">
                 <div class="w-8 h-8 rounded-full bg-red-400/20 flex items-center justify-center text-red-400 shrink-0"><i class="fas fa-sign-out-alt"></i></div>
                 Logout
             </a>
         </div>
     </div>
    </nav>


    <div class="flex-1 w-full flex flex-col items-center py-6 px-4">
        <div class="w-full max-w-5xl">

            <?= $ui_message ?>

            <?php if(isset($db_error)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-4 rounded-2xl mb-6 shadow-sm">
                    <strong class="font-bold text-lg"><i class="fas fa-exclamation-triangle mr-2"></i>Database Schema Error:</strong>
                    <p class="mt-2 font-mono text-sm bg-white/50 p-2 rounded border border-red-200"><?= htmlspecialchars($actual_error_message) ?></p>
                </div>
            <?php endif; ?>

            <div class="bg-white rounded-[30px] shadow-sm p-8 flex flex-col md:flex-row justify-between items-center gap-6 mb-6 relative overflow-hidden">
                <div class="flex items-center gap-6 relative z-10">
                    <div class="w-24 h-24 bg-[#CCBFD5] rounded-full flex items-center justify-center text-4xl font-bold text-[#3d143e] relative shadow-inner overflow-hidden border-2 border-white">
                        <?php if (!empty($profile_image_url)): ?>
                            <img src="<?= htmlspecialchars($profile_image_url) ?>" class="w-full h-full object-cover" alt="Profile Photo">
                        <?php else: ?>
                            <?= $avatar_letter ?>
                        <?php endif; ?>
                        
                        <form action="profile.php" method="POST" enctype="multipart/form-data" id="photo-upload-form" class="absolute bottom-0 w-full h-8 bg-black/50 hover:bg-black/70 transition flex items-center justify-center cursor-pointer">
                            <input type="file" id="profile-photo-input" name="profile_photo" class="hidden" accept="image/*" onchange="document.getElementById('photo-upload-form').submit();">
                            <label for="profile-photo-input" class="w-full h-full flex items-center justify-center cursor-pointer">
                                <i class="fas fa-camera text-white text-xs"></i>
                            </label>
                        </form>
                    </div>
                    <div class="text-center md:text-left">
                        <h2 class="text-3xl font-bold text-[#3d143e] capitalize"><?= htmlspecialchars($citizen_name) ?></h2>
                        <p class="text-[#3d143e] font-medium mt-1"><i class="fas fa-envelope mr-2 text-gray-500"></i><?= htmlspecialchars($citizen_email) ?></p>
                    </div>
                </div>
                
                <div class="flex items-center relative z-10">
                    <button id="email-alert-btn" onclick="toggleEmailAlerts()" class="<?= $user['email_alerts'] ? 'bg-blue-50 text-header-blue border border-blue-200' : 'bg-gray-50 text-gray-400 border border-gray-200' ?> px-4 py-2 rounded-full text-xs font-bold flex items-center gap-2 shadow-sm transition hover:shadow-md mr-3" title="Toggle Email Notifications">
                        <i id="email-alert-icon" class="<?= $user['email_alerts'] ? 'fas fa-bell' : 'fas fa-bell-slash' ?>"></i>
                        <span id="email-alert-text"><?= $user['email_alerts'] ? 'Alerts On' : 'Alerts Off' ?></span>
                    </button>
                   <?php if ($is_verified): ?>
                       <button onclick="document.getElementById('view-verif-modal').classList.remove('hidden')" class="bg-[#c6943a] hover:bg-yellow-600 transition text-white px-6 py-2.5 rounded-full font-bold flex items-center gap-2 text-sm shadow-md cursor-pointer hover:scale-105 transform">
                           <i class="fas fa-shield-check text-lg"></i> Verified
                       </button>
                   <?php elseif ($verif_status === 'Pending'): ?>
                       <span class="bg-yellow-100 text-yellow-600 border border-yellow-200 px-6 py-2.5 rounded-full font-bold flex items-center gap-2 text-sm shadow-sm">
                           <i class="fas fa-clock text-lg"></i> Verification Pending
                       </span>
                   <?php else: ?>
                       <span class="bg-red-100 text-red-500 border border-red-200 px-6 py-2.5 rounded-full font-bold flex items-center gap-2 text-sm shadow-sm">
                           <i class="fas fa-exclamation-circle text-lg"></i> Unverified
                       </span>
                   <?php endif; ?>
               </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                <div class="bg-white rounded-[30px] shadow-sm p-8 text-center hover:-translate-y-1 transition transform duration-300">
                    <div class="w-14 h-14 bg-[#d1e3f0] text-[#3d143e] rounded-full flex items-center justify-center mx-auto mb-4 shadow-inner">
                        <i class="fas fa-file-export text-xl"></i>
                    </div>
                    <h3 class="text-3xl font-black text-[#3d143e] mb-1"><?= $total_requests ?></h3>
                    <p class="text-[#3d143e] font-medium text-sm">Total Request Submit</p>
                </div>

                <div class="bg-white rounded-[30px] shadow-sm p-8 text-center hover:-translate-y-1 transition transform duration-300">
                    <div class="w-14 h-14 bg-[#d1e3f0] text-[#3d143e] rounded-full flex items-center justify-center mx-auto mb-4 shadow-inner">
                        <i class="fas fa-check-square text-xl"></i>
                    </div>
                    <h3 class="text-3xl font-black text-[#3d143e] mb-1"><?= $total_approved ?></h3>
                    <p class="text-[#3d143e] font-medium text-sm">Total Approved</p>
                </div>

                <div class="bg-white rounded-[30px] shadow-sm p-8 text-center hover:-translate-y-1 transition transform duration-300">
                    <div class="w-14 h-14 bg-[#d1e3f0] text-[#3d143e] rounded-full flex items-center justify-center mx-auto mb-4 shadow-inner">
                        <i class="fas fa-box-open text-xl"></i>
                    </div>
                    <h3 class="text-3xl font-black text-[#3d143e] mb-1"><?= $total_released ?></h3>
                    <p class="text-[#3d143e] font-medium text-sm">Total Released</p>
                </div>
            </div>

            <?php if (count($recent_tx) > 0): ?>
            <div class="bg-white rounded-[30px] shadow-sm p-8 mb-8">
                <h3 class="text-xl font-bold text-[#3d143e] mb-6 border-b-2 border-gray-100 pb-3"><i class="fas fa-history text-[#c6943a] mr-2"></i> Recent Transactions</h3>
                <div class="space-y-4">
                    <?php foreach ($recent_tx as $tx): ?>
                        <?php 
                            $status = $tx['status'];
                            $badgeClass = 'bg-yellow-50 text-yellow-600 border-yellow-200';
                            if ($status === 'Approved') $badgeClass = 'bg-green-50 text-green-600 border-green-200';
                            elseif ($status === 'Released') $badgeClass = 'bg-purple-50 text-purple-600 border-purple-200';
                            elseif ($status === 'Declined') $badgeClass = 'bg-red-50 text-red-600 border-red-200';
                        ?>
                        <div class="flex justify-between items-center p-4 bg-gray-50 rounded-2xl border border-gray-100">
                            <div>
                                <p class="font-bold text-[#3d143e]"><?= htmlspecialchars($tx['request_id']) ?></p>
                                <p class="text-xs text-gray-500 font-medium"><?= htmlspecialchars($tx['assistance_type']) ?></p>
                            </div>
                            <div class="text-right">
                                <span class="px-3 py-1 rounded-full text-xs font-bold border <?= $badgeClass ?>"><?= $status ?></span>
                                <p class="text-xs text-gray-400 mt-1"><?= date('M j, Y', strtotime($tx['date_submitted'])) ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-10">
                
                <div class="bg-white rounded-[30px] shadow-md p-8 relative overflow-hidden">
                    <h3 class="text-xl font-bold text-[#3d143e] mb-4 pb-3 border-b-2 border-[#3d143e] flex items-center gap-3">
                        <i class="fas fa-user-edit text-2xl"></i> Edit Profile
                    </h3>
                    
                    <form action="profile.php" method="POST" class="space-y-3 mt-5">
                        <div>
                            <label class="custom-label">GivenName</label>
                            <input type="text" name="given_name" value="<?= htmlspecialchars($given_name) ?>" class="custom-input" placeholder="First Name" required>
                        </div>
                        <div>
                            <label class="custom-label">MiddleName</label>
                            <input type="text" name="middle_name" value="<?= htmlspecialchars($middle_name) ?>" class="custom-input" placeholder="Middle Name">
                        </div>
                        <div>
                            <label class="custom-label">SurName</label>
                            <input type="text" name="surname" value="<?= htmlspecialchars($surname) ?>" class="custom-input" placeholder="Last Name" required>
                        </div>
                        <div>
                            <label class="custom-label">Email</label>
                            <input type="email" name="email" value="<?= htmlspecialchars($citizen_email) ?>" class="custom-input" placeholder="Email Address" required>
                        </div>
                        
                        <div class="pt-4">
                            <button type="submit" name="update_profile" class="w-full bg-[#3d143e] text-white py-2.5 rounded-full font-bold shadow-md hover:bg-purple-900 transition flex items-center justify-center gap-2">
                                <i class="fas fa-save text-sm"></i> Update Profile
                            </button>
                        </div>
                    </form>
                </div>

                <div class="bg-white rounded-[30px] shadow-md p-8 relative overflow-hidden">
                    <h3 class="text-xl font-bold text-[#3d143e] mb-4 pb-3 border-b-2 border-[#3d143e] flex items-center gap-3">
                        <i class="fas fa-lock text-2xl"></i> Change Password
                    </h3>
                    
                    <form action="profile.php" method="POST" class="space-y-3 mt-5">
                        <div>
                            <label class="custom-label">Password</label>
                            <input type="password" name="current_password" required class="custom-input" placeholder="Current Password">
                        </div>
                        <div>
                            <label class="custom-label">New Password</label>
                            <input type="password" name="new_password" required class="custom-input" placeholder="New Password">
                        </div>
                        <div>
                            <label class="custom-label">Confirm Password</label>
                            <input type="password" name="confirm_password" required class="custom-input" placeholder="Confirm New Password">
                        </div>
                        
                        <div class="pt-[3.2rem]">
                            <button type="submit" name="change_password" class="w-full bg-[#3d143e] text-white py-2.5 rounded-full font-bold shadow-md hover:bg-purple-900 transition flex items-center justify-center gap-2">
                                <i class="fas fa-key text-sm"></i> Change Password
                            </button>
                        </div>
                    </form>
                </div>

            </div>

            <div class="flex justify-center mb-10">
                <button onclick="document.getElementById('logoutModal').classList.remove('hidden')" class="bg-white border-2 border-[#3d143e] text-[#3d143e] px-10 py-3 rounded-full font-bold shadow-sm hover:bg-gray-50 transition hover:scale-105 transform flex items-center gap-3">
                    <i class="fas fa-sign-out-alt"></i> Log Out
                </button>
            </div>

        </div>
    </div>

    <div id="logoutModal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-sm z-[999] flex items-center justify-center p-4 animate-fade">
        <div class="bg-white p-8 rounded-[30px] shadow-2xl max-w-sm w-full text-center border border-gray-200 relative">
            <h3 class="text-xl font-bold text-[#3d143e] mb-8">Are you sure you want to log out?</h3>
            <div class="flex flex-col gap-3">
                <a href="../processors/logout.php" class="bg-[#3d143e] text-white px-6 py-3 rounded-full font-bold hover:bg-purple-900 transition shadow-md w-full">
                    Yes, Log Out
                </a>
                <button onclick="document.getElementById('logoutModal').classList.add('hidden')" class="bg-gray-100 border border-gray-300 text-gray-700 px-6 py-3 rounded-full font-bold hover:bg-gray-200 transition shadow-sm w-full">
                    Cancel
                </button>
            </div>
        </div>
    </div>

    <?php if ($is_verified): ?>
    <div id="view-verif-modal" class="hidden fixed inset-0 bg-black/80 backdrop-blur-sm z-[999] flex items-center justify-center p-4 animate-fade">
        <div class="bg-white w-full max-w-2xl rounded-[30px] shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
            <div class="bg-[#c6943a] p-5 md:px-8 text-white text-center shrink-0 relative">
                <h2 class="text-xl md:text-2xl font-bold flex items-center justify-center gap-3">
                    <i class="fas fa-shield-check text-white"></i> Verified Details
                </h2>
                <button onclick="document.getElementById('view-verif-modal').classList.add('hidden')" class="absolute top-1/2 right-6 transform -translate-y-1/2 text-white/60 hover:text-white text-2xl transition"><i class="fas fa-times"></i></button>
            </div>
            
            <div class="p-6 md:p-8 overflow-y-auto flex-1 custom-scrollbar bg-gray-50">
                <div class="bg-white p-5 rounded-2xl border border-gray-200 shadow-sm mb-4">
                    <p class="text-xs text-gray-500 font-bold uppercase tracking-wider mb-4 border-b border-gray-100 pb-2">Locked Information</p>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-y-3 gap-x-4">
                        <div><span class="text-[10px] text-gray-500 font-bold uppercase tracking-wider block">Full Name</span> <span class="font-bold text-[#3d143e]"><?= htmlspecialchars("$v_fname $v_mname $v_lname $v_ext") ?></span></div>
                        <div><span class="text-[10px] text-gray-500 font-bold uppercase tracking-wider block">Contact Number</span> <span class="font-bold text-[#3d143e]"><?= htmlspecialchars($v_contact) ?></span></div>
                        <div><span class="text-[10px] text-gray-500 font-bold uppercase tracking-wider block">Civil Status</span> <span class="font-bold text-[#3d143e]"><?= htmlspecialchars($v_civil) ?></span></div>
                        <div><span class="text-[10px] text-gray-500 font-bold uppercase tracking-wider block">Family Income</span> <span class="font-bold text-[#3d143e]"><?= htmlspecialchars($v_income) ?></span></div>
                        <div class="md:col-span-2"><span class="text-[10px] text-gray-500 font-bold uppercase tracking-wider block">Complete Address</span> <span class="font-bold text-[#3d143e]"><?= htmlspecialchars("$v_house $v_street, $v_brgy, $v_city") ?></span></div>
                        <div class="md:col-span-2 pt-3 border-t border-gray-200 mt-2"><span class="text-[10px] text-gray-500 font-bold uppercase tracking-wider block">Emergency Contact</span> <span class="font-bold text-[#3d143e]"><?= htmlspecialchars("$v_em_fname $v_em_lname • $v_em_contact ($v_em_rel)") ?></span></div>
                        <div class="md:col-span-2 pt-3 border-t border-gray-200 mt-2"><span class="text-[10px] text-gray-500 font-bold uppercase tracking-wider block">ID Provided</span> <span class="font-bold text-[#3d143e]"><?= htmlspecialchars("$v_id_type - $v_id_number") ?></span></div>
                    </div>
                </div>
            </div>

            <div class="p-5 md:px-8 border-t border-gray-200 bg-white shrink-0 flex gap-4">
                <button type="button" onclick="document.getElementById('view-verif-modal').classList.add('hidden'); document.getElementById('edit-verif-modal').classList.remove('hidden');" class="w-full bg-[#3d143e] text-white px-6 py-3 rounded-full font-bold shadow-md hover:bg-purple-900 transition flex items-center justify-center gap-2">
                    <i class="fas fa-edit"></i> Request Edit
                </button>
            </div>
        </div>
    </div>

    <div id="edit-verif-modal" class="hidden fixed inset-0 bg-black/80 backdrop-blur-sm z-[999] flex items-center justify-center p-4 animate-fade">
        <div class="bg-white w-full max-w-md rounded-[30px] shadow-2xl overflow-hidden flex flex-col relative">
            <div class="p-6 md:p-8">
                <h3 class="text-xl font-bold text-[#3d143e] mb-2"><i class="fas fa-info-circle text-[#c6943a] mr-2"></i> Request Data Change</h3>
                <p class="text-sm text-gray-600 mb-6">Because this data is verified by the Barangay, you must submit a request to the administrator to unlock your form for editing.</p>
                
                <form action="profile.php" method="POST" onsubmit="const btn = this.querySelector('button[type=submit]'); btn.innerHTML = '<i class=\'fas fa-spinner fa-spin mr-2\'></i> Submitting...'; btn.style.pointerEvents = 'none'; btn.classList.add('opacity-70');">
                    <input type="hidden" name="submit_edit_request" value="1">
                    <input type="hidden" name="verif_id" value="<?= htmlspecialchars($v_id) ?>">
                    
                    <div class="mb-5">
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Reason for Edit</label>
                        <textarea name="edit_reason" required placeholder="e.g. I moved to a new house, or I got married." class="w-full h-32 rounded-2xl border border-gray-300 p-4 outline-none text-[#3d143e] text-sm resize-none focus:border-[#5b8fb0]"></textarea>
                    </div>
                    <div class="flex gap-3">
                        <button type="button" onclick="document.getElementById('edit-verif-modal').classList.add('hidden'); document.getElementById('view-verif-modal').classList.remove('hidden');" class="w-1/3 bg-gray-100 text-gray-600 border border-gray-300 px-4 py-2.5 rounded-full font-bold hover:bg-gray-200 transition text-sm">Cancel</button>
                        <button type="submit" class="w-2/3 bg-[#3d143e] text-white px-4 py-2.5 rounded-full font-bold shadow-md hover:bg-purple-900 transition flex items-center justify-center gap-2 text-sm">
                            Submit Request
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <footer class="hidden md:block bg-dark-violet text-white/80 py-6 border-t-4 border-gold mt-auto no-print relative z-20">
      <div class="w-full px-6 lg:px-12 flex flex-row justify-between items-center gap-6">
          <div class="flex items-center gap-3 w-auto justify-start">
              <img src="../assets/images/navlogo.png" class="h-12 object-contain drop-shadow-md" alt="Logo">
              <div class="text-left">
                  <p class="font-bold text-white text-lg leading-tight">Barangay Santo Rosario-Kanluran</p>
                  <p class="text-sm text-gold font-bold">Municipality of Pateros</p>
              </div>
          </div>
          <div class="flex flex-wrap justify-end items-start gap-6 lg:gap-8 w-auto">
              <div class="flex flex-col items-center gap-1.5 text-center w-auto">
                  <div class="w-10 h-10 bg-gold rounded-full flex items-center justify-center text-dark-violet shadow-md"><i class="fas fa-phone-alt text-lg"></i></div>
                  <p class="text-white text-xs font-bold">8628-3210</p>
              </div>
              <a href="https://mail.google.com/mail/?view=cm&fs=1&to=brgystorosariokanluran.pateros@gmail.com" target="_blank" rel="noopener noreferrer" class="flex flex-col items-center gap-1.5 text-center group hover:opacity-80 transition cursor-pointer w-auto">
                  <div class="w-10 h-10 bg-gold rounded-full flex items-center justify-center text-[#3d143e] shadow-md group-hover:scale-110 transition"><i class="fas fa-envelope text-lg"></i></div>
                  <p class="text-white text-xs font-bold group-hover:underline leading-tight">brgystorosariokanluran<br>.pateros@gmail.com</p>
              </a>
              <a href="https://www.facebook.com/kanluranpateros" target="_blank" rel="noopener noreferrer" class="flex flex-col items-center gap-1.5 text-center group hover:opacity-80 transition cursor-pointer w-auto">
                  <div class="w-10 h-10 bg-gold rounded-full flex items-center justify-center text-[#3d143e] shadow-md group-hover:scale-110 transition"><i class="fab fa-facebook-f text-lg"></i></div>
                  <p class="text-white text-xs font-bold group-hover:underline px-1 leading-tight">Barangay sto.Rosario-<br>Kanluran Official</p>
              </a>
              <a href="https://www.google.com/maps/place/Sto.+Rosario+Kanluran+Barangay+Hall/@14.5526451,121.068596,248m/data=!3m1!1e3!4m6!3m5!1s0x3397c8862217a9d9:0x92e8df6972942ee9!8m2!3d14.5526269!4d121.0687817!16s%2Fg%2F11bzvtrrfh?entry=ttu&g_ep=EgoyMDI2MDQyMS4wIKXMDSoASAFQAw%3D%3D" target="_blank" rel="noopener noreferrer" class="flex flex-col items-center gap-1.5 text-center group hover:opacity-80 transition cursor-pointer w-auto">
                  <div class="w-10 h-10 bg-gold rounded-full flex items-center justify-center text-[#3d143e] shadow-md group-hover:scale-110 transition"><i class="fas fa-map-signs text-lg"></i></div>
                  <p class="text-white text-xs font-bold px-1 leading-tight group-hover:underline">St. Sto.Rosario<br>Kanluran, Pateros</p>
              </a>
          </div>
      </div>
  </footer>

  <footer class="block md:hidden bg-dark-violet text-white/80 py-8 border-t-4 border-gold mt-auto no-print relative z-20">
      <div class="w-full px-6 flex flex-col gap-6">
          <div class="flex flex-col items-center text-center gap-2">
              <img src="../assets/images/navlogo.png" class="h-14 object-contain drop-shadow-md" alt="Logo">
              <div>
                  <p class="font-bold text-white text-lg leading-tight">Barangay Santo Rosario-Kanluran</p>
                  <p class="text-xs text-gold font-bold mt-1">Municipality of Pateros</p>
              </div>
          </div>
          <div class="flex flex-col gap-4 w-full max-w-[280px] mx-auto">
              <div class="flex items-center gap-4 w-full">
                  <div class="w-10 h-10 shrink-0 bg-gold rounded-full flex items-center justify-center text-dark-violet shadow-md"><i class="fas fa-phone-alt text-base"></i></div>
                  <p class="text-white text-sm font-bold">8628-3210</p>
              </div>
              <a href="https://mail.google.com/mail/?view=cm&fs=1&to=brgystorosariokanluran.pateros@gmail.com" target="_blank" rel="noopener noreferrer" class="flex items-center gap-4 group hover:opacity-80 transition cursor-pointer w-full">
                  <div class="w-10 h-10 shrink-0 bg-gold rounded-full flex items-center justify-center text-[#3d143e] shadow-md group-hover:scale-110 transition"><i class="fas fa-envelope text-base"></i></div>
                  <p class="text-white text-xs font-bold group-hover:underline break-all leading-snug">brgystorosariokanluran.pateros@gmail.com</p>
              </a>
              <a href="https://www.facebook.com/kanluranpateros" target="_blank" rel="noopener noreferrer" class="flex items-center gap-4 group hover:opacity-80 transition cursor-pointer w-full">
                  <div class="w-10 h-10 shrink-0 bg-gold rounded-full flex items-center justify-center text-[#3d143e] shadow-md group-hover:scale-110 transition"><i class="fab fa-facebook-f text-base"></i></div>
                  <p class="text-white text-xs font-bold group-hover:underline leading-snug">Barangay sto.Rosario-<br>Kanluran Official</p>
              </a>
              <a href="https://www.google.com/maps/place/Sto.+Rosario+Kanluran+Barangay+Hall/@14.5526451,121.068596,248m/data=!3m1!1e3!4m6!3m5!1s0x3397c8862217a9d9:0x92e8df6972942ee9!8m2!3d14.5526269!4d121.0687817!16s%2Fg%2F11bzvtrrfh?entry=ttu&g_ep=EgoyMDI2MDQyMS4wIKXMDSoASAFQAw%3D%3D" target="_blank" rel="noopener noreferrer" class="flex items-center gap-4 w-full group hover:opacity-80 transition cursor-pointer">
                  <div class="w-10 h-10 shrink-0 bg-gold rounded-full flex items-center justify-center text-dark-violet shadow-md group-hover:scale-110 transition"><i class="fas fa-map-signs text-base"></i></div>
                  <p class="text-white text-xs font-bold leading-snug group-hover:underline">St. Sto.Rosario<br>Kanluran, Pateros</p>
              </a>
          </div>
      </div>
  </footer>

  <script>
    let emailAlertsEnabled = <?= $user['email_alerts'] ? 'true' : 'false' ?>;
    
    function toggleEmailAlerts() {
        emailAlertsEnabled = !emailAlertsEnabled;
        const icon = document.getElementById('email-alert-icon');
        const btn = document.getElementById('email-alert-btn');
        const text = document.getElementById('email-alert-text');
        
        if (emailAlertsEnabled) {
            icon.className = 'fas fa-bell';
            btn.className = 'bg-blue-50 text-header-blue border border-blue-200 px-4 py-2 rounded-full text-xs font-bold flex items-center gap-2 shadow-sm transition hover:shadow-md mr-3';
            text.innerText = 'Alerts On';
        } else {
            icon.className = 'fas fa-bell-slash';
            btn.className = 'bg-gray-50 text-gray-400 border border-gray-200 px-4 py-2 rounded-full text-xs font-bold flex items-center gap-2 shadow-sm transition hover:shadow-md mr-3';
            text.innerText = 'Alerts Off';
        }
        
        // Sends the update silently to your database
        fetch('../processors/toggle_email_alerts.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ enabled: emailAlertsEnabled })
        });
    }

    // Toggle Mobile Menu
    function toggleMobileMenu() { 
        document.getElementById('mobile-menu').classList.toggle('hidden'); 
    }

    // Navbar Notification Menu Logic
    function toggleNotifs() {
        const dropdown = document.getElementById('notif-dropdown');
        const badge = document.getElementById('notif-badge');
        
        dropdown.classList.toggle('hidden');
        
        if (!dropdown.classList.contains('hidden') && badge) {
            fetch('../processors/mark_notifications_read.php')
                .then(response => response.text())
                .then(data => {
                    if(data === 'Success') {
                        badge.style.display = 'none';
                    }
                });
        }
    }

    // Close the dropdown if clicking outside of it
    document.addEventListener('click', function(event) {
        const dropdown = document.getElementById('notif-dropdown');
        if (!dropdown) return; 
        
        const isClickInside = dropdown.contains(event.target) || event.target.closest('button[onclick="toggleNotifs()"]');
        
        if (!isClickInside && !dropdown.classList.contains('hidden')) {
            dropdown.classList.add('hidden');
        }
    });
</script>
</body>
</html>