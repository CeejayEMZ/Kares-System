<?php
// admin/view_request.php
session_start();
require_once '../config/db_connect.php';

// Force PHP to use Manila time
date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: ../auth/login.php"); exit();
}

if (!isset($_GET['id'])) { header("Location: request.php"); exit(); }

$request_id = $_GET['id'];

// Fetch the full request details
$stmt = $pdo->prepare("SELECT * FROM assistance_requests WHERE request_id = :id");
$stmt->execute([':id' => $request_id]);
$req = $stmt->fetch();

if (!$req) { die("Request not found."); }

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

// Compile all uploaded files into an array for the neat grid display
$uploaded_files = [
    'ID Front' => $req['id_front_path'],
    'ID Back' => $req['id_back_path'],
    'Indigency Cert.' => $req['indigency_cert_path'],
    'Medical Cert.' => $req['medical_cert_path'],
    'Patient ID' => $req['patient_id_path'],
    'Claimant ID' => $req['claimant_id_path'],
    'Reseta' => $req['reseta_path'],
    'Social Case Study' => $req['social_case_path'],
    'Quotation' => $req['quotation_path'],
    'Endorsement' => $req['endorsement_path'],
    'Hospital Bill' => $req['hospital_bill_path'],
    'Promissory Note' => $req['promissory_note_path'],
    'Death Cert.' => $req['death_cert_path'],
    'Funeral Contract' => $req['funeral_contract_path'],
];

// Formatting the name properly
$name_extension = $req['name_extension'] ?? '';
$full_name = trim(strtoupper($req['last_name'] . ', ' . $req['first_name'] . ' ' . $req['middle_name'] . ' ' . $name_extension));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>View Request - KARES Admin</title>
    <link rel="icon" type="image/png" href="../assets/images/kareslogo.png" />
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style> 
        * { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; } 
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .animate-fade { animation: fadeIn 0.3s ease forwards; }
    </style>
</head>
<body class="bg-[#e0d5e8] flex relative">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="flex-1 ml-64 p-10 min-h-screen relative z-10">
        
        <div class="flex justify-between items-center mb-6">
            <div class="flex items-center gap-4">
                <a href="request.php" class="bg-white text-[#5b8fb0] w-10 h-10 rounded-full flex items-center justify-center shadow hover:bg-gray-50 transition"><i class="fas fa-arrow-left"></i></a>
                <h1 class="text-3xl font-bold text-[#3d143e]">Request Details</h1>
            </div>
            
            <div class="flex gap-3">
                <?php if ($req['status'] === 'Submitted'): ?>
                    <button type="button" onclick="openDeclineModal('<?= htmlspecialchars($req['request_id']) ?>')" class="bg-white border-2 border-red-500 text-red-500 px-6 py-2 rounded-xl font-bold shadow-sm hover:bg-red-50 transition">Decline</button>
                    <a href="../processors/update_status.php?id=<?= urlencode($req['request_id']) ?>&status=Approved" onclick="return confirm('Approve this request?')" class="bg-green-500 text-white px-6 py-2 rounded-xl font-bold shadow hover:bg-green-600 transition">Approve</a>
                <?php elseif ($req['status'] === 'Approved'): ?>
                    <a href="../processors/update_status.php?id=<?= urlencode($req['request_id']) ?>&status=Released" onclick="return confirm('Mark as Released?')" class="bg-purple-600 text-white px-6 py-2 rounded-xl font-bold shadow hover:bg-purple-700 transition"><i class="fas fa-box-open mr-2"></i> Release Aid</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            
            <div class="lg:col-span-1 space-y-6">
                <div class="bg-white rounded-3xl p-6 shadow-sm border border-gray-100 text-center">
                    <p class="text-gray-500 font-bold uppercase text-xs tracking-wider mb-2">Request ID</p>
                    <h2 class="text-2xl font-black text-[#5b8fb0] mb-4"><?= htmlspecialchars($req['request_id']) ?></h2>
                    
                    <div class="inline-block px-5 py-2 rounded-full font-bold text-sm border 
                        <?php 
                            if($req['status'] === 'Submitted') echo 'bg-yellow-50 text-yellow-600 border-yellow-200';
                            elseif($req['status'] === 'Approved') echo 'bg-green-50 text-green-600 border-green-200';
                            elseif($req['status'] === 'Released') echo 'bg-purple-50 text-purple-600 border-purple-200';
                            else echo 'bg-red-50 text-red-600 border-red-200';
                        ?>">
                        Current Status: <?= htmlspecialchars($req['status']) ?>
                    </div>
                </div>

                <div class="bg-white rounded-3xl p-6 shadow-sm border border-gray-100">
                    <h3 class="font-bold text-[#3d143e] mb-4 border-b pb-2"><i class="fas fa-user text-[#c6943a] mr-2"></i> Applicant Info</h3>
                    <div class="space-y-4 text-sm">
                        <div><span class="block text-gray-400 text-xs font-bold uppercase">Full Name</span><span class="font-bold text-gray-800 text-base"><?= htmlspecialchars($full_name) ?></span></div>
                        <div><span class="block text-gray-400 text-xs font-bold uppercase">Contact / Email</span><span class="font-medium text-gray-800"><?= htmlspecialchars($req['mobile_number']) ?><br><?= htmlspecialchars($req['email']) ?></span></div>
                        <div><span class="block text-gray-400 text-xs font-bold uppercase">Civil Status / Income</span><span class="font-medium text-gray-800"><?= htmlspecialchars($req['civil_status']) ?> | ₱<?= htmlspecialchars($req['family_income']) ?></span></div>
                        <div><span class="block text-gray-400 text-xs font-bold uppercase">Address</span><span class="font-medium text-gray-800"><?= htmlspecialchars($req['house_no'] . ' ' . $req['street']) ?><br><?= htmlspecialchars($req['barangay']) ?>, <?= htmlspecialchars($req['city']) ?></span></div>
                    </div>
                </div>

                <div class="bg-white rounded-3xl p-6 shadow-sm border border-gray-100">
                    <h3 class="font-bold text-[#3d143e] mb-4 border-b pb-2"><i class="fas fa-phone-alt text-[#c6943a] mr-2"></i> Emergency Contact</h3>
                    <div class="space-y-3 text-sm">
                        <div><span class="block text-gray-400 text-xs font-bold uppercase">Name (<?= htmlspecialchars($req['em_relationship']) ?>)</span><span class="font-bold text-gray-800"><?= htmlspecialchars($req['em_last_name'] . ', ' . $req['em_first_name']) ?></span></div>
                        <div><span class="block text-gray-400 text-xs font-bold uppercase">Contact Number</span><span class="font-medium text-gray-800"><?= htmlspecialchars($req['em_contact']) ?></span></div>
                    </div>
                </div>
            </div>

            <div class="lg:col-span-2 space-y-6">
                
                <div class="bg-white rounded-3xl p-6 shadow-sm border border-gray-100">
                    <div class="flex justify-between items-start border-b pb-4 mb-4">
                        <div>
                            <h3 class="font-bold text-[#3d143e] text-lg"><i class="fas fa-hands-helping text-[#c6943a] mr-2"></i> Assistance Details</h3>
                            <p class="text-sm font-bold text-[#5b8fb0] mt-1"><?= htmlspecialchars($req['assistance_type']) ?></p>
                        </div>
                        <div class="text-right">
                            <span class="text-xs text-gray-400 font-medium bg-gray-50 px-3 py-1 rounded-md block mb-1">
                                <i class="far fa-paper-plane mr-1"></i> Submitted: <?= formatManilaTime($req['date_submitted']) ?>
                            </span>
                            <?php if ($req['status'] !== 'Submitted'): ?>
                                <span class="text-xs font-bold px-3 py-1 rounded-md block
                                    <?php 
                                        if($req['status'] === 'Approved') echo 'text-green-600 bg-green-50';
                                        elseif($req['status'] === 'Released') echo 'text-purple-600 bg-purple-50';
                                        else echo 'text-red-600 bg-red-50';
                                    ?>">
                                    <i class="far fa-clock mr-1"></i> <?= $req['status'] ?>: <?= formatManilaTime($req['date_updated']) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <h4 class="text-xs text-gray-400 font-bold uppercase mb-2">Citizen's Description of Need</h4>
                    <div class="bg-blue-50 p-4 rounded-xl border border-blue-100">
                        <p class="text-gray-700 italic leading-relaxed text-sm">"<?= nl2br(htmlspecialchars($req['description'])) ?>"</p>
                    </div>

                    <?php if (!empty($req['rejection_reason']) && $req['status'] === 'Declined'): ?>
                    <h4 class="text-xs text-red-500 font-bold uppercase mt-4 mb-2">Reason for Decline</h4>
                    <div class="bg-red-50 p-4 rounded-xl border border-red-100">
                        <p class="text-red-700 leading-relaxed text-sm"><?= nl2br(htmlspecialchars($req['rejection_reason'])) ?></p>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="bg-white rounded-3xl p-6 shadow-sm border border-gray-100">
                    <h3 class="font-bold text-[#3d143e] mb-4 border-b pb-2"><i class="fas fa-folder-open text-[#c6943a] mr-2"></i> Submitted Requirements</h3>
                    
                    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                        <?php 
                        $has_files = false;
                        foreach ($uploaded_files as $label => $path): 
                            if (!empty($path)): 
                                $has_files = true;
                                
                                // Determine if the path is an external URL (like Supabase) or local
                                $is_url = filter_var($path, FILTER_VALIDATE_URL);
                                $img_src = $is_url ? htmlspecialchars($path) : "../" . htmlspecialchars($path);
                        ?>
                            <div class="group relative bg-gray-50 rounded-2xl border border-gray-200 overflow-hidden shadow-sm hover:shadow-md transition">
                                <div class="w-full h-32 bg-gray-200">
                                    <img src="<?= $img_src ?>" class="w-full h-full object-cover opacity-90 group-hover:opacity-100 transition" alt="<?= $label ?>" onerror="this.src='https://ui-avatars.com/api/?name=File&background=e0d5e8&color=3d143e'">
                                </div>
                                <div class="p-2 text-center bg-white border-t border-gray-100">
                                    <p class="text-xs font-bold text-[#3d143e] truncate"><?= $label ?></p>
                                </div>
                                <a href="<?= $img_src ?>" target="_blank" class="absolute inset-0 bg-[#3d143e]/60 flex items-center justify-center opacity-0 group-hover:opacity-100 transition backdrop-blur-sm">
                                    <span class="bg-white text-[#3d143e] text-xs font-bold px-4 py-2 rounded-full shadow-lg"><i class="fas fa-external-link-alt mr-1"></i> View Full</span>
                                </a>
                            </div>
                        <?php 
                            endif; 
                        endforeach; 
                        
                        if (!$has_files): ?>
                            <div class="col-span-full text-center py-10 bg-gray-50 rounded-xl border border-gray-200 border-dashed">
                                <i class="fas fa-exclamation-circle text-3xl text-gray-300 mb-2 block"></i>
                                <p class="text-sm font-medium text-gray-500">No supporting documents were uploaded for this request.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <div id="decline-reason-modal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-sm z-[100] flex items-center justify-center p-4 animate-fade">
        <div class="bg-white w-full max-w-md rounded-2xl shadow-2xl p-6">
            <h3 class="text-xl font-bold text-red-600 mb-2 border-b border-red-100 pb-2"><i class="fas fa-exclamation-triangle mr-2"></i> Decline Request</h3>
            <p class="text-sm text-gray-600 mb-1">You are declining Request ID: <strong id="decline-req-id-display" class="text-gray-900"></strong></p>
            <p class="text-sm text-gray-600 mb-4">Please provide a reason. This explanation will be sent to the citizen via email and notification.</p>
            
            <form action="../processors/update_status.php" method="POST">
                <input type="hidden" name="id" id="decline-req-id-input">
                <input type="hidden" name="status" value="Declined">
                
                <textarea name="reject_reason" required class="w-full h-32 p-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-red-500 mb-4 text-sm" placeholder="e.g. You are lacking the Medical Abstract requirement. Please submit a new request with complete documents..."></textarea>
                
                <div class="flex justify-end gap-3">
                    <button type="button" onclick="closeDeclineModal()" class="px-5 py-2 rounded-lg bg-gray-100 text-gray-700 font-bold hover:bg-gray-200 transition text-sm">Cancel</button>
                    <button type="submit" class="px-5 py-2 rounded-lg bg-red-500 text-white font-bold shadow-md hover:bg-red-600 transition text-sm flex items-center gap-2"><i class="fas fa-paper-plane"></i> Decline Request</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openDeclineModal(requestId) {
            document.getElementById('decline-req-id-display').innerText = requestId;
            document.getElementById('decline-req-id-input').value = requestId;
            document.getElementById('decline-reason-modal').classList.remove('hidden');
        }

        function closeDeclineModal() {
            document.getElementById('decline-reason-modal').classList.add('hidden');
        }
    </script>
</body>
</html>