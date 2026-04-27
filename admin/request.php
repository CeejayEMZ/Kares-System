<?php
// admin/request.php
session_start();
require_once '../config/db_connect.php'; 

// Force PHP to use Manila time
date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: ../auth/login.php");
    exit();
}

// Helper function to safely convert Supabase UTC to Manila Time
function formatManilaTime($utc_string) {
    if (!$utc_string) return '---';
    try {
        $date = new DateTime($utc_string . ' UTC');
        $date->setTimezone(new DateTimeZone('Asia/Manila'));
        return $date->format('M j, Y g:i A'); 
    } catch (Exception $e) {
        return $utc_string;
    }
}

// --- NEW: FETCH FOLLOW-UP NOTIFICATIONS ---
try {
    $notif_stmt = $pdo->prepare("
        SELECT n.*, u.email 
        FROM admin_notifications n
        JOIN users u ON n.user_id = u.id
        ORDER BY n.created_at DESC 
        LIMIT 10
    ");
    $notif_stmt->execute();
    $admin_notifs = $notif_stmt->fetchAll(PDO::FETCH_ASSOC);

    $unread_stmt = $pdo->query("SELECT COUNT(*) FROM admin_notifications WHERE is_read = FALSE");
    $unread_admin_count = $unread_stmt->fetchColumn();
} catch (PDOException $e) {
    $admin_notifs = [];
    $unread_admin_count = 0;
}
// ------------------------------------------

$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? 'All';

// Pagination setup
$limit = 5; // Max requests per page
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// Base query logic
$where_sql = "1=1";
$params = [];

// OMNI-SEARCH: Checks ID, Names, Full Name, Type, and Status
if (!empty($search)) {
    $where_sql .= " AND (
        request_id LIKE :search 
        OR first_name LIKE :search 
        OR last_name LIKE :search 
        OR CONCAT(first_name, ' ', last_name) LIKE :search
        OR assistance_type LIKE :search
        OR status LIKE :search
        OR CAST(date_submitted AS TEXT) LIKE :search
    )";
    $params[':search'] = "%$search%";
}

if ($status_filter !== 'All') {
    $where_sql .= " AND status = :status";
    $params[':status'] = $status_filter;
}

// 1. Get TOTAL count for Pagination math
$count_query = "SELECT COUNT(*) FROM assistance_requests WHERE $where_sql";
$count_stmt = $pdo->prepare($count_query);
$count_stmt->execute($params);
$total_records = $count_stmt->fetchColumn();
$total_pages = ceil($total_records / $limit);

// 2. Get actual records with LIMIT and OFFSET
$query = "SELECT * FROM assistance_requests WHERE $where_sql ORDER BY date_submitted DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$requests = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Requests - KARES Admin</title>
    <link rel="icon" type="image/png" href="../assets/images/kareslogo.png" />
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style> 
        * { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; } 
        /* Custom scrollbar for notifications */
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 8px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #c6943a; border-radius: 8px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #a462a9; }
    </style>
</head>
<body class="bg-[#e0d5e8] flex"> 

    <?php include 'includes/sidebar.php'; ?>

    <div class="flex-1 ml-64 p-10 min-h-screen">
        <div class="flex justify-between items-start mb-6">
            <div>
                <h1 class="text-3xl font-bold text-[#3d143e] mb-2">Request Management</h1>
                <p class="text-gray-500 font-medium">View and filter all citizen assistance records.</p>
            </div>
        </div>
        
        <form method="GET" action="request.php" class="flex justify-between items-center mb-8 w-full bg-white p-4 rounded-2xl shadow-sm border border-gray-100 relative">
            
            <div class="flex gap-4">
                <select name="status" class="px-4 py-2.5 rounded-lg border border-gray-200 bg-gray-50 text-gray-700 outline-none focus:ring-2 focus:ring-[#c6943a] font-medium cursor-pointer" onchange="this.form.submit()">
                    <option value="All" <?= $status_filter == 'All' ? 'selected' : '' ?>>All Statuses</option>
                    <option value="Submitted" <?= $status_filter == 'Submitted' ? 'selected' : '' ?>>Pending</option>
                    <option value="Approved" <?= $status_filter == 'Approved' ? 'selected' : '' ?>>Approved</option>
                    <option value="Released" <?= $status_filter == 'Released' ? 'selected' : '' ?>>Released</option>
                    <option value="Declined" <?= $status_filter == 'Declined' ? 'selected' : '' ?>>Declined</option>
                </select>

                <?php if(!empty($search) || $status_filter !== 'All'): ?>
                    <a href="request.php" class="bg-red-50 text-red-500 border border-red-200 px-5 py-2.5 rounded-lg font-bold shadow-sm hover:bg-red-500 hover:text-white transition flex items-center">
                        <i class="fas fa-times mr-2"></i> Clear
                    </a>
                <?php endif; ?>
            </div>

            <div class="flex items-center gap-4">
                
                <div class="relative group">
                    <button type="button" onclick="toggleAdminNotifs()" class="relative text-gray-400 hover:text-[#c6943a] transition text-xl p-2 focus:outline-none">
                        <i class="fas fa-bell"></i>
                        <?php if ($unread_admin_count > 0): ?>
                            <span id="admin-notif-badge" class="absolute top-0 right-0 bg-red-500 text-white text-[10px] font-bold w-4 h-4 rounded-full flex items-center justify-center border-2 border-white shadow-sm">
                                <?= $unread_admin_count ?>
                            </span>
                        <?php endif; ?>
                    </button>
                    
                    <div id="admin-notif-dropdown" class="hidden absolute top-full right-0 mt-2 w-80 bg-white rounded-2xl shadow-2xl border border-gray-200 overflow-hidden z-50 transform origin-top-right transition-all">
                        <div class="bg-[#3d143e] text-white px-4 py-3 flex justify-between items-center">
                            <h3 class="font-bold text-sm"><i class="fas fa-inbox mr-2 text-[#c6943a]"></i>Follow-up Alerts</h3>
                            <?php if ($unread_admin_count > 0): ?>
                                <span class="bg-[#c6943a] text-white text-[10px] font-bold px-2 py-0.5 rounded-full"><?= $unread_admin_count ?> New</span>
                            <?php endif; ?>
                        </div>
                        <div class="max-h-80 overflow-y-auto custom-scrollbar bg-gray-50 flex flex-col">
                            <?php if (count($admin_notifs) > 0): ?>
                                <?php foreach ($admin_notifs as $n): ?>
                                    <a href="view_request.php?id=<?= urlencode($n['request_id']) ?>" class="block p-4 border-b border-gray-100 hover:bg-blue-50 transition <?= $n['is_read'] ? 'opacity-70 bg-white' : 'bg-blue-50/50 border-l-4 border-l-blue-500' ?>">
                                        <div class="flex justify-between items-start mb-1">
                                            <h4 class="text-[#3d143e] font-bold text-sm">ID: <?= htmlspecialchars($n['request_id']) ?></h4>
                                            <span class="text-gray-400 text-[10px] whitespace-nowrap ml-2"><?= formatManilaTime($n['created_at']) ?></span>
                                        </div>
                                        <p class="text-gray-800 text-xs font-bold mb-1"><i class="fas fa-envelope mr-1 text-[#c6943a]"></i> <?= htmlspecialchars($n['email']) ?></p>
                                        
                                        <?php 
                                            // Securely encode the message first
                                            $safe_message = htmlspecialchars($n['message']);
                                            // Find whatever is inside the brackets [...] and make it bold green!
                                            $highlighted_message = preg_replace('/\[(.*?)\]/', '<span class="text-green-600 font-bold">$1</span>', $safe_message);
                                        ?>
                                        <p class="text-gray-500 text-xs italic leading-relaxed">"<?= $highlighted_message ?>"</p>
                                    </a>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="p-8 text-center text-gray-400">
                                    <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-3">
                                        <i class="fas fa-check-double text-2xl text-gray-300"></i>
                                    </div>
                                    <p class="text-sm font-bold text-gray-500">All caught up!</p>
                                    <p class="text-xs mt-1">No follow-up requests pending.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="flex gap-2">
                    <div class="relative">
                        <i class="fas fa-search absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search anything..." class="pl-11 pr-4 py-2.5 rounded-lg border border-gray-200 bg-gray-50 shadow-inner focus:ring-2 focus:ring-[#5b8fb0] outline-none w-64 md:w-80 text-sm text-gray-700 transition">
                    </div>
                    <button type="submit" class="bg-[#5b8fb0] text-white px-6 py-2.5 rounded-lg shadow-md hover:bg-[#4a7694] transition font-bold">
                        Search
                    </button>
                </div>
            </div>

        </form>

        <div class="bg-white rounded-3xl shadow-lg border border-gray-100 overflow-hidden flex flex-col">
            <div class="overflow-x-auto flex-1">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="text-gray-500 text-sm border-b border-gray-100 bg-gray-50/50">
                            <th class="px-6 py-4 font-bold uppercase tracking-wider">Request ID</th>
                            <th class="px-6 py-4 font-bold uppercase tracking-wider">Name</th>
                            <th class="px-6 py-4 font-bold uppercase tracking-wider">Type of Assistance</th>
                            <th class="px-6 py-4 font-bold uppercase tracking-wider">Date</th>
                            <th class="px-6 py-4 font-bold uppercase tracking-wider">Status</th>
                            <th class="px-6 py-4 font-bold text-center uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="text-sm divide-y divide-gray-50">
                        <?php if (count($requests) > 0): ?>
                            <?php foreach ($requests as $row): ?>
                                <tr class="hover:bg-gray-50 transition">
                                    <td class="px-6 py-4 text-[#5b8fb0] font-bold"><?= htmlspecialchars($row['request_id']) ?></td>
                                    <td class="px-6 py-4 font-bold text-gray-800 uppercase"><?= htmlspecialchars($row['last_name'] . ', ' . $row['first_name']) ?></td>
                                    <td class="px-6 py-4 text-gray-600 font-medium"><?= htmlspecialchars($row['assistance_type']) ?></td>
                                    <td class="px-6 py-4 text-gray-500 font-medium whitespace-nowrap"><i class="far fa-clock mr-1"></i> <?= formatManilaTime($row['date_submitted']) ?></td>
                                    <td class="px-6 py-4 font-bold">
                                        <?php if ($row['status'] === 'Submitted'): ?>
                                            <span class="text-yellow-600 bg-yellow-50 px-3 py-1.5 rounded-full text-xs border border-yellow-200">Pending</span>
                                        <?php elseif ($row['status'] === 'Approved'): ?>
                                            <span class="text-green-600 bg-green-50 px-3 py-1.5 rounded-full text-xs border border-green-200">Approved</span>
                                        <?php elseif ($row['status'] === 'Released'): ?>
                                            <span class="text-purple-600 bg-purple-50 px-3 py-1.5 rounded-full text-xs border border-purple-200">Released</span>
                                        <?php else: ?>
                                            <span class="text-red-600 bg-red-50 px-3 py-1.5 rounded-full text-xs border border-red-200">Declined</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 text-center">
                                        <a href="view_request.php?id=<?= urlencode($row['request_id']) ?>" class="bg-[#c6943a] text-white px-5 py-2 rounded-full font-bold shadow hover:bg-yellow-600 transition text-xs inline-flex items-center gap-1">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="px-6 py-12 text-center text-gray-500 font-medium"><i class="fas fa-search text-3xl mb-3 text-gray-300 block"></i> No requests found matching your search.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
            <div class="px-6 py-4 border-t border-gray-100 bg-gray-50/50 flex items-center justify-between">
                <p class="text-sm text-gray-500 font-medium">
                    Showing <span class="font-bold text-gray-700"><?= $offset + 1 ?></span> to <span class="font-bold text-gray-700"><?= min($offset + $limit, $total_records) ?></span> of <span class="font-bold text-[#3d143e]"><?= $total_records ?></span> results
                </p>
                <div class="flex gap-2">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status_filter) ?>" class="px-4 py-2 rounded-lg bg-white border border-gray-200 text-gray-600 hover:bg-gray-100 transition shadow-sm text-sm font-bold flex items-center gap-2">
                            <i class="fas fa-chevron-left text-xs"></i> Prev
                        </a>
                    <?php endif; ?>
                    
                    <div class="flex gap-1 hidden sm:flex">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status_filter) ?>" 
                               class="w-10 h-10 flex items-center justify-center rounded-lg border <?= $i === $page ? 'bg-[#c6943a] text-white border-[#c6943a] shadow-md' : 'bg-white border-gray-200 text-gray-600 hover:bg-gray-100 shadow-sm' ?> transition text-sm font-bold">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>
                    </div>

                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status_filter) ?>" class="px-4 py-2 rounded-lg bg-white border border-gray-200 text-gray-600 hover:bg-gray-100 transition shadow-sm text-sm font-bold flex items-center gap-2">
                            Next <i class="fas fa-chevron-right text-xs"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <script>
        function toggleAdminNotifs() {
            const dropdown = document.getElementById('admin-notif-dropdown');
            const badge = document.getElementById('admin-notif-badge');
            
            dropdown.classList.toggle('hidden');
            
            if (!dropdown.classList.contains('hidden') && badge) {
                // If they open it, instantly clear the red badge visually
                badge.style.display = 'none';
                
                // Send a silent request to the server to mark them as read
                fetch('../processors/mark_admin_notifs_read.php', { method: 'POST' });
            }
        }

        // Close dropdown if clicked outside
        document.addEventListener('click', function(event) {
            const dropdown = document.getElementById('admin-notif-dropdown');
            if (!dropdown) return;
            const isClickInside = dropdown.contains(event.target) || event.target.closest('button[onclick="toggleAdminNotifs()"]');
            
            if (!isClickInside && !dropdown.classList.contains('hidden')) {
                dropdown.classList.add('hidden');
            }
        });
    </script>
</body>
</html>