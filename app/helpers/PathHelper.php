<?php

/**
 * PathHelper.php
 * Helper for getting project paths
 * 
 * @package EduTrack
 * @subpackage Helpers
 * @version 2.0
 */

class PathHelper
{
    /**
     * Get the project root path
     * Works from anywhere in the project
     */
    public static function getProjectRoot(): string
    {
        // Start from the current file's directory
        $path = __DIR__;

        // Keep going up until we find the project root
        // (look for the 'app' folder)
        while (!is_dir($path . '/app')) {
            $path = dirname($path);
            // Safety: don't go above drive root
            if ($path === dirname($path)) {
                break;
            }
        }

        return $path . '/';
    }

    /**
     * Get the public directory path
     */
    public static function getPublicPath(): string
    {
        return self::getProjectRoot() . 'public/';
    }
}
