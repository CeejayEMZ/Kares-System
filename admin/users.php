<?php
session_start();
require_once '../config/db_connect.php';

// Force PHP to use Manila time
date_default_timezone_set('Asia/Manila');

// Check if user is logged in and is an Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: ../auth/login.php");
    exit();
}

function formatManilaTime($utc_string) {
    if (!$utc_string) return '---';
    try {
        $date = new DateTime($utc_string, new DateTimeZone('UTC')); 
        $date->setTimezone(new DateTimeZone('Asia/Manila'));
        return $date->format('M j, Y g:i A'); 
    } catch (Exception $e) {
        return date('M j, Y', strtotime($utc_string)); 
    }
}

// --- NEW: AJAX ENDPOINT FOR HISTORY MODAL ---
// This grabs the data in the background so the page never has to reload!
if (isset($_GET['ajax_history']) && isset($_GET['user_id'])) {
    header('Content-Type: application/json');
    $uid = $_GET['user_id'];
    
    try {
        // Get the specific user's name OR username
        $name_stmt = $pdo->prepare("SELECT first_name, last_name, username FROM users WHERE id = ?");
        $name_stmt->execute([$uid]);
        $u_data = $name_stmt->fetch();
        
        $name = 'Unknown';
        if ($u_data) {
            $fullName = trim($u_data['first_name'] . ' ' . $u_data['last_name']);
            if (!empty($fullName)) {
                $name = $fullName;
            } elseif (!empty($u_data['username'])) {
                $name = $u_data['username'];
            }
        }

        // Get their entire request history
        $history_stmt = $pdo->prepare("SELECT request_id, assistance_type, status, date_submitted FROM assistance_requests WHERE user_id = ? ORDER BY date_submitted DESC");
        $history_stmt->execute([$uid]);
        $history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format dates for JSON output
        foreach ($history as &$req) {
            $req['date_formatted'] = formatManilaTime($req['date_submitted']);
        }

        echo json_encode(['success' => true, 'name' => $name, 'history' => $history]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit(); // Stop the rest of the page from loading since it's just a background request
}
// ------------------------------------------

$search = $_GET['search'] ?? '';

// Fetch users, with optional search filter
try {
    if (!empty($search)) {
        // Included 'username' in the SELECT and the LIKE search
        $stmt = $pdo->prepare("SELECT id, username, email, first_name, last_name, is_verified FROM users WHERE first_name LIKE :search OR last_name LIKE :search OR email LIKE :search OR username LIKE :search ORDER BY id DESC");
        $stmt->execute([':search' => '%' . $search . '%']);
    } else {
        // Included 'username' in the SELECT
        $stmt = $pdo->prepare("SELECT id, username, email, first_name, last_name, is_verified FROM users ORDER BY id DESC");
        $stmt->execute();
    }
    $users = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Error fetching users: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Citizens - KARES Admin</title>
    <link rel="icon" type="image/png" href="../assets/images/kareslogo.png" />
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style> 
        * { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; } 
        /* Custom scrollbar for modal */
        .custom-scrollbar::-webkit-scrollbar { width: 8px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #CCBFD5; border-radius: 10px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #a462a9; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .animate-fade { animation: fadeIn 0.3s ease forwards; }
    </style>
</head>
<body class="bg-[#e0d5e8] flex relative"> 
    <?php include 'includes/sidebar.php'; ?>

    <div class="flex-1 ml-64 p-10 min-h-screen">
        
        <div class="flex flex-row justify-between items-start mb-8">
            <div>
                <h1 class="text-3xl font-bold text-[#3d143e] mb-2">Manage Citizens</h1>
                <p class="text-gray-500 font-medium">Review registered accounts, verify citizen status, or view their request history.</p>
            </div>
            
            <form method="GET" action="users.php" class="flex items-center bg-white p-2 rounded-2xl shadow-sm border border-gray-100">
                <div class="flex gap-2">
                    <?php if(!empty($search)): ?>
                        <a href="users.php" class="bg-red-50 text-red-500 border border-red-200 px-5 py-2 rounded-lg font-bold shadow-sm hover:bg-red-500 hover:text-white transition flex items-center mr-1"><i class="fas fa-times"></i></a>
                    <?php endif; ?>
                    <div class="relative">
                        <i class="fas fa-search absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search name or email..." class="pl-11 pr-4 py-2.5 rounded-lg border border-gray-200 bg-gray-50 shadow-inner focus:ring-2 focus:ring-[#5b8fb0] outline-none w-64 md:w-80 text-sm text-gray-700 transition">
                    </div>
                    <button type="submit" class="bg-[#5b8fb0] text-white px-6 py-2.5 rounded-lg shadow-md hover:bg-[#4a7694] transition font-bold">Search</button>
                </div>
            </form>
        </div>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'Success'): ?>
            <div id="success-alert" class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-6 shadow-sm transition-opacity duration-500">
                <strong class="font-bold">Success!</strong><span class="block sm:inline"> User verification status has been updated.</span>
            </div>
            <script>setTimeout(() => { const alert = document.getElementById('success-alert'); if (alert) { alert.classList.add('opacity-0'); setTimeout(() => alert.remove(), 500); } }, 5000);</script>
        <?php endif; ?>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'Deleted'): ?>
            <div id="delete-alert" class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-6 shadow-sm transition-opacity duration-500">
                <strong class="font-bold">Deleted!</strong><span class="block sm:inline"> User and all associated data have been permanently removed.</span>
            </div>
            <script>setTimeout(() => { const alert = document.getElementById('delete-alert'); if (alert) { alert.classList.add('opacity-0'); setTimeout(() => alert.remove(), 500); } }, 5000);</script>
        <?php endif; ?>
        
        <?php if (isset($_GET['error'])): ?>
            <div id="error-alert" class="bg-orange-100 border border-orange-400 text-orange-700 px-4 py-3 rounded relative mb-6 shadow-sm transition-opacity duration-500">
                <strong class="font-bold">Notice:</strong><span class="block sm:inline"> The deletion process could not be completed. (Code: <?= htmlspecialchars($_GET['error']) ?>)</span>
            </div>
            <script>setTimeout(() => { const alert = document.getElementById('error-alert'); if (alert) { alert.classList.add('opacity-0'); setTimeout(() => alert.remove(), 500); } }, 5000);</script>
        <?php endif; ?>

        <div class="bg-white rounded-3xl shadow-lg border border-gray-100 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="text-gray-500 text-sm border-b border-gray-100 bg-gray-50/50">
                            <th class="px-6 py-4 font-bold uppercase tracking-wider">Name</th>
                            <th class="px-6 py-4 font-bold uppercase tracking-wider">Email</th>
                            <th class="px-6 py-4 font-bold uppercase tracking-wider">Verification Status</th>
                            <th class="px-6 py-4 font-bold text-center uppercase tracking-wider">Action</th>
                        </tr>
                    </thead>
                    <tbody class="text-sm divide-y divide-gray-50">
                        <?php if (count($users) > 0): ?>
                            <?php foreach ($users as $user): ?>
                                <tr class="hover:bg-gray-50 transition">
                                    <td class="px-6 py-4 font-bold text-gray-800 capitalize">
                                        <?php 
                                            $fullName = trim($user['first_name'] . ' ' . $user['last_name']);
                                            if (!empty($fullName)) {
                                                echo htmlspecialchars($fullName);
                                            } elseif (!empty($user['username'])) {
                                                echo htmlspecialchars($user['username']);
                                            } else {
                                                echo '<span class="text-gray-400 italic">No Name Set</span>';
                                            }
                                        ?>
                                    </td>
                                    
                                    <td class="px-6 py-4 text-[#5b8fb0] font-medium"><?= htmlspecialchars($user['email']) ?></td>
                                    <td class="px-6 py-4 font-bold">
                                        <?php if ($user['is_verified']): ?>
                                            <span class="text-green-600 bg-green-50 px-3 py-1.5 rounded-full text-xs border border-green-200"><i class="fas fa-shield-check mr-1"></i> Verified</span>
                                        <?php else: ?>
                                            <span class="text-orange-600 bg-orange-50 px-3 py-1.5 rounded-full text-xs border border-orange-200"><i class="fas fa-clock mr-1"></i> Unverified</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 text-center">
                                        <div class="flex items-center justify-center gap-2">
                                            
                                            <button onclick="openHistoryModal(<?= $user['id'] ?>)" class="bg-blue-50 text-blue-600 border border-blue-200 px-4 py-2 rounded-full font-bold hover:bg-blue-500 hover:text-white transition shadow-sm text-xs flex items-center gap-1.5">
                                                <i class="fas fa-history"></i> History
                                            </button>

                                            <?php if ($user['is_verified']): ?>
                                                <a href="../processors/verify_user.php?id=<?= urlencode($user['id']) ?>&action=revoke" onclick="return confirm('Revoke verification for this user?')" class="border border-red-500 text-red-500 px-5 py-2 rounded-full font-bold shadow-sm hover:bg-red-50 transition text-xs inline-block">Revoke</a>
                                            <?php else: ?>
                                                <a href="../processors/verify_user.php?id=<?= urlencode($user['id']) ?>&action=verify" onclick="return confirm('Officially verify this citizen?')" class="bg-[#c6943a] text-white px-5 py-2 rounded-full font-bold shadow hover:bg-yellow-600 transition text-xs inline-block">Verify User</a>
                                            <?php endif; ?>

                                            <form action="delete_citizen.php" method="POST" onsubmit="return confirm('Are you sure you want to completely delete this user and all their records? This cannot be undone.');" class="inline-block m-0">
                                                <input type="hidden" name="user_id" value="<?= htmlspecialchars($user['id']) ?>">
                                                <button type="submit" class="bg-red-100 text-red-600 border border-red-200 px-3 py-2 rounded-full font-bold hover:bg-red-500 hover:text-white transition shadow-sm text-xs flex items-center justify-center">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="4" class="px-6 py-12 text-center text-gray-500 font-medium">No citizens found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="history-modal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-sm z-[999] flex items-center justify-center p-4 animate-fade">
        <div class="bg-white w-full max-w-4xl rounded-[30px] shadow-2xl overflow-hidden flex flex-col max-h-[85vh]">
            <div class="bg-[#3d143e] p-6 flex justify-between items-center text-white shrink-0">
                <div>
                    <h2 class="text-xl font-bold flex items-center gap-3"><i class="fas fa-list-alt text-[#c6943a]"></i> Assistance History</h2>
                    <p class="text-sm text-gray-300 mt-1 capitalize font-medium" id="modal-citizen-name">Citizen: ---</p>
                </div>
                <button onclick="closeHistoryModal()" class="text-white/60 hover:text-white text-3xl transition"><i class="fas fa-times-circle"></i></button>
            </div>
            
            <div class="p-0 overflow-y-auto flex-1 custom-scrollbar bg-gray-50" id="modal-history-content">
                </div>
            
            <div class="bg-white border-t border-gray-200 p-4 shrink-0 flex justify-end">
                <button onclick="closeHistoryModal()" class="bg-gray-100 text-gray-700 border border-gray-300 px-6 py-2.5 rounded-full font-bold hover:bg-gray-200 transition text-sm">Close</button>
            </div>
        </div>
    </div>

    <script>
        async function openHistoryModal(userId) {
            const modal = document.getElementById('history-modal');
            const content = document.getElementById('modal-history-content');
            const nameEl = document.getElementById('modal-citizen-name');
            
            // Show loading state immediately
            nameEl.innerText = "Citizen: Loading...";
            content.innerHTML = '<div class="p-12 text-center"><i class="fas fa-spinner fa-spin text-3xl text-[#5b8fb0]"></i><p class="mt-4 text-gray-500 font-bold">Fetching history from database...</p></div>';
            modal.classList.remove('hidden');

            try {
                // Fetch data in the background
                const response = await fetch(`users.php?ajax_history=1&user_id=${userId}`);
                const data = await response.json();

                if (data.success) {
                    nameEl.innerText = `Citizen: ${data.name}`;
                    
                    if (data.history.length > 0) {
                        let rows = '';
                        data.history.forEach(req => {
                            let status = req.status;
                            let badgeClass = 'bg-yellow-50 text-yellow-600 border-yellow-200';
                            if (status === 'Approved') badgeClass = 'bg-green-50 text-green-600 border-green-200';
                            else if (status === 'Released') badgeClass = 'bg-purple-50 text-purple-600 border-purple-200';
                            else if (status === 'Declined') badgeClass = 'bg-red-50 text-red-600 border-red-200';

                            rows += `
                                <tr class="hover:bg-white transition">
                                    <td class="px-6 py-4 font-bold text-[#3d143e]">${req.request_id}</td>
                                    <td class="px-6 py-4 text-gray-700 font-medium">${req.assistance_type}</td>
                                    <td class="px-6 py-4">
                                        <span class="px-3 py-1 rounded-full text-[11px] font-bold border ${badgeClass} uppercase tracking-wide">${status}</span>
                                    </td>
                                    <td class="px-6 py-4 text-gray-500 font-medium">${req.date_formatted}</td>
                                </tr>
                            `;
                        });

                        content.innerHTML = `
                            <table class="w-full text-left">
                                <thead class="bg-white sticky top-0 shadow-sm z-10">
                                    <tr class="text-gray-400 text-[11px] uppercase tracking-wider border-b border-gray-200">
                                        <th class="px-6 py-4 font-bold">Request ID</th>
                                        <th class="px-6 py-4 font-bold">Assistance Type</th>
                                        <th class="px-6 py-4 font-bold">Status</th>
                                        <th class="px-6 py-4 font-bold">Date Submitted</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 text-sm">
                                    ${rows}
                                </tbody>
                            </table>
                        `;
                    } else {
                        content.innerHTML = `
                            <div class="p-12 text-center flex flex-col items-center justify-center">
                                <div class="w-20 h-20 bg-gray-200 rounded-full flex items-center justify-center mb-4">
                                    <i class="fas fa-folder-open text-3xl text-gray-400"></i>
                                </div>
                                <h3 class="text-lg font-bold text-gray-700">No History Found</h3>
                                <p class="text-sm text-gray-500 mt-1">This citizen has not submitted any assistance requests yet.</p>
                            </div>
                        `;
                    }
                } else {
                    content.innerHTML = `<div class="p-12 text-center text-red-500 font-bold">Error: ${data.error}</div>`;
                }
            } catch (e) {
                content.innerHTML = `<div class="p-12 text-center text-red-500 font-bold">Failed to connect to the database.</div>`;
            }
        }

        function closeHistoryModal() {
            document.getElementById('history-modal').classList.add('hidden');
        }
    </script>
</body>
</html>