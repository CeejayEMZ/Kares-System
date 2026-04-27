<?php
// admin/delete_citizen.php
session_start();
require_once '../config/db_connect.php';

// 1. Security Check: Ensure the user is logged in and is an Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: ../auth/login.php");
    exit();
}

// 2. Validate Request: Ensure it's a POST request and we have an ID
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'])) {
    $user_id_to_delete = $_POST['user_id'];

    // Prevent the admin from accidentally deleting themselves!
    if ($user_id_to_delete == $_SESSION['user_id']) {
        header("Location: users.php?error=CannotDeleteSelf");
        exit();
    }

    try {
        // 3. Start Database Transaction
        $pdo->beginTransaction();

        // 4. Delete child records FIRST to avoid Foreign Key errors
        $pdo->prepare("DELETE FROM notifications WHERE user_id = ?")->execute([$user_id_to_delete]);
        $pdo->prepare("DELETE FROM user_verifications WHERE user_id = ?")->execute([$user_id_to_delete]);
        $pdo->prepare("DELETE FROM assistance_requests WHERE user_id = ?")->execute([$user_id_to_delete]);

        // 5. Delete the parent record (the actual user) LAST
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$user_id_to_delete]);

        // 6. Commit the changes permanently to the database
        $pdo->commit();

        // FIXED: Redirect back to users.php with a success message
        header("Location: users.php?msg=Deleted");
        exit();

    } catch (PDOException $e) {
        // 7. If anything fails, undo all the deletions in this block
        $pdo->rollBack();
        
        // FIXED: Redirect back to users.php with an error code
        header("Location: users.php?error=DeleteFailed");
        exit();
    }
} else {
    // FIXED: Kick them back to users.php if they accessed the file directly
    header("Location: users.php");
    exit();
}
?>