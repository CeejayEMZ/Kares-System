<?php
// auth/verify_login.php
session_start();
require_once '../config/db_connect.php'; 

// Load PHPMailer classes
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require_once '../vendor/Exception.php';
require_once '../vendor/PHPMailer.php';
require_once '../vendor/SMTP.php';

// Ensure the user actually came from the login page
if (!isset($_SESSION['pending_login_id'])) {
    header("Location: login.php?error=missing_session");
    exit();
}

$user_id = $_SESSION['pending_login_id'];
$remember_me = $_SESSION['pending_remember'] ?? 'no';

// Fetch user data for the welcome message & Resend Email
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit();
}

$display_name = $user['first_name'] ?? $user['username'] ?? 'User';
$error = '';
$success = '';

// --- HANDLE RESEND OTP ---
if (isset($_GET['action']) && $_GET['action'] == 'resend') {
    $new_otp = sprintf("%06d", mt_rand(100000, 999999));
    
    // Update DB
    $update_stmt = $pdo->prepare("UPDATE users SET otp_code = :otp WHERE id = :id");
    $update_stmt->execute([':otp' => $new_otp, ':id' => $user_id]);

    // Send new Email
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com'; 
        $mail->SMTPAuth   = true;
        $mail->Username   = 'adminkares@gmail.com'; 
        $mail->Password   = 'vborsopcwunmcfxv'; 
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('adminkares@gmail.com', 'Barangay KARES Portal');
        $mail->addAddress($user['email']);
        
        $mail->isHTML(true);
        $mail->Subject = 'Your NEW KARES Login OTP';
        $mail->Body    = "
        <div style='font-family: Arial, sans-serif; max-width: 500px; margin: auto; padding: 20px; border: 1px solid #ddd; border-radius: 10px; background-color: #f9f9f9;'>
            <h2 style='color: #3d143e; text-align: center;'>New Login Verification</h2>
            <p style='color: #333; font-size: 16px;'>Hello,</p>
            <p style='color: #333; font-size: 16px;'>You requested a new code. Please use the following One-Time Password to access your KARES account.</p>
            <div style='text-align: center; margin: 30px 0;'>
                <span style='background-color: #3d143e; color: #fff; padding: 15px 25px; font-size: 24px; font-weight: bold; border-radius: 5px; letter-spacing: 5px;'>{$new_otp}</span>
            </div>
            <p style='color: #777; font-size: 12px; text-align: center;'>If you did not request this login, please ignore this email.</p>
        </div>";

        $mail->send();
        $success = "A new code has been sent to your email.";
    } catch (Exception $e) {
        $error = "Failed to resend code.";
    }
}


// --- HANDLE OTP VERIFICATION ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['otp'])) {
    
    // Combine the 6 inputs into one string
    if (is_array($_POST['otp'])) {
        $entered_otp = implode('', $_POST['otp']);
    } else {
        $entered_otp = trim($_POST['otp']);
    }

    if ($entered_otp === (string)$user['otp_code']) {
        
        // Success! Clear the OTP from the database
        $clear_stmt = $pdo->prepare("UPDATE users SET otp_code = NULL WHERE id = :id");
        $clear_stmt->execute([':id' => $user_id]);

        // HANDLE REMEMBER ME - Actually create the token!
        if ($remember_me === 'yes') {
            $token = bin2hex(random_bytes(32)); 
            
            // Assuming your users table has a `remember_token` column (VARCHAR 64 or 255)
            try {
                $token_stmt = $pdo->prepare("UPDATE users SET remember_token = :token WHERE id = :id");
                $token_stmt->execute([':token' => $token, ':id' => $user_id]);
                // Set cookie for 30 days
                setcookie('kares_remember_me', $token, time() + (86400 * 30), "/"); 
            } catch (PDOException $e) {
                // If the column doesn't exist, we just skip "Remember Me" for now to prevent a crash
            }
        }

        // Establish full session
        $_SESSION['user_id'] = $user_id;
        $_SESSION['role'] = $user['role'] ?? 'User';
        $_SESSION['username'] = ucfirst($display_name);

        // Remove temporary variables
        unset($_SESSION['pending_login_id']);
        unset($_SESSION['pending_remember']);

        // Redirect based on role
        if ($_SESSION['role'] === 'Admin') {
            header("Location: ../admin/dashboard.php");
        } else {
            header("Location: ../user/user_home.php");
        }
        exit();
        
    } else {
        // DO NOT REDIRECT! Just set the error message.
        $error = "Invalid OTP. Please check your email and try again.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <link rel="icon" type="image/png" href="../assets/images/kareslogo.png" />
  <title>KARES - Verify Login</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    body { display: flex; justify-content: center; align-items: center; min-height: 100vh; background-image: url('../assets/images/bg.png'); background-size: cover; background-position: center; background-color: rgba(0, 0, 0, 0.65); background-blend-mode: overlay; padding: 15px;}
    
    .otp-container { display: flex; width: 100%; max-width: 450px; background: linear-gradient(170deg, #A462A9 10%, #4B184F 50%); border-radius: 20px; box-shadow: 0 15px 35px rgba(0, 0, 0, 0.4); overflow: hidden; flex-direction: column; padding: 40px 30px; align-items: center; text-align: center; }
    
    .logo { width: 80px; height: 80px; object-fit: contain; margin-bottom: 15px; border-radius: 50%; }
    .title { color: white; font-size: 24px; font-weight: 600; margin-bottom: 10px; }
    .subtitle { color: #cca8d6; font-size: 14px; margin-bottom: 30px; line-height: 1.4; }
    
    /* Base OTP inputs for Desktop/Tablets */
    .otp-inputs { display: flex; gap: 10px; justify-content: center; margin-bottom: 25px; width: 100%;}
    .otp-input { width: 45px; height: 50px; text-align: center; font-size: 24px; font-weight: bold; border: none; border-radius: 10px; background-color: #f4f4f4; color: #333; outline: none; transition: all 0.3s ease; }
    .otp-input:focus { background-color: white; box-shadow: 0 0 0 3px #d6af3c; }
    
    .btn-container { display: flex; justify-content: center; width: 100%; }
    .verify-btn { background-color: #d6af3c; color: #111; border: none; padding: 14px 40px; border-radius: 30px; font-weight: 700; font-size: 15px; cursor: pointer; transition: background-color 0.3s; width: 100%; letter-spacing: 1px;}
    .verify-btn:hover { background-color: #bfa035; }
    
    .error-message { color: #ffb3b3; background: rgba(255, 0, 0, 0.1); padding: 10px; border-radius: 10px; font-size: 13px; margin-bottom: 15px; border: 1px solid rgba(255, 0, 0, 0.2); width: 100%; }
    .success-message { color: #b3ffb3; background: rgba(0, 255, 0, 0.1); padding: 10px; border-radius: 10px; font-size: 13px; margin-bottom: 15px; border: 1px solid rgba(0, 255, 0, 0.2); width: 100%; }
    
    .resend-text { color: #cca8d6; font-size: 13px; margin-top: 25px; }
    .resend-link { color: #d6af3c; text-decoration: none; font-weight: bold; cursor: pointer; }
    .resend-link:hover { text-decoration: underline; }
    .cancel-link { display: block; margin-top: 15px; color: #cca8d6; text-decoration: none; font-size: 12px; }
    .cancel-link:hover { color: white; }

    /* --- MOBILE RESPONSIVENESS --- */
    @media (max-width: 400px) {
        .otp-container { padding: 30px 20px; }
        .title { font-size: 20px; }
        .subtitle { font-size: 13px; margin-bottom: 20px; }
        
        /* Shrink gap and box size to ensure they fit without scrolling */
        .otp-inputs { gap: 6px; margin-bottom: 20px;}
        .otp-input { width: 38px; height: 45px; font-size: 20px; border-radius: 8px;}
        
        .verify-btn { padding: 12px 20px; font-size: 14px; }
    }
    
    @media (max-width: 320px) {
        .otp-container { padding: 25px 15px; }
        .otp-inputs { gap: 4px; }
        .otp-input { width: 32px; height: 40px; font-size: 18px; border-radius: 6px;}
    }
  </style>
</head>
<body>
  <div class="otp-container">
    <img src="../assets/images/kareslogo.png" alt="KARES" class="logo" onerror="this.style.display='none'" />
    <h2 class="title">Welcome, <?= htmlspecialchars($display_name) ?></h2>
    <p class="subtitle">To keep your account secure, we've sent a 6-digit verification code to your email.</p>
    
    <?php if (!empty($error)): ?>
      <div class="error-message"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if (!empty($success)): ?>
      <div class="success-message"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <form action="" method="POST" id="otpForm" style="width: 100%;">
      <div class="otp-inputs">
        <input type="number" name="otp[]" class="otp-input" maxlength="1" required autocomplete="off" autofocus inputmode="numeric" pattern="\d*" />
        <input type="number" name="otp[]" class="otp-input" maxlength="1" required autocomplete="off" inputmode="numeric" pattern="\d*" />
        <input type="number" name="otp[]" class="otp-input" maxlength="1" required autocomplete="off" inputmode="numeric" pattern="\d*" />
        <input type="number" name="otp[]" class="otp-input" maxlength="1" required autocomplete="off" inputmode="numeric" pattern="\d*" />
        <input type="number" name="otp[]" class="otp-input" maxlength="1" required autocomplete="off" inputmode="numeric" pattern="\d*" />
        <input type="number" name="otp[]" class="otp-input" maxlength="1" required autocomplete="off" inputmode="numeric" pattern="\d*" />
      </div>
      <div class="btn-container">
          <button type="submit" class="verify-btn">VERIFY ACCOUNT</button>
      </div>
    </form>
    
    <p class="resend-text">Didn't receive the code? <a href="?action=resend" class="resend-link" onclick="this.innerText='Sending...';">Resend it</a></p>
    <a href="login.php" class="cancel-link">Back to Login</a>
  </div>

  <script>
    // Auto-focus logic for the 6 boxes
    const inputs = document.querySelectorAll('.otp-input');
    inputs.forEach((input, index) => {
      // Input event handles typing
      input.addEventListener('input', function() {
        // Force numeric only (just in case)
        this.value = this.value.replace(/[^0-9]/g, '');
        
        if (this.value.length === 1 && index < inputs.length - 1) {
          inputs[index + 1].focus();
        }
      });
      
      // Keydown handles backspace
      input.addEventListener('keydown', function(e) {
        if (e.key === 'Backspace' && this.value.length === 0 && index > 0) {
          inputs[index - 1].focus();
        }
      });
      
      // Handle pasting a full 6-digit code
      input.addEventListener('paste', function(e) {
          const pasteData = e.clipboardData.getData('text').replace(/[^0-9]/g, '').slice(0,6);
          if (pasteData.length === 6) {
              e.preventDefault();
              for(let i=0; i<6; i++) {
                  inputs[i].value = pasteData[i];
              }
              inputs[5].focus();
              document.getElementById('otpForm').submit(); // Auto submit!
          }
      });
    });
  </script>
</body>
</html>