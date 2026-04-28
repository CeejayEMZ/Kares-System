<?php
// auth/forgot_password.php
session_start();
require_once '../config/db_connect.php'; 

// Helper function to send the OTP email via API
function sendResetEmail($email, $code, &$error) {
    // Securely fetch the API Key from Railway
    $api_key = $_SERVER['BREVO_API_KEY'] ?? $_ENV['BREVO_API_KEY'] ?? getenv('BREVO_API_KEY') ?? '';

    if (empty($api_key)) {
        $error = "Server configuration error. API key missing.";
        return false;
    }

    $htmlContent = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e0d5e8; border-radius: 10px;'>
            <h2 style='color: #3d143e;'>Password Reset Request</h2>
            <p style='color: #555;'>We received a request to reset your KARES password.</p>
            <p style='color: #555;'>Your 6-digit recovery code is:</p>
            <div style='background-color: #f4f4f4; padding: 15px; text-align: center; border-radius: 8px; margin: 20px 0;'>
                <span style='font-size: 24px; font-weight: bold; letter-spacing: 5px; color: #c6943a;'>{$code}</span>
            </div>
            <p style='color: #999; font-size: 12px;'>This code will expire in 10 minutes. If you did not request a password reset, you can safely ignore this email.</p>
        </div>
    ";

    $data = [
        'sender' => ['name' => 'KARES Support', 'email' => 'adminkares@gmail.com'],
        'to' => [['email' => $email]],
        'subject' => 'Your KARES Password Reset Code',
        'htmlContent' => $htmlContent
    ];

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'accept: application/json',
        'api-key: ' . $api_key,
        'content-type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode == 201 || $httpcode == 200 || $httpcode == 202) {
        return true;
    } else {
        $error = "Failed to send reset email. Please try again later.";
        error_log("Brevo API Error (Password Reset): " . $response);
        return false;
    }
}

$step = 1;
$error = '';
$success = '';
$email_input = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST['action'] ?? '';

    // --- STEP 1: Process Email ---
    if ($action === 'send_otp') {
        $email_input = trim($_POST['email'] ?? '');
        
        if (empty($email_input)) {
            $error = "Please enter your email address.";
        } else {
            $stmt = $pdo->prepare("SELECT id, email, first_name, username FROM users WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => $email_input]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                $otp = sprintf("%06d", mt_rand(100000, 999999));
                
                // Save OTP to user record
                $update_stmt = $pdo->prepare("UPDATE users SET otp_code = :otp WHERE id = :id");
                $update_stmt->execute([':otp' => $otp, ':id' => $user['id']]);

                if (sendResetEmail($user['email'], $otp, $error)) {
                    $_SESSION['reset_user_id'] = $user['id'];
                    $_SESSION['reset_email'] = $user['email'];
                    $_SESSION['reset_expires'] = time() + (10 * 60); // 10 minutes
                    $step = 2;
                    $success = "A verification code has been sent to your email.";
                }
            } else {
                $error = "Account not found with that email address.";
            }
        }
    }
    
    // --- STEP 2: Process OTP ---
    elseif ($action === 'verify_otp') {
        $step = 2; // Stay on step 2 if fails
        $entered_otp = '';
        if (is_array($_POST['otp'])) {
            $entered_otp = implode('', $_POST['otp']);
        } else {
            $entered_otp = trim($_POST['otp']);
        }

        if (!isset($_SESSION['reset_user_id'])) {
            $error = "Session expired. Please start over.";
            $step = 1;
        } elseif (time() > $_SESSION['reset_expires']) {
            $error = "Verification code has expired. Please try again.";
            $step = 1;
        } else {
            $stmt = $pdo->prepare("SELECT otp_code FROM users WHERE id = :id");
            $stmt->execute([':id' => $_SESSION['reset_user_id']]);
            $db_otp = $stmt->fetchColumn();

            if ($entered_otp === (string)$db_otp) {
                // Success! Clear OTP and move to step 3
                $clear_stmt = $pdo->prepare("UPDATE users SET otp_code = NULL WHERE id = :id");
                $clear_stmt->execute([':id' => $_SESSION['reset_user_id']]);
                $_SESSION['can_reset_password'] = true;
                $step = 3;
            } else {
                $error = "Incorrect code. Please try again.";
            }
        }
    }

    // --- STEP 3: Process New Password ---
    elseif ($action === 'reset_password') {
        $step = 3;
        if (!isset($_SESSION['can_reset_password']) || !$_SESSION['can_reset_password']) {
            $error = "Unauthorized request.";
            $step = 1;
        } else {
            $password = $_POST['password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';

            if (empty($password) || empty($confirm_password)) {
                $error = "All fields are required.";
            } elseif (strlen($password) < 12) {
                $error = "Password must be at least 12 characters long.";
            } elseif (!preg_match('/[A-Z]/', $password)) {
                $error = "Password must contain at least 1 uppercase letter.";
            } elseif (!preg_match('/[a-z]/', $password)) {
                $error = "Password must contain at least 1 lowercase letter.";
            } elseif (!preg_match('/[\W_]/', $password)) { 
                $error = "Password must contain at least 1 special character (e.g., *,#,!,@).";
            } elseif ($password !== $confirm_password) {
                $error = "Passwords do not match.";
            } else {
                // Update password in DB
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password = :pass WHERE id = :id");
                
                if ($stmt->execute([':pass' => $hashed_password, ':id' => $_SESSION['reset_user_id']])) {
                    session_destroy(); // Clean up session
                    $step = 4; // Show success screen
                } else {
                    $error = "Failed to update password. Please try again.";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <link rel="icon" type="image/png" href="../assets/images/kareslogo.png" />
  <title>KARES - Forgot Password</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    body { display: flex; justify-content: center; align-items: center; min-height: 100vh; background-image: url('../assets/images/bg.png'); background-size: cover; background-position: center; background-color: rgba(0, 0, 0, 0.65); background-blend-mode: overlay; padding: 15px;}
    
    .otp-container { display: flex; width: 100%; max-width: 450px; background: linear-gradient(170deg, #A462A9 10%, #4B184F 50%); border-radius: 20px; box-shadow: 0 15px 35px rgba(0, 0, 0, 0.4); overflow: hidden; flex-direction: column; padding: 40px 30px; align-items: center; text-align: center; position: relative;}
    
    .logo { width: 80px; height: 80px; object-fit: contain; margin-bottom: 15px; border-radius: 50%; }
    .title { color: white; font-size: 24px; font-weight: 600; margin-bottom: 10px; }
    .subtitle { color: #cca8d6; font-size: 14px; margin-bottom: 30px; line-height: 1.4; }
    
    .input-group { position: relative; margin-bottom: 15px; width: 100%; }
    .input-field { width: 100%; padding: 14px 20px; border: none; border-radius: 30px; font-size: 14px; background-color: #f4f4f4; color: #333; outline: none; }
    
    /* OTP inputs */
    .otp-inputs { display: flex; gap: 10px; justify-content: center; margin-bottom: 25px; width: 100%;}
    .otp-input { width: 45px; height: 50px; text-align: center; font-size: 24px; font-weight: bold; border: none; border-radius: 10px; background-color: #f4f4f4; color: #333; outline: none; transition: all 0.3s ease; }
    .otp-input:focus { background-color: white; box-shadow: 0 0 0 3px #d6af3c; }
    
    .btn-container { display: flex; justify-content: center; width: 100%; margin-top: 10px;}
    .verify-btn { background-color: #d6af3c; color: #111; border: none; padding: 14px 40px; border-radius: 30px; font-weight: 700; font-size: 14px; cursor: pointer; transition: background-color 0.3s; width: 100%; letter-spacing: 1px;}
    .verify-btn:hover { background-color: #bfa035; }
    
    .error-message { color: #ffb3b3; background: rgba(255, 0, 0, 0.1); padding: 10px; border-radius: 10px; font-size: 13px; margin-bottom: 15px; border: 1px solid rgba(255, 0, 0, 0.2); width: 100%; text-align: left;}
    .success-message { color: #b3ffb3; background: rgba(0, 255, 0, 0.1); padding: 10px; border-radius: 10px; font-size: 13px; margin-bottom: 15px; border: 1px solid rgba(0, 255, 0, 0.2); width: 100%; text-align: left;}
    
    .cancel-link { display: block; margin-top: 20px; color: #cca8d6; text-decoration: none; font-size: 13px; font-weight: 600;}
    .cancel-link:hover { color: white; }

    .info-btn, .toggle-pwd-btn { position: absolute; right: 15px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #a462a9; cursor: pointer; font-size: 16px; transition: 0.3s; }
    .info-btn { right: 45px; }
    .info-btn:hover, .toggle-pwd-btn:hover { color: #3d143e; }

    /* Modal CSS */
    .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); display: none; justify-content: center; align-items: center; z-index: 1000; }
    .modal-box { background: white; padding: 25px; border-radius: 15px; width: 90%; max-width: 350px; text-align: center; box-shadow: 0 10px 25px rgba(0,0,0,0.3); }
    .modal-box h3 { color: #3d143e; margin-bottom: 15px; font-size: 20px; }
    .modal-box ul { text-align: left; margin-bottom: 20px; padding-left: 20px; color: #555; font-size: 14px; line-height: 1.6; }
    .close-modal-btn { background: #d6af3c; color: #111; border: none; padding: 10px 25px; border-radius: 30px; font-weight: 700; font-size: 13px; cursor: pointer; transition: background-color 0.3s; }
    .close-modal-btn:hover { background: #bfa035; }

    @media (max-width: 400px) {
        .otp-container { padding: 30px 20px; }
        .title { font-size: 20px; }
        .otp-inputs { gap: 6px; }
        .otp-input { width: 38px; height: 45px; font-size: 20px; }
    }
  </style>
</head>
<body>
  <div class="otp-container">
    <img src="../assets/images/kareslogo.png" alt="KARES" class="logo" onerror="this.style.display='none'" />
    
    <?php if (!empty($error)): ?>
      <div class="error-message"><i class="fas fa-exclamation-circle mr-1"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if (!empty($success)): ?>
      <div class="success-message"><i class="fas fa-check-circle mr-1"></i> <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <?php if ($step === 1): ?>
        <h2 class="title">Forgot Password</h2>
        <p class="subtitle">Enter the email address associated with your account and we will send you a verification code.</p>
        
        <form action="" method="POST" style="width: 100%;" onsubmit="this.querySelector('.verify-btn').innerText = 'Sending...';">
            <input type="hidden" name="action" value="send_otp">
            <div class="input-group">
                <input type="email" name="email" class="input-field" placeholder="Email Address" required />
            </div>
            <div class="btn-container">
                <button type="submit" class="verify-btn">SEND CODE</button>
            </div>
        </form>
        <a href="login.php" class="cancel-link">Back to Login</a>

    <?php elseif ($step === 2): ?>
        <h2 class="title">Verification Code</h2>
        <p class="subtitle">We sent a 6-digit code to <strong><?= htmlspecialchars($_SESSION['reset_email'] ?? '') ?></strong>.</p>

        <form action="" method="POST" id="otpForm" style="width: 100%;">
            <input type="hidden" name="action" value="verify_otp">
            <div class="otp-inputs">
                <input type="number" name="otp[]" class="otp-input" maxlength="1" required autocomplete="off" autofocus inputmode="numeric" pattern="\d*" />
                <input type="number" name="otp[]" class="otp-input" maxlength="1" required autocomplete="off" inputmode="numeric" pattern="\d*" />
                <input type="number" name="otp[]" class="otp-input" maxlength="1" required autocomplete="off" inputmode="numeric" pattern="\d*" />
                <input type="number" name="otp[]" class="otp-input" maxlength="1" required autocomplete="off" inputmode="numeric" pattern="\d*" />
                <input type="number" name="otp[]" class="otp-input" maxlength="1" required autocomplete="off" inputmode="numeric" pattern="\d*" />
                <input type="number" name="otp[]" class="otp-input" maxlength="1" required autocomplete="off" inputmode="numeric" pattern="\d*" />
            </div>
            <div class="btn-container">
                <button type="submit" class="verify-btn">VERIFY CODE</button>
            </div>
        </form>
        <a href="login.php" class="cancel-link">Cancel</a>

        <script>
            // Auto-focus logic for the 6 boxes
            const inputs = document.querySelectorAll('.otp-input');
            inputs.forEach((input, index) => {
                input.addEventListener('input', function() {
                    this.value = this.value.replace(/[^0-9]/g, '');
                    if (this.value.length === 1 && index < inputs.length - 1) inputs[index + 1].focus();
                });
                input.addEventListener('keydown', function(e) {
                    if (e.key === 'Backspace' && this.value.length === 0 && index > 0) inputs[index - 1].focus();
                });
                input.addEventListener('paste', function(e) {
                    const pasteData = e.clipboardData.getData('text').replace(/[^0-9]/g, '').slice(0,6);
                    if (pasteData.length === 6) {
                        e.preventDefault();
                        for(let i=0; i<6; i++) inputs[i].value = pasteData[i];
                        inputs[5].focus();
                        document.getElementById('otpForm').submit();
                    }
                });
            });
        </script>

    <?php elseif ($step === 3): ?>
        <h2 class="title">Create New Password</h2>
        <p class="subtitle">Your new password must be different from previous used passwords.</p>

        <form action="" method="POST" style="width: 100%;" onsubmit="this.querySelector('.verify-btn').innerText = 'Updating...';">
            <input type="hidden" name="action" value="reset_password">
            
            <div class="input-group">
                <input type="password" name="password" id="new-password" class="input-field" placeholder="New Password" style="padding-right: 70px;" required />
                <button type="button" class="info-btn" onclick="document.getElementById('pwd-modal').style.display='flex'" title="View Password Criteria">
                    <i class="fas fa-info-circle"></i>
                </button>
                <button type="button" class="toggle-pwd-btn" onclick="togglePassword('new-password', this)">
                    <i class="fas fa-eye"></i>
                </button>
            </div>
            
            <div class="input-group">
                <input type="password" name="confirm_password" id="confirm-password" class="input-field" placeholder="Confirm Password" style="padding-right: 40px;" required />
                <button type="button" class="toggle-pwd-btn" onclick="togglePassword('confirm-password', this)">
                    <i class="fas fa-eye"></i>
                </button>
            </div>

            <div class="btn-container">
                <button type="submit" class="verify-btn">SAVE PASSWORD</button>
            </div>
        </form>
        <a href="login.php" class="cancel-link">Cancel</a>

    <?php elseif ($step === 4): ?>
        <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4 mt-2">
            <i class="fas fa-check text-4xl text-green-500"></i>
        </div>
        <h2 class="title">Password Reset</h2>
        <p class="subtitle">Your password has been successfully updated.</p>
        <div class="btn-container">
            <button onclick="window.location.href='login.php'" class="verify-btn">BACK TO LOGIN</button>
        </div>
    <?php endif; ?>

  </div>

  <?php if ($step === 3): ?>
  <div id="pwd-modal" class="modal-overlay">
      <div class="modal-box">
          <h3>Password Criteria</h3>
          <ul>
              <li>Must be at least <strong>12 characters</strong> long.</li>
              <li>Must contain at least <strong>1 uppercase</strong> letter.</li>
              <li>Must contain at least <strong>1 lowercase</strong> letter.</li>
              <li>Must contain at least <strong>1 special character</strong> (e.g., *,#,!,@).</li>
          </ul>
          <button type="button" class="close-modal-btn" onclick="document.getElementById('pwd-modal').style.display='none'">I Understand</button>
      </div>
  </div>

  <script>
    function togglePassword(inputId, btn) {
        var input = document.getElementById(inputId);
        var icon = btn.querySelector('i');
        if (input.type === "password") {
            input.type = "text";
            icon.classList.remove("fa-eye");
            icon.classList.add("fa-eye-slash");
        } else {
            input.type = "password";
            icon.classList.remove("fa-eye-slash");
            icon.classList.add("fa-eye");
        }
    }
  </script>
  <?php endif; ?>

</body>
</html>