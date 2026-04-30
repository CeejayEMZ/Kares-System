<?php
// admin/reports.php
session_start();
require_once '../config/db_connect.php'; 

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: ../auth/login.php"); exit();
}
// --- AJAX HANDLER FOR DYNAMIC ACTIVITY FEED ---
if (isset($_GET['ajax']) && $_GET['ajax'] === 'activity') {
    header('Content-Type: application/json');
    
    $limit = 5; 
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    if ($page < 1) $page = 1;
    $offset = ($page - 1) * $limit;

    $total_activity_query = $pdo->query("SELECT SUM(cnt) FROM (SELECT COUNT(*) as cnt FROM assistance_requests UNION ALL SELECT COUNT(*) as cnt FROM user_verifications) as t");
    $total_activities = $total_activity_query->fetchColumn();
    $total_pages = ceil($total_activities / $limit);

    $recent_activity_stmt = $pdo->prepare("
        (SELECT 'Assistance Request' as type, a.assistance_type as detail, a.status, a.date_submitted as activity_date, u.email 
         FROM assistance_requests a 
         JOIN users u ON a.user_id::integer = u.id)
        UNION ALL
        (SELECT 'Account Verification' as type, v.id_type as detail, v.status, v.submitted_at as activity_date, u.email 
         FROM user_verifications v 
         JOIN users u ON v.user_id::integer = u.id)
        ORDER BY activity_date DESC 
        LIMIT :limit OFFSET :offset
    ");
    $recent_activity_stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $recent_activity_stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $recent_activity_stmt->execute();
    
    $activities = $recent_activity_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format the date in PHP so JS doesn't have to guess
    foreach($activities as &$act) {
        $act['formatted_date'] = date('M j, g:i A', strtotime($act['activity_date']));
    }

    echo json_encode([
        'activities' => $activities,
        'page' => $page,
        'total_pages' => $total_pages
    ]);
    exit();
}
// ----------------------------------------------

// 1. Overall Assistance Stats
$total = $pdo->query("SELECT COUNT(*) FROM assistance_requests")->fetchColumn();
$pending = $pdo->query("SELECT COUNT(*) FROM assistance_requests WHERE status = 'Submitted'")->fetchColumn();
$declined = $pdo->query("SELECT COUNT(*) FROM assistance_requests WHERE status = 'Declined'")->fetchColumn();
$approved = $pdo->query("SELECT COUNT(*) FROM assistance_requests WHERE status = 'Approved'")->fetchColumn();
$released = $pdo->query("SELECT COUNT(*) FROM assistance_requests WHERE status = 'Released'")->fetchColumn();

// 2. Citizen Stats
$total_users = $pdo->query("SELECT COUNT(*) FROM users WHERE role != 'Admin'")->fetchColumn();
$verified_users = $pdo->query("SELECT COUNT(*) FROM users WHERE role != 'Admin' AND is_verified = TRUE")->fetchColumn();
$unverified_users = $total_users - $verified_users;

// 3. Verification Specific Stats
$total_verifications = $pdo->query("SELECT COUNT(*) FROM user_verifications")->fetchColumn();
$pending_verifs = $pdo->query("SELECT COUNT(*) FROM user_verifications WHERE status = 'Pending'")->fetchColumn();
$rejected_verifs = $pdo->query("SELECT COUNT(*) FROM user_verifications WHERE status = 'Rejected'")->fetchColumn();
$edit_requests = $pdo->query("SELECT COUNT(*) FROM user_verifications WHERE status = 'Approved' AND edit_reason IS NOT NULL AND edit_reason != ''")->fetchColumn();

// 4. Monthly Performance Stats
$current_month = date('Y-m');
$stmt_month = $pdo->prepare("SELECT COUNT(*) FROM assistance_requests WHERE CAST(date_submitted AS TEXT) LIKE :ym");
$stmt_month->execute([':ym' => "$current_month%"]);
$month_total = $stmt_month->fetchColumn();

$stmt_month_app = $pdo->prepare("SELECT COUNT(*) FROM assistance_requests WHERE status IN ('Approved', 'Released') AND CAST(date_submitted AS TEXT) LIKE :ym");
$stmt_month_app->execute([':ym' => "$current_month%"]);
$month_approved = $stmt_month_app->fetchColumn();

$approval_rate = ($total > 0) ? round((($approved + $released) / $total) * 100) : 0;

// 5. Fetch Supabase Bucket Storage Size
$bucket_size = '0 Bytes';
$max_storage = '1GB'; 

try {
    $stmt_storage = $pdo->prepare("SELECT SUM((metadata->>'size')::bigint) AS total_bytes FROM storage.objects WHERE bucket_id = 'kares-uploads'");
    $stmt_storage->execute();
    $raw_bytes = $stmt_storage->fetchColumn();
    
    if ($raw_bytes) {
        if ($raw_bytes >= 1048576) {
            $formatted_size = round($raw_bytes / 1048576, 2) . ' MB';
        } elseif ($raw_bytes >= 1024) {
            $formatted_size = round($raw_bytes / 1024, 2) . ' KB';
        } else {
            $formatted_size = $raw_bytes . ' Bytes';
        }
        $bucket_size = $formatted_size . ' / ' . $max_storage;
    } else {
        $bucket_size = '0 MB / ' . $max_storage;
    }
} catch (PDOException $e) {
    $bucket_size = 'Unavailable'; 
}

// 6. Breakdown for Charts
$breakdown = $pdo->query("SELECT assistance_type, COUNT(*) as count FROM assistance_requests GROUP BY assistance_type ORDER BY count DESC")->fetchAll();
$type_labels = []; $type_data = [];
foreach($breakdown as $stat) {
    $parts = explode(' - ', $stat['assistance_type']);
    $type_labels[] = count($parts) > 1 ? trim($parts[1]) : $stat['assistance_type'];
    $type_data[] = $stat['count'];
}

// 7. Recent Activity Feed (WITH PAGINATION)
$limit = 5; // How many activities to show per page
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// First, get the total count of all activities to calculate total pages
$total_activity_query = $pdo->query("
    SELECT SUM(cnt) FROM (
        SELECT COUNT(*) as cnt FROM assistance_requests
        UNION ALL
        SELECT COUNT(*) as cnt FROM user_verifications
    ) as t
");
$total_activities = $total_activity_query->fetchColumn();
$total_pages = ceil($total_activities / $limit);

// Now fetch the actual limited records
$recent_activity_stmt = $pdo->prepare("
    (SELECT 'Assistance Request' as type, a.assistance_type as detail, a.status, a.date_submitted as activity_date, u.email 
     FROM assistance_requests a 
     JOIN users u ON a.user_id::integer = u.id)
    UNION ALL
    (SELECT 'Account Verification' as type, v.id_type as detail, v.status, v.submitted_at as activity_date, u.email 
     FROM user_verifications v 
     JOIN users u ON v.user_id::integer = u.id)
    ORDER BY activity_date DESC 
    LIMIT :limit OFFSET :offset
");
$recent_activity_stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$recent_activity_stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$recent_activity_stmt->execute();
$recent_activity = $recent_activity_stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Analytics & Reports - KARES Admin</title>
    <link rel="icon" type="image/png" href="../assets/images/kareslogo.png" />
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style> * { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; } </style>
</head>
<body class="bg-[#e0d5e8] flex">
    <?php include 'includes/sidebar.php'; ?>
    <div class="flex-1 ml-64 p-10 min-h-screen relative">
        
        <div class="mb-8 border-b border-gray-300 pb-6 flex justify-between items-end">
            <div>
                <h1 class="text-3xl font-bold text-[#3d143e]">System Analytics & Reports</h1>
                <p class="text-gray-600 font-medium mt-1">Visual breakdown of citizen assistance and system health.</p>
            </div>
            <button onclick="document.getElementById('reportModal').classList.remove('hidden')" class="bg-[#3d143e] text-white px-6 py-3 rounded-xl font-bold shadow hover:bg-purple-900 transition flex items-center hover:-translate-y-1 transform">
                <i class="fas fa-file-pdf mr-2 text-[#c6943a]"></i> Generate Official Report
            </button>
        </div>

        <h2 class="text-lg font-bold text-[#3d143e] mb-3"><i class="fas fa-calendar-alt text-[#c6943a] mr-2"></i> Monthly Performance (<?= date('F Y') ?>)</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
            <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100 flex items-center justify-between border-l-4 border-blue-400 hover:shadow-md transition">
                <div><p class="text-gray-500 text-xs font-bold uppercase mb-1 tracking-widest">Requests This Month</p><h3 class="text-2xl font-black text-blue-600"><?= $month_total ?></h3></div>
                <div class="w-12 h-12 bg-blue-50 rounded-full flex items-center justify-center text-blue-400"><i class="fas fa-chart-line text-xl"></i></div>
            </div>
            <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100 flex items-center justify-between border-l-4 border-green-400 hover:shadow-md transition">
                <div><p class="text-gray-500 text-xs font-bold uppercase mb-1 tracking-widest">Approved This Month</p><h3 class="text-2xl font-black text-green-600"><?= $month_approved ?></h3></div>
                <div class="w-12 h-12 bg-green-50 rounded-full flex items-center justify-center text-green-400"><i class="fas fa-check-double text-xl"></i></div>
            </div>
            <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100 flex items-center justify-between border-l-4 border-yellow-400 hover:shadow-md transition">
                <div><p class="text-gray-500 text-xs font-bold uppercase mb-1 tracking-widest">All-Time Approval Rate</p><h3 class="text-2xl font-black text-yellow-600"><?= $approval_rate ?>%</h3></div>
                <div class="w-12 h-12 bg-yellow-50 rounded-full flex items-center justify-center text-yellow-400"><i class="fas fa-percent text-xl"></i></div>
            </div>
        </div>

        <h2 class="text-lg font-bold text-[#3d143e] mb-3"><i class="fas fa-file-invoice text-[#c6943a] mr-2"></i> All-Time Assistance Requests</h2>
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-8">
            <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100 text-center border-t-4 border-gray-400 hover:-translate-y-1 transition">
                <p class="text-gray-500 text-xs font-bold uppercase mb-1 tracking-widest">Total</p><h3 class="text-3xl font-black text-gray-800"><?= $total ?></h3>
            </div>
            <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100 text-center border-t-4 border-green-400 hover:-translate-y-1 transition">
                <p class="text-gray-500 text-xs font-bold uppercase mb-1 tracking-widest">Approved</p><h3 class="text-3xl font-black text-green-600"><?= $approved ?></h3>
            </div>
            <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100 text-center border-t-4 border-yellow-400 hover:-translate-y-1 transition">
                <p class="text-gray-500 text-xs font-bold uppercase mb-1 tracking-widest">Pending</p><h3 class="text-3xl font-black text-yellow-600"><?= $pending ?></h3>
            </div>
            <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100 text-center border-t-4 border-red-400 hover:-translate-y-1 transition">
                <p class="text-gray-500 text-xs font-bold uppercase mb-1 tracking-widest">Declined</p><h3 class="text-3xl font-black text-red-500"><?= $declined ?></h3>
            </div>
            <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100 text-center border-t-4 border-purple-500 hover:-translate-y-1 transition">
                <p class="text-gray-500 text-xs font-bold uppercase mb-1 tracking-widest">Released</p><h3 class="text-3xl font-black text-purple-600"><?= $released ?></h3>
            </div>
        </div>

        <h2 class="text-lg font-bold text-[#3d143e] mb-3"><i class="fas fa-id-card text-[#c6943a] mr-2"></i> Verification Analytics</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-10">
            <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100 flex flex-col justify-center items-center relative overflow-hidden">
                <div class="absolute -right-4 -bottom-4 text-gray-100 opacity-50 text-6xl"><i class="fas fa-users"></i></div>
                <h3 class="text-3xl font-black text-[#3d143e] relative z-10"><?= $total_verifications ?></h3>
                <p class="text-gray-500 text-xs font-bold uppercase mt-1 tracking-widest relative z-10">Total Submissions</p>
            </div>
            <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100 flex flex-col justify-center items-center relative overflow-hidden">
                <div class="absolute -right-4 -bottom-4 text-yellow-50 opacity-50 text-6xl"><i class="fas fa-clock"></i></div>
                <h3 class="text-3xl font-black text-yellow-600 relative z-10"><?= $pending_verifs ?></h3>
                <p class="text-gray-500 text-xs font-bold uppercase mt-1 tracking-widest relative z-10">Pending Review</p>
            </div>
            <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100 flex flex-col justify-center items-center relative overflow-hidden">
                <div class="absolute -right-4 -bottom-4 text-red-50 opacity-50 text-6xl"><i class="fas fa-times-circle"></i></div>
                <h3 class="text-3xl font-black text-red-500 relative z-10"><?= $rejected_verifs ?></h3>
                <p class="text-gray-500 text-xs font-bold uppercase mt-1 tracking-widest relative z-10">Rejected IDs</p>
            </div>
            <div class="bg-white rounded-2xl p-5 shadow-sm border border-blue-100 flex flex-col justify-center items-center relative overflow-hidden bg-blue-50/30">
                <div class="absolute -right-4 -bottom-4 text-blue-100 opacity-50 text-6xl"><i class="fas fa-edit"></i></div>
                <h3 class="text-3xl font-black text-blue-600 relative z-10"><?= $edit_requests ?></h3>
                <p class="text-blue-800 text-xs font-bold uppercase mt-1 tracking-widest relative z-10">Edit Requests</p>
            </div>
        </div>

        <h2 class="text-lg font-bold text-[#3d143e] mb-3"><i class="fas fa-server text-[#c6943a] mr-2"></i> Users & Infrastructure</h2>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-10">
            <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100 flex items-center justify-between">
                <div><p class="text-gray-500 text-xs font-bold uppercase mb-1 tracking-widest">Total Users</p><h3 class="text-2xl font-black text-[#3d143e]"><?= $total_users ?></h3></div>
                <div class="w-12 h-12 rounded-full bg-gray-100 flex items-center justify-center text-gray-500 text-xl"><i class="fas fa-user-friends"></i></div>
            </div>
            <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100 flex items-center justify-between">
                <div><p class="text-gray-500 text-xs font-bold uppercase mb-1 tracking-widest">Verified</p><h3 class="text-2xl font-black text-blue-600"><?= $verified_users ?></h3></div>
                <div class="w-12 h-12 rounded-full bg-blue-50 flex items-center justify-center text-blue-500 text-xl"><i class="fas fa-user-check"></i></div>
            </div>
            <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100 flex items-center justify-between">
                <div><p class="text-gray-500 text-xs font-bold uppercase mb-1 tracking-widest">Unverified</p><h3 class="text-2xl font-black text-orange-500"><?= $unverified_users ?></h3></div>
                <div class="w-12 h-12 rounded-full bg-orange-50 flex items-center justify-center text-orange-500 text-xl"><i class="fas fa-user-clock"></i></div>
            </div>
            
            <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100 flex items-center justify-between border-b-4 border-indigo-500">
                <div>
                    <p class="text-gray-500 text-xs font-bold uppercase mb-1 tracking-widest">Cloud Storage</p>
                    <h3 class="text-xl font-black text-indigo-600 tracking-tight"><?= htmlspecialchars($bucket_size) ?></h3>
                </div>
                <div class="w-12 h-12 rounded-full bg-indigo-50 flex items-center justify-center text-indigo-500 text-xl"><i class="fas fa-cloud-upload-alt"></i></div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
            <div class="bg-white rounded-3xl shadow-md border border-white/50 p-6 flex flex-col items-center">
                <h3 class="text-lg font-bold text-[#3d143e] w-full border-b pb-3 mb-4">Request Statuses</h3>
                <div class="w-full max-w-[250px] aspect-square relative flex-1"><canvas id="statusChart"></canvas></div>
            </div>
            <div class="lg:col-span-2 bg-white rounded-3xl shadow-md border border-white/50 p-6 flex flex-col">
                <h3 class="text-lg font-bold text-[#3d143e] w-full border-b pb-3 mb-4">Top Assistance Requested</h3>
                <div class="flex-1 w-full relative min-h-[250px]"><canvas id="typeChart"></canvas></div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="bg-white rounded-3xl shadow-md border border-white/50 p-8">
                <h3 class="text-xl font-bold text-[#3d143e] mb-6 border-b pb-4 flex items-center gap-3"><i class="fas fa-list-ul text-[#c6943a]"></i> Request Breakdown</h3>
                <div class="space-y-3">
                    <?php foreach($breakdown as $stat): ?>
                        <div class="flex justify-between items-center bg-gray-50 p-3 rounded-xl border border-gray-200">
                            <span class="font-bold text-gray-700 text-sm"><?= htmlspecialchars($stat['assistance_type']) ?></span>
                            <span class="bg-[#3d143e] text-white px-3 py-1 rounded-lg text-sm font-bold shadow-sm"><?= $stat['count'] ?></span>
                        </div>
                    <?php endforeach; ?>
                    <?php if(empty($breakdown)): ?>
                        <p class="text-gray-500 italic py-8 text-center bg-gray-50 rounded-xl">No request data available.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="bg-white rounded-3xl shadow-md border border-white/50 p-8 flex flex-col justify-between">
                <div>
                    <h3 class="text-xl font-bold text-[#3d143e] mb-6 border-b pb-4 flex items-center gap-3"><i class="fas fa-bolt text-[#c6943a]"></i> Recent System Activity</h3>
                    <div id="activity-feed-wrapper" class="transition-opacity duration-300">
                    <div id="activity-list" class="space-y-4">
                        <?php foreach($recent_activity as $act): ?>
                            <?php 
                                $icon = $act['type'] === 'Account Verification' ? 'fa-id-card text-blue-500' : 'fa-file-alt text-green-500';
                                $user_email = htmlspecialchars($act['email'] ?? 'Unknown User');
                            ?>
                            <div class="flex items-start gap-4">
                                <div class="mt-1"><i class="fas <?= $icon ?> text-lg"></i></div>
                                <div>
                                    <p class="text-sm font-bold text-[#3d143e]">
                                        <?= $act['type'] ?> 
                                        <span class="text-xs font-normal text-gray-500 ml-1">by <?= $user_email ?></span>
                                    </p>
                                    <p class="text-xs font-medium text-gray-600 mt-0.5">
                                        <?= htmlspecialchars($act['detail']) ?> 
                                        <span class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-gray-100 text-gray-600 ml-1"><?= htmlspecialchars($act['status']) ?></span>
                                    </p>
                                    <p class="text-[10px] text-gray-400 mt-1 uppercase tracking-wider"><?= date('M j, g:i A', strtotime($act['activity_date'])) ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        
                        <?php if(empty($recent_activity)): ?>
                            <p class="text-gray-500 italic py-8 text-center bg-gray-50 rounded-xl">No system activity found.</p>
                        <?php endif; ?>
                    </div>

                    <?php if ($total_pages > 1): ?>
                    <div class="mt-6 flex items-center justify-between border-t border-gray-100 pt-4">
                        <p id="current-page-text" class="text-xs text-gray-500 font-medium">Page <?= $page ?> of <?= $total_pages ?></p>
                        <div class="flex gap-2">
                            <button type="button" id="prev-page-btn" <?= ($page > 1) ? '' : 'disabled' ?> onclick="fetchActivity(<?= $page - 1 ?>)" class="px-3 py-1.5 rounded-lg border <?= ($page > 1) ? 'border-gray-200 text-[#3d143e] hover:bg-gray-50' : 'border-gray-100 text-gray-300 cursor-not-allowed' ?> text-xs font-bold transition">Previous</button>
                            
                            <button type="button" id="next-page-btn" <?= ($page < $total_pages) ? '' : 'disabled' ?> onclick="fetchActivity(<?= $page + 1 ?>)" class="px-3 py-1.5 rounded-lg border <?= ($page < $total_pages) ? 'border-gray-200 text-[#3d143e] hover:bg-gray-50' : 'border-gray-100 text-gray-300 cursor-not-allowed' ?> text-xs font-bold transition">Next</button>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

    </div>

    <div id="reportModal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4 transition-opacity">
        <form action="print_report.php" method="GET" target="_blank" class="bg-[#f5f1f7] w-full max-w-2xl rounded-[30px] overflow-hidden shadow-2xl p-8 border border-white relative">
            
            <button type="button" onclick="document.getElementById('reportModal').classList.add('hidden')" class="absolute top-6 right-6 text-gray-400 hover:text-gray-700 text-2xl"><i class="fas fa-times"></i></button>
            
            <div class="mb-8">
                <h2 class="text-2xl font-bold text-[#3d143e] flex items-center gap-3"><i class="fas fa-file-pdf text-[#c6943a]"></i> Generate Official Report</h2>
                <p class="text-gray-500 text-sm mt-1">Select the parameters for your document export.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-8">
                
                <div>
                    <h3 class="bg-[#3d143e] text-white text-center font-bold py-2 rounded-full mb-4 shadow-sm">Report Category</h3>
                    <div class="space-y-3 px-2">
                        
                        <p class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1 mt-2 border-b border-gray-200 pb-1">Assistance Records</p>
                        
                        <label class="flex items-center gap-3 cursor-pointer group">
                            <input type="radio" name="assistance_type" value="All" checked class="w-4 h-4 accent-[#c6943a]" onchange="toggleStatusFilter(false)">
                            <span class="text-gray-700 font-medium group-hover:text-[#3d143e]">All Assistance Types</span>
                        </label>
                        <label class="flex items-center gap-3 cursor-pointer group">
                            <input type="radio" name="assistance_type" value="Medical Assistance" class="w-4 h-4 accent-[#c6943a]" onchange="toggleStatusFilter(false)">
                            <span class="text-gray-700 font-medium group-hover:text-[#3d143e]">Medical Assistance</span>
                        </label>
                        <label class="flex items-center gap-3 cursor-pointer group">
                            <input type="radio" name="assistance_type" value="Hospital Bill" class="w-4 h-4 accent-[#c6943a]" onchange="toggleStatusFilter(false)">
                            <span class="text-gray-700 font-medium group-hover:text-[#3d143e]">Hospital Bill</span>
                        </label>
                        <label class="flex items-center gap-3 cursor-pointer group">
                            <input type="radio" name="assistance_type" value="Financial Assistance" class="w-4 h-4 accent-[#c6943a]" onchange="toggleStatusFilter(false)">
                            <span class="text-gray-700 font-medium group-hover:text-[#3d143e]">Financial Assistance</span>
                        </label>
                        <label class="flex items-center gap-3 cursor-pointer group">
                            <input type="radio" name="assistance_type" value="Burial Assistance" class="w-4 h-4 accent-[#c6943a]" onchange="toggleStatusFilter(false)">
                            <span class="text-gray-700 font-medium group-hover:text-[#3d143e]">Burial Assistance</span>
                        </label>

                        <p class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1 mt-5 border-b border-gray-200 pb-1">Administrative Records</p>
                        
                        <label class="flex items-center gap-3 cursor-pointer group">
                            <input type="radio" name="assistance_type" value="Citizens Masterlist" class="w-4 h-4 accent-[#5b8fb0]" onchange="toggleStatusFilter(true)">
                            <span class="text-[#5b8fb0] font-bold group-hover:text-[#4a7694]">Registered Citizens Masterlist</span>
                        </label>
                        <label class="flex items-center gap-3 cursor-pointer group">
                            <input type="radio" name="assistance_type" value="System Activity Log" class="w-4 h-4 accent-[#5b8fb0]" onchange="toggleStatusFilter(true)">
                            <span class="text-[#5b8fb0] font-bold group-hover:text-[#4a7694]">Recent System Activity Log</span>
                        </label>

                    </div>
                </div>

                <div id="status-filter-container" class="transition-opacity duration-300">
                    <h3 class="bg-[#3d143e] text-white text-center font-bold py-2 rounded-full mb-4 shadow-sm">Status Filter</h3>
                    <div class="space-y-3 px-2">
                        <label class="flex items-center gap-3 cursor-pointer group">
                            <input type="radio" name="status" value="All" checked class="w-4 h-4 accent-[#c6943a]">
                            <span class="text-gray-700 font-medium group-hover:text-[#3d143e]">All Statuses</span>
                        </label>
                        <label class="flex items-center gap-3 cursor-pointer group">
                            <input type="radio" name="status" value="Submitted" class="w-4 h-4 accent-[#c6943a]">
                            <span class="text-gray-700 font-medium group-hover:text-[#3d143e]">Pending Only</span>
                        </label>
                        <label class="flex items-center gap-3 cursor-pointer group">
                            <input type="radio" name="status" value="Approved" class="w-4 h-4 accent-[#c6943a]">
                            <span class="text-gray-700 font-medium group-hover:text-[#3d143e]">Approved Only</span>
                        </label>
                        <label class="flex items-center gap-3 cursor-pointer group">
                            <input type="radio" name="status" value="Released" class="w-4 h-4 accent-[#c6943a]">
                            <span class="text-gray-700 font-medium group-hover:text-[#3d143e]">Released Only</span>
                        </label>
                        <label class="flex items-center gap-3 cursor-pointer group">
                            <input type="radio" name="status" value="Declined" class="w-4 h-4 accent-[#c6943a]">
                            <span class="text-gray-700 font-medium group-hover:text-[#3d143e]">Declined / Rejected</span>
                        </label>
                    </div>
                </div>

            </div>

            <div class="flex justify-end gap-4 pt-4 border-t border-gray-300">
                <button type="submit" name="output_mode" value="download" onclick="document.getElementById('reportModal').classList.add('hidden')" class="bg-[#3d143e] text-white px-6 py-2.5 rounded-full font-bold shadow-md border-2 border-[#3d143e] hover:bg-purple-900 transition flex items-center">
                    <i class="fas fa-file-download mr-2 text-[#c6943a]"></i> Download PDF
                </button>
                <button type="submit" name="output_mode" value="print" onclick="document.getElementById('reportModal').classList.add('hidden')" class="bg-[#3d143e] text-white px-6 py-2.5 rounded-full font-bold shadow-md border-2 border-[#3d143e] hover:bg-purple-900 transition flex items-center">
                    <i class="fas fa-print mr-2 text-[#c6943a]"></i> Print Report
                </button>
            </div>
            
        </form>
    </div>
    
    <script>
        // Disables the status filter if they choose an Admin Report
        function toggleStatusFilter(disable) {
            const container = document.getElementById('status-filter-container');
            const radios = container.querySelectorAll('input[type="radio"]');
            if (disable) {
                container.style.opacity = '0.4';
                container.style.pointerEvents = 'none';
                radios.forEach(r => { r.checked = (r.value === 'All'); });
            } else {
                container.style.opacity = '1';
                container.style.pointerEvents = 'auto';
            }
        }

        const statusCtx = document.getElementById('statusChart').getContext('2d');
        new Chart(statusCtx, {
            type: 'doughnut', data: { labels: ['Approved', 'Pending', 'Declined', 'Released'], datasets: [{ data: [<?= $approved ?>, <?= $pending ?>, <?= $declined ?>, <?= $released ?>], backgroundColor: ['#22c55e', '#eab308', '#ef4444', '#a855f7'], borderWidth: 0, hoverOffset: 4 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 20, font: {family: "'Segoe UI', sans-serif", weight: 'bold'} } } }, cutout: '60%' }
        });

        const typeCtx = document.getElementById('typeChart').getContext('2d');
        new Chart(typeCtx, {
            type: 'bar', data: { labels: <?= json_encode($type_labels) ?>, datasets: [{ label: 'Number of Requests', data: <?= json_encode($type_data) ?>, backgroundColor: '#5b8fb0', borderRadius: 6, borderSkipped: false }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1, font: {family: "'Segoe UI', sans-serif"} } }, x: { ticks: { font: {family: "'Segoe UI', sans-serif", weight: '600'} } } } }
        });
        
        async function fetchActivity(page) {
            const wrapper = document.getElementById('activity-feed-wrapper');
            wrapper.style.opacity = '0.4'; // Smooth fade out effect

            try {
                const response = await fetch(`reports.php?ajax=activity&page=${page}`);
                const data = await response.json();

                let html = '';
                if (data.activities.length === 0) {
                    html = '<p class="text-gray-500 italic py-8 text-center bg-gray-50 rounded-xl">No system activity found.</p>';
                } else {
                    data.activities.forEach(act => {
                        const icon = act.type === 'Account Verification' ? 'fa-id-card text-blue-500' : 'fa-file-alt text-green-500';
                        const userEmail = act.email ? act.email : 'Unknown User';
                        
                        html += `
                            <div class="flex items-start gap-4">
                                <div class="mt-1"><i class="fas ${icon} text-lg"></i></div>
                                <div>
                                    <p class="text-sm font-bold text-[#3d143e]">
                                        ${act.type} 
                                        <span class="text-xs font-normal text-gray-500 ml-1">by ${userEmail}</span>
                                    </p>
                                    <p class="text-xs font-medium text-gray-600 mt-0.5">
                                        ${act.detail} 
                                        <span class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-gray-100 text-gray-600 ml-1">${act.status}</span>
                                    </p>
                                    <p class="text-[10px] text-gray-400 mt-1 uppercase tracking-wider">${act.formatted_date}</p>
                                </div>
                            </div>
                        `;
                    });
                }

                document.getElementById('activity-list').innerHTML = html;
                document.getElementById('current-page-text').innerText = `Page ${data.page} of ${data.total_pages}`;

                // Update Previous Button
                const prevBtn = document.getElementById('prev-page-btn');
                if (data.page > 1) {
                    prevBtn.disabled = false;
                    prevBtn.className = "px-3 py-1.5 rounded-lg border border-gray-200 text-[#3d143e] hover:bg-gray-50 text-xs font-bold transition";
                    prevBtn.setAttribute('onclick', `fetchActivity(${data.page - 1})`);
                } else {
                    prevBtn.disabled = true;
                    prevBtn.className = "px-3 py-1.5 rounded-lg border border-gray-100 text-gray-300 cursor-not-allowed text-xs font-bold transition";
                    prevBtn.removeAttribute('onclick');
                }

                // Update Next Button
                const nextBtn = document.getElementById('next-page-btn');
                if (data.page < data.total_pages) {
                    nextBtn.disabled = false;
                    nextBtn.className = "px-3 py-1.5 rounded-lg border border-gray-200 text-[#3d143e] hover:bg-gray-50 text-xs font-bold transition";
                    nextBtn.setAttribute('onclick', `fetchActivity(${data.page + 1})`);
                } else {
                    nextBtn.disabled = true;
                    nextBtn.className = "px-3 py-1.5 rounded-lg border border-gray-100 text-gray-300 cursor-not-allowed text-xs font-bold transition";
                    nextBtn.removeAttribute('onclick');
                }

            } catch (error) {
                console.error("Error fetching activity:", error);
            }

            wrapper.style.opacity = '1'; // Smooth fade in
        }
    </script>
</body>
</html>