<?php
// admin/verifications.php
session_start();
require_once '../config/db_connect.php'; 

// Force PHP to use Manila time
date_default_timezone_set('Asia/Manila');

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
        return $utc_string; 
    }
}

// --- HANDLE POST ACTIONS (APPROVE / REJECT / EDIT REQUESTS) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    $verif_id = $_POST['verif_id'];
    $user_id = $_POST['user_id'];
    $action = $_POST['action'];

    if ($action === 'approve') {
        $pdo->prepare("UPDATE user_verifications SET status = 'Approved' WHERE id = ?")->execute([$verif_id]);
        $pdo->prepare("UPDATE users SET is_verified = TRUE WHERE id = ?")->execute([$user_id]);
        $msg = "Your account verification has been approved. You can now enjoy auto-fill features!";
        $pdo->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, 'Verification Approved', ?)")->execute([$user_id, $msg]);
        header("Location: verifications.php?status=Pending&msg=Success"); 
        exit();
        
    } elseif ($action === 'reject') {
        // FIXED: Catch the rejection reason
        $reason = trim($_POST['reject_reason'] ?? 'No reason provided.');
        
        $pdo->prepare("UPDATE user_verifications SET status = 'Rejected', rejection_reason = ? WHERE id = ?")->execute([$reason, $verif_id]);
        
        // Include the reason in the notification!
        $msg = "Your account verification was rejected. Reason: " . $reason . " Please review your details and submit clear, valid IDs.";
        $pdo->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, 'Verification Rejected', ?)")->execute([$user_id, $msg]);
        
        header("Location: verifications.php?status=Pending&msg=Success"); 
        exit();
        
    } elseif ($action === 'approve_edit') {
        $pdo->prepare("UPDATE user_verifications SET status = 'Archived', edit_reason = NULL WHERE id = ?")->execute([$verif_id]);
        $pdo->prepare("UPDATE users SET is_verified = FALSE WHERE id = ?")->execute([$user_id]);
        $msg = "Your request to edit your verified data has been granted. Please go to your profile to review and update your information.";
        $pdo->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, 'Edit Request Granted', ?)")->execute([$user_id, $msg]);
        header("Location: verifications.php?status=Edit Requests&msg=Success"); 
        exit();
        
    } elseif ($action === 'reject_edit') {
        $pdo->prepare("UPDATE user_verifications SET edit_reason = NULL WHERE id = ?")->execute([$verif_id]);
        $msg = "Your request to edit your verified data was declined. Your current verified status remains active.";
        $pdo->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, 'Edit Request Declined', ?)")->execute([$user_id, $msg]);
        header("Location: verifications.php?status=Edit Requests&msg=Success"); 
        exit();
    }
}
// --------------------------------------------------------------

$status_filter = $_GET['status'] ?? 'Pending';

if ($status_filter === 'Edit Requests') {
    $where_sql = "uv.status = 'Approved' AND uv.edit_reason IS NOT NULL AND uv.edit_reason != ''";
    $params = [];
} else {
    $where_sql = "uv.status = :status AND (uv.edit_reason IS NULL OR uv.edit_reason = '')";
    $params = [':status' => $status_filter];
}

$query = "SELECT uv.*, u.profile_image 
          FROM user_verifications uv 
          JOIN users u ON uv.user_id = u.id 
          WHERE $where_sql 
          ORDER BY uv.submitted_at DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$verifications = $stmt->fetchAll();

$count_edits_query = $pdo->query("SELECT COUNT(*) FROM user_verifications WHERE status = 'Approved' AND edit_reason IS NOT NULL AND edit_reason != ''");
$pending_edits_count = $count_edits_query->fetchColumn();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Verifications - KARES Admin</title>
    <link rel="icon" type="image/png" href="../assets/images/kareslogo.png" />
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style> 
        * { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; } 
        .custom-scrollbar::-webkit-scrollbar { width: 8px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #CCBFD5; border-radius: 10px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #a462a9; }
    </style>
</head>
<body class="bg-gray-50 flex"> 

    <?php include 'includes/sidebar.php'; ?>

    <div class="flex-1 ml-64 p-10 min-h-screen relative">
        <h1 class="text-3xl font-bold text-[#3d143e] mb-2">Account Verifications</h1>
        <p class="text-gray-500 font-medium mb-6">Review submitted citizen details and IDs to grant verified status.</p>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'Success'): ?>
            <div id="success-alert" class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-6 shadow-sm transition-opacity duration-500">
                <strong class="font-bold">Success!</strong><span class="block sm:inline"> Verification status updated.</span>
            </div>
            <script>setTimeout(() => document.getElementById('success-alert').remove(), 3000);</script>
        <?php endif; ?>

        <div class="flex gap-4 mb-6 overflow-x-auto pb-2">
            <a href="?status=Pending" class="whitespace-nowrap px-6 py-2 rounded-full font-bold text-sm <?= $status_filter === 'Pending' ? 'bg-[#3d143e] text-white shadow-md' : 'bg-white text-gray-500 border border-gray-200 hover:bg-gray-50' ?>">Pending</a>
            <a href="?status=Approved" class="whitespace-nowrap px-6 py-2 rounded-full font-bold text-sm <?= $status_filter === 'Approved' ? 'bg-green-600 text-white shadow-md' : 'bg-white text-gray-500 border border-gray-200 hover:bg-gray-50' ?>">Approved</a>
            <a href="?status=Rejected" class="whitespace-nowrap px-6 py-2 rounded-full font-bold text-sm <?= $status_filter === 'Rejected' ? 'bg-red-500 text-white shadow-md' : 'bg-white text-gray-500 border border-gray-200 hover:bg-gray-50' ?>">Rejected</a>
            
            <a href="?status=Edit Requests" class="whitespace-nowrap px-6 py-2 rounded-full font-bold text-sm flex items-center gap-2 <?= $status_filter === 'Edit Requests' ? 'bg-blue-500 text-white shadow-md' : 'bg-white text-gray-500 border border-gray-200 hover:bg-gray-50' ?>">
                <i class="fas fa-edit"></i> Edit Requests
                <?php if ($pending_edits_count > 0): ?>
                    <span class="bg-red-500 text-white text-[10px] font-black px-2 py-0.5 rounded-full shadow-inner border border-red-600"><?= $pending_edits_count ?></span>
                <?php endif; ?>
            </a>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="text-gray-400 text-xs uppercase bg-gray-50/50 border-b border-gray-100">
                        <th class="px-6 py-4 font-bold">Citizen Name</th>
                        <th class="px-6 py-4 font-bold">Mobile</th>
                        <th class="px-6 py-4 font-bold">ID Type</th>
                        <th class="px-6 py-4 font-bold">Date Submitted</th>
                        <th class="px-6 py-4 font-bold text-center">Action</th>
                    </tr>
                </thead>
                <tbody class="text-sm divide-y divide-gray-50">
                    <?php if (count($verifications) > 0): ?>
                        <?php foreach ($verifications as $row): ?>
                            <tr class="hover:bg-gray-50 transition">
                                <td class="px-6 py-4 font-bold text-gray-800 uppercase">
                                    <?= htmlspecialchars($row['last_name'] . ', ' . $row['first_name']) ?>
                                </td>
                                <td class="px-6 py-4 text-gray-600"><?= htmlspecialchars($row['mobile_number'] ?? $row['contact_number']) ?></td>
                                <td class="px-6 py-4 font-bold text-[#5b8fb0]"><?= htmlspecialchars($row['id_type']) ?></td>
                                <td class="px-6 py-4 text-gray-500 font-medium"><?= formatManilaTime($row['submitted_at']) ?></td>
                                <td class="px-6 py-4 text-center">
                                    <button onclick="openReviewModal(<?= htmlspecialchars(json_encode($row)) ?>)" class="bg-[#c6943a] text-white px-5 py-2 rounded-full font-bold shadow hover:bg-yellow-600 transition text-xs inline-flex items-center gap-2">
                                        <i class="fas fa-eye"></i> Review Data
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="px-6 py-12 text-center text-gray-500 font-medium">No <?= strtolower($status_filter) ?> verifications found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="review-modal" class="hidden fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-[#f8f9fa] w-full max-w-5xl rounded-[30px] shadow-2xl overflow-hidden flex flex-col max-h-[95vh]">
            <div class="bg-[#3d143e] p-5 md:px-8 flex justify-between items-center text-white shrink-0">
                <h2 class="text-xl font-bold flex items-center gap-3"><i class="fas fa-user-shield text-[#c6943a]"></i> Citizen Verification Review</h2>
                <button onclick="closeReviewModal()" class="text-white/60 hover:text-white text-2xl"><i class="fas fa-times"></i></button>
            </div>
            
            <div class="p-6 md:p-8 overflow-y-auto flex-1 custom-scrollbar">
                
                <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-200 mb-6">
                    <div class="flex items-center gap-4 border-b border-gray-100 pb-4 mb-4">
                        <div class="w-14 h-14 rounded-full bg-gray-200 border-2 border-[#5b8fb0] overflow-hidden flex items-center justify-center shrink-0">
                            <img id="m-profile-img" src="" class="w-full h-full object-cover hidden">
                            <i id="m-profile-icon" class="fas fa-user text-2xl text-gray-400"></i>
                        </div>
                        <h3 class="text-[#3d143e] font-bold text-lg">Personal Information</h3>
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div class="col-span-2">
                            <p class="text-[10px] text-gray-400 font-bold uppercase tracking-wider mb-0.5">Full Name (Last, First, Middle, Ext)</p>
                            <p id="m-name" class="text-base font-black text-gray-800 uppercase">---</p>
                        </div>
                        <div>
                            <p class="text-[10px] text-gray-400 font-bold uppercase tracking-wider mb-0.5">Civil Status</p>
                            <p id="m-civil" class="text-base font-bold text-gray-700">---</p>
                        </div>
                        <div>
                            <p class="text-[10px] text-gray-400 font-bold uppercase tracking-wider mb-0.5">Family Income</p>
                            <p id="m-income" class="text-base font-bold text-gray-700">---</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-200 mb-6">
                    <h3 class="text-[#3d143e] font-bold text-lg border-b border-gray-100 pb-2 mb-4"><i class="fas fa-address-book mr-2 text-[#5b8fb0]"></i> Contact & Address</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                        <div>
                            <p class="text-[10px] text-gray-400 font-bold uppercase tracking-wider mb-0.5">Mobile Number</p>
                            <p id="m-mobile" class="text-base font-bold text-gray-700">---</p>
                        </div>
                        <div>
                            <p class="text-[10px] text-gray-400 font-bold uppercase tracking-wider mb-0.5">GCash Number</p>
                            <p id="m-gcash" class="text-base font-bold text-gray-700">---</p>
                        </div>
                        <div>
                            <p class="text-[10px] text-gray-400 font-bold uppercase tracking-wider mb-0.5">Email Address</p>
                            <p id="m-email" class="text-base font-bold text-gray-700">---</p>
                        </div>
                    </div>
                    <div class="bg-gray-50 p-4 rounded-xl border border-gray-100">
                        <p class="text-[10px] text-gray-400 font-bold uppercase tracking-wider mb-0.5">Complete Address</p>
                        <p id="m-address" class="text-base font-bold text-gray-700 leading-tight">---</p>
                    </div>
                </div>

                <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-200 mb-6">
                    <h3 class="text-[#3d143e] font-bold text-lg border-b border-gray-100 pb-2 mb-4"><i class="fas fa-heartbeat mr-2 text-red-400"></i> Emergency Contact</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <p class="text-[10px] text-gray-400 font-bold uppercase tracking-wider mb-0.5">Contact Name</p>
                            <p id="m-em-name" class="text-base font-bold text-gray-800 uppercase">---</p>
                        </div>
                        <div>
                            <p class="text-[10px] text-gray-400 font-bold uppercase tracking-wider mb-0.5">Relationship</p>
                            <p id="m-em-rel" class="text-base font-bold text-gray-700">---</p>
                        </div>
                        <div>
                            <p class="text-[10px] text-gray-400 font-bold uppercase tracking-wider mb-0.5">Contact Number</p>
                            <p id="m-em-contact" class="text-base font-bold text-gray-700">---</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-200">
                    <div class="flex justify-between items-end border-b border-gray-100 pb-2 mb-4">
                        <h3 class="text-[#3d143e] font-bold text-lg"><i class="fas fa-id-card mr-2 text-[#c6943a]"></i> Presented Identification</h3>
                        <div class="text-right">
                            <p id="m-idtype" class="text-sm font-black text-[#5b8fb0] uppercase tracking-wide">---</p>
                            <p id="m-idnum" class="text-xs font-bold text-gray-500 tracking-wider">ID: ---</p>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="bg-gray-100 p-2 rounded-xl">
                            <p class="text-center font-bold text-gray-500 mb-2 text-xs uppercase tracking-widest">Front of ID</p>
                            <img id="m-front" src="" class="w-full h-48 md:h-64 object-contain rounded-lg shadow-sm border border-white cursor-pointer hover:opacity-80 transition bg-white" onclick="window.open(this.src, '_blank')">
                        </div>
                        <div class="bg-gray-100 p-2 rounded-xl">
                            <p class="text-center font-bold text-gray-500 mb-2 text-xs uppercase tracking-widest">Back of ID</p>
                            <img id="m-back" src="" class="w-full h-48 md:h-64 object-contain rounded-lg shadow-sm border border-white cursor-pointer hover:opacity-80 transition bg-white" onclick="window.open(this.src, '_blank')">
                        </div>
                    </div>
                </div>

            </div>

            <div class="p-5 md:px-8 border-t border-gray-200 bg-white shrink-0" id="action-container">
                <form method="POST" action="" id="review-action-form">
                    <input type="hidden" name="verif_id" id="f-vid">
                    <input type="hidden" name="user_id" id="f-uid">
                    <input type="hidden" name="action" id="f-action">
                    <input type="hidden" name="reject_reason" id="f-reason"> <div id="dynamic-action-content" class="w-full">
                        </div>
                </form>
            </div>
        </div>
    </div>

    <div id="reject-reason-modal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-sm z-[100] flex items-center justify-center p-4">
        <div class="bg-white w-full max-w-md rounded-2xl shadow-2xl p-6">
            <h3 class="text-xl font-bold text-red-600 mb-2 border-b border-red-100 pb-2"><i class="fas fa-exclamation-circle mr-2"></i> Reject Verification</h3>
            <p class="text-sm text-gray-600 mb-4">Please provide a reason for rejecting this verification request. The citizen will receive this explanation via notification.</p>
            
            <textarea id="reject-reason-input" class="w-full h-32 p-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-red-500 mb-4 text-sm" placeholder="e.g. The ID picture is too blurry to read, or the uploaded document is expired..."></textarea>
            
            <div class="flex justify-end gap-3">
                <button type="button" onclick="closeRejectModal()" class="px-5 py-2 rounded-lg bg-gray-100 text-gray-700 font-bold hover:bg-gray-200 transition text-sm">Cancel</button>
                <button type="button" onclick="submitRejection()" class="px-5 py-2 rounded-lg bg-red-500 text-white font-bold shadow-md hover:bg-red-600 transition text-sm flex items-center gap-2"><i class="fas fa-paper-plane"></i> Send Rejection</button>
            </div>
        </div>
    </div>

    <script>
        function openReviewModal(data) {
            // Profile Image Logic
            const imgEl = document.getElementById('m-profile-img');
            const iconEl = document.getElementById('m-profile-icon');
            if (data.profile_image) {
                imgEl.src = data.profile_image;
                imgEl.classList.remove('hidden');
                iconEl.classList.add('hidden');
            } else {
                imgEl.classList.add('hidden');
                iconEl.classList.remove('hidden');
            }
            
            // Personal Info
            let mname = data.middle_name ? ' ' + data.middle_name : '';
            let ext = data.name_extension ? ' ' + data.name_extension : '';
            document.getElementById('m-name').innerText = data.last_name + ', ' + data.first_name + mname + ext;
            document.getElementById('m-civil').innerText = data.civil_status || 'N/A';
            document.getElementById('m-income').innerText = data.family_income || 'N/A';

            // Contact & Address
            document.getElementById('m-mobile').innerText = data.mobile_number || data.contact_number || 'N/A';
            document.getElementById('m-gcash').innerText = data.gcash_number || 'N/A';
            document.getElementById('m-email').innerText = data.email || 'N/A';
            
            // Build Address
            let house = data.house_no || '';
            let street = data.street || '';
            let brgy = data.barangay || 'Sto. Rosario-Kanluran';
            let city = data.city || 'Pateros';
            let region = data.region || 'NCR';
            
            let fullAddress = [];
            if(house) fullAddress.push(house);
            if(street) fullAddress.push(street);
            if(fullAddress.length > 0) fullAddress.push('<br>');
            fullAddress.push(brgy + ', ' + city + ', ' + region);
            document.getElementById('m-address').innerHTML = fullAddress.join(' ').replace(' <br> ', '<br>');

            // Emergency Contact
            let em_mname = data.em_middle_name ? ' ' + data.em_middle_name : '';
            let em_ext = data.em_ext ? ' ' + data.em_ext : '';
            let em_full = '';
            if (data.em_last_name && data.em_first_name) {
                em_full = data.em_last_name + ', ' + data.em_first_name + em_mname + em_ext;
            } else {
                em_full = 'N/A';
            }
            
            document.getElementById('m-em-name').innerText = em_full;
            document.getElementById('m-em-rel').innerText = data.em_relationship || 'N/A';
            document.getElementById('m-em-contact').innerText = data.em_contact || 'N/A';

            // ID Documents
            document.getElementById('m-idtype').innerText = data.id_type;
            document.getElementById('m-idnum').innerText = 'ID: ' + (data.id_number || 'N/A');
            document.getElementById('m-front').src = data.id_front_path;
            document.getElementById('m-back').src = data.id_back_path;
            
            // Hidden Inputs
            document.getElementById('f-vid').value = data.id;
            document.getElementById('f-uid').value = data.user_id;

            // Handle Dynamic Action Buttons
            const actionContainer = document.getElementById('action-container');
            const dynamicContent = document.getElementById('dynamic-action-content');

            if (data.status === 'Pending') {
                // FIXED: Reject button now opens modal instead of submitting directly
                dynamicContent.innerHTML = `
                    <div class="flex justify-end gap-4 w-full">
                        <button type="button" onclick="openRejectModal()" class="px-6 md:px-8 py-3 rounded-full font-bold text-red-500 bg-red-50 border border-red-200 hover:bg-red-500 hover:text-white transition">Reject Verification</button>
                        <button type="button" onclick="submitApproval()" class="px-6 md:px-8 py-3 rounded-full font-bold text-white bg-green-500 shadow-md hover:bg-green-600 transition flex items-center gap-2"><i class="fas fa-check-circle"></i> Verify Account</button>
                    </div>
                `;
                actionContainer.style.display = 'block';
            } else if (data.status === 'Approved' && data.edit_reason) {
                dynamicContent.innerHTML = `
                    <div class="w-full text-left bg-blue-50 border border-blue-200 p-4 rounded-xl mb-4 text-blue-800 text-sm">
                        <strong class="block mb-1 text-xs uppercase tracking-wider text-blue-500">Reason for Edit Request:</strong> 
                        ${data.edit_reason}
                    </div>
                    <div class="flex justify-end gap-4 w-full">
                        <button type="submit" onclick="document.getElementById('f-action').value='reject_edit'" class="px-6 md:px-8 py-3 rounded-full font-bold text-gray-500 bg-gray-50 border border-gray-200 hover:bg-gray-500 hover:text-white transition">Deny Request</button>
                        <button type="submit" onclick="document.getElementById('f-action').value='approve_edit'" class="px-6 md:px-8 py-3 rounded-full font-bold text-white bg-blue-500 shadow-md hover:bg-blue-600 transition flex items-center gap-2"><i class="fas fa-unlock"></i> Grant Edit Access</button>
                    </div>
                `;
                actionContainer.style.display = 'block';
            } else {
                actionContainer.style.display = 'none';
            }

            document.getElementById('review-modal').classList.remove('hidden');
        }

        function closeReviewModal() {
            document.getElementById('review-modal').classList.add('hidden');
        }
        
        // --- NEW REJECTION MODAL LOGIC ---
        function submitApproval() {
            document.getElementById('f-action').value = 'approve';
            document.getElementById('review-action-form').submit();
        }

        function openRejectModal() {
            document.getElementById('reject-reason-input').value = ''; // Clear old reason
            document.getElementById('reject-reason-modal').classList.remove('hidden');
        }

        function closeRejectModal() {
            document.getElementById('reject-reason-modal').classList.add('hidden');
        }

        function submitRejection() {
            const reason = document.getElementById('reject-reason-input').value.trim();
            if (!reason) {
                alert("Please provide a reason for rejection.");
                return;
            }
            
            document.getElementById('f-reason').value = reason;
            document.getElementById('f-action').value = 'reject';
            document.getElementById('review-action-form').submit();
        }
    </script>
</body>
</html>