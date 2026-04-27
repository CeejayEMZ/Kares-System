<?php
// processors/logout.php
session_start();

// 1. Clear all session variables
$_SESSION = array();

// 2. Destroy the session itself
session_destroy();

// 3. Destroy the "Remember Me" cookie if it exists
if (isset($_COOKIE['kares_remember_me'])) {
    unset($_COOKIE['kares_remember_me']); 
    // Set the cookie's expiration date to the past to force the browser to delete it
    setcookie('kares_remember_me', '', time() - 3600, '/'); 
}

// 4. Send them back to the login page
header("Location: ../auth/login.php");
exit();
?>