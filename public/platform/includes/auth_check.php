<?php
/**
 * Authentication Check
 * Include this at the top of every protected page
 */

session_start();

// Check if user is authenticated via session
if (!isset($_SESSION['user_id'])) {
    // If not in session, redirect to login
    // The login page will handle the token from localStorage
    header('Location: /platform/login.php');
    exit;
}
?>