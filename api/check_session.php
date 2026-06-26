<?php
/**
 * Session Check Endpoint
 * Returns user session status in JSON format
 */

require_once 'functions.php';

header('Content-Type: application/json');

if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    $username = $_SESSION['username'];
    $userData = getUserDashboardData($username);
    
    echo json_encode([
        'logged_in' => true,
        'username' => $username,
        'data' => $userData
    ]);
} else {
    echo json_encode([
        'logged_in' => false
    ]);
}
?>
