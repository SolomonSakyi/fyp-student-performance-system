<?php

/**
 * LoggerHelper.php
 * Simple logging helper
 * 
 * @package EduTrack
 * @subpackage Helpers
 * @filepath app/helpers/LoggerHelper.php
 */

class LoggerHelper
{
    private static $instance = null;
    private $logFile;

    public function __construct()
    {
        $logDir = dirname(__DIR__, 2) . '/logs/';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }
        $this->logFile = $logDir . 'app_' . date('Y-m-d') . '.log';
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('WARNING', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('DEBUG', $message, $context);
    }

    private function log(string $level, string $message, array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
        $logEntry = "[$timestamp] [$level] $message$contextStr" . PHP_EOL;
        @error_log($logEntry, 3, $this->logFile);
    }

    public function setLogFile(string $path): void
    {
        $this->logFile = $path;
    }
}
