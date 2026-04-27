<?php
// admin/released.php
session_start();
require_once '../config/db_connect.php';

// Force PHP to use Manila time
date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: ../auth/login.php"); exit();
}

function formatManilaTime($utc_string) {
    if (!$utc_string) return '---';
    try {
        $date = new DateTime($utc_string, new DateTimeZone('UTC')); 
        $date->setTimezone(new DateTimeZone('Asia/Manila'));
        return $date->format('M j, Y g:i A'); 
    } catch (Exception $e) {
        return date('M j, Y g:i A', strtotime($utc_string)); 
    }
}

$search = $_GET['search'] ?? '';

// Pagination setup
$limit = 5; // Max requests per page
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

$where_sql = "status = 'Released'";
$params = [];

if (!empty($search)) {
    $where_sql .= " AND (request_id LIKE :search OR first_name LIKE :search OR last_name LIKE :search)";
    $params[':search'] = "%$search%";
}

// 1. Get TOTAL count for Pagination
$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM assistance_requests WHERE $where_sql");
$count_stmt->execute($params);
$total_records = $count_stmt->fetchColumn();
$total_pages = ceil($total_records / $limit);

// 2. Get actual records with LIMIT and OFFSET
$stmt = $pdo->prepare("SELECT * FROM assistance_requests WHERE $where_sql ORDER BY date_submitted DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset);
$stmt->execute($params);
$requests = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Released Aid - KARES Admin</title>
    <link rel="icon" type="image/png" href="../assets/images/kareslogo.png" />
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style> * { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; } </style>
</head>
<body class="bg-[#e0d5e8] flex">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="flex-1 ml-64 p-10 min-h-screen">
        
        <div class="mb-8 border-b border-gray-300 pb-6">
            <div class="w-full flex justify-between items-center mt-2">
                <div>
                    <h1 class="text-3xl font-bold text-[#3d143e]"><i class="fas fa-box-open text-[#c6943a] mr-3"></i>Released Aid</h1>
                    <p class="text-gray-500 mt-2 font-medium">History of all fully distributed citizen assistance.</p>
                </div>
                <form action="released.php" method="GET" class="flex gap-2 mt-4">
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search ID or Name..." class="px-4 py-2.5 rounded-lg border border-gray-200 bg-white shadow-sm focus:ring-2 focus:ring-[#5b8fb0] outline-none w-64 md:w-80 text-sm text-gray-700">
                    <button type="submit" class="bg-[#5b8fb0] text-white px-5 py-2.5 rounded-lg shadow hover:bg-[#4a7694] transition">
                        <i class="fas fa-search"></i>
                    </button>
                </form>
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

        <div class="bg-white rounded-3xl shadow-sm border border-gray-100 overflow-hidden flex flex-col">
            <div class="overflow-x-auto flex-1">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="text-gray-400 text-xs uppercase bg-white border-b border-gray-100">
                            <th class="px-6 py-5 font-bold tracking-wider">Request ID & Date</th>
                            <th class="px-6 py-5 font-bold tracking-wider">Citizen Name</th>
                            <th class="px-6 py-5 font-bold tracking-wider">Request Details</th>
                            <th class="px-6 py-5 font-bold text-center tracking-wider">Action</th>
                        </tr>
                    </thead>
                    <tbody class="text-sm text-gray-700">
                        <?php if (count($requests) > 0): ?>
                            <?php foreach ($requests as $row): ?>
                                <tr class="border-b border-gray-50 hover:bg-gray-50 transition">
                                    <td class="px-6 py-5">
                                        <p class="font-bold text-[#5b8fb0] text-base mb-1"><?= htmlspecialchars($row['request_id']) ?></p>
                                        <p class="text-gray-400 text-xs font-medium"><i class="far fa-clock mr-1"></i> <?= formatManilaTime($row['date_submitted']) ?></p>
                                        <p class="text-purple-600 text-xs font-bold mt-1"><i class="fas fa-box-open mr-1"></i> Released: <?= formatManilaTime($row['date_updated']) ?></p>
                                    </td>
                                    <td class="px-6 py-5">
                                        <p class="font-bold text-gray-800 text-base uppercase"><?= htmlspecialchars($row['last_name'] . ', ' . $row['first_name']) ?></p>
                                    </td>
                                    <td class="px-6 py-5">
                                        <span class="bg-[#eee0c0]/50 text-[#c6943a] px-4 py-1.5 rounded-full text-xs font-bold border border-[#c6943a]/20 inline-block mb-2">
                                            <?= htmlspecialchars($row['assistance_type']) ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-5 text-center">
                                        <a href="view_request.php?id=<?= urlencode($row['request_id']) ?>" class="text-gray-500 hover:text-[#5b8fb0] transition font-bold text-sm inline-flex items-center gap-2 bg-gray-50 px-4 py-2 rounded-lg border border-gray-200 shadow-sm hover:shadow">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="4" class="px-6 py-10 text-center text-gray-500 font-medium">No released records found matching your search.</td></tr>
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
                        <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>" class="px-4 py-2 rounded-lg bg-white border border-gray-200 text-gray-600 hover:bg-gray-100 transition shadow-sm text-sm font-bold flex items-center gap-2">
                            <i class="fas fa-chevron-left text-xs"></i> Prev
                        </a>
                    <?php endif; ?>
                    
                    <div class="flex gap-1 hidden sm:flex">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>" 
                               class="w-10 h-10 flex items-center justify-center rounded-lg border <?= $i === $page ? 'bg-[#c6943a] text-white border-[#c6943a] shadow-md' : 'bg-white border-gray-200 text-gray-600 hover:bg-gray-100 shadow-sm' ?> transition text-sm font-bold">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>
                    </div>

                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>" class="px-4 py-2 rounded-lg bg-white border border-gray-200 text-gray-600 hover:bg-gray-100 transition shadow-sm text-sm font-bold flex items-center gap-2">
                            Next <i class="fas fa-chevron-right text-xs"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
</body>
</html>