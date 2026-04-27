<?php
// admin/includes/sidebar.php
$current_page = basename($_SERVER['PHP_SELF']);

// Grab the admin's name from the session, with a fallback if it's missing
$admin_name = $_SESSION['username'] ?? 'Administrator';
$admin_initial = strtoupper(substr($admin_name, 0, 1));

// Fetch the profile image from the database to ensure it's always up to date
$sb_avatar = '';
if (isset($_SESSION['user_id']) && isset($pdo)) {
    try {
        $sb_stmt = $pdo->prepare("SELECT profile_image FROM users WHERE id = :id");
        $sb_stmt->execute([':id' => $_SESSION['user_id']]);
        $sb_user = $sb_stmt->fetch();
        $sb_avatar = $sb_user['profile_image'] ?? '';
    } catch (PDOException $e) {
        // Silently ignore if DB query fails so the sidebar doesn't crash
    }
}
?>
<div class="fixed top-0 left-0 h-screen w-64 bg-[#3d143e] text-white p-6 flex flex-col shadow-2xl z-50">
    
    <div class="flex items-center gap-3 border-b border-[#CCBFD5]/30 pb-6 mb-8">
        <img src="../assets/images/navlogo.png" alt="KARES Logo" class="h-12 w-auto">
        <div>
            <span class="text-xl font-black text-white tracking-tight">KARES</span>
            <span class="text-xs block text-[#c6943a] font-bold uppercase tracking-widest">Admin</span>
        </div>
    </div>
    
    <nav class="flex-1 space-y-2 text-sm overflow-y-auto">
        <a href="dashboard.php" class="flex items-center gap-3 px-5 py-3 rounded-xl font-semibold transition <?= ($current_page == 'dashboard.php') ? 'bg-[#c6943a] text-white shadow-md' : 'text-white/80 hover:bg-white/10 hover:text-white' ?>">
            <i class="fas fa-th-large text-lg w-6"></i> Dashboard
        </a>
        
        <a href="request.php" class="flex items-center gap-3 px-5 py-3 rounded-xl font-semibold transition <?= ($current_page == 'request.php') ? 'bg-[#c6943a] text-white shadow-md' : 'text-white/80 hover:bg-white/10 hover:text-white' ?>">
            <i class="fas fa-search text-lg w-6"></i> All Request
        </a>
        
        <a href="approval.php" class="flex items-center gap-3 px-5 py-3 rounded-xl font-semibold transition <?= ($current_page == 'approval.php') ? 'bg-[#c6943a] text-white shadow-md' : 'text-white/80 hover:bg-white/10 hover:text-white' ?>">
            <i class="fas fa-user-check text-lg w-6"></i> Approval
        </a>
        
        <a href="released.php" class="flex items-center gap-3 px-5 py-3 rounded-xl font-semibold transition <?= ($current_page == 'released.php') ? 'bg-[#c6943a] text-white shadow-md' : 'text-white/80 hover:bg-white/10 hover:text-white' ?>">
            <i class="fas fa-box-open text-lg w-6"></i> Released Aid
        </a>
        
        <a href="users.php" class="flex items-center gap-3 px-5 py-3 rounded-xl font-semibold transition <?= ($current_page == 'users.php') ? 'bg-[#c6943a] text-white shadow-md' : 'text-white/80 hover:bg-white/10 hover:text-white' ?>">
            <i class="fas fa-users text-lg w-6"></i> Manage Citizens
        </a>
        
        <a href="reports.php" class="flex items-center gap-3 px-5 py-3 rounded-xl font-semibold transition <?= ($current_page == 'reports.php') ? 'bg-[#c6943a] text-white shadow-md' : 'text-white/80 hover:bg-white/10 hover:text-white' ?>">
            <i class="fas fa-chart-pie text-lg w-6"></i> Reports
        </a>
        
        <a href="verifications.php" class="flex items-center gap-3 px-5 py-3 rounded-xl font-semibold transition <?= ($current_page == 'verifications.php') ? 'bg-[#c6943a] text-white shadow-md' : 'text-white/80 hover:bg-white/10 hover:text-white' ?>">
            <i class="fas fa-id-card-alt text-lg w-6"></i> Verifications
        </a>
    </nav>
    
    <div class="mt-auto border-t border-[#CCBFD5]/30 pt-6 mt-6">
        
        <a href="admin_profile.php" class="flex items-center gap-3 mb-4 px-2 hover:bg-white/10 p-2 rounded-xl transition cursor-pointer group">
            <div class="w-10 h-10 rounded-full bg-[#c6943a] flex items-center justify-center text-[#3d143e] font-bold text-lg shadow-inner shrink-0 group-hover:scale-105 transition transform overflow-hidden">
                <?php if (!empty($sb_avatar)): ?>
                    <img src="<?= htmlspecialchars($sb_avatar) ?>" alt="Admin Avatar" class="w-full h-full object-cover">
                <?php else: ?>
                    <?= $admin_initial ?>
                <?php endif; ?>
            </div>
            <div class="overflow-hidden">
                <p class="text-sm font-bold text-white truncate capitalize group-hover:text-[#c6943a] transition"><?= htmlspecialchars($admin_name) ?></p>
                <p class="text-[10px] text-[#CCBFD5] font-bold uppercase tracking-wider">System Admin</p>
            </div>
        </a>

        <a href="../processors/logout.php" class="flex items-center justify-center gap-2 bg-white/10 px-5 py-3 rounded-full font-bold text-red-300 hover:bg-white/20 hover:text-red-100 transition shadow-inner w-full">
            <i class="fas fa-sign-out-alt"></i> Sign Out
        </a>
        
    </div>
</div>