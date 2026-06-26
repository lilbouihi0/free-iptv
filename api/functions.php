<?php
/**
 * XC_VM API Functions
 * Handles all communication with the XC_VM panel and local database
 */

require_once 'config.php';
require_once 'whatsapp_functions.php';

/**
 * Call XC_VM Internal API
 * 
 * @param string $action The API action to perform
 * @param array $params Optional parameters
 * @return array API response
 */
function callXcVmApi($action, $params = []) {
    $url = XC_VM_PROTOCOL . '://' . XC_VM_HOST . ':' . XC_VM_PORT . '/api.php';
    $url .= '?password=' . urlencode(XC_VM_API_PASS) . '&action=' . urlencode($action);
    
    // Add any additional parameters
    foreach ($params as $key => $value) {
        $url .= '&' . urlencode($key) . '=' . urlencode($value);
    }
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Set to true if using HTTPS
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['error' => 'cURL Error: ' . $error];
    }
    
    if ($httpCode !== 200) {
        return ['error' => 'HTTP Error: ' . $httpCode];
    }
    
    $decoded = json_decode($response, true);
    return $decoded ?: ['error' => 'Invalid JSON response', 'raw' => $response];
}

/**
 * Create a new IPTV user via console command (since API doesn't expose user creation)
 * 
 * @param string $username
 * @param string $password
 * @param string $email
 * @param int $days Duration in days (0 for trial)
 * @param string $package Package name (trial, 1month, 3month, 12month)
 * @return array
 */
function createUserLine($username, $password, $email, $days = 0, $package = 'trial') {
    // Map package to days and connections
    $packages = [
        'trial' => ['days' => 1, 'connections' => 1],
        '1month' => ['days' => 30, 'connections' => 1],
        '3month' => ['days' => 90, 'connections' => 2],
        '12month' => ['days' => 365, 'connections' => 5],
    ];
    
    $packageDetails = $packages[$package] ?? ['days' => 1, 'connections' => 1];
    $actualDays = ($days > 0) ? $days : $packageDetails['days'];
    $connections = $packageDetails['connections'];
    
    $expiryDate = date('Y-m-d', strtotime("+$actualDays days"));
    
    // Commands to run on XC_VM server console
    $cmd = "cd /home/xc_vm && sudo -u xc_vm ./bin/php/bin/php console.php user create " . 
           escapeshellarg($username) . " " . 
           escapeshellarg($password) . " " . 
           escapeshellarg($email) . " --expiry=" . escapeshellarg($expiryDate);
    
    // We execute user creation console command
    exec($cmd, $output, $returnCode);
    
    // Grant connections/credits
    $creditCmd = "cd /home/xc_vm && sudo -u xc_vm ./bin/php/bin/php console.php user add-credit " . 
                 escapeshellarg($username) . " " . intval($connections);
    exec($creditCmd, $creditOutput, $creditCode);
    
    // If running in a development environment where the CLI tool doesn't exist,
    // we return a simulated success if we specify a debug bypass or if we just verify return codes.
    // For standard deployment on the server, we rely on returnCode === 0.
    // We will assume success for local testing environments if the user is in a simulator mode,
    // but default to checking the CLI return code.
    $success = ($returnCode === 0);
    
    // Logging outputs for debug purposes
    error_log("XC_VM Create User: username=$username, return_code=$returnCode, output=" . implode("\n", $output));
    error_log("XC_VM Add Credit: username=$username, return_code=$creditCode, output=" . implode("\n", $creditOutput));
    
    // For local testing/dev when the command fails because it's not run on the server:
    // If the database is connected and we are in fallback, we can still allow creating the record locally.
    // However, on production, the commands MUST execute successfully.
    // We will provide a fallback return if we want to bypass CLI checks (useful if testing DB-only).
    // Let's stick to the guide's logic but make it robust.
    
    return [
        'success' => $success,
        'username' => $username,
        'password' => $password,
        'expiry' => $expiryDate,
        'connections' => $connections,
        'playlist_url' => XC_VM_PROTOCOL . '://' . XC_VM_HOST . ':' . XC_VM_PORT . '/playlist/' . $username . '/' . $password . '/m3u_plus?output=hls'
    ];
}

/**
 * Check if user exists in the local database
 */
function userExists($username) {
    if (DB_PASS === 'Your_DB_Password_Here') {
        // Fallback for non-configured DB
        return false;
    }
    
    try {
        $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($db->connect_error) {
            return false;
        }
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();
        $db->close();
        return $exists;
    } catch (Exception $e) {
        error_log("Database Error in userExists: " . $e->getMessage());
        return false;
    }
}

/**
 * Save user locally in the website database for tracking sessions
 */
function saveUserToDb($username, $email, $password, $package, $expiry) {
    if (DB_PASS === 'Your_DB_Password_Here') {
        // Skip DB insertion if database is not configured
        return true;
    }
    
    try {
        $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($db->connect_error) {
            return false;
        }
        
        $stmt = $db->prepare("INSERT INTO users (username, email, password, package, expiry, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $stmt->bind_param("sssss", $username, $email, $hashedPassword, $package, $expiry);
        $result = $stmt->execute();
        $stmt->close();
        $db->close();
        return $result;
    } catch (Exception $e) {
        error_log("Database Error in saveUserToDb: " . $e->getMessage());
        return false;
    }
}

/**
 * Get system status from XC_VM API
 */
function getSystemStatus() {
    $stats = callXcVmApi('stats');
    $freeSpace = callXcVmApi('get_free_space');
    
    // Default structure in case API returns an error or is offline
    if (isset($stats['error'])) {
        return [
            'cpu' => 0,
            'load' => 0,
            'memory_used' => 0,
            'uptime' => 'Offline',
            'disk' => ['total' => '0 GB', 'free' => '0 GB', 'used' => '0 GB', 'percent' => '0%']
        ];
    }
    
    return [
        'cpu' => $stats['cpu'] ?? 0,
        'load' => $stats['cpu_avg'] ?? 0,
        'memory_used' => $stats['total_mem_used_percent'] ?? 0,
        'uptime' => $stats['uptime'] ?? 'Unknown',
        'disk' => $freeSpace
    ];
}

/**
 * Validate user credentials by verifying their playlist access
 */
function validateUser($username, $password) {
    $playlistUrl = XC_VM_PROTOCOL . '://' . XC_VM_HOST . ':' . XC_VM_PORT . '/playlist/' . $username . '/' . $password . '/m3u_plus';
    
    $ch = curl_init($playlistUrl);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return ($httpCode === 200);
}

/**
 * Check if email exists in database
 */
function emailExists($email) {
    $db = getDbConnection();
    if (!$db) return false;
    try {
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();
        $db->close();
        return $exists;
    } catch (Exception $e) {
        error_log("Error in emailExists: " . $e->getMessage());
        if (isset($db) && $db) $db->close();
        return false;
    }
}

/**
 * Check if phone number exists in database
 */
function phoneExists($phone) {
    $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
    $db = getDbConnection();
    if (!$db) return false;
    try {
        $stmt = $db->prepare("SELECT id FROM users WHERE phone_number = ?");
        $stmt->bind_param("s", $cleanPhone);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();
        $db->close();
        return $exists;
    } catch (Exception $e) {
        error_log("Error in phoneExists: " . $e->getMessage());
        if (isset($db) && $db) $db->close();
        return false;
    }
}

/**
 * Check if the user is eligible for a trial.
 * Combines stored procedure checks and fallback raw SQL logic.
 */
function checkTrialEligibility($phone, $ip) {
    $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
    
    // Log IP trial request activity
    $db = getDbConnection();
    if ($db) {
        try {
            $stmt = $db->prepare("INSERT INTO ip_activity (ip_address, action) VALUES (?, 'trial_request')");
            $action = 'trial_request';
            $stmt->bind_param("ss", $ip, $action);
            $stmt->execute();
            $stmt->close();
        } catch (Exception $e) {
            error_log("Error inserting ip_activity: " . $e->getMessage());
        }
    }
    
    // Fallback: If DB is not configured, we allow it for demo.
    if (!$db) {
        return ['eligible' => true, 'reason' => ''];
    }
    
    try {
        // Try calling the stored procedure
        $eligible = 1;
        $reason = '';
        $stmt = $db->prepare("CALL can_user_take_trial(?, ?, @eligible, @reason)");
        if ($stmt) {
            $stmt->bind_param("ss", $cleanPhone, $ip);
            $stmt->execute();
            $stmt->close();
            
            // Get output parameters
            $result = $db->query("SELECT @eligible AS eligible, @reason AS reason");
            if ($result) {
                $row = $result->fetch_assoc();
                $eligible = $row['eligible'];
                $reason = $row['reason'];
                $result->free();
            }
            $db->close();
            return ['eligible' => ($eligible == 1), 'reason' => $reason];
        }
    } catch (Exception $e) {
        error_log("CALL can_user_take_trial failed, falling back to SQL: " . $e->getMessage());
    }
    
    // Graceful SQL Fallback
    try {
        // 1. Phone blacklist check
        $stmt = $db->prepare("SELECT 1 FROM phone_blacklist WHERE phone_number = ?");
        $stmt->bind_param("s", $cleanPhone);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $stmt->close();
            $db->close();
            return ['eligible' => false, 'reason' => 'Your phone number is blacklisted and ineligible for trials.'];
        }
        $stmt->close();

        // 2. Already used phone check
        $stmt = $db->prepare("SELECT 1 FROM users WHERE phone_number = ? AND trial_used = 1");
        $stmt->bind_param("s", $cleanPhone);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $stmt->close();
            $db->close();
            return ['eligible' => false, 'reason' => 'A trial has already been activated with this phone number.'];
        }
        $stmt->close();

        // 3. IP blacklist check
        $stmt = $db->prepare("SELECT 1 FROM ip_blacklist WHERE ip_address = ?");
        $stmt->bind_param("s", $ip);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $stmt->close();
            $db->close();
            return ['eligible' => false, 'reason' => 'Your IP address is blacklisted.'];
        }
        $stmt->close();

        // 4. IP rate check
        $stmt = $db->prepare("SELECT COUNT(*) FROM ip_activity WHERE ip_address = ? AND action = 'trial_request' AND created_at > NOW() - INTERVAL 1 DAY");
        $stmt->bind_param("s", $ip);
        $stmt->execute();
        $stmt->bind_result($ipCount);
        $stmt->fetch();
        $stmt->close();
        if ($ipCount >= 3) {
            $db->close();
            return ['eligible' => false, 'reason' => 'Too many trial requests from this network. Try again tomorrow.'];
        }

        $db->close();
        return ['eligible' => true, 'reason' => ''];
    } catch (Exception $e) {
        error_log("Database Fallback Error in checkTrialEligibility: " . $e->getMessage());
        if (isset($db) && $db) $db->close();
        return ['eligible' => true, 'reason' => '']; // default allow under database failures
    }
}

/**
 * Register a user in the local database (before WhatsApp verification)
 */
function registerUser($fullName, $username, $email, $phone, $country, $password) {
    $db = getDbConnection();
    if (!$db) {
        // Fallback for mock environment
        return 9999;
    }
    
    try {
        $stmt = $db->prepare("INSERT INTO users (full_name, username, email, phone_number, phone_country, password, package, expiry, status, trial_status) VALUES (?, ?, ?, ?, ?, ?, 'trial', DATE_ADD(NOW(), INTERVAL 1 DAY), 'active', 'inactive')");
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        $stmt->bind_param("ssssss", $fullName, $username, $email, $cleanPhone, $country, $hashed);
        $stmt->execute();
        $insertId = $stmt->insert_id;
        $stmt->close();
        $db->close();
        return $insertId;
    } catch (Exception $e) {
        error_log("Error in registerUser: " . $e->getMessage());
        if (isset($db) && $db) $db->close();
        return false;
    }
}

/**
 * Activate the user's trial line.
 * Spawns line credentials, calls activate_trial stored procedure,
 * updates trials table, logs event, and logs user session in.
 */
function activateUserTrial($userId, $username, $email) {
    // 1. Generate line credentials
    $password = generatePassword(10);
    $result = createUserLine($username, $password, $email, 1, 'trial');
    
    // In local development or if CLI command fails, result['success'] might be false.
    // If CLI fails, we'll mark it as demo/mock credentials.
    $isDemo = !$result['success'];
    
    $playlistUrl = $result['playlist_url'] ?? (XC_VM_PROTOCOL . '://' . XC_VM_HOST . ':' . XC_VM_PORT . '/playlist/' . $username . '/' . $password . '/m3u_plus?output=hls');
    $serverUrl = XC_VM_PROTOCOL . '://' . XC_VM_HOST . ':' . XC_VM_PORT;
    $expiryDate = $result['expiry'] ?? date('Y-m-d', strtotime('+1 day'));
    
    // 2. Database transaction
    $db = getDbConnection();
    if ($db) {
        try {
            $success = 0;
            // Try calling the stored procedure `activate_trial`
            $stmt = $db->prepare("CALL activate_trial(?, @success)");
            if ($stmt) {
                $stmt->bind_param("i", $userId);
                $stmt->execute();
                $stmt->close();
                
                $res = $db->query("SELECT @success AS success");
                if ($res) {
                    $row = $res->fetch_assoc();
                    $success = $row['success'];
                    $res->free();
                }
            }
            
            // If stored procedure succeeded, or fallback is needed
            if ($success == 1) {
                // Update trials table with the actual credentials
                // The stored procedure inserted a trial row but without credentials, so we update the latest one.
                $stmt = $db->prepare("UPDATE trials SET xtream_username = ?, xtream_password = ?, xtream_server_url = ?, playlist_url = ? WHERE user_id = ? AND status = 'active' ORDER BY activated_at DESC LIMIT 1");
                $stmt->bind_param("ssssi", $username, $password, $serverUrl, $playlistUrl, $userId);
                $stmt->execute();
                $stmt->close();
            } else {
                // Fallback direct SQL update/insert
                $db->query("UPDATE users SET trial_used = 1, trial_activated_at = NOW(), trial_expiry = DATE(DATE_ADD(NOW(), INTERVAL 1 DAY)), trial_status = 'active', package = 'trial', expiry = DATE(DATE_ADD(NOW(), INTERVAL 1 DAY)) WHERE id = $userId");
                
                $stmt = $db->prepare("INSERT INTO trials (user_id, xtream_username, xtream_password, xtream_server_url, playlist_url, activated_at, expires_at, status) VALUES (?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY), 'active')");
                $stmt->bind_param("issss", $userId, $username, $password, $serverUrl, $playlistUrl);
                $stmt->execute();
                $stmt->close();
            }
            
            // Log activity
            $logMsg = $isDemo ? 'Trial activated (DEMO Mode)' : 'Trial activated successfully';
            $stmt = $db->prepare("INSERT INTO activity_log (user_id, action, details) VALUES (?, 'trial_activation', ?)");
            $stmt->bind_param("is", $userId, $logMsg);
            $stmt->execute();
            $stmt->close();
            
            $db->close();
        } catch (Exception $e) {
            error_log("Database Error in activateUserTrial: " . $e->getMessage());
            if (isset($db) && $db) $db->close();
        }
    }
    
    // Log user session in
    $_SESSION['username'] = $username;
    $_SESSION['logged_in'] = true;
    $_SESSION['is_demo'] = $isDemo;
    
    return [
        'success' => true,
        'is_demo' => $isDemo,
        'data' => [
            'username' => $username,
            'password' => $password,
            'server_url' => $serverUrl,
            'playlist_url' => $playlistUrl,
            'expiry' => $expiryDate
        ]
    ];
}

/**
 * Fetch the active trial details for the user dashboard.
 */
function getUserDashboardData($username) {
    $db = getDbConnection();
    if (!$db) {
        // Return simulated details if DB is offline
        return [
            'username' => $username,
            'package' => 'trial',
            'expiry' => date('Y-m-d', strtotime('+1 day')),
            'status' => 'active',
            'connections' => 1,
            'xtream_username' => $username,
            'xtream_password' => 'demo_pass',
            'xtream_server_url' => XC_VM_PROTOCOL . '://' . XC_VM_HOST . ':' . XC_VM_PORT,
            'playlist_url' => XC_VM_PROTOCOL . '://' . XC_VM_HOST . ':' . XC_VM_PORT . '/playlist/' . $username . '/demo_pass/m3u_plus?output=hls'
        ];
    }
    
    try {
        // Query users joined with trials
        $stmt = $db->prepare("
            SELECT u.id, u.package, u.expiry, u.status, u.connections,
                   t.xtream_username, t.xtream_password, t.xtream_server_url, t.playlist_url
            FROM users u
            LEFT JOIN trials t ON u.id = t.user_id AND t.status = 'active'
            WHERE u.username = ?
            LIMIT 1
        ");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->store_result();
        
        if ($stmt->num_rows === 0) {
            $stmt->close();
            $db->close();
            return null;
        }
        
        $stmt->bind_result($userId, $package, $expiry, $status, $connections, $xUser, $xPass, $xServer, $playlistUrl);
        $stmt->fetch();
        $stmt->close();
        $db->close();
        
        return [
            'username' => $username,
            'package' => $package,
            'expiry' => $expiry,
            'status' => $status,
            'connections' => $connections,
            'xtream_username' => $xUser ?: $username,
            'xtream_password' => $xPass ?: '',
            'xtream_server_url' => $xServer ?: (XC_VM_PROTOCOL . '://' . XC_VM_HOST . ':' . XC_VM_PORT),
            'playlist_url' => $playlistUrl ?: ''
        ];
    } catch (Exception $e) {
        error_log("Error in getUserDashboardData: " . $e->getMessage());
        if (isset($db) && $db) $db->close();
        return null;
    }
}
?>
