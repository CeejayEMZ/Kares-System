<?php
// admin/print_report.php
session_start();
require_once '../config/db_connect.php'; 

// Force PHP to use Manila time
date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    die("Unauthorized access.");
}

$aid_type = $_GET['assistance_type'] ?? 'All';
$status = $_GET['status'] ?? 'All';

// Formatting the Headers
$display_aid_type = ($aid_type === 'All') ? 'All assistance request' : htmlspecialchars($aid_type);
$current_date = date('F d, Y');
$current_time = date('h:i A'); // 12-hour format with AM/PM

$records = [];
$total_count = 0;
$table_headers = [];

// --- LOGIC 1: CITIZENS MASTERLIST ---
if ($aid_type === 'Citizens Masterlist') {
    $stmt = $pdo->query("SELECT id, username, email, first_name, last_name, is_verified FROM users WHERE role != 'Admin' ORDER BY id DESC");
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_count = count($records);
    $table_headers = ['Citizen ID', 'Full Name', 'Email Address', 'Status'];
} 
// --- LOGIC 2: SYSTEM ACTIVITY LOG ---
elseif ($aid_type === 'System Activity Log') {
    $stmt = $pdo->prepare("
        (SELECT 'Assistance Request' as type, a.assistance_type as detail, a.status, a.date_submitted as activity_date, u.email, u.first_name, u.last_name 
         FROM assistance_requests a 
         LEFT JOIN users u ON a.user_id::integer = u.id)
        UNION ALL
        (SELECT 'Account Verification' as type, v.id_type as detail, v.status, v.submitted_at as activity_date, u.email, u.first_name, u.last_name 
         FROM user_verifications v 
         LEFT JOIN users u ON v.user_id::integer = u.id)
        ORDER BY activity_date DESC
    ");
    $stmt->execute();
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_count = count($records);
    $table_headers = ['Activity Type', 'User / Citizen', 'Details', 'Result', 'Date & Time'];
} 
// --- LOGIC 3: ASSISTANCE REQUESTS (Default) ---
else {
    $where_clauses = [];
    $params = [];

    if ($aid_type !== 'All') {
        $where_clauses[] = "a.assistance_type LIKE :aid_type";
        $params[':aid_type'] = $aid_type . '%';
    }
    if ($status !== 'All') {
        $where_clauses[] = "a.status = :status";
        $params[':status'] = $status;
    }

    $where_sql = count($where_clauses) > 0 ? "WHERE " . implode(" AND ", $where_clauses) : "";
    
    $query = "SELECT a.*, u.first_name, u.last_name, u.email FROM assistance_requests a LEFT JOIN users u ON a.user_id::integer = u.id $where_sql ORDER BY a.date_submitted DESC";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_count = count($records);
    
    // FIXED: Dynamic Column Header based on the Status Filter!
    $dynamic_date_header = 'Date Processed';
    if ($status === 'Approved') $dynamic_date_header = 'Date Approved';
    if ($status === 'Released') $dynamic_date_header = 'Date Released';
    if ($status === 'Declined') $dynamic_date_header = 'Date Declined';

    $table_headers = ['Request ID', 'Citizen Name', 'Specific Assistance', 'Date Submitted', $dynamic_date_header, 'Status'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Official Report - KARES</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Responsive shrinking to fit everything on a Portrait page */
        @media print {
            @page { margin: 10mm; } 
            body { 
                -webkit-print-color-adjust: exact; 
                print-color-adjust: exact; 
                background-color: white !important; 
                padding: 0 !important; 
                margin: 0 !important;
            }
            .no-print { display: none !important; }
            .print-container { 
                max-width: 100% !important; 
                width: 100% !important; 
                border: none !important; 
                box-shadow: none !important; 
                padding: 0 !important; 
                margin: 0 !important;
            }
            table { 
                width: 100% !important; 
                max-width: 100% !important;
                table-layout: auto !important; 
                border-collapse: collapse !important; 
            }
            th, td { 
                word-wrap: break-word !important; 
                overflow-wrap: break-word !important; 
                white-space: normal !important; 
                padding: 4px 6px !important; 
                font-size: 10px !important; 
            }
            /* Shrink Headers for Print */
            h1 { font-size: 22px !important; margin-bottom: 4px !important; }
            h2 { font-size: 16px !important; }
            .header-info p { font-size: 11px !important; margin: 2px 0 !important; }
            .mb-10 { margin-bottom: 15px !important; }
            .pb-6 { padding-bottom: 10px !important; }
        }
        * { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    </style>
</head>
<body class="bg-gray-100 text-gray-900 p-4 md:p-8">
    
    <div class="fixed bottom-6 right-6 no-print z-50">
        <button onclick="window.print()" class="bg-[#3d143e] text-white px-8 py-4 rounded-full font-bold shadow-2xl hover:bg-purple-900 transition hover:-translate-y-1 transform flex items-center gap-3 text-lg border-4 border-white">
            <i class="fas fa-print"></i> Print Document
        </button>
    </div>

    <div class="print-container max-w-[1200px] mx-auto bg-white p-10 md:p-12 shadow-lg border border-gray-200 relative">
        
        <div class="text-center mb-10 pb-6 border-b-4 border-[#3d143e]">
            <h1 class="text-3xl md:text-4xl font-black text-[#3d143e] tracking-tight mb-2">Assistance Request Report</h1>
            <h2 class="text-lg md:text-xl font-bold text-[#c6943a] uppercase tracking-widest">Barangay Kanluran</h2>
            
            <div class="header-info mt-6 flex flex-col items-center justify-center gap-1 text-sm font-bold text-[#5b8fb0]">
                <p class="text-base text-[#3d143e]">Type of Assistance: <span class="text-[#5b8fb0] font-black"><?= $display_aid_type ?></span></p>
                
                <?php if (!in_array($aid_type, ['Citizens Masterlist', 'System Activity Log'])): ?>
                    <p class="text-base text-[#3d143e]">Status Filter: <span class="text-[#5b8fb0] font-black"><?= htmlspecialchars($status) ?></span></p>
                <?php endif; ?>
                
                <p class="mt-2 text-gray-500">Date: <?= $current_date ?></p>
                <p class="text-gray-500">Time: <?= $current_time ?></p>
            </div>
        </div>

        <div class="mb-4 flex items-end justify-between">
            <h3 class="text-xl font-black text-[#3d143e]">Records</h3>
            <p class="font-bold text-gray-600 bg-gray-100 px-3 py-1.5 rounded-lg border border-gray-200 text-sm">Total Rows: <span class="text-[#3d143e] text-base"><?= $total_count ?></span></p>
        </div>

        <div class="overflow-x-auto w-full">
            <table class="w-full text-left border-collapse text-xs md:text-sm">
                <thead>
                    <tr class="bg-[#3d143e] text-white">
                        <?php foreach($table_headers as $th): ?>
                            <th class="border border-[#3d143e] px-4 py-2 font-bold uppercase tracking-wider text-[11px]"><?= $th ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($total_count > 0): ?>
                        <?php foreach($records as $index => $row): ?>
                            <?php $bg_class = ($index % 2 === 0) ? 'bg-white' : 'bg-gray-50'; ?>
                            
                            <tr class="<?= $bg_class ?>">
                                <?php if ($aid_type === 'Citizens Masterlist'): ?>
                                    <?php 
                                        $fname = trim($row['first_name'] ?? '');
                                        $lname = trim($row['last_name'] ?? '');
                                        $name = '';
                                        
                                        if (!empty($fname) || !empty($lname)) {
                                            $name = trim($fname . ' ' . $lname);
                                        }
                                        if (empty($name)) {
                                            $name = !empty($row['username']) ? $row['username'] : $row['email'];
                                        }

                                        $verif = $row['is_verified'] ? 'Verified' : 'Unverified';
                                        $v_color = $row['is_verified'] ? 'text-green-600' : 'text-orange-500';
                                    ?>
                                    <td class="border border-gray-200 px-4 py-2 font-bold text-gray-600 whitespace-nowrap">#<?= htmlspecialchars($row['id']) ?></td>
                                    <td class="border border-gray-200 px-4 py-2 uppercase font-bold text-[#3d143e]"><?= htmlspecialchars($name) ?></td>
                                    <td class="border border-gray-200 px-4 py-2" style="word-break: break-all;"><?= htmlspecialchars($row['email']) ?></td>
                                    <td class="border border-gray-200 px-4 py-2 font-bold <?= $v_color ?>"><?= $verif ?></td>
                                
                                <?php elseif ($aid_type === 'System Activity Log'): ?>
                                    <?php 
                                        $fname = trim($row['first_name'] ?? '');
                                        $lname = trim($row['last_name'] ?? '');
                                        $name = '';
                                        
                                        if (!empty($fname) || !empty($lname)) {
                                            $name = trim($fname . ' ' . $lname);
                                        }
                                        if (empty($name)) {
                                            $name = !empty($row['email']) ? $row['email'] : 'Unknown Citizen';
                                        }

                                        $date = date('M j, Y g:i A', strtotime($row['activity_date']));
                                        $type_color = $row['type'] === 'Account Verification' ? 'text-blue-600' : 'text-green-600';
                                    ?>
                                    <td class="border border-gray-200 px-4 py-2 font-bold <?= $type_color ?> whitespace-nowrap"><?= htmlspecialchars($row['type']) ?></td>
                                    <td class="border border-gray-200 px-4 py-2 uppercase font-bold text-[#3d143e]"><?= htmlspecialchars($name) ?></td>
                                    <td class="border border-gray-200 px-4 py-2"><?= htmlspecialchars($row['detail']) ?></td>
                                    <td class="border border-gray-200 px-4 py-2 font-bold text-gray-600"><?= htmlspecialchars($row['status']) ?></td>
                                    <td class="border border-gray-200 px-4 py-2 text-gray-500 whitespace-nowrap"><?= $date ?></td>

                                <?php else: ?>
                                    <?php 
                                        $fname = trim($row['first_name'] ?? '');
                                        $lname = trim($row['last_name'] ?? '');
                                        $name = '';
                                        
                                        if (!empty($fname) || !empty($lname)) {
                                            $name = trim($lname . ', ' . $fname, ', ');
                                        }
                                        if (empty($name)) {
                                            $name = !empty($row['email']) ? $row['email'] : 'Unknown Citizen';
                                        }
                                        
                                        $date_sub = date('M j, Y g:i A', strtotime($row['date_submitted']));
                                        
                                        $date_proc = '---';
                                        $proc_color = 'text-gray-500';
                                        
                                        if (in_array($row['status'], ['Approved', 'Released', 'Declined']) && !empty($row['date_updated'])) {
                                            $date_proc = date('M j, Y g:i A', strtotime($row['date_updated']));
                                            if ($row['status'] === 'Approved') $proc_color = 'text-green-600';
                                            if ($row['status'] === 'Released') $proc_color = 'text-purple-600';
                                            if ($row['status'] === 'Declined') $proc_color = 'text-red-500';
                                        }
                                    ?>
                                    <td class="border border-gray-200 px-4 py-2 font-bold text-[#5b8fb0] whitespace-nowrap"><?= htmlspecialchars($row['request_id']) ?></td>
                                    <td class="border border-gray-200 px-4 py-2 uppercase font-bold text-[#3d143e]"><?= htmlspecialchars($name) ?></td>
                                    <td class="border border-gray-200 px-4 py-2 text-gray-700"><?= htmlspecialchars($row['assistance_type']) ?></td>
                                    <td class="border border-gray-200 px-4 py-2 text-gray-500 whitespace-nowrap"><?= $date_sub ?></td>
                                    <td class="border border-gray-200 px-4 py-2 <?= $proc_color ?> font-medium whitespace-nowrap"><?= $date_proc ?></td>
                                    <td class="border border-gray-200 px-4 py-2 font-bold text-gray-600 uppercase text-[10px]"><?= htmlspecialchars($row['status']) ?></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="border border-gray-200 px-5 py-8 text-center text-gray-500 italic text-sm bg-gray-50">No records found matching this exact filter.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="mt-12 text-center text-xs font-bold text-gray-400 pb-4">
            <p class="tracking-widest uppercase mb-1">--- End of Official Report ---</p>
            <p>Generated By: Barangay Kanluran, Assistance Request System</p>
        </div>
        
    </div>
    
    <script>
        window.onload = function() {
            setTimeout(() => { window.print(); }, 800);
        };
    </script>
</body>
</html>