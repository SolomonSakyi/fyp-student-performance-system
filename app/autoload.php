<?php
/**
 * autoload.php
 * 
 * Simple Autoloader for EduTrack
 * 
 * @package EduTrack
 * @version 1.0
 */

// Hardcode project root
$projectRoot = 'C:/Users/Almighty/Documents/FYP_Student_Performance_System_0.1/';

// Register autoloader
spl_autoload_register(function ($class) use ($projectRoot) {
    // Define class paths - try all possible locations
    $paths = [
        $projectRoot . 'app/models/Platform/',
        $projectRoot . 'app/controllers/Platform/',
        $projectRoot . 'app/services/Platform/',
        $projectRoot . 'app/middleware/',
        $projectRoot . 'app/helpers/',
        $projectRoot . 'app/models/',
        $projectRoot . 'app/controllers/',
        $projectRoot . 'app/services/',
    ];
    
    foreach ($paths as $path) {
        $file = $path . $class . '.php';
        if (file_exists($file)) {
            require_once $file;
            return true;
        }
    }
    
    return false;
});