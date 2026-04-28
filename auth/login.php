<?php
// auth/login.php
session_start();
require_once '../config/db_connect.php'; 

// Load PHPMailer classes
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once '../vendor/Exception.php';
require_once '../vendor/PHPMailer.php';
require_once '../vendor/SMTP.php'; 

// --- AUTO-LOGIN VIA REMEMBER ME COOKIE ---
if (!isset($_SESSION['user_id']) && isset($_COOKIE['kares_remember_me'])) {
    $token = $_COOKIE['kares_remember_me'];
    $stmt = $pdo->prepare("SELECT * FROM users WHERE remember_token = :token LIMIT 1");
    $stmt->execute([':token' => $token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $_SESSION['user_id'] = $user['id'] ?? $user['user_id'] ?? 1;
        $_SESSION['role'] = $user['role'] ?? 'User';
        $email_parts = explode('@', $user['email'] ?? 'User@');
        $_SESSION['username'] = $user['first_name'] ?? $user['username'] ?? ucfirst($email_parts[0]);
        
        if ($_SESSION['role'] === 'Admin') {
            header("Location: ../admin/dashboard.php");
        } else {
            header("Location: ../user/user_home.php");
        }
        exit();
    }
}

// If already logged in normally
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'Admin') {
        header("Location: ../admin/dashboard.php");
    } else {
        header("Location: ../user/user_home.php");
    }
    exit();
}

$error = '';
$username_input = ''; // Initialize so we can echo it back in the form

// Check if we just came back from a failed OTP attempt or missing session
if (isset($_GET['error'])) {
    if ($_GET['error'] == 'invalid_otp') {
        $error = "Too many failed attempts. Please login again.";
    } elseif ($_GET['error'] == 'missing_session') {
        $error = "Session expired. Please login again.";
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username_input = trim($_POST['username'] ?? '');
    $password_input = $_POST['password'] ?? '';
    $remember_me = isset($_POST['remember']) ? 'yes' : 'no'; 

    if (empty($username_input) || empty($password_input)) {
        $error = "Please enter both email and password.";
    } else {
        try {
            try {
                $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :input OR username = :input LIMIT 1");
                $stmt->execute([':input' => $username_input]);
            } catch (PDOException $e) {
                $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :input LIMIT 1");
                $stmt->execute([':input' => $username_input]);
            }
            
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                $db_password = $user['password'] ?? $user['pass'] ?? $user['user_password'] ?? $user['pwd'] ?? null;

                if ($db_password === null) {
                    $error = "System Error: Password column missing in the database table.";
                } elseif ($password_input === $db_password || password_verify($password_input, $db_password)) {
                    
                    // PASSWORD IS CORRECT! Now we generate OTP instead of logging them in.
                    $otp = sprintf("%06d", mt_rand(100000, 999999));
                    
                    // Save OTP to user record
                    $update_stmt = $pdo->prepare("UPDATE users SET otp_code = :otp WHERE id = :id");
                    $update_stmt->execute([':otp' => $otp, ':id' => $user['id']]);

                    // Send the Email
                    // ... inside your OTP generating block ...

                    $mail = new PHPMailer(true);
                    try {
                        $mail->isSMTP();
                        
                        // Force SSL right away to prevent handshake hangs
                        $mail->Host       = 'ssl://smtp.gmail.com'; 
                        $mail->SMTPAuth   = true;
                        
                        $mail->Username   = getenv('SMTP_USER') ?: 'adminkares@gmail.com'; 
                        $mail->Password   = getenv('SMTP_PASS') ?: 'your_local_app_password_here'; 
                        
                        // Switch to SMTPS and Port 465
                        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; 
                        $mail->Port       = 465; 

                        // Keep the timeout and SSL overrides for Railway
                        $mail->Timeout    = 20; 
                        $mail->SMTPOptions = array(
                            'ssl' => array(
                                'verify_peer' => false,
                                'verify_peer_name' => false,
                                'allow_self_signed' => true
                            )
                        );

                        $mail->setFrom($mail->Username, 'Barangay KARES Portal');
                        $mail->addAddress($user['email']);
    
// ... rest of the email body code ...
                        
                        $mail->isHTML(true);
                        $mail->Subject = 'Your KARES Login OTP';
                        $mail->Body    = "
                        <div style='font-family: Arial, sans-serif; max-width: 500px; margin: auto; padding: 20px; border: 1px solid #ddd; border-radius: 10px; background-color: #f9f9f9;'>
                            <h2 style='color: #3d143e; text-align: center;'>Login Verification</h2>
                            <p style='color: #333; font-size: 16px;'>Hello,</p>
                            <p style='color: #333; font-size: 16px;'>Please use the following One-Time Password to access your KARES account. This code is for a single use.</p>
                            <div style='text-align: center; margin: 30px 0;'>
                                <span style='background-color: #3d143e; color: #fff; padding: 15px 25px; font-size: 24px; font-weight: bold; border-radius: 5px; letter-spacing: 5px;'>{$otp}</span>
                            </div>
                            <p style='color: #777; font-size: 12px; text-align: center;'>If you did not request this login, please ignore this email.</p>
                        </div>";

                        $mail->send();

                        // Set temporary session vars so we know who is trying to login
                        $_SESSION['pending_login_id'] = $user['id'];
                        $_SESSION['pending_remember'] = $remember_me;
                        
                        header("Location: verify_login.php");
                        exit();

                    } catch (Exception $e) {
                        $error = "Failed to send OTP email. Please check your internet or try again later.";
                        error_log("OTP Email Error: " . $mail->ErrorInfo);
                    }
                    
                } else {
                    $error = "Incorrect password. Please try again.";
                }
            } else {
                $error = "Account not found. Please register first.";
            }
        } catch (PDOException $e) {
            $error = "Database Error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link rel="icon" type="image/png" href="../assets/images/kareslogo.png" />
  <title>KARES Login</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    body { display: flex; justify-content: center; align-items: center; min-height: 100vh; background-image: url('../assets/images/bg.png'); background-size: cover; background-position: center; background-color: rgba(0, 0, 0, 0.65); background-blend-mode: overlay; padding: 20px;}
    
    .login-container { display: flex; width: 100%; max-width: 800px; min-height: 480px; background: linear-gradient(-165deg, #F8F9FA 0%, #CCBFD5 70%); border-radius: 20px; box-shadow: 0 15px 35px rgba(0, 0, 0, 0.4); overflow: hidden; flex-direction: column; }
    
    .left-section { flex: 1; display: flex; flex-direction: column; position: relative; padding: 25px; align-items: center; justify-content: center; }
    .top-logos { display: flex; gap: 15px; align-items: center; position: absolute; top: 25px; left: 25px; }
    .small-logo { width: 40px; height: 40px; object-fit: contain; border-radius: 50%; }
    .main-branding { flex: 1; display: flex; flex-direction: column; justify-content: center; align-items: center; margin-top: 40px; }
    .main-logo { width: 120px; height: 120px; object-fit: contain; margin-bottom: 10px; border-radius: 50%; }
    .brand-name { color: #3b1445; font-size: 28px; font-weight: 800; letter-spacing: 1px; }
    
    .right-section { flex: 1.1; background: linear-gradient(170deg, #A462A9 10%, #4B184F 50%); padding: 40px 30px; display: flex; flex-direction: column; justify-content: center; }
    .login-title { color: white; font-size: 24px; font-weight: 600; margin-bottom: 25px; text-align: center; }
    .login-form { display: flex; flex-direction: column; }
    .input-group { position: relative; margin-bottom: 15px; }
    .input-field { width: 100%; padding: 14px 20px; border: none; border-radius: 30px; font-size: 14px; background-color: #f4f4f4; color: #333; outline: none; }
    .form-options { display: flex; flex-direction: column; gap: 10px; align-items: center; margin-top: 5px; margin-bottom: 30px; font-size: 13px; color: #cca8d6; }
    .remember-me { display: flex; align-items: center; gap: 8px; cursor: pointer; }
    .remember-me input[type="checkbox"] { accent-color: #874791; width: 14px; height: 14px; }
    .signup-link { color: #cca8d6; text-decoration: none; transition: color 0.3s; cursor: pointer; }
    .signup-link:hover { color: white; }
    .btn-container { display: flex; justify-content: center; }
    .sign-in-btn { background-color: #d6af3c; color: #111; border: none; padding: 12px 35px; border-radius: 30px; font-weight: 700; font-size: 14px; cursor: pointer; transition: background-color 0.3s; width: 100%; max-width: 200px;}
    .sign-in-btn:hover { background-color: #bfa035; }
    .error-message { color: #ffb3b3; background: rgba(255, 0, 0, 0.1); padding: 10px; border-radius: 10px; font-size: 13px; margin-bottom: 15px; border: 1px solid rgba(255, 0, 0, 0.2); text-align: center; }

    /* Desktop View Adjustments */
    @media (min-width: 768px) {
        .login-container { flex-direction: row; }
        .left-section { padding: 25px; }
        .top-logos { position: absolute; top: 25px; left: 25px; }
        .small-logo { width: 50px; height: 50px; }
        .main-branding { margin-top: -30px; }
        .main-logo { width: 160px; height: 160px; }
        .brand-name { font-size: 32px; }
        
        .right-section { border-top-left-radius: 80px; border-bottom-left-radius: 80px; padding: 60px 50px; }
        .login-title { font-size: 28px; text-align: left; margin-bottom: 35px; }
        .form-options { flex-direction: row; justify-content: space-between; margin-bottom: 40px; font-size: 12px; }
        .btn-container { justify-content: flex-end; }
        .sign-in-btn { width: auto; font-size: 13px; }
        .error-message { text-align: left; }
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
      <h2 class="login-title">Sign In</h2>
      <?php if (!empty($error)): ?>
        <div class="error-message"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      <form class="login-form" action="" method="POST" onsubmit="this.querySelector('.sign-in-btn').innerText = 'Sending OTP...'; this.querySelector('.sign-in-btn').style.opacity = '0.7';">
        <div class="input-group">
          <input type="text" name="username" class="input-field" placeholder="Email Address" value="<?= htmlspecialchars($username_input) ?>" required />
        </div>
        <div class="input-group">
          <input type="password" name="password" class="input-field" placeholder="Password" required />
        </div>
        <div class="form-options">
          <label class="remember-me"><input type="checkbox" name="remember" value="yes" /> Remember me</label>
          <a href="register.php" class="signup-link">Don't have an account yet? Sign up.</a>
        </div>
        <div class="btn-container"><button type="submit" class="sign-in-btn">SIGN IN</button></div>
      </form>
    </div>
  </div>
</body>
</html>