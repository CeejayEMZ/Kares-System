<?php
// auth/register.php
session_start();
require_once '../config/db_connect.php'; 

if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'Admin') {
        header("Location: ../admin/dashboard.php");
    } else {
        header("Location: ../user/user_home.php");
    }
    exit();
}

$error = '';
$step = 1; // 1 for Form, 2 for Verification
$email_input = ''; // Initialize so we can echo it back if passwords don't match

// Helper function to send the OTP email via API
function sendVerificationEmail($email, $code, &$error) {
    $api_key = $_SERVER['BREVO_API_KEY'] ?? $_ENV['BREVO_API_KEY'] ?? getenv('BREVO_API_KEY') ?? '';

    if (empty($api_key)) {
    error_log("CRITICAL: API Key is completely empty. Railway is not passing the variable.");
    }
    $htmlContent = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e0d5e8; border-radius: 10px;'>
            <h2 style='color: #3d143e;'>Verify Your KARES Account</h2>
            <p style='color: #555;'>Thank you for registering with the Kalinga Assistance Request and Evaluation System.</p>
            <p style='color: #555;'>Your 5-digit verification code is:</p>
            <div style='background-color: #f4f4f4; padding: 15px; text-align: center; border-radius: 8px; margin: 20px 0;'>
                <span style='font-size: 24px; font-weight: bold; letter-spacing: 5px; color: #c6943a;'>{$code}</span>
            </div>
            <p style='color: #999; font-size: 12px;'>This code will expire in 4 minutes. If you did not request this, please ignore this email.</p>
        </div>
    ";

    $data = [
        'sender' => ['name' => 'KARES Support', 'email' => 'adminkares@gmail.com'],
        'to' => [['email' => $email]],
        'subject' => 'Your KARES Verification Code',
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
        $error = "Failed to send verification email. API Error Code: " . $httpcode;
        error_log("Brevo API Error: " . $response);
        return false;
    }
}

// Handle Form Submissions
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // --- STEP 1: Process Initial Registration Form ---
    if (isset($_POST['action']) && $_POST['action'] === 'register') {
        $email_input = trim($_POST['email'] ?? ''); // Save input so we don't clear it on error
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $email_opt_in = isset($_POST['email_opt_in']) ? 1 : 0;

        if (empty($email_input) || empty($password) || empty($confirm_password)) {
            $error = "All fields are required.";
        } elseif (!filter_var($email_input, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
            
        // --- STRICT PASSWORD VALIDATION ---
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
            // Check if email already exists
            try {
                $check = $pdo->prepare("SELECT email FROM users WHERE email = :email");
                $check->execute([':email' => $email_input]);
                
                if ($check->rowCount() > 0) {
                    $error = "This email is already registered. Please log in.";
                } else {
                    $verification_code = sprintf("%05d", mt_rand(1, 99999));
                    
                    // Save details to Session temporarily
                    $_SESSION['temp_reg_email'] = $email_input;
                    $_SESSION['temp_reg_pass'] = $password;
                    $_SESSION['temp_reg_opt_in'] = $email_opt_in;
                    $_SESSION['temp_reg_code'] = $verification_code;
                    $_SESSION['temp_reg_expires'] = time() + (4 * 60 + 10); // 4m 10s expiry
                    
                    if(sendVerificationEmail($email_input, $verification_code, $error)) {
                        $step = 2; // Switch UI to verification only if email succeeds
                    }
                }
            } catch (PDOException $e) {
                $error = "Database Error: Could not check user.";
            }
        }
    }
    
    // --- STEP 1.5: Resend Code Logic ---
    elseif (isset($_POST['action']) && $_POST['action'] === 'resend_code') {
        if(isset($_SESSION['temp_reg_email'])) {
            $new_code = sprintf("%05d", mt_rand(1, 99999));
            $_SESSION['temp_reg_code'] = $new_code;
            $_SESSION['temp_reg_expires'] = time() + (4 * 60 + 10);
            
            if(sendVerificationEmail($_SESSION['temp_reg_email'], $new_code, $error)) {
                $error = "A new code has been sent to your email."; 
                $step = 2; 
            }
        } else {
            $error = "Session expired. Please register again.";
            $step = 1;
        }
    }
    
    // --- STEP 2: Process Code Verification ---
    elseif (isset($_POST['action']) && $_POST['action'] === 'verify') {
        $entered_code = trim($_POST['code'] ?? '');
        $step = 2; // Keep them on the verify screen if it fails
        
        if (empty($entered_code)) {
            $error = "Please enter the verification code.";
        } elseif (time() > $_SESSION['temp_reg_expires']) {
            $error = "Verification code has expired. Please try registering again.";
            unset($_SESSION['temp_reg_email'], $_SESSION['temp_reg_pass'], $_SESSION['temp_reg_opt_in'], $_SESSION['temp_reg_code'], $_SESSION['temp_reg_expires']);
            $step = 1;
        } elseif ($entered_code !== $_SESSION['temp_reg_code']) {
            $error = "Incorrect code. Please try again.";
        } else {
            // Code matches! Create the user and AUTO-LOGIN
            try {
                $hashed_password = password_hash($_SESSION['temp_reg_pass'], PASSWORD_DEFAULT);
                $email_parts = explode('@', $_SESSION['temp_reg_email']);
                $generated_username = $email_parts[0];
                $opt_in_val = $_SESSION['temp_reg_opt_in'] ?? 0;

                $stmt = $pdo->prepare("INSERT INTO users (username, email, password, role, is_verified, email_alerts) VALUES (:username, :email, :password, 'User', FALSE, :alerts)");
                $stmt->execute([
                    ':username' => $generated_username,
                    ':email' => $_SESSION['temp_reg_email'], 
                    ':password' => $hashed_password,
                    ':alerts' => $opt_in_val ? 'true' : 'false'
                ]);
                
                $new_user_id = $pdo->lastInsertId();
                
                $_SESSION['user_id'] = $new_user_id;
                $_SESSION['role'] = 'User';
                $_SESSION['username'] = ucfirst($generated_username);

                unset($_SESSION['temp_reg_email'], $_SESSION['temp_reg_pass'], $_SESSION['temp_reg_opt_in'], $_SESSION['temp_reg_code'], $_SESSION['temp_reg_expires']);

                header("Location: ../user/user_home.php");
                exit();
                
            } catch (PDOException $e) {
                $error = "Database Error: Could not register user.";
                $step = 1;
            }
        }
    }
    
    // --- Cancel Logic ---
    elseif (isset($_POST['action']) && $_POST['action'] === 'cancel') {
        unset($_SESSION['temp_reg_email'], $_SESSION['temp_reg_pass'], $_SESSION['temp_reg_opt_in'], $_SESSION['temp_reg_code'], $_SESSION['temp_reg_expires']);
        $step = 1;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <link rel="icon" type="image/png" href="../assets/images/kareslogo.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Create an Account - KARES</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    body { display: flex; justify-content: center; align-items: center; min-height: 100vh; background-image: url('../assets/images/bg.png'); background-size: cover; background-position: center; background-color: rgba(0, 0, 0, 0.65); background-blend-mode: overlay; padding: 20px;}
    
    .login-container { display: flex; width: 100%; max-width: 800px; min-height: 500px; background: linear-gradient(-165deg, #F8F9FA 0%, #CCBFD5 70%); border-radius: 20px; box-shadow: 0 15px 35px rgba(0, 0, 0, 0.4); overflow: hidden; flex-direction: column;}
    
    .left-section { flex: 1; display: flex; flex-direction: column; position: relative; padding: 25px; align-items: center; justify-content: center; }
    .top-logos { display: flex; gap: 15px; align-items: center; position: absolute; top: 25px; left: 25px;}
    .small-logo { width: 40px; height: 40px; object-fit: contain; border-radius: 50%; }
    .main-branding { flex: 1; display: flex; flex-direction: column; justify-content: center; align-items: center; margin-top: 40px; }
    .main-logo { width: 120px; height: 120px; object-fit: contain; margin-bottom: 10px; border-radius: 50%; }
    .brand-name { color: #3b1445; font-size: 28px; font-weight: 800; letter-spacing: 1px; }
    
    .right-section { flex: 1.1; background: linear-gradient(170deg, #A462A9 10%, #4B184F 50%); padding: 40px 30px; display: flex; flex-direction: column; justify-content: center; position: relative;}
    
    .login-title { color: white; font-size: 24px; font-weight: 600; margin-bottom: 20px; text-align: center; }
    .verify-title { color: white; font-size: 20px; font-weight: 700; margin-bottom: 10px; text-align: center;}
    .verify-desc { color: rgba(255,255,255,0.6); font-size: 13px; margin-bottom: 25px; line-height: 1.4; text-align: center;}
    
    .input-group { position: relative; margin-bottom: 15px; }
    .input-field { width: 100%; padding: 14px 20px; border: none; border-radius: 30px; font-size: 14px; background-color: #f4f4f4; color: #333; outline: none; }
    
    .info-btn { position: absolute; right: 45px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #a462a9; cursor: pointer; font-size: 16px; transition: 0.3s; }
    .info-btn:hover { color: #3d143e; }
    .toggle-pwd-btn { position: absolute; right: 15px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #a462a9; cursor: pointer; font-size: 16px; transition: 0.3s; }
    .toggle-pwd-btn:hover { color: #3d143e; }
    
    .code-box-container { background: #f4f4f4; border-radius: 15px; padding: 10px 20px; margin-bottom: 20px; box-shadow: inset 0 2px 5px rgba(0,0,0,0.05);}
    .code-input { width: 100%; border: none; background: transparent; font-size: 14px; color: #333; outline: none; padding: 5px 0; text-align: center; letter-spacing: 2px;}
    .code-meta { font-size: 11px; color: #666; margin-top: 10px; border-top: 1px solid #ddd; padding-top: 10px; display: flex; justify-content: space-between; align-items: center; flex-direction: column; gap: 8px;}
    .resend-btn { background: none; border: none; color: #a462a9; font-weight: bold; cursor: pointer; text-decoration: none;}
    
    .btn-container { display: flex; flex-direction: column; gap: 10px; margin-top: 10px;}
    .sign-in-btn { background-color: #d6af3c; color: #111; border: none; padding: 12px 35px; border-radius: 30px; font-weight: 700; font-size: 14px; cursor: pointer; transition: background-color 0.3s; width: 100%;}
    .sign-in-btn:hover { background-color: #bfa035; }
    .cancel-btn { background-color: transparent; color: rgba(255,255,255,0.7); border: 1px solid rgba(255,255,255,0.3); padding: 12px 25px; border-radius: 30px; font-weight: 700; font-size: 14px; cursor: pointer; transition: all 0.3s; width: 100%;}
    .cancel-btn:hover { background-color: rgba(255,255,255,0.1); color: white;}
    
    .login-link { color: #b8a8cf; font-size: 13px; margin-top: 20px; text-align: center; }
    .login-link a { color: #f3e4ff; text-decoration: none; font-weight: 700; cursor: pointer; }
    .login-link a:hover { color: white; }
    
    .error-message { color: #ffb3b3; background: rgba(255, 0, 0, 0.1); padding: 10px; border-radius: 10px; font-size: 13px; margin-bottom: 15px; border: 1px solid rgba(255, 0, 0, 0.2); text-align: center;}
    .success-message { color: #b3ffb3; background: rgba(0, 255, 0, 0.1); padding: 10px; border-radius: 10px; font-size: 13px; margin-bottom: 15px; border: 1px solid rgba(0, 255, 0, 0.2); text-align: center;}

    /* Modal CSS */
    .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); display: none; justify-content: center; align-items: center; z-index: 1000; }
    .modal-box { background: white; padding: 25px; border-radius: 15px; width: 90%; max-width: 350px; text-align: center; box-shadow: 0 10px 25px rgba(0,0,0,0.3); }
    .modal-box h3 { color: #3d143e; margin-bottom: 15px; font-size: 20px; }
    .modal-box ul { text-align: left; margin-bottom: 20px; padding-left: 20px; color: #555; font-size: 14px; line-height: 1.6; }
    .close-modal-btn { background: #d6af3c; color: #111; border: none; padding: 10px 25px; border-radius: 30px; font-weight: 700; font-size: 13px; cursor: pointer; transition: background-color 0.3s; }
    .close-modal-btn:hover { background: #bfa035; }

    /* Desktop View Adjustments */
    @media (min-width: 768px) {
        .login-container { flex-direction: row; }
        .left-section { padding: 25px; }
        .top-logos { top: 25px; left: 25px; }
        .small-logo { width: 50px; height: 50px; }
        .main-branding { margin-top: -30px; }
        .main-logo { width: 160px; height: 160px; }
        .brand-name { font-size: 32px; }
        
        .right-section { border-top-left-radius: 80px; border-bottom-left-radius: 80px; padding: 50px; }
        .login-title { font-size: 28px; text-align: left; }
        .verify-title { text-align: left; }
        .verify-desc { text-align: left; font-size: 12px; }
        .code-meta { flex-direction: row; }
        .btn-container { flex-direction: row; justify-content: flex-end; }
        .sign-in-btn, .cancel-btn { width: auto; font-size: 13px;}
        .login-link { margin-top: 15px; font-size: 12px;}
        .error-message, .success-message { text-align: left; }
    }
  </style>
</head>
<body>
  <div class="login-container">
    <div class="left-section">
      <div class="top-logos">
        <img src="../assets/images/ibon.png" alt="Logo 1" class="small-logo" onerror="this.style.display='none'" />
        <img src="../assets/images/marta.png" alt="Logo 2" class="small-logo" onerror="this.style.display='none'" />
      </div>
      <div class="main-branding">
        <img src="../assets/images/kareslogo.png" alt="KARES" class="main-logo" onerror="this.style.display='none'" />
        <div class="brand-name">KARES</div>
      </div>
    </div>
    
    <div class="right-section">
        
      <?php if (!empty($error)): ?>
        <?php if ($error === "A new code has been sent to your email."): ?>
            <div class="success-message"><?= htmlspecialchars($error) ?></div>
        <?php else: ?>
            <div class="error-message"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
      <?php endif; ?>

      <?php if ($step === 1): ?>
          <h2 class="login-title">Create an Account</h2>
          <form class="login-form" action="" method="POST" onsubmit="this.querySelector('.sign-in-btn').innerText = 'Processing...'; this.querySelector('.sign-in-btn').style.opacity = '0.7';">
            <input type="hidden" name="action" value="register">
            <div class="input-group">
              <input type="email" name="email" class="input-field" placeholder="Email Address" value="<?= htmlspecialchars($email_input) ?>" required />
            </div>
            
            <div class="input-group">
              <input type="password" name="password" id="reg-password" class="input-field" placeholder="Password" style="padding-right: 70px;" required />
              <button type="button" class="info-btn" onclick="document.getElementById('pwd-modal').style.display='flex'" title="View Password Criteria">
                  <i class="fas fa-info-circle"></i>
              </button>
              <button type="button" class="toggle-pwd-btn" onclick="togglePassword('reg-password', this)">
                  <i class="fas fa-eye"></i>
              </button>
            </div>
            
            <div class="input-group">
              <input type="password" name="confirm_password" id="reg-confirm" class="input-field" placeholder="Confirm Password" style="padding-right: 40px;" required />
              <button type="button" class="toggle-pwd-btn" onclick="togglePassword('reg-confirm', this)">
                  <i class="fas fa-eye"></i>
              </button>
            </div>

            <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; color: #cca8d6; margin-bottom: 20px; cursor: pointer;">
                <input type="checkbox" name="email_opt_in" value="1" checked style="accent-color: #874791; width: 14px; height: 14px;">
                I want to receive email updates for my requests
            </label>

            <div class="btn-container"><button type="submit" class="sign-in-btn">Submit</button></div>
            <div class="login-link">Already have an account? <a href="login.php">Sign in Here</a></div>
          </form>

      <?php else: ?>
          <h2 class="verify-title">Enter the Code</h2>
          <p class="verify-desc">To create your account, enter the 5 digit<br>code we sent to <?= htmlspecialchars($_SESSION['temp_reg_email']) ?>.</p>
          
          <form class="login-form" action="" method="POST" onsubmit="if(document.getElementById('verify-action').value === 'verify') { this.querySelector('.sign-in-btn').innerText = 'Verifying...'; this.querySelector('.sign-in-btn').style.opacity = '0.7'; }">
            <input type="hidden" id="verify-action" name="action" value="verify">
            
            <div class="code-box-container">
                <input type="text" name="code" class="code-input" placeholder="Enter Code" maxlength="5" autocomplete="off" required />
                <div class="code-meta">
                    <span id="timerText" style="color: #888;">Code expires in 4:10 seconds</span>
                    <div style="display: flex; align-items: center; gap: 5px;">
                        <span>Didn't receive a code?</span>
                        <button type="button" class="resend-btn" onclick="document.getElementById('verify-action').value='resend_code'; this.innerText='Sending...'; this.form.submit();">Resend</button>
                    </div>
                </div>
            </div>

            <div class="btn-container">
                <button type="button" class="cancel-btn" onclick="document.getElementById('verify-action').value='cancel'; this.form.submit();">Cancel</button>
                <button type="submit" class="sign-in-btn">Continue</button>
            </div>
          </form>

          <script>
            let timeLeft = <?= max(0, $_SESSION['temp_reg_expires'] - time()) ?>; 
            let timerEl = document.getElementById('timerText');
            
            let countdown = setInterval(function() {
                if(timeLeft <= 0) {
                    clearInterval(countdown);
                    timerEl.innerHTML = "Code expired.";
                    timerEl.style.color = "red";
                } else {
                    let minutes = Math.floor(timeLeft / 60);
                    let seconds = timeLeft % 60;
                    if(seconds < 10) seconds = "0" + seconds;
                    timerEl.innerHTML = "Code expires in " + minutes + ":" + seconds;
                    timeLeft -= 1;
                }
            }, 1000);
          </script>
      <?php endif; ?>

    </div>
  </div>

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
</body>
</html>