<?php
/**
 * XC_VM API Configuration (Example Template)
 * Rename this file to config.php and enter your local connection details.
 */

// XC_VM Panel Connection Details
define('XC_VM_HOST', '178.18.248.202');
define('XC_VM_PORT', 80);
define('XC_VM_API_PASS', 'YOUR_XC_VM_API_PASSWORD_HERE');
define('XC_VM_PROTOCOL', 'https');

// Website Database (for storing user sessions and local data)
define('DB_HOST', 'localhost');
define('DB_NAME', 'iptv_website');
define('DB_USER', 'website_user');
define('DB_PASS', 'YOUR_DATABASE_PASSWORD_HERE');

// Session Configuration for security
if (session_status() == PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', 0); // Set to 1 if using HTTPS
    ini_set('session.use_only_cookies', 1);
    ini_set('session.gc_maxlifetime', 86400); // 24 hours
    session_start();
}
?>
