<?php
/**
 * WhatsApp Business API Configuration
 * Reads from 'settings' table in database or falls back to default values.
 */

require_once 'config.php';

if (!function_exists('getDbSetting')) {
    /**
     * Fetch a setting from the local settings table.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    function getDbSetting($key, $default = '') {
        if (DB_PASS === 'Your_DB_Password_Here') {
            return $default;
        }
        
        try {
            $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            if ($db->connect_error) {
                return $default;
            }
            
            $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
            if ($stmt) {
                $stmt->bind_param("s", $key);
                $stmt->execute();
                $stmt->bind_result($val);
                $found = $stmt->fetch();
                $stmt->close();
                $db->close();
                return $found ? $val : $default;
            }
            $db->close();
        } catch (Exception $e) {
            error_log("Error reading setting $key: " . $e->getMessage());
        }
        return $default;
    }
}

// Define WhatsApp Settings Constants (Falls back if DB not initialized/connected)
define('WA_TOKEN', getDbSetting('whatsapp_token', 'EAABw_YOUR_LONG_LIVED_META_ACCESS_TOKEN_HERE'));
define('WA_PHONE_NUMBER_ID', getDbSetting('whatsapp_phone_number_id', 'YOUR_PHONE_NUMBER_ID_HERE'));
define('WA_API_VERSION', getDbSetting('whatsapp_version', 'v19.0'));
define('WA_TEMPLATE_NAME', getDbSetting('whatsapp_template_name', 'verification_otp'));
define('WA_TEMPLATE_LANG', getDbSetting('whatsapp_template_lang', 'en_US'));

// Bypass API calls (Demo Mode) when set to true. Perfect for testing without Meta credentials.
// Will default to 1 (true) if the token is default, so developer can run the app immediately.
$defaultToken = (WA_TOKEN === 'EAABw_YOUR_LONG_LIVED_META_ACCESS_TOKEN_HERE');
define('WA_BYPASS_API', getDbSetting('whatsapp_bypass_api', $defaultToken ? '1' : '0') === '1');
?>
