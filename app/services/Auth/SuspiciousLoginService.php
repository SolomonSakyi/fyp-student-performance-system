<?php

/**
 * SuspiciousLoginService - heuristic detector for suspicious login activity.
 *
 * @package EduTrack
 * @subpackage Services\Auth
 * @version 1.0
 * @filepath app/services/Auth/SuspiciousLoginService.php
 *
 * PURPOSE:
 *   Read-only heuristic that examines recent login activity for one
 *   user and returns a verdict of whether the current login attempt
 *   looks suspicious. Called by login.php after a successful password
 *   verify and before the session is written.
 *
 * WHAT THIS SERVICE DOES:
 *   - Reads from login_attempts (written by login.php) and
 *     platform_users (for prior-login history).
 *   - Runs two rules:
 *       (19-A-1) failed_burst: >= 5 failed attempts for the same
 *                username or user_id within the last 15 minutes.
 *       (19-A-3) new_ip: the current successful login comes from an
 *                IP address not seen for this user in the last 30
 *                days, and the user has at least one prior successful
 *                login on record.
 *   - Sums the per-rule scores.
 *   - Returns ['suspicious' => bool, 'score' => int, 'reasons' => array].
 *
 * WHAT THIS SERVICE DOES NOT DO:
 *   - It does not write to login_attempts or any other table.
 *   - It does not log to audit_logs. The caller does that.
 *   - It does not block, challenge, or reject a login. It only
 *     reports. The caller decides the response.
 *   - It does not send notifications.
 *   - It does not read from any table other than login_attempts and
 *     platform_users.
 *   - It does not add any public method beyond check().
 *
 * FAILURE MODE:
 *   The service fails open. On any exception, check() returns
 *   ['suspicious' => false, 'score' => 0, 'reasons' => []] so the
 *   login flow is never broken by a heuristic failure. The exception
 *   is logged via error_log() for diagnostics.
 *
 * WEIGHTS AND THRESHOLDS (locked this session):
 *   - Failed burst window:     15 minutes.
 *   - Failed burst threshold:  5 failed attempts.
 *   - Failed burst score:      30 points.
 *   - New IP window:           30 days.
 *   - New IP score:            25 points.
 *   - Verdict threshold:       40 points.
 *   - Rule: suspicious = (score >= VERDICT_THRESHOLD).
 *     A single rule firing (25 or 30) does NOT flip the verdict. Both
 *     rules firing together (55) does.
 *
 * CALLER:
 *   login.php v2.6, after the password_verify() succeeds and before
 *   the session is written. The caller writes an audit_logs row with
 *   the verdict, then decides whether to challenge, block, or simply
 *   log the activity.
 */

class SuspiciousLoginService
{
    // -------------------- Constants --------------------
    private const BURST_WINDOW_MINUTES   = 15;
    private const BURST_THRESHOLD_ATTEMPTS = 5;
    private const BURST_SCORE            = 30;

    private const NEW_IP_WINDOW_DAYS     = 30;
    private const NEW_IP_SCORE           = 25;

    private const VERDICT_THRESHOLD      = 40;

    // -------------------- DB helper --------------------
    private static $db = null;

    private static function getDb()
    {
        if (self::$db === null) {
            $projectRoot = dirname(__DIR__, 2);
            require_once $projectRoot . '/app/helpers/DatabaseHelper.php';
            self::$db = DatabaseHelper::getInstance();
        }
        return self::$db;
    }

    // -------------------- Public API --------------------
    /**
     * Evaluate recent login activity and return a verdict.
     *
     * @param int         $userId    The resolved platform_users.id. Pass 0 when
     *                               the username did not resolve to a row.
     * @param string      $username  The literal username submitted. Always
     *                               required — even when $userId > 0 — so the
     *                               burst rule can also match failed attempts
     *                               against usernames that never resolved.
     * @param string      $ip        The client IP address (IPv4 or IPv6).
     * @param string|null $userAgent The User-Agent header, or null.
     *
     * @return array ['suspicious' => bool, 'score' => int, 'reasons' => array]
     */
    public static function check(int $userId, string $username, string $ip, ?string $userAgent): array
    {
        $reasons = [];
        $score   = 0;

        try {
            // Rule 1: failed-login burst.
            $burstScore = self::ruleFailedBurst($userId, $username);
            if ($burstScore > 0) {
                $score    += $burstScore;
                $reasons[] = 'failed_burst';
            }

            // Rule 2: new-IP successful login.
            // Only meaningful when the login resolved to a known user.
            if ($userId > 0) {
                $newIpScore = self::ruleNewIp($userId, $ip);
                if ($newIpScore > 0) {
                    $score    += $newIpScore;
                    $reasons[] = 'new_ip';
                }
            }
        } catch (Throwable $e) {
            // Fail open: log and return a non-suspicious verdict.
            error_log('SuspiciousLoginService::check failed: ' . $e->getMessage());
            return [
                'suspicious' => false,
                'score'      => 0,
                'reasons'    => [],
            ];
        }

        return [
            'suspicious' => ($score >= self::VERDICT_THRESHOLD),
            'score'      => $score,
            'reasons'    => $reasons,
        ];
    }

    // -------------------- Rules --------------------
    /**
     * (19-A-1) Failed-login burst.
     *
     * Counts failed attempts within the burst window, matching either
     * the resolved user_id (when present) or the literal username.
     * Returns the burst score if the count meets the threshold, else 0.
     */
    private static function ruleFailedBurst(int $userId, string $username): int
    {
        $db = self::getDb();

        // Build the WHERE clause. Always filter by window and outcome.
        // Match user_id when we have one; always also match the
        // literal username so failed attempts against usernames that
        // never resolved are counted toward the same burst.
        $whereParts = [
            "outcome = 'failed'",
            "deleted_at IS NULL",
            "created_at >= DATE_SUB(NOW(), INTERVAL " . self::BURST_WINDOW_MINUTES . " MINUTE)"
        ];
        $params     = [];

        if ($userId > 0) {
            $whereParts[] = "(user_id = ? OR username_attempted = ?)";
            $params[]     = $userId;
            $params[]     = $username;
        } else {
            $whereParts[] = "username_attempted = ?";
            $params[]     = $username;
        }

        $sql = "SELECT COUNT(*) AS c FROM login_attempts WHERE " . implode(' AND ', $whereParts);
        $row = $db->fetchOne($sql, $params);
        $count = (int)($row['c'] ?? 0);

        return $count >= self::BURST_THRESHOLD_ATTEMPTS ? self::BURST_SCORE : 0;
    }

    /**
     * (19-A-3) New-IP successful login.
     *
     * Returns the new-IP score when the current successful login's IP
     * has no prior successful login for this user within the window,
     * AND the user has at least one prior successful login on record
     * (so this is not the account's very first login). Otherwise 0.
     */
    private static function ruleNewIp(int $userId, string $ip): int
    {
        $db = self::getDb();

        // Does this user have any prior successful login at all?
        $priorRow = $db->fetchOne(
            "SELECT COUNT(*) AS c FROM login_attempts
             WHERE user_id = ? AND outcome = 'success' AND deleted_at IS NULL",
            [$userId]
        );
        $priorCount = (int)($priorRow['c'] ?? 0);
        if ($priorCount === 0) {
            // First-ever successful login for this user. Not suspicious.
            return 0;
        }

        // Has this user logged in successfully from this IP within the window?
        $sameIpRow = $db->fetchOne(
            "SELECT COUNT(*) AS c FROM login_attempts
             WHERE user_id = ? AND outcome = 'success' AND ip_address = ?
               AND deleted_at IS NULL
               AND created_at >= DATE_SUB(NOW(), INTERVAL " . self::NEW_IP_WINDOW_DAYS . " DAY)",
            [$userId, $ip]
        );
        $sameIpCount = (int)($sameIpRow['c'] ?? 0);

        return $sameIpCount === 0 ? self::NEW_IP_SCORE : 0;
    }
}
