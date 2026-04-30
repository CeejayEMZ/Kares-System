<?php
// admin/dashboard.php
session_start();
require_once '../config/db_connect.php'; 

// Force PHP to use Manila time
date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: ../auth/login.php");
    exit();
}

// --- NEW: AJAX Toggle for Business Hours ---
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'toggle_bh') {
    header('Content-Type: application/json');
    $settings_file = '../config/sys_settings.json';
    
    // Default to true (locked) if file doesn't exist yet
    $settings = ['business_hours' => true];
    if (file_exists($settings_file)) {
        $settings = json_decode(file_get_contents($settings_file), true);
    }
    
    // Toggle the boolean value
    $settings['business_hours'] = !$settings['business_hours'];
    file_put_contents($settings_file, json_encode($settings));

    echo json_encode(['success' => true, 'new_state' => $settings['business_hours']]);
    exit();
}
// -------------------------------------------

// Read current state for the UI Switch
$settings_file = '../config/sys_settings.json';
$bh_active = true;
if (file_exists($settings_file)) {
    $bh_active = json_decode(file_get_contents($settings_file), true)['business_hours'] ?? true;
}

// Helper function to safely convert Supabase UTC to Manila Time
function formatManilaTime($utc_string) {
    if (!$utc_string) return '---';
    try {
        $date = new DateTime($utc_string . ' UTC');
        $date->setTimezone(new DateTimeZone('Asia/Manila'));
        return $date->format('M j, Y g:i A'); // Outputs like "Apr 13, 2026 2:06 PM"
    } catch (Exception $e) {
        return $utc_string;
    }
}

// Request Stats
$total_req = $pdo->query("SELECT COUNT(*) FROM assistance_requests")->fetchColumn();
$pending_req = $pdo->query("SELECT COUNT(*) FROM assistance_requests WHERE status = 'Submitted'")->fetchColumn();
$declined_req = $pdo->query("SELECT COUNT(*) FROM assistance_requests WHERE status = 'Declined'")->fetchColumn();
$approved_req = $pdo->query("SELECT COUNT(*) FROM assistance_requests WHERE status = 'Approved'")->fetchColumn();
$released_req = $pdo->query("SELECT COUNT(*) FROM assistance_requests WHERE status = 'Released'")->fetchColumn();

// Citizen Stats
$total_users = $pdo->query("SELECT COUNT(*) FROM users WHERE role != 'Admin'")->fetchColumn();
$verified_users = $pdo->query("SELECT COUNT(*) FROM users WHERE role != 'Admin' AND is_verified = TRUE")->fetchColumn();
$unverified_users = $total_users - $verified_users;
$pending_verifs = $pdo->query("SELECT COUNT(*) FROM user_verifications WHERE status = 'Pending'")->fetchColumn(); // NEW QUERY

$recent_requests = $pdo->query("SELECT request_id, first_name, last_name, assistance_type, status, date_submitted FROM assistance_requests ORDER BY date_submitted DESC LIMIT 5")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - KARES</title>
    <link rel="icon" type="image/png" href="../assets/images/kareslogo.png" />
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style> * { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; } </style>
</head>
<body class="bg-gray-50 flex">

    <?php include 'includes/sidebar.php'; ?>

    <div class="flex-1 ml-64 p-10 min-h-screen">
        
        <div class="flex justify-between items-center mb-8">
            <div class="flex items-center gap-5">
                <div class="flex -space-x-3">
                    <img src="../assets/images/Rosario.png" onerror="this.style.display='none'" alt="Barangay Seal" class="w-16 h-16 rounded-full border-2 border-white shadow-md relative z-10 bg-white object-cover">
                    <img src="../assets/images/pateros.jpg" onerror="this.style.display='none'" alt="Pateros Seal" class="w-16 h-16 rounded-full border-2 border-white shadow-md relative z-0 bg-white object-cover">
                </div>
                <div>
                    <h1 class="text-3xl font-bold text-[#3d143e]">Dashboard Overview</h1>
                    <p class="text-gray-500 mt-1 font-medium">Welcome back, <?= htmlspecialchars($_SESSION['username']) ?></p>
                </div>
            </div>
            
            <div class="flex flex-col items-end gap-3">
                <div class="bg-white px-5 py-2.5 rounded-xl shadow-sm border border-gray-100 flex items-center gap-3 w-full justify-between">
                    <i class="far fa-calendar-alt text-[#c6943a]"></i>
                    <span class="text-sm font-bold text-[#3d143e]"><?= date('F j, Y') ?></span>
                </div>
                
                <div class="bg-white px-4 py-2.5 rounded-xl shadow-sm border border-gray-100 flex items-center gap-4 cursor-pointer hover:bg-gray-50 transition w-full justify-between" onclick="toggleBusinessHours()">
                    <span class="text-[11px] font-bold text-gray-500 uppercase tracking-widest"><i class="fas fa-lock mr-1"></i> Business Hours Lock</span>
                    <div id="bh-toggle-bg" class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors <?= $bh_active ? 'bg-green-500' : 'bg-gray-300' ?>">
                        <span id="bh-toggle-dot" class="inline-block h-4 w-4 transform rounded-full bg-white shadow-sm transition-transform <?= $bh_active ? 'translate-x-6' : 'translate-x-1' ?>"></span>
                    </div>
                </div>
            </div>
        </div>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'StatusUpdated'): ?>
            <div id="success-alert" class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-6 shadow-sm transition-opacity duration-500">
                <strong class="font-bold">Success!</strong><span class="block sm:inline"> The request status has been updated.</span>
            </div>
            <script>
                setTimeout(() => {
                    const alert = document.getElementById('success-alert');
                    if (alert) {
                        alert.classList.add('opacity-0');
                        setTimeout(() => alert.remove(), 500);
                    }
                }, 5000);
            </script>
        <?php endif; ?>

        <div id="toast-message" class="fixed top-10 right-10 bg-[#3d143e] text-white px-6 py-3 rounded-full shadow-2xl z-[9999] opacity-0 transition-opacity duration-300 pointer-events-none font-bold text-sm">
            Status message
        </div>

        <h2 class="text-lg font-bold text-[#3d143e] mb-3"><i class="fas fa-file-invoice text-[#c6943a] mr-2"></i> Assistance Requests</h2>
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-8">
            <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100 text-center border-t-4 border-gray-400">
                <p class="text-gray-500 text-xs font-bold uppercase mb-1 tracking-widest">Total Request</p>
                <h3 class="text-3xl font-black text-gray-800"><?= $total_req ?></h3>
            </div>
            <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100 text-center border-t-4 border-green-400">
                <p class="text-gray-500 text-xs font-bold uppercase mb-1 tracking-widest">Approved</p>
                <h3 class="text-3xl font-black text-green-600"><?= $approved_req ?></h3>
            </div>
            <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100 text-center border-t-4 border-yellow-400">
                <p class="text-gray-500 text-xs font-bold uppercase mb-1 tracking-widest">Pending</p>
                <h3 class="text-3xl font-black text-yellow-600"><?= $pending_req ?></h3>
            </div>
            <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100 text-center border-t-4 border-red-400">
                <p class="text-gray-500 text-xs font-bold uppercase mb-1 tracking-widest">Declined</p>
                <h3 class="text-3xl font-black text-red-500"><?= $declined_req ?></h3>
            </div>
            <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100 text-center border-t-4 border-purple-500">
                <p class="text-gray-500 text-xs font-bold uppercase mb-1 tracking-widest">Released</p>
                <h3 class="text-3xl font-black text-purple-600"><?= $released_req ?></h3>
            </div>
        </div>

        <h2 class="text-lg font-bold text-[#3d143e] mb-3"><i class="fas fa-users text-[#c6943a] mr-2"></i> Registered Citizens</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-10">
            <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100 flex items-center justify-between">
                <div>
                    <p class="text-gray-500 text-xs font-bold uppercase mb-1 tracking-widest">Total Users</p>
                    <h3 class="text-2xl font-black text-[#3d143e]"><?= $total_users ?></h3>
                </div>
                <div class="w-12 h-12 rounded-full bg-gray-100 flex items-center justify-center text-gray-500 text-xl"><i class="fas fa-user-friends"></i></div>
            </div>
            <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100 flex items-center justify-between">
                <div>
                    <p class="text-gray-500 text-xs font-bold uppercase mb-1 tracking-widest">Verified Accounts</p>
                    <h3 class="text-2xl font-black text-blue-600"><?= $verified_users ?></h3>
                </div>
                <div class="w-12 h-12 rounded-full bg-blue-50 flex items-center justify-center text-blue-500 text-xl"><i class="fas fa-user-check"></i></div>
            </div>
            <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100 flex items-center justify-between">
                <div>
                    <p class="text-gray-500 text-xs font-bold uppercase mb-1 tracking-widest">Unverified</p>
                    <h3 class="text-2xl font-black text-orange-500"><?= $unverified_users ?></h3>
                </div>
                <div class="w-12 h-12 rounded-full bg-orange-50 flex items-center justify-center text-orange-500 text-xl"><i class="fas fa-user-clock"></i></div>
            </div>
            <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100 flex items-center justify-between">
                <div>
                    <p class="text-gray-500 text-xs font-bold uppercase mb-1 tracking-widest">Pending Verifs</p>
                    <h3 class="text-2xl font-black text-yellow-600"><?= $pending_verifs ?></h3>
                </div>
                <div class="w-12 h-12 rounded-full bg-yellow-50 flex items-center justify-center text-yellow-500 text-xl"><i class="fas fa-id-card-clip"></i></div>
            </div>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
                <h2 class="text-lg font-bold text-[#3d143e]">Recent Submissions</h2>
                <a href="approval.php" class="text-sm text-[#5b8fb0] font-bold hover:underline bg-blue-50 px-4 py-1.5 rounded-full border border-blue-100">View Needs Action</a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="text-gray-400 text-xs uppercase bg-white border-b border-gray-100">
                            <th class="px-6 py-4 font-bold">Request ID</th>
                            <th class="px-6 py-4 font-bold">Citizen Name</th>
                            <th class="px-6 py-4 font-bold">Assistance Type</th>
                            <th class="px-6 py-4 font-bold">Date Submitted</th>
                            <th class="px-6 py-4 font-bold">Status</th>
                            <th class="px-6 py-4 font-bold text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody class="text-sm">
                        <?php if (count($recent_requests) > 0): ?>
                            <?php foreach ($recent_requests as $row): ?>
                                <tr class="border-b border-gray-50 hover:bg-gray-50 transition">
                                    <td class="px-6 py-4 font-bold text-[#5b8fb0]"><?= htmlspecialchars($row['request_id']) ?></td>
                                    <td class="px-6 py-4 font-bold text-gray-800 uppercase"><?= htmlspecialchars($row['last_name'] . ', ' . $row['first_name']) ?></td>
                                    <td class="px-6 py-4 text-gray-600"><span class="bg-gray-100 px-3 py-1 rounded-md text-xs font-semibold"><?= htmlspecialchars($row['assistance_type']) ?></span></td>
                                    <td class="px-6 py-4 text-gray-500 font-medium whitespace-nowrap"><i class="far fa-clock mr-1"></i> <?= formatManilaTime($row['date_submitted']) ?></td>
                                    <td class="px-6 py-4">
                                        <?php if ($row['status'] === 'Submitted'): ?>
                                            <span class="bg-yellow-50 text-yellow-600 px-3 py-1.5 rounded-full text-xs font-bold border border-yellow-200">Pending</span>
                                        <?php elseif ($row['status'] === 'Approved'): ?>
                                            <span class="bg-green-50 text-green-600 px-3 py-1.5 rounded-full text-xs font-bold border border-green-200">Approved</span>
                                        <?php elseif ($row['status'] === 'Released'): ?>
                                            <span class="bg-purple-50 text-purple-600 px-3 py-1.5 rounded-full text-xs font-bold border border-purple-200">Released</span>
                                        <?php else: ?>
                                            <span class="bg-red-50 text-red-600 px-3 py-1.5 rounded-full text-xs font-bold border border-red-200"><?= htmlspecialchars($row['status']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 text-center">
                                        <a href="view_request.php?id=<?= urlencode($row['request_id']) ?>" class="bg-[#c6943a] text-white px-5 py-2 rounded-full font-bold shadow hover:bg-yellow-600 transition text-xs inline-block">View</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="px-6 py-8 text-center text-gray-500 font-medium">No requests found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        function showToast(message) {
            const toast = document.getElementById('toast-message');
            toast.innerText = message;
            toast.style.opacity = '1';
            setTimeout(() => { toast.style.opacity = '0'; }, 3000);
        }

        async function toggleBusinessHours() {
            try {
                let formData = new FormData();
                formData.append('ajax_action', 'toggle_bh');
                
                let response = await fetch('dashboard.php', {
                    method: 'POST',
                    body: formData
                });
                let data = await response.json();
                
                if (data.success) {
                    const bg = document.getElementById('bh-toggle-bg');
                    const dot = document.getElementById('bh-toggle-dot');
                    
                    if (data.new_state) {
                        // Switch is ON (Green / Locked)
                        bg.classList.remove('bg-gray-300');
                        bg.classList.add('bg-green-500');
                        dot.classList.remove('translate-x-1');
                        dot.classList.add('translate-x-6');
                        showToast('Business hours lock ENABLED.');
                    } else {
                        // Switch is OFF (Gray / Unlocked 24/7)
                        bg.classList.remove('bg-green-500');
                        bg.classList.add('bg-gray-300');
                        dot.classList.remove('translate-x-6');
                        dot.classList.add('translate-x-1');
                        showToast('Business hours lock DISABLED (24/7 Access).');
                    }
                }
            } catch (e) {
                alert('Failed to update business hours setting. Check server permissions.');
            }
        }
    </script>
</body>
</html>