<?php
// user/user_home.php
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

if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit(); }
if ($_SESSION['role'] === 'Admin') { header("Location: ../admin/dashboard.php"); exit(); }

$show_success = isset($_GET['success']) && $_GET['success'] == '1';
$show_vsuccess = isset($_GET['vsuccess']) && $_GET['vsuccess'] == '1';
$new_req_id = $_GET['req_id'] ?? '';
$user_id = $_SESSION['user_id'];
$citizen_name = $_SESSION['username'] ?? 'Citizen';
$citizen_initial = strtoupper(substr($citizen_name, 0, 1));

// Get user's email, alert preference, verification status, AND profile image
$user_stmt = $pdo->prepare("SELECT email, email_alerts, is_verified, profile_image FROM users WHERE id = :uid");
$user_stmt->execute([':uid' => $user_id]);
$user_data = $user_stmt->fetch(PDO::FETCH_ASSOC);

$citizen_email = $user_data['email'] ?? '';
$email_alerts_enabled = $user_data['email_alerts'] ? 'true' : 'false';
$is_verified = filter_var($user_data['is_verified'], FILTER_VALIDATE_BOOLEAN);
$citizen_avatar = $user_data['profile_image'] ?? '';

// Check if verification is currently pending
$pending_verif_stmt = $pdo->prepare("SELECT status FROM user_verifications WHERE user_id = :uid ORDER BY submitted_at DESC LIMIT 1");
$pending_verif_stmt->execute([':uid' => $user_id]);
$verif_status = $pending_verif_stmt->fetchColumn();

// --- BUSINESS HOURS LOGIC ---
$settings_file = '../config/sys_settings.json';
$bh_active = true; // Default to locked if missing
if (file_exists($settings_file)) {
    $settings = json_decode(file_get_contents($settings_file), true);
    $bh_active = $settings['business_hours'] ?? true;
}

$is_within_hours = true;
if ($bh_active) {
    // Only enforce if the switch is ON in the admin dashboard
    $current_hour = (int)date('G'); // 0-23 format
    $current_day = date('N'); // 1 (Mon) - 7 (Sun)
    
    // Mon-Fri, 8 AM to 5 PM (17:00)
    if ($current_day > 5 || $current_hour < 8 || $current_hour >= 17) {
        $is_within_hours = false;
    }
}
// ----------------------------

// --- ANTI-SPAM LOGIC (PER CATEGORY) ---
// Initialize the cooldown status for each main category
$category_cooldowns = [
    'Medical Assistance' => ['on_cooldown' => false, 'end_date' => ''],
    'Hospital Bill' => ['on_cooldown' => false, 'end_date' => ''],
    'Financial Assistance' => ['on_cooldown' => false, 'end_date' => ''],
    'Burial Assistance' => ['on_cooldown' => false, 'end_date' => '']
];

$cd_stmt = $pdo->prepare("SELECT assistance_type, status, date_submitted FROM assistance_requests WHERE user_id = :uid ORDER BY date_submitted DESC");
$cd_stmt->execute([':uid' => $user_id]);
$all_reqs = $cd_stmt->fetchAll(PDO::FETCH_ASSOC);

$now = new DateTime('now', new DateTimeZone('Asia/Manila'));

// Scan the history to check the latest request for each category
foreach ($all_reqs as $req) {
    // Extract main category (e.g., "Medical Assistance" from "Medical Assistance - Medical Support")
    $parts = explode(' - ', $req['assistance_type']);
    $main_cat = trim($parts[0]);

    // Only process the MOST RECENT request for each category
    if (isset($category_cooldowns[$main_cat]) && !isset($category_cooldowns[$main_cat]['checked'])) {
        $category_cooldowns[$main_cat]['checked'] = true; // Mark as processed
        
        if (in_array($req['status'], ['Submitted', 'Approved'])) {
            try {
                $submit_date = new DateTime($req['date_submitted'], new DateTimeZone('UTC'));
                $submit_date->setTimezone(new DateTimeZone('Asia/Manila'));
                $submit_date->modify('+5 days'); // 5 Days Cooldown
                
                if ($now < $submit_date) {
                    $category_cooldowns[$main_cat]['on_cooldown'] = true;
                    $category_cooldowns[$main_cat]['end_date'] = $submit_date->format('F j, Y \a\t g:i A');
                }
            } catch (Exception $e) {}
        }
    }
}
// ---------------------------------------------


// --- AUTO-FILL LOGIC ---
$v_fname = ''; $v_lname = ''; $v_mname = ''; $v_ext = ''; $v_civil = ''; $v_income = '';
$v_contact = ''; $v_gcash = ''; $v_address = ''; $v_region = ''; $v_city = ''; $v_brgy = '';
$v_house = ''; $v_street = ''; $v_em_fname = ''; $v_em_lname = ''; $v_em_mname = ''; 
$v_em_ext = ''; $v_em_contact = ''; $v_em_rel = ''; $v_id_type = ''; $v_id_number = '';
$v_mobile = '';

// ONLY auto-fill if the user is fully verified
if ($is_verified) {
    $latest_verif_stmt = $pdo->prepare("SELECT * FROM user_verifications WHERE user_id = :uid AND status = 'Approved' ORDER BY submitted_at DESC LIMIT 1");
    $latest_verif_stmt->execute([':uid' => $user_id]);

    if ($verif_data = $latest_verif_stmt->fetch(PDO::FETCH_ASSOC)) {
        $v_fname = $verif_data['first_name'] ?? '';
        $v_lname = $verif_data['last_name'] ?? '';
        $v_mname = $verif_data['middle_name'] ?? '';
        $v_ext = $verif_data['name_extension'] ?? '';
        $v_civil = $verif_data['civil_status'] ?? '';
        $v_income = $verif_data['family_income'] ?? '';
        $v_contact = $verif_data['mobile_number'] ?? '';
        $v_gcash = $verif_data['gcash_number'] ?? '';
        $v_address = $verif_data['address'] ?? '';
        $v_region = $verif_data['region'] ?? 'NCR';
        $v_city = $verif_data['city'] ?? 'Pateros';
        $v_brgy = $verif_data['barangay'] ?? 'Sto. Rosario-Kanluran';
        $v_house = $verif_data['house_no'] ?? '';
        $v_street = $verif_data['street'] ?? '';
        $v_em_fname = $verif_data['em_first_name'] ?? '';
        $v_em_lname = $verif_data['em_last_name'] ?? '';
        $v_em_mname = $verif_data['em_middle_name'] ?? ''; 
        $v_em_ext = $verif_data['em_name_extension'] ?? ''; 
        $v_em_contact = $verif_data['em_contact'] ?? '';
        $v_em_rel = $verif_data['em_relationship'] ?? '';
        $v_id_type = $verif_data['id_type'] ?? '';
        $v_id_number = $verif_data['id_number'] ?? '';
        $v_mobile = $v_contact; 
    }
}

// Fetch Notifications for the logged-in user
$notif_stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = :uid ORDER BY created_at DESC LIMIT 10");
$notif_stmt->execute([':uid' => $user_id]);
$notifications = $notif_stmt->fetchAll();

// Count Unread
$unread_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND is_read = FALSE");
$unread_stmt->execute([':uid' => $user_id]);
$unread_count = $unread_stmt->fetchColumn();

// Fetch User's Request History
$history_stmt = $pdo->prepare("SELECT * FROM assistance_requests WHERE user_id = :uid ORDER BY date_submitted DESC");
$history_stmt->execute([':uid' => $user_id]);
$request_history = $history_stmt->fetchAll();
?>
<!doctype html>
<html lang="en" class="h-full">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>Barangay Kanluran - User Portal</title>
  <link rel="icon" type="image/png" href="../assets/images/kareslogo.png" />
  <script src="https://cdn.tailwindcss.com/3.4.17"></script>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
  <script>
    tailwind.config = { theme: { extend: { colors: { 'dark-violet': '#3d143e', 'gold': '#c6943a', 'off-white': '#e0d5e8', 'panel-blue': '#d1e3f0', 'header-blue': '#5b8fb0', 'card-beige': '#eee0c0', 'success-green': '#4ade80' } } } }
  </script>
  <style>
    * { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    body { box-sizing: border-box; background-color: #e0d5e8; display: flex; flex-direction: column; min-height: 100vh;}
    #app { flex: 1; display: flex; flex-direction: column; min-height: 100vh;} 
    #citizen-view { flex: 1; }
    .nav-dropdown { display: none; } 
    .nav-item:hover .nav-dropdown { display: block; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    .animate-fade { animation: fadeIn 0.3s ease forwards; }
    
    .reg-input { width: 100%; height: 50px; border-radius: 50px; border: none; padding: 0 25px; background: white; outline: none; color: #3d143e; font-weight: 500; font-size: 16px;}
    .desc-input { min-height: 100px !important; border-radius: 25px !important; padding: 15px 25px !important; resize: none; font-size: 16px;}
    
    /* MODERN RESPONSIVE PROGRESS TRACKER */
    .progress-tracker-wrapper { width: 100%; position: relative; padding-bottom: 30px; margin-bottom: 10px;}
    .progress-tracker { display: flex; justify-content: space-between; position: relative; z-index: 10; width: 100%; }
    .step-item { display: flex; flex-direction: column; align-items: center; flex: 1; z-index: 2; position: relative; }
    
    .step-item:not(:last-child)::after { content: ''; position: absolute; top: 12px; left: 50%; width: 100%; height: 3px; background-color: rgba(255,255,255,0.5); z-index: -1; transition: background-color 0.3s; }
    .step-item.completed:not(:last-child)::after { background-color: #4ade80; }
    
    .step-circle { width: 26px; height: 26px; border-radius: 50%; background-color: white; display: flex; justify-content: center; align-items: center; color: transparent; font-size: 11px; font-weight: bold; transition: all 0.3s; border: 3px solid transparent; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
    .step-item.active .step-circle { border-color: #c6943a; color: #c6943a; transform: scale(1.15); }
    .step-item.completed .step-circle { background-color: #4ade80; color: white; border-color: #4ade80; }
    
    .step-label { font-size: 10px; text-align: center; color: #5b8fb0; font-weight: 700; position: absolute; top: 35px; width: 200%; opacity: 0; transition: opacity 0.3s; pointer-events: none;}
    
    @media (max-width: 767px) { .step-item.active .step-label { opacity: 1; color: #3d143e; } }
    @media (min-width: 768px) {
        .progress-tracker-wrapper { padding-bottom: 40px; }
        .step-item:not(:last-child)::after { top: 16px; height: 4px; }
        .step-circle { width: 36px; height: 36px; font-size: 14px; }
        .step-label { font-size: 12px; top: 45px; opacity: 1; pointer-events: auto; }
        .step-item.active .step-label { color: #3d143e; }
    }
    
    .form-section, .form-section-v { display: none; animation: fadeIn 0.4s; } 
    .form-section.active, .form-section-v.active { display: block; }
    .file-upload-box { background: white; border-radius: 20px; padding: 20px; text-align: center; transition: 0.3s; position: relative; overflow: hidden; display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 140px;}
    .file-upload-box:hover { background: #fdfdfd; transform: scale(1.02); }
    .upload-preview-img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; z-index: 1; opacity: 0.9;}
    .upload-content { position: relative; z-index: 2; background: rgba(255,255,255,0.85); padding: 5px 15px; border-radius: 15px; pointer-events: none; color: #5b8fb0; font-weight: 700; font-size: 14px;}
    
    .option-pill { background: #eee0c0; margin: 10px 0; padding: 15px 20px; border-radius: 50px; display: flex; align-items: center; gap: 12px; cursor: pointer; transition: all 0.2s ease; border: 2px solid transparent; color: #3d143e; font-weight: 600; font-size: 16px;}
    .option-pill.selected { border-color: #5b8fb0; background: #fdf6e6; }
    .option-pill i { font-size: 24px; color: #3d143e; }
    
    #cares-mascot-container { position: fixed; bottom: 24px; right: 16px; z-index: 40; will-change: bottom; transition: bottom 0.3s ease;}
    .cares-mascot-btn { pointer-events: auto; display: inline-flex; cursor: pointer; background: transparent; border: none; outline: none; position: relative; z-index: 10; }
    .cares-mascot-img { width: 90px; height: auto; object-fit: contain; animation: mascot-bounce 2s ease-in-out infinite; filter: drop-shadow(0 4px 6px rgba(0,0,0,0.1)); }
    @media (min-width: 768px) { .cares-mascot-img { width: 120px; height: auto; right: 24px;} }
    @keyframes mascot-bounce { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-8px); } }
    
    .cares-chat-window { position: absolute; bottom: 80px; right: 0; width: calc(100vw - 32px); max-width: 360px; max-height: 60vh; background: white; border-radius: 12px; box-shadow: 0 8px 32px rgba(75, 24, 79, 0.3); display: flex; flex-direction: column; opacity: 0; visibility: hidden; transform: translateY(20px) scale(0.95); transition: all 0.3s ease; z-index: 1001; }
    .cares-chat-window.open { opacity: 1; visibility: visible; transform: translateY(0) scale(1); }
    .cares-chat-header { background: linear-gradient(135deg, #3d143e, #5b8fb0); padding: 16px 20px; display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #CCBFD5; border-radius: 12px 12px 0 0; }
    .cares-chat-title { color: #F0EDF2; font-size: 16px; font-weight: 600; margin: 0; }
    .cares-close-btn { background: rgba(255, 255, 255, 0.2); border: none; color: #F0EDF2; width: 32px; height: 32px; border-radius: 6px; cursor: pointer; font-size: 20px; transition: background 0.3s ease;}
    .cares-close-btn:hover { background: rgba(255, 255, 255, 0.3); }
    .cares-chat-content { flex: 1; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 12px; height: 350px;}
    .cares-messages { display: flex; flex-direction: column; gap: 10px; padding: 8px 0; }
    .cares-bubble { max-width: 85%; padding: 12px 14px; border-radius: 12px; font-size: 15px; line-height: 1.4; font-weight: 500; word-wrap: break-word;}
    .cares-bot-msg { align-self: flex-start; background: linear-gradient(135deg, #F8F4FA, #FFF); border: 1px solid #EDE5EF; color: #333;}
    .cares-user-msg { align-self: flex-end; background: linear-gradient(135deg, #5b8fb0, #3d143e); color: #fff; }
    .cares-questions { display: flex; flex-direction: column; gap: 10px; }
    .cares-question-btn { background: #eee0c0; border: 2px solid transparent; padding: 14px 16px; border-radius: 20px; cursor: pointer; font-size: 15px; font-weight: 600; text-align: left; color: #3d143e; transition: transform 0.2s;}
    .cares-question-btn:hover { transform: scale(1.02); border-color: #c6943a; }
    
    .chat-input-area { display: flex; gap: 8px; padding: 12px 16px; border-top: 1px solid #eee; background: #fafafa; border-radius: 0 0 12px 12px; align-items: center;}
    .chat-input-area input { flex: 1; padding: 10px 15px; border-radius: 20px; border: 1px solid #ddd; outline: none; font-size: 14px; }
    .chat-input-area input:focus { border-color: #5b8fb0; }

    .carousel-container { position: relative; width: 100%; overflow: hidden; border-radius: 1.5rem; }
    .carousel-slide { display: none; width: 100%; height: 350px; object-fit: cover; animation: fade 0.8s ease-in-out; }
    .carousel-slide.active { display: block; }
    @keyframes fade { from { opacity: .4 } to { opacity: 1 } }
    .carousel-nav { position: absolute; bottom: 15px; left: 50%; transform: translateX(-50%); display: flex; gap: 8px; z-index: 20; }
    .dot { height: 12px; width: 12px; background-color: rgba(255,255,255,0.5); border-radius: 50%; cursor: pointer; transition: background-color 0.3s ease; }
    .dot.active { background-color: #c6943a; }

    .typing-indicator { display: flex; gap: 4px; padding: 12px; background: #eee0c0; border-radius: 15px; width: fit-content; margin-bottom: 10px; border-bottom-left-radius: 2px;}
    .typing-dot { width: 6px; height: 6px; background: #3d143e; border-radius: 50%; animation: typing 1.4s infinite ease-in-out both; }
    .typing-dot:nth-child(1) { animation-delay: -0.32s; }
    .typing-dot:nth-child(2) { animation-delay: -0.16s; }
    @keyframes typing { 0%, 80%, 100% { transform: scale(0); } 40% { transform: scale(1); } }
  </style>
</head>
<body>

  <div id="toast-message" class="fixed bottom-10 left-1/2 transform -translate-x-1/2 bg-[#3d143e] text-white px-6 py-3 rounded-full shadow-2xl z-[9999] opacity-0 transition-opacity duration-300 pointer-events-none font-bold text-sm">
      Status message
  </div>

  <div id="app">
   <div id="citizen-view" class="pb-10">
    
    <nav class="bg-dark-violet shadow-lg sticky top-0 z-50">
     <div class="w-full px-3 lg:px-12 relative">
      <div class="flex justify-between items-center h-16">
       
       <div class="flex items-center gap-2 sm:gap-3 cursor-pointer" onclick="showPage('home')">
            <img src="../assets/images/navlogo.png" alt="Kanluran Logo" class="h-8 sm:h-10 w-auto object-contain drop-shadow-md" onerror="this.style.display='none'">
            <span class="text-white font-bold text-[13px] sm:text-lg tracking-wide truncate max-w-[120px] sm:max-w-none">Barangay Kanluran</span>
       </div>
       
       <div class="hidden lg:flex items-center gap-2 absolute left-1/2 transform -translate-x-1/2">
            <button id="nav-home" onclick="showPage('home')" class="nav-btn bg-gold text-white px-5 py-1.5 rounded-full transition-all font-bold flex items-center gap-2"><i class="fas fa-home"></i> Home</button>
            
            <div class="nav-item relative py-3">
             <button id="nav-request" class="nav-btn text-white hover:text-gold px-5 py-1.5 rounded-full transition-all font-medium flex items-center gap-2"><i class="fas fa-file-alt"></i> Request</button>
             <div class="nav-dropdown absolute top-full left-1/2 -translate-x-1/2 min-w-64 z-50 pt-2">
              <div class="bg-white rounded-2xl shadow-xl py-2 overflow-hidden border border-gray-200">
                  <button onclick="attemptAssistanceRequest()" class="w-full text-left px-5 py-3 text-dark-violet hover:bg-panel-blue font-bold transition-all flex items-center gap-3"><i class="fas fa-hands-helping text-lg"></i> Social Welfare Assistance</button>
              </div>
             </div>
            </div>
            
            <button id="nav-track" onclick="showPage('track')" class="nav-btn text-white hover:text-gold px-5 py-1.5 rounded-full transition-all font-medium flex items-center gap-2"><i class="fas fa-search"></i> Track Request</button>
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
                   <button onclick="document.getElementById('verify-intro-modal').classList.remove('hidden')" class="bg-red-500/20 text-red-400 border border-red-500/30 hover:bg-red-500 hover:text-white transition px-2 py-1 md:px-4 md:py-1.5 rounded-full text-[10px] md:text-xs font-bold flex items-center gap-1 md:gap-2 shadow-sm animate-pulse">
                       <i class="fas fa-exclamation-circle"></i> Unverified
                   </button>
               <?php endif; ?>
           </div>

           <div class="relative py-3 cursor-pointer group">
               <button onclick="toggleNotifs()" class="relative text-white hover:text-gold transition text-lg sm:text-xl p-1 sm:p-2 focus:outline-none">
                   <i class="fas fa-bell"></i>
                   <?php if ($unread_count > 0): ?>
                       <span id="notif-badge" class="absolute top-0 right-0 sm:top-1 sm:right-1 bg-red-500 text-white text-[9px] sm:text-[10px] font-bold w-3 h-3 sm:w-4 sm:h-4 rounded-full flex items-center justify-center border border-dark-violet">
                           <?= $unread_count ?>
                       </span>
                   <?php endif; ?>
               </button>
               
               <div id="notif-dropdown" class="hidden absolute top-full right-0 w-[260px] sm:w-80 bg-white rounded-2xl shadow-2xl border border-gray-200 overflow-hidden z-50 transform origin-top-right transition-all">
                   <div class="bg-header-blue text-white px-4 py-3 flex justify-between items-center">
                       <h3 class="font-bold text-sm">Notifications</h3>
                       <?php if ($unread_count > 0): ?>
                           <span class="bg-white text-header-blue text-xs font-bold px-2 py-0.5 rounded-full"><?= $unread_count ?> New</span>
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
                        <a href="profile.php" class="w-full text-left px-5 py-3 text-dark-violet hover:bg-gray-50 font-bold transition-all flex items-center gap-3">
                            <i class="fas fa-id-badge text-lg"></i> My Profile
                        </a>
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
                   <button onclick="document.getElementById('verify-intro-modal').classList.remove('hidden'); toggleMobileMenu();" class="bg-red-500/20 text-red-400 border border-red-500/30 hover:bg-red-50 hover:text-white transition px-4 py-3 rounded-xl text-sm font-bold flex items-center gap-3 justify-center w-full shadow-sm">
                       <i class="fas fa-exclamation-circle text-lg"></i> Unverified - Click to Verify
                   </button>
                 <?php endif; ?>
             </div>

             <button onclick="showPage('home'); toggleMobileMenu()" class="text-left text-white font-medium py-3 px-4 rounded-xl hover:bg-white/10 active:bg-white/20 transition flex items-center gap-4">
                 <div class="w-8 h-8 rounded-full bg-white/10 flex items-center justify-center text-white shrink-0"><i class="fas fa-home"></i></div>
                 Home
             </button>
             
             <button onclick="attemptAssistanceRequest(); toggleMobileMenu()" class="text-left text-white font-medium py-3 px-4 rounded-xl hover:bg-white/10 active:bg-white/20 transition flex items-center gap-4">
                 <div class="w-8 h-8 rounded-full bg-white/10 flex items-center justify-center text-white shrink-0"><i class="fas fa-hands-helping"></i></div>
                 Request Assistance
             </button>
             
             <button onclick="showPage('track'); toggleMobileMenu()" class="text-left text-white font-medium py-3 px-4 rounded-xl hover:bg-white/10 active:bg-white/20 transition flex items-center gap-4">
                 <div class="w-8 h-8 rounded-full bg-white/10 flex items-center justify-center text-white shrink-0"><i class="fas fa-search"></i></div>
                 Track Request
             </button>
             
             <div class="h-px bg-white/10 my-2"></div> 
             <a href="profile.php" class="text-left text-gold font-bold py-3 px-4 rounded-xl hover:bg-white/10 active:bg-white/20 transition flex items-center gap-4">
                 <div class="w-8 h-8 rounded-full bg-gold/20 flex items-center justify-center text-gold shrink-0 overflow-hidden">
                    <?php if (!empty($citizen_avatar)): ?>
                        <img src="<?= htmlspecialchars($citizen_avatar) ?>" alt="Profile" class="w-full h-full object-cover">
                    <?php else: ?>
                        <div class="w-full h-full flex items-center justify-center text-sm"><?= $citizen_initial ?></div>
                    <?php endif; ?>
                 </div>
                 My Profile
             </a>
             
             <a href="../processors/logout.php" class="text-left text-red-400 font-bold py-3 px-4 rounded-xl hover:bg-white/10 active:bg-white/20 transition flex items-center gap-4">
                 <div class="w-8 h-8 rounded-full bg-red-400/20 flex items-center justify-center text-red-400 shrink-0"><i class="fas fa-sign-out-alt"></i></div>
                 Logout
             </a>
         </div>
    </div>
    </nav>

    <div id="business-hours-modal" class="hidden fixed inset-0 bg-black/80 backdrop-blur-sm z-[999] flex items-center justify-center p-4 animate-fade">
        <div class="bg-white w-full max-w-md rounded-[30px] shadow-2xl p-6 md:p-8 relative border border-gray-100 text-center">
            <button onclick="closeBusinessHoursModal()" class="absolute top-4 right-4 text-gray-400 hover:text-gray-700 w-10 h-10 rounded-full hover:bg-gray-100 transition flex items-center justify-center text-xl">
                <i class="fas fa-times"></i>
            </button>
            <div class="w-16 h-16 md:w-20 md:h-20 bg-blue-50 text-header-blue rounded-full flex items-center justify-center text-3xl md:text-4xl mx-auto mb-4 border-4 border-blue-100">
                <i class="fas fa-clock"></i>
            </div>
            <h2 class="text-xl md:text-2xl font-bold text-[#3d143e] mb-2">Outside Business Hours</h2>
            <p class="text-gray-600 text-sm mb-6 leading-relaxed">The Social Welfare Assistance request form is only available during standard barangay business hours.</p>
            
            <div class="grid grid-cols-2 gap-4 mb-6">
                <div class="bg-gray-50 border border-gray-200 rounded-xl p-3">
                    <i class="fas fa-calendar-day text-gold text-xl mb-1"></i>
                    <p class="text-[10px] text-gray-500 font-bold uppercase tracking-wider mt-1">Days Open</p>
                    <p class="text-sm font-bold text-[#3d143e]">Mon - Fri</p>
                </div>
                <div class="bg-gray-50 border border-gray-200 rounded-xl p-3">
                    <i class="fas fa-hourglass-half text-gold text-xl mb-1"></i>
                    <p class="text-[10px] text-gray-500 font-bold uppercase tracking-wider mt-1">Hours</p>
                    <p class="text-sm font-bold text-[#3d143e]">8 AM - 5 PM</p>
                </div>
            </div>

            <button onclick="closeBusinessHoursModal()" class="bg-header-blue text-white px-8 py-3 rounded-full font-bold shadow-md hover:bg-blue-600 transition w-full text-base">
                Okay
            </button>
        </div>
    </div>

    <div id="cooldown-modal" class="hidden fixed inset-0 bg-black/80 backdrop-blur-sm z-[999] flex items-center justify-center p-4 animate-fade">
        <div class="bg-white w-full max-w-md rounded-[30px] shadow-2xl p-6 md:p-8 relative border border-gray-100 text-center">
            <button onclick="closeCooldownModal()" class="absolute top-4 right-4 text-gray-400 hover:text-gray-700 w-10 h-10 rounded-full hover:bg-gray-100 transition flex items-center justify-center text-xl">
                <i class="fas fa-times"></i>
            </button>
            <div class="w-16 h-16 md:w-20 md:h-20 bg-orange-50 text-orange-500 rounded-full flex items-center justify-center text-3xl md:text-4xl mx-auto mb-4 border-4 border-orange-100">
                <i class="fas fa-hourglass-half"></i>
            </div>
            <h2 class="text-xl md:text-2xl font-bold text-[#3d143e] mb-2">Request on Cooldown</h2>
            <p class="text-gray-600 text-sm mb-4 leading-relaxed">To prevent spam, you must wait <strong>5 days</strong> before requesting this specific assistance type again.</p>
            <div class="bg-gray-50 border border-gray-200 rounded-xl p-4 mb-4">
                <p class="text-xs text-gray-500 font-bold uppercase tracking-wider mb-1">Cooldown Ends On</p>
                <p id="cooldown-date-text" class="text-base font-black text-[#3d143e]"></p>
            </div>
            
            <p class="text-xs text-[#3d143e] font-medium bg-[#e0d5e8] p-3 rounded-lg border border-[#CCBFD5] mb-2 text-left">
                <i class="fas fa-info-circle mr-1 text-[#c6943a]"></i> If your active request gets <strong>Released</strong> before this date, your cooldown will instantly reset!
            </p>
            <p class="text-xs text-purple-600 font-medium bg-purple-50 p-3 rounded-lg border border-purple-100 mb-6 text-left">
                <i class="fas fa-robot mr-1"></i> Need an update? You can make a <strong>follow-up</strong> for your request using the <strong>KARES AI Chatbot</strong> in the bottom right corner!
            </p>
            
            <div class="flex gap-3">
                <button onclick="closeCooldownModal(); showPage('track');" class="bg-gray-100 text-[#3d143e] px-4 py-3 rounded-full font-bold shadow-sm hover:bg-gray-200 transition w-1/2 text-sm border border-gray-200">
                    Track Request
                </button>
                <button onclick="closeCooldownModal()" class="bg-dark-violet text-white px-4 py-3 rounded-full font-bold shadow-md hover:bg-purple-900 transition w-1/2 text-sm">
                    Understood
                </button>
            </div>
        </div>
    </div>


    <div id="verify-intro-modal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4 animate-fade">
        <div class="bg-white w-full max-w-md rounded-[30px] shadow-2xl p-6 md:p-8 relative border border-gray-100 text-center">
            <button onclick="document.getElementById('verify-intro-modal').classList.add('hidden')" class="absolute top-4 right-4 text-gray-400 hover:text-gray-700 w-10 h-10 rounded-full hover:bg-gray-100 transition flex items-center justify-center text-xl">
                <i class="fas fa-times"></i>
            </button>
            <div class="w-16 h-16 md:w-20 md:h-20 bg-red-50 text-red-500 rounded-full flex items-center justify-center text-3xl md:text-4xl mx-auto mb-4 border-4 border-red-100">
                <i class="fas fa-id-card"></i>
            </div>
            <h2 class="text-xl md:text-2xl font-bold text-[#3d143e] mb-2">Account Verification</h2>
            <p class="text-gray-600 text-xs md:text-sm mb-6 leading-relaxed">Verifying your account allows us to automatically fill out your information on future assistance forms, saving you time. You will need to provide your basic personal information and upload a back-to-back photo of a valid ID.</p>
            <button onclick="document.getElementById('verify-intro-modal').classList.add('hidden'); openPrivacyModal('verify');" class="bg-gold text-white px-8 py-3 rounded-full font-bold shadow-md hover:bg-yellow-600 transition w-full text-base md:text-lg">
                Start Verification
            </button>
        </div>
    </div>

    <div id="privacy-consent-modal" class="hidden fixed inset-0 bg-black/80 backdrop-blur-sm z-[999] flex items-center justify-center p-4 animate-fade">
        <div class="bg-white w-full max-w-2xl rounded-[30px] shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
            <div class="bg-[#3d143e] p-5 md:px-8 text-white text-center shrink-0 relative">
                <h2 class="text-xl md:text-2xl font-bold flex items-center justify-center gap-3">
                    <i class="fas fa-user-shield text-gold"></i> Data Privacy Consent
                </h2>
                <button onclick="closePrivacyModal()" class="absolute top-1/2 right-6 transform -translate-y-1/2 text-white/60 hover:text-white text-2xl transition"><i class="fas fa-times"></i></button>
            </div>
            
            <div class="p-6 md:p-8 overflow-y-auto flex-1 custom-scrollbar text-sm md:text-base text-gray-700 leading-relaxed">
                <p class="mb-4">In compliance with the <strong>Data Privacy Act of 2012 (Republic Act No. 10173)</strong> of the Philippines, Barangay Santo Rosario-Kanluran is committed to protecting your personal information.</p>
                
                <h3 class="font-bold text-[#3d143e] mb-2 border-b pb-1">1. Information We Collect</h3>
                <p class="mb-4">By proceeding, you agree to provide personal and sensitive information including, but not limited to, your full name, address, contact details, family income, civil status, and valid identification documents.</p>

                <h3 class="font-bold text-[#3d143e] mb-2 border-b pb-1">2. Purpose of Data Collection</h3>
                <p class="mb-4">Your data will be used strictly for the purpose of identity verification, processing of social welfare assistance requests, and maintaining official barangay records. It will not be used for commercial purposes.</p>

                <h3 class="font-bold text-[#3d143e] mb-2 border-b pb-1">3. Data Protection and Sharing</h3>
                <p class="mb-4">We employ strict security measures to protect your data. Your information will only be accessible to authorized barangay personnel and will not be shared with third parties without your explicit consent, except when required by law or for coordination with national agencies (e.g., PCSO, DSWD) regarding your assistance.</p>
            </div>

            <div class="p-5 md:px-8 border-t border-gray-200 bg-gray-50 shrink-0">
                <label class="flex items-start gap-3 cursor-pointer mb-6 group">
                    <div class="relative flex items-start pt-1">
                        <input type="checkbox" id="privacy-checkbox" class="w-5 h-5 accent-[#3d143e] cursor-pointer" onchange="togglePrivacySubmit()">
                    </div>
                    <span class="text-sm text-gray-700 font-medium group-hover:text-[#3d143e] transition select-none">
                        I have read and understood the Data Privacy Consent. I hereby authorize Barangay Santo Rosario-Kanluran to collect and process my personal data for the specified purposes.
                    </span>
                </label>
                
                <div class="flex gap-4">
                    <button onclick="closePrivacyModal()" class="w-1/3 bg-white text-gray-600 border border-gray-300 px-6 py-3 rounded-full font-bold hover:bg-gray-100 transition">Decline</button>
                    <button id="privacy-submit-btn" disabled onclick="acceptPrivacy()" class="w-2/3 bg-[#3d143e] text-white px-6 py-3 rounded-full font-bold opacity-50 cursor-not-allowed transition flex items-center justify-center gap-2">
                        I Agree & Proceed <i class="fas fa-arrow-right"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div id="final-confirm-modal" class="hidden fixed inset-0 bg-black/80 backdrop-blur-sm z-[999] flex items-center justify-center p-4 animate-fade">
        <div class="bg-white w-full max-w-2xl rounded-[30px] shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
            <div class="bg-[#5b8fb0] p-5 md:px-8 text-white text-center shrink-0 relative">
                <h2 class="text-xl md:text-2xl font-bold flex items-center justify-center gap-3">
                    <i class="fas fa-clipboard-check text-white"></i> Final Review
                </h2>
                <button onclick="closeFinalConfirmModal()" class="absolute top-1/2 right-6 transform -translate-y-1/2 text-white/60 hover:text-white text-2xl transition"><i class="fas fa-times"></i></button>
            </div>
            
            <div class="p-6 md:p-8 overflow-y-auto flex-1 custom-scrollbar bg-gray-50">
                <div class="bg-white p-5 rounded-2xl border border-gray-200 shadow-sm mb-4">
                    <p class="text-sm text-gray-500 font-bold uppercase tracking-wider mb-3">Please verify your details:</p>
                    <div id="final-confirm-details" class="text-sm md:text-base text-gray-800 space-y-2">
                        </div>
                </div>
                <div class="bg-yellow-50 border border-yellow-200 p-4 rounded-xl text-yellow-800 text-sm font-medium flex gap-3 items-start">
                    <i class="fas fa-exclamation-triangle mt-1"></i>
                    <p>By clicking submit, you confirm that all information provided is accurate and true to the best of your knowledge.</p>
                </div>
            </div>

            <div class="p-5 md:px-8 border-t border-gray-200 bg-white shrink-0 flex gap-4">
                <button type="button" onclick="closeFinalConfirmModal()" class="w-1/3 bg-white text-gray-600 border border-gray-300 px-6 py-3 rounded-full font-bold hover:bg-gray-100 transition">Go Back & Edit</button>
                <button type="button" id="actual-submit-btn" class="w-2/3 bg-[#3d143e] text-white px-6 py-3 rounded-full font-bold shadow-md hover:bg-purple-900 transition flex items-center justify-center gap-2">
                    Submit Form <i class="fas fa-paper-plane"></i>
                </button>
            </div>
        </div>
    </div>

    <div id="page-success" class="page hidden animate-fade py-12 px-4 flex flex-col items-center">
     <div class="bg-dark-violet text-white px-8 md:px-10 py-3 rounded-2xl text-xl md:text-2xl font-bold shadow-lg mb-10 text-center">Submission Success</div>
     <div class="bg-panel-blue rounded-[40px] w-full max-w-2xl mx-auto overflow-hidden shadow-xl p-8 md:p-10 text-center border border-white/50">
       <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-6 shadow-sm"><i class="fas fa-check text-4xl text-green-600"></i></div>
       <h2 class="text-2xl font-bold text-dark-violet mb-2">Request Submitted!</h2>
       <p class="text-gray-700 mb-6 text-lg">Your assistance request has been saved.</p>
       <div class="bg-white rounded-3xl p-6 mb-8 shadow-sm">
        <p class="text-sm text-header-blue font-bold">Your Reference ID:</p>
        <p id="success-request-id" class="text-2xl md:text-3xl font-black text-dark-violet tracking-wider mt-2"><?= htmlspecialchars($new_req_id) ?></p>
       </div>
       <div class="flex flex-col md:flex-row gap-4 justify-center mt-6 w-full">
           <button onclick="showPage('home')" class="bg-white text-header-blue border border-header-blue px-8 py-3 rounded-full font-bold shadow-sm hover:bg-gray-50 transition w-full md:w-auto">
               Back to Home
           </button>
           <button onclick="document.getElementById('track-search').value = '<?= htmlspecialchars($new_req_id) ?>'; showPage('track'); searchDatabaseRequest();" class="bg-gold text-white px-8 py-3 rounded-full font-bold shadow-md hover:bg-yellow-600 transition w-full md:w-auto flex items-center justify-center gap-2">
               <i class="fas fa-search"></i> Track Request
           </button>
       </div>
     </div>
    </div>

    <div id="page-vsuccess" class="page hidden animate-fade py-12 px-4 flex flex-col items-center">
     <div class="bg-panel-blue rounded-[40px] w-full max-w-2xl mx-auto overflow-hidden shadow-xl p-8 md:p-10 text-center border border-white/50">
       <div class="w-20 h-20 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-6 shadow-sm"><i class="fas fa-shield-alt text-4xl text-blue-500"></i></div>
       <h2 class="text-2xl font-bold text-dark-violet mb-2">Verification Submitted!</h2>
       <p class="text-gray-700 mb-6 text-lg">Our administrators will review your ID shortly. Your status is now pending.</p>
       <button onclick="window.location.href='user_home.php'" class="bg-gold text-white px-10 py-4 rounded-full font-bold shadow hover:bg-yellow-600 transition w-full md:w-auto text-lg">Back to Home</button>
     </div>
    </div>

    <div id="page-home" class="page animate-fade flex flex-col items-center py-6 md:py-8 px-4">
      <div class="bg-gradient-to-br from-[#4B184F] to-[#3d143e] w-full max-w-5xl mx-auto rounded-[40px] overflow-hidden shadow-2xl text-center py-10 md:py-16 px-4 md:px-8 mt-2 relative">
          <div class="absolute inset-0 opacity-10 bg-[url('../assets/images/ibon.png')] bg-cover bg-center mix-blend-overlay"></div>
          <div class="relative z-10 flex flex-col items-center">
              <div class="flex items-center justify-center gap-4 md:gap-6 mb-6">
                <img src="../assets/images/Rosario.png" alt="Barangay Seal" class="w-16 h-16 md:w-28 md:h-28 drop-shadow-xl border-2 border-white/20 rounded-full p-1 bg-white/10" onerror="this.style.display='none'">
                <img src="../assets/images/pateros.jpg" alt="KARES Logo" class="w-16 h-16 md:w-28 md:h-28 drop-shadow-xl border-2 border-white/20 rounded-full p-1 bg-white/10" onerror="this.style.display='none'">
              </div>
              <h1 class="text-2xl md:text-4xl lg:text-5xl font-extrabold mb-3 md:mb-4 text-white tracking-tight">Kalinga Assistance Portal</h1>
              <p class="text-base md:text-xl text-gold mb-6 md:mb-8 font-medium">Barangay Santo Rosario-Kanluran</p>
              <div class="bg-white/10 backdrop-blur-sm border border-white/20 rounded-2xl p-4 md:p-6 max-w-2xl mx-auto mb-8 md:mb-10 text-white/90 leading-relaxed text-sm md:text-lg shadow-inner">
                  Welcome to the Kalinga Assistance Request and Evaluation System (KARES). This portal is designed to provide our beloved constituents with fast, transparent, and easy access to medical, financial, and social welfare assistance right from the comfort of your home.
              </div>
              
              <button onclick="attemptAssistanceRequest()" class="bg-gold text-white px-8 md:px-10 py-3 md:py-4 rounded-full font-bold shadow-lg hover:bg-yellow-600 transition-all inline-flex items-center justify-center gap-3 text-base md:text-lg hover:scale-105 transform w-full sm:w-auto"> 
                  <i class="fas fa-hand-holding-heart text-xl"></i> Request Assistance
              </button>
              
              <?php if (!$is_verified && $verif_status !== 'Pending'): ?>
                  <button onclick="document.getElementById('verify-intro-modal').classList.remove('hidden')" class="mt-4 bg-white/10 hover:bg-white/20 text-white border border-white/30 px-6 py-2 rounded-full font-medium transition-all inline-flex items-center justify-center gap-2 text-sm w-full sm:w-auto">
                      <i class="fas fa-shield-alt"></i> Verify Account for Auto-fill
                  </button>
              <?php endif; ?>
          </div>
      </div>

      <div class="w-full max-w-5xl mx-auto mt-8 grid grid-cols-1 lg:grid-cols-3 gap-6 md:gap-8 mb-8">
          
          <div class="lg:col-span-2 bg-white rounded-3xl p-3 shadow-xl border border-gray-100 h-[350px] relative">
              <div class="carousel-container h-full w-full rounded-[1.5rem] overflow-hidden relative">
                  <img class="carousel-slide active w-full h-full object-cover" src="../assets/images/action1.jpg" alt="Barangay Action 1" onerror="this.src='https://images.unsplash.com/photo-1593113565694-c8c7c94b7b25?q=80&w=2070&auto=format&fit=crop'">
                  <img class="carousel-slide w-full h-full object-cover" src="../assets/images/action2.jpg" alt="Barangay Action 2">
                  <img class="carousel-slide w-full h-full object-cover" src="../assets/images/action3.jpg" alt="Barangay Action 3">
                  <img class="carousel-slide w-full h-full object-cover" src="../assets/images/action4.jpg" alt="Barangay Action 4">
                  <img class="carousel-slide w-full h-full object-cover" src="../assets/images/action5.jpg" alt="Barangay Action 5">
                  <img class="carousel-slide w-full h-full object-cover" src="../assets/images/action6.jpg" alt="Barangay Action 6">
                  <img class="carousel-slide w-full h-full object-cover" src="../assets/images/action7.jpg" alt="Barangay Action 7">
                  
                  <div class="absolute top-4 right-4 bg-black/50 backdrop-blur-md text-white text-xs font-bold px-3 py-1.5 rounded-full shadow-md z-10">Barangay in Action</div>
                  
                  <div class="carousel-nav absolute bottom-4 left-1/2 transform -translate-x-1/2 flex gap-2 z-20">
                      <span class="dot active w-3 h-3 bg-white/50 rounded-full cursor-pointer transition" onclick="currentSlide(1)"></span>
                      <span class="dot w-3 h-3 bg-white/50 rounded-full cursor-pointer transition" onclick="currentSlide(2)"></span>
                      <span class="dot w-3 h-3 bg-white/50 rounded-full cursor-pointer transition" onclick="currentSlide(3)"></span>
                      <span class="dot w-3 h-3 bg-white/50 rounded-full cursor-pointer transition" onclick="currentSlide(4)"></span>
                      <span class="dot w-3 h-3 bg-white/50 rounded-full cursor-pointer transition" onclick="currentSlide(5)"></span>
                      <span class="dot w-3 h-3 bg-white/50 rounded-full cursor-pointer transition" onclick="currentSlide(6)"></span>
                      <span class="dot w-3 h-3 bg-white/50 rounded-full cursor-pointer transition" onclick="currentSlide(7)"></span>
                  </div>
              </div>
          </div>

          <div class="bg-panel-blue rounded-3xl p-6 md:p-8 shadow-xl border border-white/50 flex flex-col justify-center relative overflow-hidden h-[350px]">
              <div class="absolute top-[-20px] right-[-20px] opacity-10"><i class="fas fa-search text-9xl text-header-blue"></i></div>
              <h3 class="text-xl md:text-2xl font-bold text-dark-violet mb-2 relative z-10">Already Applied?</h3>
              <p class="text-gray-600 text-xs md:text-sm mb-6 relative z-10">Check the real-time status of your submitted assistance request.</p>
              
              <button onclick="showPage('track')" class="bg-white rounded-2xl p-4 md:p-5 shadow-sm border border-gray-100 relative z-10 text-center mb-6 hover:bg-gray-50 hover:scale-105 transition duration-300 w-full cursor-pointer">
                  <i class="fas fa-clipboard-list text-3xl md:text-4xl text-gold mb-2 md:mb-3"></i>
                  <p class="text-[10px] md:text-xs font-bold text-gray-400 uppercase tracking-widest">Track your Reference ID</p>
              </button>
              
              <button onclick="showPage('track')" class="bg-dark-violet text-white w-full py-3 md:py-3.5 rounded-full font-bold shadow-md hover:bg-purple-900 transition flex items-center justify-center gap-2 relative z-10 mt-auto">
                  Track Request <i class="fas fa-arrow-right"></i>
              </button>
          </div>
      </div>
      
      <div class="w-full max-w-5xl mx-auto mb-10">
          <div class="bg-white rounded-3xl p-6 md:p-10 shadow-xl border border-gray-100">
              <div class="text-center mb-8 md:mb-10">
                  <h3 class="text-xl md:text-3xl font-bold text-dark-violet">How KARES Works</h3>
                  <p class="text-gray-500 mt-2 font-medium text-sm md:text-base">A simple, transparent process to get the help you need.</p>
              </div>
              <div class="grid grid-cols-1 md:grid-cols-3 gap-8 relative">
                  <div class="hidden md:block absolute top-1/2 left-[15%] right-[15%] h-1 bg-gray-100 -translate-y-1/2 z-0"></div>
                  <div class="relative z-10 flex flex-col items-center text-center group">
                      <div class="w-16 h-16 md:w-20 md:h-20 bg-panel-blue text-header-blue rounded-full flex items-center justify-center text-2xl md:text-3xl shadow-md border-4 border-white mb-3 md:mb-4 group-hover:scale-110 transition duration-300"><i class="fas fa-laptop-medical"></i></div>
                      <h4 class="font-bold text-[#3d143e] text-base md:text-lg mb-2">1. Submit Request</h4>
                      <p class="text-xs md:text-sm text-gray-600 px-2 md:px-4">Fill out the digital form and upload photos of your required documents.</p>
                  </div>
                  <div class="relative z-10 flex flex-col items-center text-center group">
                      <div class="w-16 h-16 md:w-20 md:h-20 bg-yellow-100 text-yellow-600 rounded-full flex items-center justify-center text-2xl md:text-3xl shadow-md border-4 border-white mb-3 md:mb-4 group-hover:scale-110 transition duration-300"><i class="fas fa-user-clock"></i></div>
                      <h4 class="font-bold text-[#3d143e] text-base md:text-lg mb-2">2. Under Review</h4>
                      <p class="text-xs md:text-sm text-gray-600 px-2 md:px-4">The Barangay Admin will evaluate your submitted documents. Track this status anytime.</p>
                  </div>
                  <div class="relative z-10 flex flex-col items-center text-center group">
                      <div class="w-16 h-16 md:w-20 md:h-20 bg-green-100 text-green-600 rounded-full flex items-center justify-center text-2xl md:text-3xl shadow-md border-4 border-white mb-3 md:mb-4 group-hover:scale-110 transition duration-300"><i class="fas fa-hand-holding-medical"></i></div>
                      <h4 class="font-bold text-[#3d143e] text-base md:text-lg mb-2">3. Receive Aid</h4>
                      <p class="text-xs md:text-sm text-gray-600 px-2 md:px-4">Once Approved, you will be instructed to visit the barangay hall to physically receive your assistance.</p>
                  </div>
              </div>
          </div>
      </div>
      
      <div class="w-full max-w-5xl mx-auto grid grid-cols-1 lg:grid-cols-3 gap-6 md:gap-8 mb-6">
          
          <div class="bg-white rounded-3xl p-6 md:p-8 shadow-xl border border-gray-100 flex flex-col relative overflow-hidden">
              <div class="absolute top-0 right-0 p-4 opacity-5 pointer-events-none"><i class="fas fa-phone-alt text-8xl text-red-500"></i></div>
              <h3 class="text-lg md:text-xl font-bold text-red-500 mb-5 flex items-center gap-2 border-b border-gray-100 pb-3 relative z-10">
                  <i class="fas fa-exclamation-triangle"></i> Emergency Hotlines
              </h3>
              <div class="space-y-3 relative z-10 flex-1 flex flex-col justify-center">
                  <div class="flex items-center justify-between bg-red-50 p-3 md:p-4 rounded-xl border border-red-100">
                      <span class="text-xs md:text-sm font-bold text-gray-700"><i class="fas fa-building text-red-400 mr-2"></i> Brgy. Desk</span>
                      <span class="text-sm md:text-base font-black text-red-600">8628-3210</span>
                  </div>
                  <div class="flex items-center justify-between bg-blue-50 p-3 md:p-4 rounded-xl border border-blue-100">
                      <span class="text-xs md:text-sm font-bold text-gray-700"><i class="fas fa-shield-alt text-blue-400 mr-2"></i> Police</span>
                      <span class="text-sm md:text-base font-black text-blue-600">117 / 911</span>
                  </div>
                  <div class="flex items-center justify-between bg-orange-50 p-3 md:p-4 rounded-xl border border-orange-100">
                      <span class="text-xs md:text-sm font-bold text-gray-700"><i class="fas fa-fire-extinguisher text-orange-400 mr-2"></i> Fire Dept.</span>
                      <span class="text-sm md:text-base font-black text-orange-600">8641-1000</span>
                  </div>
              </div>
          </div>

          <div class="lg:col-span-2 bg-panel-blue rounded-3xl p-6 md:p-8 shadow-xl border border-white/50 relative overflow-hidden flex flex-col justify-center">
              <div class="absolute top-0 right-0 p-6 opacity-10 pointer-events-none"><i class="fas fa-map-marked-alt text-9xl text-header-blue"></i></div>
              <h3 class="text-2xl font-bold text-dark-violet mb-6 flex items-center gap-3 relative z-10"><i class="fas fa-info-circle text-gold"></i> About Our Barangay</h3>
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 md:gap-6 relative z-10">
                  <div class="bg-white/60 p-5 rounded-2xl border border-white/50 shadow-sm">
                      <div class="flex items-center gap-3 mb-2 text-header-blue"><i class="fas fa-users text-xl"></i><h4 class="font-bold text-sm uppercase tracking-wider">Population</h4></div>
                      <p class="text-3xl font-extrabold text-dark-violet">5,345</p><p class="text-sm text-gray-700 mt-1 font-medium">As of 2020 Census</p>
                  </div>
                  <div class="bg-white/60 p-5 rounded-2xl border border-white/50 shadow-sm">
                      <div class="flex items-center gap-3 mb-2 text-header-blue"><i class="fas fa-map-marker-alt text-xl"></i><h4 class="font-bold text-sm uppercase tracking-wider">Location</h4></div>
                      <p class="text-lg font-bold text-dark-violet leading-tight">NCR, Municipality of Pateros</p><p class="text-sm text-gray-700 mt-1 font-medium">Bounded by Pasig & Makati</p>
                  </div>
              </div>
          </div>
      </div>

      <div class="w-full max-w-5xl mx-auto mb-10">
          <div class="bg-white rounded-3xl p-6 shadow-xl border border-gray-100 flex flex-col md:flex-row items-center justify-between gap-6">
              <div class="flex items-center gap-4 w-full md:w-auto border-b md:border-b-0 md:border-r border-gray-100 pb-4 md:pb-0 md:pr-6">
                  <div class="w-14 h-14 bg-yellow-50 rounded-full flex items-center justify-center text-gold shrink-0"><i class="fas fa-clock text-2xl"></i></div>
                  <div>
                      <h3 class="text-lg font-bold text-[#3d143e] leading-tight">Barangay Hall Operating Hours</h3>
                      <p class="text-xs text-gray-500 font-medium">For physical claiming of assistance</p>
                  </div>
              </div>
              <div class="flex items-center gap-3 w-full md:w-auto">
                  <div class="w-10 h-10 bg-panel-blue rounded-full flex items-center justify-center text-header-blue shrink-0"><i class="fas fa-calendar-day text-sm"></i></div>
                  <div>
                      <p class="text-[10px] text-gray-400 font-bold uppercase tracking-wider">Days Open</p>
                      <p class="text-sm font-bold text-[#3d143e]">Monday - Friday</p>
                  </div>
              </div>
              <div class="flex items-center gap-3 w-full md:w-auto">
                  <div class="w-10 h-10 bg-panel-blue rounded-full flex items-center justify-center text-header-blue shrink-0"><i class="fas fa-hourglass-half text-sm"></i></div>
                  <div>
                      <p class="text-[10px] text-gray-400 font-bold uppercase tracking-wider">Business Hours</p>
                      <p class="text-sm font-bold text-[#3d143e]">8:00 AM - 5:00 PM</p>
                  </div>
              </div>
          </div>
      </div>

      <!-- OFFICIAL MANDATE SECTION -->
      <div class="w-full max-w-5xl mx-auto mb-6">
          <div class="bg-white rounded-3xl p-6 md:p-10 shadow-xl border border-gray-100">
              <h2 class="text-2xl font-bold text-dark-violet mb-6 border-b pb-4 flex items-center gap-3">
                  <i class="fas fa-file-contract text-gold"></i> Official Mandate & Declaration
              </h2>

              <div class="mb-8">
                  <h3 class="text-lg font-bold text-[#5b8fb0] mb-2">I. Mandate</h3>
                  <p class="text-gray-700 leading-relaxed text-sm md:text-base">Barangay Sto. Rosario Kanluran serves as the primary planning and implementing unit of government policies, plans, programs, projects, and activities in the community. It acts as a forum where the collective views of the people may be expressed, crystallized, and considered.</p>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                  <div class="bg-panel-blue rounded-[20px] p-6 border border-white/50 shadow-sm">
                      <h3 class="text-lg font-bold text-[#5b8fb0] mb-3 flex items-center gap-2"><i class="fas fa-eye text-gold"></i> II. Vision</h3>
                      <p class="text-gray-700 text-sm leading-relaxed">A Barangay that continuously strive from economic growth, for peaceful and orderly community, for clean and green environment and for public unity that aspires productive and quality life.</p>
                  </div>
                  <div class="bg-panel-blue rounded-[20px] p-6 border border-white/50 shadow-sm">
                      <h3 class="text-lg font-bold text-[#5b8fb0] mb-3 flex items-center gap-2"><i class="fas fa-bullseye text-gold"></i> III. Mission</h3>
                      <p class="text-gray-700 text-sm leading-relaxed">To deliver quality and reliable public service and to carry out the mandates of honesty, transparency and good governance.</p>
                  </div>
              </div>

              <div>
                  <h3 class="text-lg font-bold text-[#5b8fb0] mb-3">IV. Service Pledge</h3>
                  <p class="text-dark-violet font-bold mb-4">We commit to:</p>
                  <div class="bg-gray-50 rounded-2xl p-5 border border-gray-100 space-y-4">
                      <div class="flex items-start gap-3">
                          <i class="fas fa-check-circle text-green-500 text-lg mt-0.5"></i>
                          <p class="text-gray-700 text-sm md:text-base"><strong class="text-dark-violet">Promptness:</strong> We will attend to your needs and concerns promptly and without delay.</p>
                      </div>
                      <div class="flex items-start gap-3">
                          <i class="fas fa-check-circle text-green-500 text-lg mt-0.5"></i>
                          <p class="text-gray-700 text-sm md:text-base"><strong class="text-dark-violet">Respect:</strong> We will treat everyone with respect, dignity, and professionalism.</p>
                      </div>
                      <div class="flex items-start gap-3">
                          <i class="fas fa-check-circle text-green-500 text-lg mt-0.5"></i>
                          <p class="text-gray-700 text-sm md:text-base"><strong class="text-dark-violet">Transparency:</strong> We will provide clear and accurate information about our programs, services, and transactions.</p>
                      </div>
                      <div class="flex items-start gap-3">
                          <i class="fas fa-check-circle text-green-500 text-lg mt-0.5"></i>
                          <p class="text-gray-700 text-sm md:text-base"><strong class="text-dark-violet">Accountability:</strong> We will be accountable for our actions and decisions, and we will take responsibility for our mistakes.</p>
                      </div>
                      <div class="flex items-start gap-3">
                          <i class="fas fa-check-circle text-green-500 text-lg mt-0.5"></i>
                          <p class="text-gray-700 text-sm md:text-base"><strong class="text-dark-violet">Empathy:</strong> We will listen to your concerns and provide assistance with compassion and understanding.</p>
                      </div>
                  </div>
              </div>

          </div>
      </div>
      <!-- END OFFICIAL MANDATE SECTION -->

    </div>

    <div id="page-track" class="page hidden animate-fade py-8 md:py-12 px-4 flex flex-col items-center">
        <div class="bg-[#3d143e] text-white px-6 md:px-8 py-2 md:py-3 rounded-[20px] text-lg md:text-xl font-bold shadow-lg relative z-10 -mb-4 md:-mb-5">
            Track your Request
        </div>
        
        <div class="bg-[#d1e3f0] rounded-[30px] w-full max-w-4xl mx-auto shadow-xl p-4 sm:p-6 md:p-8 pt-10 md:pt-12 border border-white/50 relative">
            <div class="w-full max-w-lg mx-auto relative mb-6 flex gap-2">
                <div class="relative flex-1">
                    <input type="text" id="track-search" class="w-full h-12 md:h-14 rounded-full pl-5 md:pl-6 pr-12 md:pr-14 outline-none text-gray-800 shadow-sm border border-gray-100 font-bold text-sm md:text-lg" placeholder="Enter Request ID">
                    <button onclick="searchDatabaseRequest()" class="absolute right-1 top-1 md:right-2 md:top-2 bg-gold w-10 h-10 md:w-10 md:h-10 rounded-full flex items-center justify-center text-white hover:bg-yellow-600 transition shadow-sm">
                        <i class="fas fa-search text-base md:text-lg"></i>
                    </button>
                </div>
            </div>

            <div id="track-message" class="text-center text-[#3d143e] font-bold mt-2 mb-4 text-sm md:text-base"></div>

            <div id="track-result" class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6">
                <div class="bg-white rounded-[20px] p-4 md:p-6 shadow-sm border border-white/60">
                    <div class="flex justify-between items-start mb-4 md:mb-5 border-b border-gray-100 pb-3">
                        <div>
                            <p class="text-[10px] md:text-xs text-[#3d143e] font-black uppercase tracking-wider mb-0.5">Request ID</p>
                            <p id="res-id" class="text-sm md:text-base font-bold text-gray-700">---</p>
                        </div>
                        <button onclick="document.getElementById('timeline-modal').classList.remove('hidden')" class="bg-gray-100 text-[#3d143e] text-[10px] md:text-xs font-black px-3 md:px-4 py-1.5 md:py-2 rounded-lg shadow-sm hover:bg-gray-200 transition flex items-center gap-1 md:gap-2">
                            <i class="fas fa-list text-gray-500"></i> View Status
                        </button>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-3 md:gap-4 mb-4 md:mb-5 border-b border-gray-100 pb-4">
                        <div>
                            <p class="text-[10px] md:text-xs text-[#3d143e] font-black uppercase tracking-wider mb-0.5">Assistance Type</p>
                            <p id="res-type" class="text-sm md:text-base text-gray-700 font-bold leading-tight">---</p>
                        </div>
                        <div>
                            <p class="text-[10px] md:text-xs text-[#3d143e] font-black uppercase tracking-wider mb-0.5">Date Submitted</p>
                            <p id="res-date" class="text-sm md:text-base text-gray-700 font-bold">---</p>
                        </div>
                    </div>
                    
                    <div class="mb-3 md:mb-4">
                        <p class="text-[10px] md:text-xs text-[#3d143e] font-black uppercase tracking-wider mb-0.5">Name</p>
                        <p id="res-name" class="text-sm md:text-base text-gray-700 font-bold">---</p>
                    </div>
                    <div class="mb-3 md:mb-4">
                        <p class="text-[10px] md:text-xs text-[#3d143e] font-black uppercase tracking-wider mb-0.5">Contact Number</p>
                        <p id="res-contact" class="text-sm md:text-base text-gray-700 font-bold">---</p>
                    </div>
                    <div>
                        <p class="text-[10px] md:text-xs text-[#3d143e] font-black uppercase tracking-wider mb-0.5">Complete Address</p>
                        <p id="res-address" class="text-sm md:text-base text-gray-700 font-bold leading-tight">---</p>
                    </div>
                </div>

                <div class="bg-white rounded-[20px] p-4 md:p-6 shadow-sm border border-white/60 flex flex-col">
                    <div class="mb-5 md:mb-6 flex-1">
                        <p class="text-[10px] md:text-xs text-[#3d143e] font-black uppercase tracking-wider mb-2">Description</p>
                        <div class="bg-gray-50 p-3 md:p-4 rounded-xl border border-gray-200">
                            <p id="res-desc" class="text-sm md:text-base text-gray-700 italic font-medium">Waiting for search...</p>
                        </div>
                    </div>
                    
                    <div class="mb-4 md:mb-5 flex items-center justify-between">
                        <p class="text-[10px] md:text-xs text-[#3d143e] font-black uppercase tracking-wider">Approval</p>
                        <div id="res-approval-pill" class="inline-block px-4 py-1.5 rounded-full shadow-inner text-xs md:text-sm font-bold bg-white border border-gray-200 text-gray-500">Pending</div>
                    </div>
                    <div class="flex items-center justify-between flex-wrap gap-2">
                        <p class="text-[10px] md:text-xs text-[#3d143e] font-black uppercase tracking-wider">Released</p>
                        <div id="res-released-container" class="flex flex-col items-end">
                            <div id="res-released-pill" class="inline-block px-4 py-1.5 rounded-full shadow-inner text-xs md:text-sm font-bold bg-white border border-gray-200 text-gray-500">Pending</div>
                            <p id="res-released-time" class="hidden text-[11px] text-gray-500 font-bold mt-2"><i class="fas fa-box-open text-purple-500 mr-1"></i> <span id="res-released-time-text"></span></p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-10 bg-white rounded-[20px] shadow-sm border border-gray-100 overflow-hidden">
                <div class="bg-header-blue px-6 py-4">
                    <h3 class="text-white font-bold text-lg"><i class="fas fa-history mr-2"></i> Your Request History</h3>
                </div>
                <div class="p-0 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-gray-50 text-gray-500 uppercase text-[10px] font-black">
                            <tr>
                                <th class="px-6 py-3">Request ID</th>
                                <th class="px-6 py-3">Type</th>
                                <th class="px-6 py-3">Date Submitted</th>
                                <th class="px-6 py-3">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100" id="history-tbody">
                            <?php if (count($request_history) > 0): ?>
                                <?php foreach ($request_history as $req): ?>
                                    <tr class="history-row hover:bg-gray-50 cursor-pointer transition hidden" onclick="document.getElementById('track-search').value = '<?= $req['request_id'] ?>'; searchDatabaseRequest(); window.scrollTo(0, 0);">
                                        <td class="px-6 py-4 font-bold text-dark-violet"><?= htmlspecialchars($req['request_id']) ?></td>
                                        <td class="px-6 py-4 text-gray-600 font-medium"><?= htmlspecialchars($req['assistance_type']) ?></td>
                                        <td class="px-6 py-4 text-gray-500"><?= formatManilaTimeShort($req['date_submitted']) ?></td>
                                        <td class="px-6 py-4">
                                            <?php 
                                                $badgeClass = 'bg-gray-100 text-gray-600';
                                                if ($req['status'] === 'Approved') $badgeClass = 'bg-green-100 text-green-700';
                                                if ($req['status'] === 'Declined') $badgeClass = 'bg-red-100 text-red-700';
                                                if ($req['status'] === 'Released') $badgeClass = 'bg-purple-100 text-purple-700';
                                            ?>
                                            <span class="px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider <?= $badgeClass ?>"><?= $req['status'] ?></span>
                                            
                                            <?php if ($req['status'] === 'Released'): ?>
                                                <p class="text-[10px] text-gray-500 font-bold mt-1 whitespace-nowrap">
                                                    <i class="fas fa-box-open text-purple-500 mr-1"></i> <?= formatManilaTimeShort($req['date_updated']) ?>
                                                </p>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr id="empty-history"><td colspan="4" class="px-6 py-8 text-center text-gray-400 font-medium">No request history found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <div id="history-pagination" class="px-6 py-4 border-t border-gray-100 bg-gray-50/50 flex items-center justify-between hidden">
                    <p class="text-xs text-gray-500 font-medium" id="history-page-info">Showing 1 to 5 of X entries</p>
                    <div class="flex gap-2" id="history-page-btns"></div>
                </div>

            </div>

        </div>
    </div>

    <div id="timeline-modal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4 animate-fade">
        <div class="bg-white w-full max-w-sm rounded-[30px] shadow-2xl p-6 md:p-8 relative border border-gray-100">
            <button onclick="document.getElementById('timeline-modal').classList.add('hidden')" class="absolute top-4 right-4 text-gray-400 hover:text-gray-700 w-8 h-8 md:w-10 md:h-10 rounded-full hover:bg-gray-100 transition flex items-center justify-center text-lg md:text-xl">
                <i class="fas fa-times"></i>
            </button>
            <div class="flex justify-between items-start mb-4 md:mb-6 pt-2">
                <div>
                    <p class="text-[10px] md:text-xs text-[#3d143e] font-black uppercase tracking-wider">Request ID</p>
                    <p id="mod-id" class="text-sm md:text-base font-bold text-gray-700">---</p>
                </div>
                <div class="text-right pr-6 md:pr-8">
                    <p id="mod-status" class="text-sm md:text-base text-[#5b8fb0] font-black uppercase">---</p>
                </div>
            </div>
            <div class="flex justify-between items-start border-b border-gray-200 pb-4 md:pb-6 mb-4 md:mb-6">
                <div class="w-1/2 pr-2">
                    <p class="text-[10px] md:text-xs text-[#3d143e] font-black uppercase tracking-wider">Assistance Type</p>
                    <p id="mod-type" class="text-sm md:text-base font-bold text-gray-700 leading-tight">---</p>
                </div>
                <div class="w-1/2 pl-2 text-right">
                    <p class="text-[10px] md:text-xs text-[#3d143e] font-black uppercase tracking-wider">Date Submitted</p>
                    <p id="mod-date" class="text-sm md:text-base font-bold text-gray-700">---</p>
                </div>
            </div>
            <div id="timeline-container" class="pl-2 space-y-6 md:space-y-8 relative py-2">
                </div>
        </div>
    </div>

   </div>

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
   
  </div>

  <div id="cares-mascot-container">
    <div id="cares-speech-bubble" class="absolute bottom-20 right-4 w-56 bg-white text-[#3d143e] p-4 rounded-[20px] rounded-br-none shadow-xl text-sm font-black border-2 border-purple-100 opacity-0 transition-opacity duration-300 pointer-events-none z-0"></div>
    <button class="cares-mascot-btn" id="cares-btn"><img src="../assets/images/CARES1.gif" alt="Cares Mascot" class="cares-mascot-img" onerror="this.style.display='none'"></button>
    
    <div class="cares-chat-window" id="cares-window">
      <div class="cares-chat-header"><h3 class="cares-chat-title">🦹 KARES Assistant</h3><button class="cares-close-btn" id="cares-close">×</button></div>
      
      <div class="cares-chat-content" id="cares-content">
          <div class="cares-messages" id="chat-body"></div>
          
          <div id="faq-wrapper" style="display:none; margin-top: 10px;">
              <div class="flex justify-center mb-3">
                  <button onclick="caresMascot.toggleFaqs()" id="faq-toggle-btn" class="text-[11px] text-gray-500 font-bold hover:text-[#3d143e] transition bg-gray-100 border border-gray-200 px-3 py-1.5 rounded-full flex items-center gap-1 shadow-sm">
                      <i class="fas fa-chevron-up"></i> Show Suggestions
                  </button>
              </div>
              <div class="cares-questions" id="cares-questions" style="display: none;"></div>
          </div>
          
          <div id="cares-followup-actions"></div>
      </div>
      
      <div class="chat-input-area" id="chat-input-area" style="display:none;">
          <input type="text" id="chat-input" placeholder="Type your message here..." onkeypress="caresMascot.handleKeyPress(event)">
          <button id="chat-send-btn" onclick="caresMascot.sendMessage()" class="bg-gold text-white w-10 h-10 rounded-full flex items-center justify-center shadow-sm hover:bg-yellow-600 transition shrink-0">
              <i class="fas fa-paper-plane"></i>
          </button>
      </div>
    </div>
  </div>
  
  <script>
    const isUserVerified = <?= $is_verified ? 'true' : 'false' ?>;
    <?php if($show_success): ?> window.onload = () => { showPage('success'); }; <?php endif; ?>
    <?php if($show_vsuccess): ?> window.onload = () => { showPage('vsuccess'); }; <?php endif; ?>

    let currentMainCategory = '';
    let pendingPrivacyAction = ''; 
    let finalSubmitFormId = ''; 

    const isWithinHours = <?= $is_within_hours ? 'true' : 'false' ?>;
    const categoryCooldowns = <?= json_encode($category_cooldowns) ?>;

    // --- NEW: HISTORY PAGINATION LOGIC ---
    const historyRows = document.querySelectorAll('.history-row');
    const rowsPerPage = 5;
    let currentHistoryPage = 1;

    function renderHistoryPage(page) {
        if(historyRows.length === 0) return;
        const totalPages = Math.ceil(historyRows.length / rowsPerPage);
        if (page < 1) page = 1;
        if (page > totalPages) page = totalPages;
        currentHistoryPage = page;

        const start = (page - 1) * rowsPerPage;
        const end = start + rowsPerPage;

        historyRows.forEach((row, index) => {
            if (index >= start && index < end) {
                row.classList.remove('hidden');
            } else {
                row.classList.add('hidden');
            }
        });

        const info = document.getElementById('history-page-info');
        const actualEnd = Math.min(end, historyRows.length);
        if(info) info.innerText = `Showing ${start + 1} to ${actualEnd} of ${historyRows.length} requests`;

        const btnContainer = document.getElementById('history-page-btns');
        const paginationContainer = document.getElementById('history-pagination');
        
        if (totalPages > 1) {
            paginationContainer.classList.remove('hidden');
            let html = '';
            
            // Previous Button
            if (page > 1) {
                html += `<button onclick="renderHistoryPage(${page - 1})" class="px-3 py-1.5 rounded-lg border border-gray-200 text-[#3d143e] hover:bg-gray-50 text-xs font-bold transition">Prev</button>`;
            } else {
                html += `<button disabled class="px-3 py-1.5 rounded-lg border border-gray-100 text-gray-300 cursor-not-allowed text-xs font-bold">Prev</button>`;
            }

            // Next Button
            if (page < totalPages) {
                html += `<button onclick="renderHistoryPage(${page + 1})" class="px-3 py-1.5 rounded-lg border border-gray-200 text-[#3d143e] hover:bg-gray-50 text-xs font-bold transition">Next</button>`;
            } else {
                html += `<button disabled class="px-3 py-1.5 rounded-lg border border-gray-100 text-gray-300 cursor-not-allowed text-xs font-bold">Next</button>`;
            }
            
            btnContainer.innerHTML = html;
        } else {
            paginationContainer.classList.add('hidden');
            // If 5 or less, just show them all
            historyRows.forEach(row => row.classList.remove('hidden'));
        }
    }
    // -------------------------------------

    function openSubModal(mainCategory, options) {
        // Check if the specific main category is on cooldown
        if (categoryCooldowns[mainCategory] && categoryCooldowns[mainCategory].on_cooldown) {
            document.getElementById('cooldown-date-text').innerText = categoryCooldowns[mainCategory].end_date;
            document.getElementById('cooldown-modal').classList.remove('hidden');
            return;
        }
        
        currentMainCategory = mainCategory;
        document.getElementById('modal-header-title').innerText = mainCategory;
        const container = document.getElementById('sub-options-container');
        container.innerHTML = '';
        options.forEach(opt => {
            const pill = document.createElement('div'); pill.className = 'option-pill';
            pill.innerHTML = `<i class="far fa-circle pill-icon"></i><span>${opt}</span>`;
            pill.onclick = function() {
                document.querySelectorAll('.option-pill').forEach(p => { p.classList.remove('selected'); p.querySelector('.pill-icon').className = 'far fa-circle pill-icon'; });
                this.classList.add('selected'); this.querySelector('.pill-icon').className = 'fas fa-check-circle pill-icon'; this.dataset.value = opt;
            };
            container.appendChild(pill);
        });
        document.getElementById('selection-modal').classList.remove('hidden');
    }

    // Interceptor function for requesting assistance
    function attemptAssistanceRequest() {
        if (!isWithinHours) {
            document.getElementById('business-hours-modal').classList.remove('hidden');
            return;
        }
        showPage('aid');
    }
    
    function closeBusinessHoursModal() {
        document.getElementById('business-hours-modal').classList.add('hidden');
    }
    
    function closeCooldownModal() {
        document.getElementById('cooldown-modal').classList.add('hidden');
    }

    const requirementConfigs = {
        'Medical Assistance - Medical Support': [{ id: 'indigency_cert', label: 'Upload Indigency Certificate', icon: 'fa-file-alt' }, { id: 'medical_cert', label: 'Clinical Abstract/Medical Certificate (Original)', icon: 'fa-notes-medical' }, { id: 'patient_id', label: 'Valid ID of Patient', icon: 'fa-id-card-alt' }, { id: 'claimant_id', label: 'Valid ID of Claimant', icon: 'fa-id-badge' }],
        'Medical Assistance - Mercury Drugs (Medicine)': [{ id: 'reseta', label: 'Reseta (Original)', icon: 'fa-prescription' }, { id: 'medical_cert', label: 'Clinical Abstract (Original)', icon: 'fa-notes-medical' }, { id: 'indigency_cert', label: 'BRGY. Indigency for Medical Assistance', icon: 'fa-file-alt' }, { id: 'social_case', label: 'Social Case Study (MSWD Office)', icon: 'fa-folder-open' }, { id: 'quotation', label: 'Quotation for Mercury Drugs', icon: 'fa-file-invoice-dollar' }, { id: 'patient_id', label: 'Valid ID of Patient (Back to Back)', icon: 'fa-id-card-alt' }, { id: 'claimant_id', label: 'Valid ID of Claimant (Back to Back)', icon: 'fa-id-badge' }],
        'Hospital Bill - Hospital Cost Support': [{ id: 'social_case', label: 'Social Case Study (MSWD Office)', icon: 'fa-folder-open' }, { id: 'endorsement', label: 'Endorsement Letter/Acceptance of Guarantee letter', icon: 'fa-envelope-open-text' }, { id: 'hospital_bill', label: 'Hospital Bill', icon: 'fa-file-invoice' }, { id: 'medical_cert', label: 'Clinical Abstract (Original)', icon: 'fa-notes-medical' }, { id: 'indigency_cert', label: 'BRGY. Indigency for Hospital Bill', icon: 'fa-file-alt' }, { id: 'patient_id', label: 'Valid ID of Patient (Back to Back)', icon: 'fa-id-card-alt' }, { id: 'claimant_id', label: 'Valid ID of Claimant (Back to Back)', icon: 'fa-id-badge' }],
        'Financial Assistance - Financial Support': [{ id: 'indigency_cert', label: 'Upload Indigency Certificate', icon: 'fa-file-alt' }, { id: 'medical_cert', label: 'Clinical Abstract/Medical Certificate (Original)', icon: 'fa-notes-medical' }, { id: 'patient_id', label: 'Valid ID of Patient', icon: 'fa-id-card-alt' }, { id: 'claimant_id', label: 'Valid ID of Claimant', icon: 'fa-id-badge' }],
        'Hospital Bill - Hospital Cost for PCSO': [{ id: 'endorsement', label: 'Endorsement Letter/Acceptance of Guarantee', icon: 'fa-envelope-open-text' }, { id: 'hospital_bill', label: 'Hospital Bill or Statement of Account', icon: 'fa-file-invoice' }, { id: 'medical_cert', label: 'Clinical Abstract (Original)', icon: 'fa-notes-medical' }, { id: 'promissory_note', label: 'Notarized Promissory Note', icon: 'fa-file-signature' }, { id: 'indigency_cert', label: 'BRGY. Indigency for Hospital Bill', icon: 'fa-file-alt' }, { id: 'social_case', label: 'Social Case Study (MSWD Office)', icon: 'fa-folder-open' }, { id: 'patient_id', label: 'Valid ID of Patient (Back to Back)', icon: 'fa-id-card-alt' }, { id: 'claimant_id', label: 'Valid ID of Claimant (Back to Back)', icon: 'fa-id-badge' }],
        'Burial Assistance - Funeral Support': [{ id: 'death_cert', label: 'Death Certificate W/Registered No. (CTC)', icon: 'fa-book-dead' }, { id: 'funeral_contract', label: 'Funeral Contract', icon: 'fa-file-signature' }, { id: 'social_case', label: 'Social Case Study (MSWD Office)', icon: 'fa-folder-open' }, { id: 'indigency_cert', label: 'BRGY. Indigency for Burial Assistance (Original)', icon: 'fa-file-alt' }, { id: 'patient_id', label: 'Valid ID of the deceased person (Back to Back)', icon: 'fa-id-card-alt' }, { id: 'claimant_id', label: 'Valid ID of Claimant (Back to Back)', icon: 'fa-id-badge' }]
    };

    const defaultRequirements = [ { id: 'indigency_cert', label: 'Upload Indigency Certificate', icon: 'fa-file-alt' }, { id: 'claimant_id', label: 'Upload Valid ID', icon: 'fa-id-badge' } ];

    function disableSubmitBtn(form) {
        const btn = form.querySelector('button[type="submit"]');
        if (btn) {
            if (btn.disabled) return false; 
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Uploading... Please wait';
            btn.classList.add('opacity-70', 'cursor-wait');
        }
        return true;
    }

    function toggleMobileMenu() { document.getElementById('mobile-menu').classList.toggle('hidden'); }

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

    document.addEventListener('click', function(event) {
        const dropdown = document.getElementById('notif-dropdown');
        const isClickInside = dropdown.contains(event.target) || event.target.closest('button[onclick="toggleNotifs()"]');
        
        if (!isClickInside && !dropdown.classList.contains('hidden')) {
            dropdown.classList.add('hidden');
        }
    });

    let emailAlertsEnabled = <?= $email_alerts_enabled ?>;
    
    function toggleEmailAlerts() {
        emailAlertsEnabled = !emailAlertsEnabled;
        const icon = document.getElementById('email-alert-icon');
        const btn = document.getElementById('email-alert-btn');
        
        if (emailAlertsEnabled) {
            icon.className = 'fas fa-bell text-lg md:text-xl group-hover:scale-110 transition';
            btn.classList.remove('text-header-blue');
            btn.classList.add('text-gray-400');
            showToast("Email notifications disabled."); 
        } else {
            icon.className = 'fas fa-bell-slash text-lg md:text-xl group-hover:scale-110 transition';
            btn.classList.remove('text-gray-400');
            btn.classList.add('text-header-blue');
            showToast("Email notifications enabled.");
        }
        
        fetch('../processors/toggle_email_alerts.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ enabled: emailAlertsEnabled })
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        const btn = document.getElementById('email-alert-btn');
        if (!emailAlertsEnabled && btn) {
            btn.classList.remove('text-header-blue');
            btn.classList.add('text-gray-400');
        }
        
        // Execute Pagination on load
        if(historyRows.length > 0) renderHistoryPage(1);

        if ("Notification" in window) {
            if (Notification.permission === "default") {
                setTimeout(() => {
                    Notification.requestPermission().then(permission => {
                        if (permission === "granted") {
                            new Notification("KARES Portal", {
                                body: "You will now receive desktop alerts for your requests!",
                                icon: "../assets/images/kareslogo.png"
                            });
                        }
                    });
                }, 3000); 
            }
        }
    });

    function showToast(message) {
        const toast = document.getElementById('toast-message');
        toast.innerText = message;
        toast.style.opacity = '1';
        setTimeout(() => { toast.style.opacity = '0'; }, 3000);
    }

    function openPrivacyModal(action) {
        pendingPrivacyAction = action;
        const checkbox = document.getElementById('privacy-checkbox');
        const submitBtn = document.getElementById('privacy-submit-btn');
        checkbox.checked = false; 
        submitBtn.disabled = true;
        submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
        
        document.getElementById('privacy-consent-modal').classList.remove('hidden');
    }

    function closePrivacyModal() {
        document.getElementById('privacy-consent-modal').classList.add('hidden');
    }

    function togglePrivacySubmit() {
        const checkbox = document.getElementById('privacy-checkbox');
        const submitBtn = document.getElementById('privacy-submit-btn');
        if (checkbox.checked) {
            submitBtn.disabled = false;
            submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
        } else {
            submitBtn.disabled = true;
            submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
        }
    }

    function acceptPrivacy() {
        closePrivacyModal();
        if (pendingPrivacyAction === 'verify') {
            showPage('verify');
        } else if (pendingPrivacyAction === 'request') {
            proceedToFormFinal();
        }
    }

    function openFinalConfirmModal(formType) {
        let currentSection = (formType === 'verify') ? document.getElementById('v-step-5') : document.getElementById('step-6');
        let inputs = currentSection.querySelectorAll('input[required], select[required], textarea[required]');
        for (let i = 0; i < inputs.length; i++) {
            if (!inputs[i].checkValidity()) { 
                inputs[i].reportValidity(); 
                return; 
            }
        }

        const formId = (formType === 'verify') ? 'karesVerifForm' : 'karesForm';
        const form = document.getElementById(formId);
        const detailsContainer = document.getElementById('final-confirm-details');

        if (!detailsContainer) {
            form.submit();
            return;
        }

        let html = '<div class="grid grid-cols-1 md:grid-cols-2 gap-y-3 gap-x-4">';
        
        const getVal = (name) => {
            const el = form.querySelector(`[name="${name}"]`);
            return el && el.value ? el.value : '<span class="text-gray-400 italic">Not provided</span>';
        };

        html += `<div><span class="text-[10px] text-gray-500 font-bold uppercase tracking-wider block">Full Name</span> <span class="font-bold text-[#3d143e]">${getVal('fname')} ${getVal('mname')} ${getVal('lname')} ${getVal('ext')}</span></div>`;
        html += `<div><span class="text-[10px] text-gray-500 font-bold uppercase tracking-wider block">Contact Number</span> <span class="font-bold text-[#3d143e]">${getVal('mobile')}</span></div>`;
        html += `<div><span class="text-[10px] text-gray-500 font-bold uppercase tracking-wider block">Email Address</span> <span class="font-bold text-[#3d143e]">${getVal('email')}</span></div>`;
        html += `<div><span class="text-[10px] text-gray-500 font-bold uppercase tracking-wider block">Civil Status</span> <span class="font-bold text-[#3d143e]">${getVal('civil_status')}</span></div>`;
        html += `<div class="md:col-span-2"><span class="text-[10px] text-gray-500 font-bold uppercase tracking-wider block">Complete Address</span> <span class="font-bold text-[#3d143e]">${getVal('house_no')} ${getVal('street')}, ${getVal('brgy')}, ${getVal('city')}</span></div>`;
        html += `<div class="md:col-span-2 pt-3 border-t border-gray-200 mt-2"><span class="text-[10px] text-gray-500 font-bold uppercase tracking-wider block">Emergency Contact</span> <span class="font-bold text-[#3d143e]">${getVal('em_fname')} ${getVal('em_lname')} • ${getVal('em_contact')} (${getVal('em_rel')})</span></div>`;
        html += `<div class="md:col-span-2 pt-3 border-t border-gray-200 mt-2"><span class="text-[10px] text-gray-500 font-bold uppercase tracking-wider block">ID Provided</span> <span class="font-bold text-[#3d143e]">${getVal('id_type')} - ${getVal('id_number')}</span></div>`;
        
        if(formType === 'request') {
             html += `<div class="md:col-span-2 pt-3 border-t border-gray-200 mt-2"><span class="text-[10px] text-header-blue font-bold uppercase tracking-wider block">Assistance Type</span> <span class="font-bold text-lg text-header-blue">${getVal('aid_type')}</span></div>`;
        }

        html += '</div>';
        detailsContainer.innerHTML = html;

        document.getElementById('actual-submit-btn').onclick = function() {
            this.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Submitting...';
            this.disabled = true;
            this.classList.add('opacity-70', 'cursor-wait');
            
            form.onsubmit = null;

            if (formType === 'verify') {
                document.getElementById('hidden-verif-submit').click();
            } else {
                document.getElementById('hidden-req-submit').click();
            }
        };

        document.getElementById('final-confirm-modal').classList.remove('hidden');
    }

    function closeFinalConfirmModal() {
        document.getElementById('final-confirm-modal').classList.add('hidden');
    }

    function closeSubModal() { document.getElementById('selection-modal').classList.add('hidden'); }

    function handleSelectionProceed() {
        const selected = document.querySelector('.option-pill.selected');
        if (!selected) { 
            alert("Please select a specific assistance type first."); 
            return; 
        }
        openPrivacyModal('request');
    }

    function proceedToFormFinal() {
        const selected = document.querySelector('.option-pill.selected');
        if (!selected) { alert("Please select a specific assistance type first."); return; }
        const fullAssistanceName = `${currentMainCategory} - ${selected.dataset.value}`;
        document.getElementById('display_aid_type').value = fullAssistanceName;
        const uiId = '0789-' + Math.floor(1000000 + Math.random() * 9000000);
        
        document.getElementById('global-req-id').innerText = uiId;
        document.getElementById('hidden_req_id').value = uiId;
        
        if(fullAssistanceName.includes('PCSO')) { document.getElementById('pcso-note').classList.remove('hidden'); } else { document.getElementById('pcso-note').classList.add('hidden'); }

        document.getElementById('global-request-info').classList.remove('hidden');
        renderRequirementsFields(fullAssistanceName);
        closeSubModal();
        showPage('form');

        document.querySelectorAll('.form-section').forEach(s => s.classList.remove('active'));
        document.querySelectorAll('.step-item').forEach(c => { c.classList.remove('active', 'completed'); c.querySelector('.step-circle').innerHTML = ''; });
        
        const titleBadge = document.getElementById('form-dynamic-title');

        if (isUserVerified) {
            for(let i=1; i<=5; i++) {
                let section = document.getElementById('step-' + i);
                let inputs = section.querySelectorAll('input, select, textarea');
                inputs.forEach(inp => inp.removeAttribute('required'));
            }
            document.getElementById('step-6').classList.add('active');
            
            const fname = "<?= addslashes($v_fname) ?>";
            const lname = "<?= addslashes($v_lname) ?>";
            const mname = "<?= addslashes($v_mname ?? '') ?>";
            const ext = "<?= addslashes($v_ext ?? '') ?>";
            let formattedName = `${lname}, ${fname}`;
            if(mname) formattedName += ` ${mname}`;
            if(ext) formattedName += ` ${ext}`;
            
            document.getElementById('applicant-span').innerText = formattedName.toUpperCase();
            document.getElementById('global-applicant-name').classList.remove('hidden');

            for(let i=1; i<=5; i++) {
                let marker = document.getElementById('step-marker-' + i);
                marker.classList.add('completed');
                marker.querySelector('.step-circle').innerHTML = '<i class="fas fa-check"></i>';
            }
            document.getElementById('step-marker-6').classList.add('active');
            document.getElementById('btn-back-step6').setAttribute('onclick', "showPage('aid')");

            if (titleBadge) titleBadge.innerText = "Request Form";

        } else {
            document.getElementById('global-applicant-name').classList.add('hidden');
            document.getElementById('applicant-span').innerText = '';
            document.getElementById('step-1').classList.add('active');
            document.getElementById('step-marker-1').classList.add('active');
            document.getElementById('btn-back-step6').setAttribute('onclick', "prevStep(6)");
            
            if (titleBadge) titleBadge.innerText = "Account Verification";
        }
    }

    function renderRequirementsFields(assistanceType) {
        const grid = document.getElementById('dynamic-requirements-grid'); grid.innerHTML = ''; 
        const fields = requirementConfigs[assistanceType] || defaultRequirements;
        fields.forEach(field => {
            const html = `<div class="file-upload-box relative"><img id="preview_${field.id}" class="upload-preview-img hidden"><div class="upload-content"><i class="fas ${field.icon} text-3xl mb-2"></i><br>${field.label}</div><input type="file" id="${field.id}" name="${field.id}" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10" accept="image/*" required onchange="previewImage(this, 'preview_${field.id}')"></div>`;
            grid.innerHTML += html;
        });
    }

    function previewImage(input, previewId) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) { document.getElementById(previewId).src = e.target.result; document.getElementById(previewId).classList.remove('hidden'); }
            reader.readAsDataURL(input.files[0]);
        }
    }

    function showPage(page) {
      document.querySelectorAll('.page').forEach(p => p.classList.add('hidden'));
      
      if (page !== 'form') {
          document.getElementById('request-progress-container').classList.add('hidden');
      } else {
          document.getElementById('request-progress-container').classList.remove('hidden');
      }

      const pageEl = document.getElementById(`page-${page}`);
      if (pageEl) { pageEl.classList.remove('hidden'); window.scrollTo(0, 0); }
      document.querySelectorAll('.nav-btn').forEach(btn => btn.className = 'nav-btn text-white hover:text-gold px-5 py-1.5 rounded-full transition-all font-medium flex items-center gap-2 hover:font-bold');
      const activeBtnId = `nav-${page === 'aid' || page === 'form' || page === 'success' || page === 'verify' || page === 'vsuccess' ? 'request' : page}`;
      const activeBtn = document.getElementById(activeBtnId);
      if(activeBtn) activeBtn.className = 'nav-btn bg-gold text-white px-5 py-1.5 rounded-full transition-all font-bold flex items-center gap-2';

      const mascotContainer = document.getElementById('cares-mascot-container');
      if (page === 'home') { mascotContainer.style.display = 'block'; } else { mascotContainer.style.display = 'none'; if (caresMascot.isOpen) caresMascot.toggleChat(); }
    }

    function nextVerifStep(currentStep) {
        let currentSection = document.getElementById('v-step-' + currentStep);
        let inputs = currentSection.querySelectorAll('input[required], select[required], textarea[required]');
        for (let i = 0; i < inputs.length; i++) if (!inputs[i].checkValidity()) { inputs[i].reportValidity(); return; }
        
        currentSection.classList.remove('active');
        let currentMarker = document.getElementById('v-step-marker-' + currentStep);
        currentMarker.classList.remove('active'); 
        currentMarker.classList.add('completed'); 
        currentMarker.querySelector('.step-circle').innerHTML = '<i class="fas fa-check"></i>';
        
        document.getElementById('v-step-' + (currentStep + 1)).classList.add('active');
        document.getElementById('v-step-marker-' + (currentStep + 1)).classList.add('active');
    }

    function prevVerifStep(currentStep) {
        document.getElementById('v-step-' + currentStep).classList.remove('active');
        document.getElementById('v-step-marker-' + currentStep).classList.remove('active');
        document.getElementById('v-step-' + (currentStep - 1)).classList.add('active');
        let prevMarker = document.getElementById('v-step-marker-' + (currentStep - 1));
        prevMarker.classList.remove('completed'); 
        prevMarker.classList.add('active'); 
        prevMarker.querySelector('.step-circle').innerHTML = '';
    }

    function nextStep(currentStep) {
        let currentSection = document.getElementById('step-' + currentStep);
        let inputs = currentSection.querySelectorAll('input[required], select[required], textarea[required]');
        for (let i = 0; i < inputs.length; i++) if (!inputs[i].checkValidity()) { inputs[i].reportValidity(); return; }
        if(currentStep === 2) {
            const form = document.getElementById('karesForm');
            const fname = form.querySelector('input[name="fname"]').value.trim();
            const lname = form.querySelector('input[name="lname"]').value.trim();
            const mname = form.querySelector('input[name="mname"]').value.trim();
            const ext = form.querySelector('input[name="ext"]').value.trim();
            let formattedName = `${lname}, ${fname}`;
            if(mname) formattedName += ` ${mname}`;
            if(ext) formattedName += ` ${ext}`;
            document.getElementById('applicant-span').innerText = formattedName.toUpperCase();
            document.getElementById('global-applicant-name').classList.remove('hidden');
        }
        currentSection.classList.remove('active');
        let currentMarker = document.getElementById('step-marker-' + currentStep);
        currentMarker.classList.remove('active'); currentMarker.classList.add('completed'); currentMarker.querySelector('.step-circle').innerHTML = '<i class="fas fa-check"></i>';
        document.getElementById('step-' + (currentStep + 1)).classList.add('active');
        document.getElementById('step-marker-' + (currentStep + 1)).classList.add('active');
        
        if ((currentStep + 1) === 6) {
            const titleBadge = document.getElementById('form-dynamic-title');
            if (titleBadge) titleBadge.innerText = "Request Form";
        }
    }

    function prevStep(currentStep) {
        document.getElementById('step-' + currentStep).classList.remove('active');
        document.getElementById('step-marker-' + currentStep).classList.remove('active');
        document.getElementById('step-' + (currentStep - 1)).classList.add('active');
        let prevMarker = document.getElementById('step-marker-' + (currentStep - 1));
        prevMarker.classList.remove('completed'); prevMarker.classList.add('active'); prevMarker.querySelector('.step-circle').innerHTML = '';
        if(currentStep === 3) document.getElementById('global-applicant-name').classList.add('hidden');
        
        if ((currentStep - 1) < 6) {
            const titleBadge = document.getElementById('form-dynamic-title');
            if (titleBadge) titleBadge.innerText = "Account Verification";
        }
    }

    function formatSupabaseDate(dateString) {
        if (!dateString) return '---';
        let ds = dateString;
        if (!ds.includes('Z') && !ds.includes('+')) {
            ds += 'Z';
        }
        const date = new Date(ds);
        if (isNaN(date.getTime())) return dateString; 
        
        return date.toLocaleString('en-US', {
            timeZone: 'Asia/Manila',
            month: '2-digit', day: '2-digit', year: 'numeric',
            hour: '2-digit', minute: '2-digit', hour12: true
        }).replace(',', '');
    }

    async function fetchTrackingData(reqId) {
        try {
            let res = await fetch('../processors/track_request_api.php?req_id=' + encodeURIComponent(reqId));
            return await res.json();
        } catch(e) { return {success: false, message: 'Server error'}; }
    }

    function buildWireframeTimeline(status) {
        const container = document.getElementById('timeline-container');
        container.innerHTML = '<div class="absolute left-[13px] top-2 bottom-6 w-1 bg-gray-200 rounded-full"></div>';
        
        const steps = [
            { id: 'Submitted', label: 'Submitted', desc: 'Your request has been received' },
            { id: 'Under Review', label: 'Under Review', desc: 'Being evaluate by barangay' },
            { id: 'Approved', label: 'Approved', desc: 'Your request has been approved' },
            { id: 'Released', label: 'Released', desc: 'Assistance has been received' }
        ];

        let activeIndex = 0;
        if(status === 'Submitted') activeIndex = 1; 
        if(status === 'Approved') activeIndex = 2;
        if(status === 'Released') activeIndex = 3;
        if(status === 'Declined') activeIndex = 1;

        steps.forEach((step, index) => {
            const isActive = index <= activeIndex;
            if(status === 'Declined' && index > 0) return; 
            
            let colorDot = isActive ? 'bg-[#3d143e]' : 'bg-gray-300';
            let colorText = isActive ? 'text-[#3d143e]' : 'text-gray-400';
            
            if(status === 'Declined' && index === 1) {
                step.label = 'Declined'; step.desc = 'Your request was not approved.'; colorDot = 'bg-red-500'; colorText = 'text-red-500';
            }

            container.innerHTML += `
                <div class="flex items-start gap-5 relative z-10 pb-4">
                    <div class="w-4 h-4 rounded-full ${colorDot} mt-1 shadow-md ring-4 ring-white"></div>
                    <div class="-mt-1">
                        <p class="text-xs md:text-sm font-black ${colorText} uppercase tracking-wide">${step.label}</p>
                        <p class="text-xs md:text-sm text-gray-500 mt-0.5 font-medium">${step.desc}</p>
                    </div>
                </div>
            `;
        });
    }

    async function searchDatabaseRequest() {
        const reqId = document.getElementById('track-search').value.trim();
        const msgDiv = document.getElementById('track-message');
        const resultDiv = document.getElementById('track-result');
        if (!reqId) { msgDiv.innerHTML = "Please enter an ID."; return; }
        
        msgDiv.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Searching database...';

        let data = await fetchTrackingData(reqId);
        
        if(data.success) {
            msgDiv.innerHTML = ''; 
            let d = data.data;

            document.getElementById('res-id').innerText = d.request_id;
            document.getElementById('res-type').innerText = d.assistance_type;
            
            let formattedDate = formatSupabaseDate(d.date_submitted || d.created_at);
            document.getElementById('res-date').innerText = formattedDate;
            
            let fName = d.first_name || d.fname || d.citizen_name || '';
            let mName = d.middle_name || d.mname || '';
            let lName = d.last_name || d.lname || '';
            let ext = d.name_extension || d.ext || '';
            let fullName = `${fName} ${mName ? mName + ' ' : ''}${lName} ${ext}`.trim();
            document.getElementById('res-name').innerText = fullName || 'N/A';
            
            document.getElementById('res-contact').innerText = d.mobile_number || d.mobile || d.contact_number || 'N/A';
            
            let house = d.house_no || d.house || '';
            let street = d.street || '';
            let brgy = d.barangay || d.brgy || '';
            let address = `${house} ${street} ${brgy}`.trim().replace(/\s+/g, ' ');
            if(!address) address = d.address || 'N/A';
            document.getElementById('res-address').innerText = address;

            document.getElementById('res-desc').innerText = d.description || d.purpose || 'No description provided.';
            
            let appPill = document.getElementById('res-approval-pill');
            let relPill = document.getElementById('res-released-pill');
            let relTime = document.getElementById('res-released-time');
            let relTimeText = document.getElementById('res-released-time-text');
            
            appPill.className = "inline-block px-4 py-1.5 rounded-full shadow-inner text-xs md:text-sm font-bold bg-white border border-gray-200 text-gray-500";
            appPill.innerText = "Pending";
            relPill.className = "inline-block px-4 py-1.5 rounded-full shadow-inner text-xs md:text-sm font-bold bg-white border border-gray-200 text-gray-500";
            relPill.innerText = "Pending";
            relTime.classList.add('hidden');

            if(d.status === 'Approved' || d.status === 'Released') { appPill.innerText = "Approved"; appPill.className = "inline-block px-4 py-1.5 rounded-full shadow-inner text-xs md:text-sm font-bold bg-green-50 text-green-600"; }
            if(d.status === 'Declined') { appPill.innerText = "Declined"; appPill.className = "inline-block px-4 py-1.5 rounded-full shadow-inner text-xs md:text-sm font-bold bg-red-50 text-red-600"; }
            
            if(d.status === 'Released') { 
                relPill.innerText = "Released"; 
                relPill.className = "inline-block px-4 py-1.5 rounded-full shadow-inner text-xs md:text-sm font-bold bg-purple-50 text-purple-600"; 
                if (d.date_updated) {
                    relTimeText.innerText = formatSupabaseDate(d.date_updated);
                    relTime.classList.remove('hidden');
                }
            }

            document.getElementById('mod-id').innerText = d.request_id;
            document.getElementById('mod-status').innerText = d.status;
            document.getElementById('mod-type').innerText = d.assistance_type;
            
            document.getElementById('mod-date').innerText = formattedDate;
            
            buildWireframeTimeline(d.status);

        } else { 
            msgDiv.innerHTML = '<span class="text-red-500 font-bold"><i class="fas fa-times-circle mr-2"></i> Error: ' + data.message + '</span>'; 
        }
    }

    const userName = "<?= htmlspecialchars(explode(' ', $citizen_name)[0] ?? 'Citizen') ?>";

    const caresMascot = {
        isOpen: false,
        currentTrackedId: null,
        bubbleTexts: [ "If you're lost, you can ask me some questions!", "Need help tracking your request? I can do that!", "Hi! I'm Cares. Click me if you need assistance." ],
        bubbleInterval: null,
        faqs: { 
            request: { question: 'How do I request assistance?', answer: 'Click on "Request Social Welfare Assistance" on your dashboard, choose your program, and fill out the form.' }, 
            track: { question: 'How do I track my request?', answer: 'I can track that for you right now! Just send me your Reference ID.' }, 
            followup: { question: 'Can I follow up on my request?', answer: 'Yes! I can send a follow-up nudge to the admin for you. Just provide your Reference ID to track it first.' },
            requirements: { question: 'What are the requirements?', answer: 'It varies! You usually need a Valid ID and an Indigency Certificate. Medical requests also require a Medical Abstract or Prescription.' },
            hours: { question: 'What are your business hours?', answer: 'The Barangay Hall is open Monday to Friday, 8:00 AM to 5:00 PM for processing and releasing of assistance.' },
            emergency: { question: 'Emergency Contact?', answer: 'Please call your local barangay emergency hotline immediately: 8628-3210.' } 
        },
        
        init() {
            document.getElementById('cares-btn').addEventListener('click', () => this.toggleChat());
            document.getElementById('cares-close').addEventListener('click', () => this.toggleChat());
            
            const qContainer = document.getElementById('cares-questions');
            Object.entries(this.faqs).forEach(([key, faq]) => {
                const btn = document.createElement('button'); 
                btn.className = 'cares-question-btn'; 
                btn.textContent = faq.question;
                btn.onclick = () => this.askQuestion(key); 
                if(qContainer) qContainer.appendChild(btn);
            });
            
            this.startBubbles();
            this.handleFooterOverlap();
        },

        startBubbles() {
            const bubble = document.getElementById('cares-speech-bubble');
            if(this.isOpen || !bubble) return;
            
            let textIndex = 0;
            const showBubble = () => {
                if(this.isOpen) return;
                bubble.innerText = this.bubbleTexts[textIndex]; 
                bubble.style.opacity = '1';
                textIndex = (textIndex + 1) % this.bubbleTexts.length;
                setTimeout(() => { if(!this.isOpen) bubble.style.opacity = '0'; }, 4000); 
            };
            
            setTimeout(showBubble, 2000); 
            this.bubbleInterval = setInterval(showBubble, 10000); 
        },

        toggleChat() {
            const chatBox = document.getElementById('cares-window');
            const bubble = document.getElementById('cares-speech-bubble');
            const inputArea = document.getElementById('chat-input-area');
            this.isOpen = !this.isOpen;
            
            if (this.isOpen) {
                if(chatBox) chatBox.classList.add('open');
                if(bubble) bubble.style.opacity = '0';
                clearInterval(this.bubbleInterval);
                if(inputArea) inputArea.style.display = 'flex';
                
                const chatBody = document.getElementById('chat-body');
                if (chatBody && chatBody.innerHTML.trim() === '') {
                    const hour = new Date().getHours();
                    let timeGreeting = "Good evening";
                    if (hour < 12) timeGreeting = "Good morning";
                    else if (hour < 18) timeGreeting = "Good afternoon";
                    
                    this.botReply(`${timeGreeting}, ${userName}! I'm Cares, your Barangay AI Assistant. How can I help you today?`, true);
                }
            } else {
                if(chatBox) chatBox.classList.remove('open');
                if(inputArea) inputArea.style.display = 'none';
                this.startBubbles();
            }
        },

        handleFooterOverlap() {
            const updateMascotPosition = () => {
                const footers = document.querySelectorAll('footer');
                let activeFooter = null;
                
                footers.forEach(f => {
                    if (window.getComputedStyle(f).display !== 'none' && f.offsetHeight > 0) {
                        activeFooter = f;
                    }
                });

                const mascot = document.getElementById('cares-mascot-container');
                if(!activeFooter || !mascot) return;
                
                const footerRect = activeFooter.getBoundingClientRect(); 
                const windowHeight = window.innerHeight;
                
                if (footerRect.top < windowHeight) { 
                    const overlap = windowHeight - footerRect.top; 
                    mascot.style.bottom = (24 + overlap) + 'px'; 
                } else { 
                    mascot.style.bottom = '24px'; 
                }
            };

            window.addEventListener('scroll', updateMascotPosition);
            window.addEventListener('resize', updateMascotPosition);
            setTimeout(updateMascotPosition, 100);
        },

        toggleFaqs() {
            const faqDiv = document.getElementById('cares-questions');
            const toggleBtn = document.getElementById('faq-toggle-btn');
            if(!faqDiv || !toggleBtn) return;
            
            if (faqDiv.style.display === 'none') {
                faqDiv.style.display = 'flex';
                toggleBtn.innerHTML = '<i class="fas fa-chevron-down"></i> Hide Suggestions';
            } else {
                faqDiv.style.display = 'none';
                toggleBtn.innerHTML = '<i class="fas fa-chevron-up"></i> Show Suggestions';
            }
            const chatContent = document.getElementById('cares-content');
            if(chatContent) chatContent.scrollTop = chatContent.scrollHeight;
        },

        askQuestion(key) {
            const faqWrapper = document.getElementById('faq-wrapper');
            if(faqWrapper) faqWrapper.style.display = 'none';
            
            this.appendMessage(this.faqs[key].question, 'user');
            
            setTimeout(() => { 
                this.botReply(this.faqs[key].answer);
                if (key === 'track' || key === 'followup') setTimeout(() => this.showReferenceInput(), 1500); 
                
                setTimeout(() => {
                    if(faqWrapper) faqWrapper.style.display = 'block';
                    const questions = document.getElementById('cares-questions');
                    if(questions) questions.style.display = 'none';
                    
                    const toggleBtn = document.getElementById('faq-toggle-btn');
                    if(toggleBtn) toggleBtn.innerHTML = '<i class="fas fa-chevron-up"></i> Show Suggestions';
                    
                    const chatContent = document.getElementById('cares-content');
                    if(chatContent) chatContent.scrollTop = chatContent.scrollHeight;
                }, 2000);

            }, 300);
        },

        handleKeyPress(event) {
            if (event.key === 'Enter') {
                this.sendMessage();
            }
        },

        async sendMessage() {
            const inputEl = document.getElementById('chat-input');
            if(!inputEl) return;
            const userText = inputEl.value.trim();
            if (!userText) return;

            this.appendMessage(userText, 'user');
            inputEl.value = '';

            const textLower = userText.toLowerCase();

            // ==========================================
            // 1. PROFANITY FILTER (Tagalog & English)
            // ==========================================
            const badWords = ['bobo', 'tanga', 'gago', 'gaga', 'puta', 'tae', 'stupid', 'idiot', 'fuck', 'shit', 'Tangina', 'Tangina mo', 'bitch', 'Nigga', 'Nigger'];
            
            const isProfane = badWords.some(word => textLower.includes(word));
            if (isProfane) {
                this.botReply("Please keep our conversation respectful. I am here to assist you with official Barangay matters. Do you have a valid question or Reference ID?");
                return;
            }

            // ==========================================
            // 2. GIBBERISH / SPAM FILTER
            // ==========================================
            const isSpam = /(.)\1{4,}/.test(textLower); 
            const isLongGibberish = textLower.length > 15 && !textLower.includes(' ') && !textLower.match(/^\d{4}-/); 

            if (isSpam || isLongGibberish) {
                this.botReply("That doesn't look like a valid question or Reference ID. Please type clearly so I can assist you properly!");
                return;
            }

            // ==========================================
            // NORMAL CHATBOT LOGIC CONTINUES BELOW...
            // ==========================================
            let botResponse = "I'm not quite sure about that. Try clicking one of the FAQ buttons, or give me your Reference ID to track a request!";

            if (textLower === "hi" || textLower === "hello" || textLower === "hey") {
                botResponse = "Hello there! How can I assist you with your barangay needs today?";
            } 
            else if (textLower.includes("how are you")) {
                botResponse = "I'm doing great, just here ready to help you out! What do you need assistance with?";
            }
            else if (textLower.includes("good morning") || textLower.includes("good afternoon") || textLower.includes("good evening")) {
                botResponse = "Good day to you too! Are you looking to request assistance or track an existing one?";
            }
            else if (textLower.includes("follow up") || textLower.includes("follow-up") || textLower.includes("nudge") || textLower.includes("update")) {
                botResponse = "I can help you send a follow-up nudge to the admins! Just give me your Reference ID so I can track it first.";
                setTimeout(() => this.showReferenceInput(), 2000);
            }
            else if (textLower.includes("time") || textLower.includes("hours") || textLower.includes("open") || textLower.includes("close")) {
                botResponse = "The Barangay Hall is open Monday to Friday, from 8:00 AM to 5:00 PM.";
            }
            else if (textLower.includes("medical") || textLower.includes("hospital")) {
                botResponse = "For Medical or Hospital assistance, you will need a Medical Certificate and a valid ID. Click 'Social Welfare Assistance' on your home screen to start!";
            } 
            else if (textLower.includes("burial") || textLower.includes("funeral") || textLower.includes("dead")) {
                botResponse = "I am sorry for your loss. For Burial Assistance, prepare a Death Certificate and Funeral Contract. You can submit these in the Request menu.";
            } 
            else if (textLower.includes("financial") || textLower.includes("money") || textLower.includes("cash")) {
                botResponse = "If you need Financial Assistance, please prepare an Indigency Certificate and a valid ID. You can apply through the 'Social Welfare Assistance' menu.";
            }
            else if (textLower.includes("require") || textLower.includes("documents") || textLower.includes("need")) {
                botResponse = "The requirements depend on the assistance. You generally need a Valid ID and a Certificate of Indigency. Specific types (like Medical or Burial) require additional proofs like a Medical Certificate or Death Certificate.";
            }
            else if (textLower.includes("verify") || textLower.includes("kyc") || textLower.includes("id")) {
                botResponse = "Verifying your account makes requesting assistance much faster! Just go to your Profile and click 'Get Verified' to upload your ID.";
            } 
            else if (textLower.includes("where") || textLower.includes("location") || textLower.includes("address")) {
                botResponse = "We are located at St. Sto. Rosario Kanluran, Municipality of Pateros, Metro Manila.";
            }
            else if (textLower.includes("thank")) {
                botResponse = "You're very welcome! Let me know if you need anything else.";
            } 
            else if (textLower.includes("who are you") || textLower.includes("what are you")) {
                botResponse = "I am Cares, the official AI assistant for Barangay Santo Rosario-Kanluran. I can guide you through requests and track your documents!";
            }
            // ==========================================
            // 3. DEVELOPER EASTER EGG!
            // ==========================================
            else if (textLower.includes("developer") || textLower.includes("creator") || textLower.includes("maker") || textLower.includes("who made") || textLower.includes("Developed") || textLower.includes("who created") || textLower.includes("who built")) {
                botResponse = "I was proudly built by a brilliant team of 3rd-year students from the University of Makati! 🎓 The developers are Carl Justin Mijares, Christine Mae Luis, Sean Dael, and Adrian Valencia.";
            }
            // ==========================================
            else if (textLower.match(/^\d{4}-/)) {
                botResponse = "It looks like you entered a Reference ID! Let me check the database for that...";
                this.botReply(botResponse);
                
                setTimeout(() => {
                    const trackInput = document.getElementById('cares-track-input');
                    if(trackInput) trackInput.value = userText;
                    this.handleTrackSubmit();
                }, 2000);
                return;
            }
            
            this.botReply(botResponse);
        },

        showReferenceInput() {
            const followupEl = document.getElementById('cares-followup-actions'); 
            if(!followupEl) return;
            followupEl.innerHTML = '';
            
            const container = document.createElement('div'); 
            container.style.display = 'flex'; container.style.gap = '8px'; container.style.marginTop = '8px';
            
            const input = document.createElement('input'); 
            input.type = 'text'; input.id = 'cares-track-input'; input.placeholder = 'e.g., 0789-0098456'; 
            input.className = 'px-4 py-3 rounded-xl border border-gray-300 text-base text-gray-800 font-bold'; 
            input.style.flex = '1'; input.style.outline = 'none';
            
            const submit = document.createElement('button'); 
            submit.className = 'cares-question-btn !py-3'; submit.textContent = 'Check'; 
            submit.addEventListener('click', () => this.handleTrackSubmit());
            
            container.appendChild(input); container.appendChild(submit); followupEl.appendChild(container);
            
            const chatContent = document.getElementById('cares-content');
            if(chatContent) chatContent.scrollTop = chatContent.scrollHeight;
        },

        async handleTrackSubmit() {
            const input = document.getElementById('cares-track-input'); if (!input) return;
            const ref = input.value.trim(); if (!ref) return;
            
            this.appendMessage(ref, 'user'); 
            
            const followupEl = document.getElementById('cares-followup-actions');
            if(followupEl) followupEl.innerHTML = '<span style="font-size:14px; font-weight:bold; color:gray;"><i class="fas fa-spinner fa-spin mr-2"></i>Searching database...</span>';
            
            const chatContent = document.getElementById('cares-content');
            if(chatContent) chatContent.scrollTop = chatContent.scrollHeight;
            
            let result = await fetchTrackingData(ref);
            
            let botText = '';
            if(result.success) {
                botText = `Found it! Your request for <strong>${result.data.assistance_type}</strong> is currently: <strong style="color:#A462A9;">${result.data.status}</strong>.`;
                this.currentTrackedId = ref; 
            } else {
                botText = `I couldn't find anything for ID: ${ref}. Please check the number and try again.`;
                this.currentTrackedId = null;
            }
            
            if(followupEl) followupEl.innerHTML = '';
            this.botReply(botText);

            if(result.success && (result.data.status === 'Submitted' || result.data.status === 'Approved')) {
                setTimeout(() => {
                    this.showFollowUpOption();
                }, 2000);
            }
        },

        showFollowUpOption() {
            const followupEl = document.getElementById('cares-followup-actions'); 
            if(!followupEl) return;
            
            followupEl.innerHTML = '';
            
            const container = document.createElement('div'); 
            container.className = 'mt-3 bg-blue-50 border border-blue-200 p-3 rounded-xl text-center animate-fade';
            
            const text = document.createElement('p');
            text.className = 'text-xs text-[#3d143e] font-bold mb-2';
            text.innerText = 'Would you like to send a follow-up nudge to the admin?';
            
            const btn = document.createElement('button');
            btn.className = 'bg-[#c6943a] text-white px-4 py-2.5 rounded-full font-bold shadow-sm hover:bg-yellow-600 transition text-xs w-full flex justify-center items-center gap-2';
            btn.innerHTML = '<i class="fas fa-bell"></i> Send Follow-up';
            btn.onclick = () => this.sendFollowUpRequest();
            
            container.appendChild(text); 
            container.appendChild(btn); 
            followupEl.appendChild(container);
            
            const chatContent = document.getElementById('cares-content');
            if(chatContent) chatContent.scrollTop = chatContent.scrollHeight;
        },

        async sendFollowUpRequest() {
            if (!this.currentTrackedId) return;

            const followupEl = document.getElementById('cares-followup-actions');
            if(followupEl) followupEl.innerHTML = '<span style="font-size:12px; font-weight:bold; color:gray;"><i class="fas fa-spinner fa-spin mr-1"></i> Sending...</span>';

            try {
                let formData = new FormData();
                formData.append('request_id', this.currentTrackedId);

                let response = await fetch('../processors/send_followup.php', {
                    method: 'POST',
                    body: formData
                });

                let result = await response.json();

                if (result.success) {
                    this.appendMessage('I want to follow up on this request.', 'user');
                    this.botReply('Got it! I have successfully nudged the admin about your request. They will review it shortly.');
                } else {
                    this.botReply('Notice: ' + result.message);
                }
            } catch (e) {
                this.botReply('Sorry, I lost connection to the server. Please try again later.');
            }

            if(followupEl) followupEl.innerHTML = '';
            this.currentTrackedId = null;
        },

        appendMessage(text, sender) {
            const chatBody = document.getElementById('chat-body');
            const chatContent = document.getElementById('cares-content');
            if(!chatBody) return;
            
            const alignClass = sender === 'user' ? 'text-right' : 'text-left';
            const bgClass = sender === 'user' ? 'cares-user-msg' : 'cares-bot-msg';
            
            chatBody.innerHTML += `<div class="${alignClass} mb-3"><div class="inline-block cares-bubble ${bgClass}">${text}</div></div>`;
            if(chatContent) chatContent.scrollTop = chatContent.scrollHeight;
        },

        botReply(text, isIntro = false) {
            const chatBody = document.getElementById('chat-body');
            const chatContent = document.getElementById('cares-content');
            if(!chatBody) return;
            
            const typingId = 'typing-' + Date.now();
            chatBody.innerHTML += `<div id="${typingId}" class="typing-indicator"><div class="typing-dot"></div><div class="typing-dot"></div><div class="typing-dot"></div></div>`;
            if(chatContent) chatContent.scrollTop = chatContent.scrollHeight;

            setTimeout(() => {
                const typingEl = document.getElementById(typingId);
                if(typingEl) typingEl.remove();
                
                this.appendMessage(text, 'bot');
                
                if (isIntro) {
                    setTimeout(() => { 
                        const faqWrapper = document.getElementById('faq-wrapper');
                        const caresQuestions = document.getElementById('cares-questions');
                        const toggleBtn = document.getElementById('faq-toggle-btn');
                        
                        if(faqWrapper) faqWrapper.style.display = 'block'; 
                        if(caresQuestions) caresQuestions.style.display = 'none'; // Suggestions hidden by default
                        if(toggleBtn) toggleBtn.innerHTML = '<i class="fas fa-chevron-up"></i> Show Suggestions';
                        
                        if(chatContent) chatContent.scrollTop = chatContent.scrollHeight;
                    }, 500);
                }
            }, 1200);
        }
    };
    // ----------------------------

    let slideIndex = 1;
    let slideInterval;
    
    function showSlides(n) {
        let i;
        let slides = document.getElementsByClassName("carousel-slide");
        let dots = document.getElementsByClassName("dot");
        if(slides.length === 0) return;
        
        if (n > slides.length) {slideIndex = 1}
        if (n < 1) {slideIndex = slides.length}
        for (i = 0; i < slides.length; i++) {
            slides[i].classList.remove("active");
        }
        for (i = 0; i < dots.length; i++) {
            dots[i].classList.remove("active");
        }
        slides[slideIndex-1].classList.add("active");
        dots[slideIndex-1].classList.add("active");
    }

    function currentSlide(n) {
        clearInterval(slideInterval);
        showSlides(slideIndex = n);
        startCarousel();
    }
    
    function startCarousel() {
        slideInterval = setInterval(function() {
            slideIndex++;
            showSlides(slideIndex);
        }, 5000); 
    }

    document.addEventListener('DOMContentLoaded', () => {
        caresMascot.init();
        showSlides(slideIndex);
        startCarousel();

        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('show_aid') === 'true') {
            attemptAssistanceRequest();
        } else if (urlParams.get('show_track') === 'true') {
            showPage('track');
        }
    });
  </script>
</body>
</html>