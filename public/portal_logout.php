<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

session_destroy();
header('Location: /portal_login.php');
exit;