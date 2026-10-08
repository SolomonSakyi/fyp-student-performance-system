<?php

/**
 * SettingsMaintenanceService.php
 * Platform maintenance operations: clear cache, optimize database,
 * test SMTP connection. Extracted from SettingsController so the
 * tenant settings tab bodies can call them directly without an HTTP
 * loopback.
 *
 * @package EduTrack
 * @subpackage Services\Platform
 * @version 1.5.1
 * @filepath app/services/Platform/SettingsMaintenanceService.php
 *
 * v1.5.1 changes (2026-10-05) [ITEM-28]:
 *   - The tls branch's stream_socket_enable_crypto() call now pins
 *     the client to TLS 1.2 and TLS 1.3:
 *         STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
 *         | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
 *     The previous version passed STREAM_CRYPTO_METHOD_TLS_CLIENT,
 *     a bitmask that enables every TLS version the local OpenSSL
 *     supports — on OpenSSL 3.1.3, TLS 1.0 through 1.3. TLS 1.0 and
 *     1.1 are deprecated by every major standards body and disabled
 *     by Gmail and most SMTP operators. The pin excludes them. TLS
 *     1.3 remains available and is negotiated when the server offers
 *     it. Every other line of the file is byte-identical to v1.5.
 *
 * v1.5 changes (2026-10-05) [ITEM-27]:
 *   - The tls branch's stream_socket_client() call no longer passes
 *     a stream context.
 *
 * v1.4 changes (2026-10-05) [ITEM-27]:
 *   - smtpReadLine() now takes its line buffer by reference.
 *
 * v1.3 changes (2026-10-05) [ITEM-27]:
 *   - smtpReadResponse() and smtpReadMultilineResponse() use
 *     stream_select() + fread() instead of fgets().
 *
 * v1.2 changes (2026-10-05) [ITEM-27]:
 *   - The tls branch opened its socket with the ssl branch's
 *     six-argument shape. (Superseded by v1.5.)
 *
 * v1.1 changes (2026-10-05) [ITEM-27]:
 *   - testEmailConnection() now authenticates via AUTH LOGIN.
 *
 * DEPENDENCIES:
 *   - app/helpers/DatabaseHelper.php
 *   - app/helpers/LoggerHelper.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/helpers/DatabaseHelper.php';
require_once $projectRoot . 'app/helpers/LoggerHelper.php';

class SettingsMaintenanceService
{
    /**
     * @var DatabaseHelper
     */
    private $db;

    /**
     * @var LoggerHelper
     */
    private $logger;

    /**
     * The read timeout, in seconds, used by the SMTP helpers.
     * @var int
     */
    private const SMTP_READ_TIMEOUT = 10;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
    }

    // ============================================================
    // CLEAR CACHE
    // ============================================================
    public function clearCache(): array
    {
        $projectRoot = dirname(__DIR__, 3) . '/';
        $cacheDir = $projectRoot . 'storage/cache/';

        if (!is_dir($cacheDir)) {
            if (!@mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
                throw new Exception('Could not create cache directory: ' . $cacheDir);
            }
            return ['cleared' => 0, 'note' => 'Cache directory did not exist; created empty.'];
        }

        $cleared = 0;
        $items = @scandir($cacheDir);
        if ($items === false) {
            throw new Exception('Could not read cache directory: ' . $cacheDir);
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $cacheDir . $item;
            if (is_file($path)) {
                if (@unlink($path)) {
                    $cleared++;
                }
            } elseif (is_dir($path)) {
                $cleared += $this->removeDirectoryRecursive($path);
            }
        }
        return ['cleared' => $cleared, 'note' => null];
    }

    private function removeDirectoryRecursive(string $dir): int
    {
        $count = 0;
        if (!is_dir($dir)) {
            return $count;
        }
        $items = @scandir($dir);
        if ($items === false) {
            return $count;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $count += $this->removeDirectoryRecursive($path);
            } elseif (is_file($path)) {
                if (@unlink($path)) {
                    $count++;
                }
            }
        }
        @rmdir($dir);
        return $count;
    }

    // ============================================================
    // OPTIMIZE DATABASE
    // ============================================================
    public function optimizeDatabase(): array
    {
        $tables = [
            'results',
            'result_locks',
            'attendance_summaries',
            'student_attendance',
            'student_discipline',
            'student_behaviour',
            'audit_logs',
            'settings_audit_log',
            'platform_audit_logs',
            'notifications',
            'report_phrases',
            'payment_audit_logs',
        ];

        $results = [];
        foreach ($tables as $table) {
            try {
                $rows = $this->db->fetchAll('OPTIMIZE TABLE `' . $table . '`');
                $results[$table] = [
                    'ok'   => true,
                    'rows' => $rows,
                ];
            } catch (Exception $e) {
                $results[$table] = [
                    'ok'    => false,
                    'error' => $e->getMessage(),
                ];
            }
        }
        return ['optimized' => $results];
    }

    // ============================================================
    // TEST EMAIL CONNECTION
    // ============================================================
    public function testEmailConnection(array $settings): array
    {
        $host = trim((string)($settings['smtp_host'] ?? ''));
        $port = (int)($settings['smtp_port'] ?? 0);
        $enc  = strtolower(trim((string)($settings['smtp_encryption'] ?? 'tls')));

        $user = trim((string)($settings['smtp_username'] ?? ''));
        $pass = (string)($settings['smtp_password'] ?? '');

        if ($host === '' || $port <= 0) {
            throw new Exception('SMTP host and port are required.');
        }

        $timeout = self::SMTP_READ_TIMEOUT;

        // ---------------- 'none' or '' ----------------
        if ($enc === '' || $enc === 'none') {
            $sock = @stream_socket_client(
                $host . ':' . $port,
                $errno,
                $errstr,
                $timeout
            );
            if ($sock === false) {
                throw new Exception('Could not connect to SMTP server: ' . $errstr);
            }

            stream_set_timeout($sock, $timeout);

            $this->smtpGreetAndEhlo($sock);
            $auth = $this->smtpTryAuth($sock, $user, $pass);

            fclose($sock);

            return [
                'connected'     => true,
                'host'          => $host,
                'port'          => $port,
                'encryption'    => 'none',
                'authenticated' => $auth['authenticated'],
                'message'       => $auth['message'],
            ];
        }

        // ---------------- 'ssl' (implicit TLS) ----------------
        if ($enc === 'ssl') {
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer'       => true,
                    'verify_peer_name'  => true,
                    'allow_self_signed' => false,
                ],
            ]);
            $sock = @stream_socket_client(
                'ssl://' . $host . ':' . $port,
                $errno,
                $errstr,
                $timeout,
                STREAM_CLIENT_CONNECT,
                $context
            );
            if ($sock === false) {
                throw new Exception('Could not connect to SMTP server over SSL: ' . $errstr);
            }

            stream_set_timeout($sock, $timeout);

            $this->smtpGreetAndEhlo($sock);
            $auth = $this->smtpTryAuth($sock, $user, $pass);

            fclose($sock);

            return [
                'connected'     => true,
                'host'          => $host,
                'port'          => $port,
                'encryption'    => 'ssl',
                'authenticated' => $auth['authenticated'],
                'message'       => $auth['message'],
            ];
        }

        // ---------------- 'tls' (STARTTLS) ----------------
        if ($enc === 'tls') {
            // [v1.5 ITEM-27] Five-argument shape, no stream context.
            $sock = @stream_socket_client(
                $host . ':' . $port,
                $errno,
                $errstr,
                $timeout,
                STREAM_CLIENT_CONNECT
            );
            if ($sock === false) {
                throw new Exception('Could not connect to SMTP server: ' . $errstr);
            }

            stream_set_timeout($sock, $timeout);

            $ehloResponse = $this->smtpGreetAndEhlo($sock);
            if (stripos($ehloResponse, 'STARTTLS') === false) {
                fclose($sock);
                throw new Exception('SMTP server does not advertise STARTTLS.');
            }

            if (fwrite($sock, "STARTTLS\r\n") === false) {
                fclose($sock);
                throw new Exception('Could not send STARTTLS to SMTP server.');
            }
            $starttlsResponse = $this->smtpReadResponse($sock);
            if ($starttlsResponse === null || strpos($starttlsResponse, '220') !== 0) {
                fclose($sock);
                throw new Exception('SMTP server did not accept STARTTLS.');
            }

            // [v1.5.1 ITEM-28] Pin the client to TLS 1.2 and 1.3.
            $crypto = @stream_socket_enable_crypto(
                $sock,
                true,
                STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
            );
            if ($crypto !== true) {
                fclose($sock);
                throw new Exception('Could not negotiate TLS on the SMTP connection.');
            }

            $this->smtpGreetAndEhlo($sock);
            $auth = $this->smtpTryAuth($sock, $user, $pass);

            fclose($sock);

            return [
                'connected'     => true,
                'host'          => $host,
                'port'          => $port,
                'encryption'    => 'tls',
                'authenticated' => $auth['authenticated'],
                'message'       => $auth['message'],
            ];
        }

        throw new Exception('Unknown SMTP encryption value: ' . $enc);
    }

    private function smtpGreetAndEhlo($sock): string
    {
        $greeting = $this->smtpReadResponse($sock);
        if ($greeting === null || strpos($greeting, '220') !== 0) {
            throw new Exception('SMTP server did not send a 220 greeting.');
        }

        $clientHost = gethostname() ?: 'localhost';
        if (fwrite($sock, "EHLO {$clientHost}\r\n") === false) {
            throw new Exception('Could not send EHLO to SMTP server.');
        }
        $ehloResponse = $this->smtpReadMultilineResponse($sock);
        if ($ehloResponse === null || strpos($ehloResponse, '250') !== 0) {
            throw new Exception('SMTP server did not accept EHLO.');
        }

        return $ehloResponse;
    }

    private function smtpTryAuth($sock, string $user, string $pass): array
    {
        $userEmpty = ($user === '');
        $passEmpty = ($pass === '');

        if ($userEmpty && $passEmpty) {
            return [
                'authenticated' => false,
                'message'       => 'SMTP credentials not configured; transport verified.',
            ];
        }

        if ($userEmpty || $passEmpty) {
            $missing = $userEmpty ? 'username' : 'password';
            return [
                'authenticated' => false,
                'message'       => 'SMTP credentials incomplete; ' . $missing . ' missing; transport verified.',
            ];
        }

        if (fwrite($sock, "AUTH LOGIN\r\n") === false) {
            throw new Exception('Could not send AUTH LOGIN to SMTP server.');
        }
        $r1 = $this->smtpReadResponse($sock);
        if ($r1 === null || strpos($r1, '334') !== 0) {
            throw new Exception('SMTP server did not accept AUTH LOGIN.');
        }

        if (fwrite($sock, base64_encode($user) . "\r\n") === false) {
            throw new Exception('Could not send SMTP username.');
        }
        $r2 = $this->smtpReadResponse($sock);
        if ($r2 === null || strpos($r2, '334') !== 0) {
            throw new Exception('SMTP server did not accept the username.');
        }

        if (fwrite($sock, base64_encode($pass) . "\r\n") === false) {
            throw new Exception('Could not send SMTP password.');
        }
        $r3 = $this->smtpReadResponse($sock);
        if ($r3 === null || strpos($r3, '235') !== 0) {
            throw new Exception('SMTP server rejected the credentials.');
        }

        return [
            'authenticated' => true,
            'message'       => 'SMTP connection and authentication succeeded.',
        ];
    }

    private function smtpReadResponse($sock): ?string
    {
        $buffer = '';
        return $this->smtpReadLine($sock, $buffer);
    }

    private function smtpReadMultilineResponse($sock): ?string
    {
        $buffer = '';
        $full   = '';

        while (true) {
            $line = $this->smtpReadLine($sock, $buffer);
            if ($line === null) {
                return $full === '' ? null : trim($full);
            }
            $full .= $line . "\r\n";
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
            if (strlen($line) < 4) {
                break;
            }
        }
        return trim($full);
    }

    private function smtpReadLine($sock, string &$buffer): ?string
    {
        $pos = strpos($buffer, "\n");
        if ($pos !== false) {
            $line   = substr($buffer, 0, $pos + 1);
            $buffer = substr($buffer, $pos + 1);
            return rtrim($line, "\r\n");
        }

        stream_set_blocking($sock, false);

        $deadline = microtime(true) + self::SMTP_READ_TIMEOUT;

        while (true) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                stream_set_blocking($sock, true);
                if ($buffer === '') {
                    return null;
                }
                $line   = $buffer;
                $buffer = '';
                return rtrim($line, "\r\n");
            }

            $read   = [$sock];
            $write  = null;
            $except = null;

            $sec  = (int)floor($remaining);
            $usec = (int)(($remaining - $sec) * 1000000);

            $ready = @stream_select($read, $write, $except, $sec, $usec);
            if ($ready === false) {
                stream_set_blocking($sock, true);
                if ($buffer === '') {
                    return null;
                }
                $line   = $buffer;
                $buffer = '';
                return rtrim($line, "\r\n");
            }
            if ($ready === 0) {
                continue;
            }

            $chunk = @fread($sock, 1024);
            if ($chunk === false || $chunk === '') {
                if (feof($sock)) {
                    stream_set_blocking($sock, true);
                    if ($buffer === '') {
                        return null;
                    }
                    $line   = $buffer;
                    $buffer = '';
                    return rtrim($line, "\r\n");
                }
                continue;
            }

            $buffer .= $chunk;

            $pos = strpos($buffer, "\n");
            if ($pos !== false) {
                $line   = substr($buffer, 0, $pos + 1);
                $buffer = substr($buffer, $pos + 1);
                stream_set_blocking($sock, true);
                return rtrim($line, "\r\n");
            }
        }
    }
}
