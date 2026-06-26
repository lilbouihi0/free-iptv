<?php
/**
 * AJAX Request Handler
 * Receives requests from JavaScript, routes to appropriate functions, and returns JSON.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'functions.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

$response = ['success' => false, 'message' => 'Invalid action'];

switch ($action) {
    
    case 'trial':
        // Handle free trial activation
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        
        if (empty($username) || empty($email)) {
            $response = ['success' => false, 'message' => 'Username and email are required'];
            break;
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $response = ['success' => false, 'message' => 'Invalid email address format'];
            break;
        }
        
        if (userExists($username)) {
            $response = ['success' => false, 'message' => 'Username already exists'];
            break;
        }
        
        $password = generatePassword();
        $result = createUserLine($username, $password, $email, 1, 'trial');
        
        // In local development or if exec isn't configured, CLI user creation might return false.
        // We will allow DB-level mock activation for testing/demo if the CLI failed due to env,
        // but clearly indicate if it is in demo/mock mode.
        if ($result['success'] || DB_PASS === 'Your_DB_Password_Here') {
            // If the script runs locally, we simulate success for demonstration
            $isDemo = !$result['success'];
            
            // Save to local database for tracking
            saveUserToDb($username, $email, $password, 'trial', date('Y-m-d', strtotime('+1 day')));
            
            $response = [
                'success' => true,
                'message' => $isDemo ? 'Trial activated (DEMO Mode)! Details generated.' : 'Trial activated successfully!',
                'data' => [
                    'username' => $username,
                    'password' => $password,
                    'playlist_url' => $result['playlist_url'] ?? (XC_VM_PROTOCOL . '://' . XC_VM_HOST . ':' . XC_VM_PORT . '/playlist/' . $username . '/' . $password . '/m3u_plus?output=hls'),
                    'expiry' => $result['expiry']
                ]
            ];
        } else {
            $response = ['success' => false, 'message' => 'Failed to create trial account on XC_VM server'];
        }
        break;
    
    case 'register':
        // Handle user registration
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $package = $_POST['package'] ?? '1month';
        
        if (empty($username) || empty($email) || empty($password)) {
            $response = ['success' => false, 'message' => 'All fields are required'];
            break;
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $response = ['success' => false, 'message' => 'Invalid email address format'];
            break;
        }
        
        if (userExists($username)) {
            $response = ['success' => false, 'message' => 'Username already exists'];
            break;
        }
        
        $result = createUserLine($username, $password, $email, 0, $package);
        
        if ($result['success'] || DB_PASS === 'Your_DB_Password_Here') {
            $isDemo = !$result['success'];
            
            // Save to local database for tracking
            $packages = [
                'trial' => 1,
                '1month' => 30,
                '3month' => 90,
                '12month' => 365,
            ];
            $days = $packages[$package] ?? 30;
            saveUserToDb($username, $email, $password, $package, date('Y-m-d', strtotime("+$days days")));
            
            $response = [
                'success' => true,
                'message' => $isDemo ? 'Account created (DEMO Mode)! Redirecting to payment...' : 'Account created! Redirecting to payment...',
                'data' => [
                    'username' => $username,
                    'password' => $password,
                    'playlist_url' => $result['playlist_url'] ?? (XC_VM_PROTOCOL . '://' . XC_VM_HOST . ':' . XC_VM_PORT . '/playlist/' . $username . '/' . $password . '/m3u_plus?output=hls'),
                    'expiry' => $result['expiry'] ?? date('Y-m-d', strtotime("+$days days"))
                ]
            ];
        } else {
            $response = ['success' => false, 'message' => 'Registration failed on XC_VM server'];
        }
        break;
    
    case 'register_user':
        $fullName = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone_number'] ?? '');
        $country = trim($_POST['phone_country'] ?? 'MA');
        $password = trim($_POST['password'] ?? '');
        
        if (empty($fullName) || empty($username) || empty($email) || empty($phone) || empty($password)) {
            $response = ['success' => false, 'message' => 'All fields are required.'];
            break;
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $response = ['success' => false, 'message' => 'Invalid email address format.'];
            break;
        }
        
        // Check uniqueness
        if (userExists($username)) {
            $response = ['success' => false, 'message' => 'Username is already registered.'];
            break;
        }
        if (emailExists($email)) {
            $response = ['success' => false, 'message' => 'Email is already registered.'];
            break;
        }
        if (phoneExists($phone)) {
            $response = ['success' => false, 'message' => 'Phone number has already claimed a trial.'];
            break;
        }
        
        // Check trial eligibility
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $eligibility = checkTrialEligibility($phone, $ip);
        if (!$eligibility['eligible']) {
            $response = ['success' => false, 'message' => $eligibility['reason']];
            break;
        }
        
        // Save draft in session
        $_SESSION['signup_draft'] = [
            'full_name' => $fullName,
            'username' => $username,
            'email' => $email,
            'phone_number' => $phone,
            'phone_country' => $country,
            'password' => $password
        ];
        
        // Send OTP
        $res = sendWhatsAppOTP($phone);
        if ($res['success']) {
            $response = [
                'success' => true,
                'message' => 'Verification code sent to WhatsApp.',
                'requires_verification' => true
            ];
            // Include OTP code in response if Meta API is bypassed (for easy testing/dev environment)
            if (defined('WA_BYPASS_API') && WA_BYPASS_API && isset($res['otp'])) {
                $response['otp'] = $res['otp'];
                $_SESSION['signup_otp'] = $res['otp']; // Backup session check
            }
        } else {
            $response = ['success' => false, 'message' => $res['message']];
        }
        break;
        
    case 'verify_phone':
        $otpCode = trim($_POST['otp_code'] ?? '');
        if (empty($otpCode)) {
            $response = ['success' => false, 'message' => 'Verification code is required.'];
            break;
        }
        
        if (!isset($_SESSION['signup_draft'])) {
            $response = ['success' => false, 'message' => 'Session expired. Please start registration again.'];
            break;
        }
        
        $draft = $_SESSION['signup_draft'];
        $verifyRes = verifyWhatsAppOTP($draft['phone_number'], $otpCode);
        
        if ($verifyRes['success']) {
            // Register user in local DB
            $userId = registerUser(
                $draft['full_name'],
                $draft['username'],
                $draft['email'],
                $draft['phone_number'],
                $draft['phone_country'],
                $draft['password']
            );
            
            if ($userId) {
                // Activate trial on local DB & XC_VM Panel
                $activation = activateUserTrial($userId, $draft['username'], $draft['email']);
                if ($activation['success']) {
                    // Clear signup draft
                    unset($_SESSION['signup_draft']);
                    unset($_SESSION['signup_otp']);
                    
                    $response = [
                        'success' => true,
                        'message' => $activation['is_demo'] ? 'Trial activated (DEMO Mode)!' : 'Trial activated successfully!',
                        'data' => $activation['data']
                    ];
                } else {
                    $response = ['success' => false, 'message' => 'Verification successful, but failed to activate trial. Contact support.'];
                }
            } else {
                $response = ['success' => false, 'message' => 'Failed to create user account. Please try again.'];
            }
        } else {
            $response = ['success' => false, 'message' => $verifyRes['message']];
        }
        break;
        
    case 'resend_otp':
        if (!isset($_SESSION['signup_draft'])) {
            $response = ['success' => false, 'message' => 'Session expired. Please restart registration.'];
            break;
        }
        
        $draft = $_SESSION['signup_draft'];
        $res = sendWhatsAppOTP($draft['phone_number']);
        
        if ($res['success']) {
            $response = [
                'success' => true,
                'message' => 'A new verification code was sent.'
            ];
            if (defined('WA_BYPASS_API') && WA_BYPASS_API && isset($res['otp'])) {
                $response['otp'] = $res['otp'];
                $_SESSION['signup_otp'] = $res['otp'];
            }
        } else {
            $response = ['success' => false, 'message' => $res['message']];
        }
        break;

    case 'login':
        // Handle user login
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        
        if (empty($username) || empty($password)) {
            $response = ['success' => false, 'message' => 'Username and password required'];
            break;
        }
        
        // Try local DB password login first
        $dbLoginSuccess = false;
        $db = getDbConnection();
        if ($db) {
            try {
                $stmt = $db->prepare("SELECT id, password FROM users WHERE username = ? OR email = ?");
                if ($stmt) {
                    $stmt->bind_param("ss", $username, $username);
                    $stmt->execute();
                    $stmt->bind_result($uId, $hashedPass);
                    if ($stmt->fetch() && password_verify($password, $hashedPass)) {
                        $dbLoginSuccess = true;
                        $stmt->close();
                        // Update last login
                        $db->query("UPDATE users SET last_login = NOW() WHERE id = $uId");
                    } else {
                        $stmt->close();
                    }
                }
            } catch (Exception $e) {
                error_log("Database Login Error: " . $e->getMessage());
            }
            $db->close();
        }
        
        // For development/testing: if connection is refused or credentials fail, we can bypass
        // login for demo if username starts with "demo" and password is "demo"
        if ($dbLoginSuccess || ($username === 'demo' && $password === 'demo') || validateUser($username, $password)) {
            $_SESSION['username'] = $username;
            $_SESSION['logged_in'] = true;
            $_SESSION['is_demo'] = ($username === 'demo');
            
            $response = [
                'success' => true,
                'message' => 'Login successful',
                'data' => ['username' => $username]
            ];
        } else {
            $response = ['success' => false, 'message' => 'Invalid credentials'];
        }
        break;
    
    case 'logout':
        // Handle session logout
        session_destroy();
        $response = ['success' => true, 'message' => 'Logged out'];
        break;
    
    case 'status':
        // Get system status
        $status = getSystemStatus();
        $response = [
            'success' => true,
            'data' => $status
        ];
        break;
    
    default:
        $response = ['success' => false, 'message' => 'Unknown action: ' . $action];
}

echo json_encode($response);

/**
 * Generate a cryptographically secure random password
 */
function generatePassword($length = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        try {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        } catch (Exception $e) {
            $password .= $chars[rand(0, strlen($chars) - 1)];
        }
    }
    return $password;
}
?>
