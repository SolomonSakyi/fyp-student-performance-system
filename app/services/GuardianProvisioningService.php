<?php

/**
 * GuardianProvisioningService — provisions platform_users rows
 * from guardians rows.
 *
 * @package EduTrack
 * @subpackage Services
 * @version 1.1
 * @filepath app/services/GuardianProvisioningService.php
 *
 * Purpose:
 *   Turn a guardians row into a platform_users row that can log
 *   in to the student portal. The service is the guardian side
 *   of the user-identity milestone's code phase.
 *
 * WHAT THIS FILE DOES:
 *   - provisionForGuardian($guardianId, $bulk) inserts one
 *     platform_users row for one guardian, if the guardian is
 *     eligible and has no live platform_users row yet.
 *   - provisionPending($tenantId, $limit) selects eligible
 *     guardians with no live platform_users row and provisions
 *     them one at a time in their own transactions.
 *
 * WHAT THIS FILE DOES NOT DO:
 *   - It does not write session keys. Login writes them.
 *   - It does not send SMS or email itself. It calls the
 *     GuardianCredentialsDelivery interface.
 *   - It does not create student_guardians rows.
 *   - It does not re-provision an existing guardian.
 *   - It does not touch students.guardian_*.
 *   - It does not touch any other table.
 *
 * LOCKED DECISIONS (2026-10-04):
 *   Q1   The guardian is the login. No student login exists.
 *   Q3   OTP on new device, password change request, and
 *        suspicious login. Separate from the first-login
 *        credential.
 *   Q4   Bulk-provisioned logins are created disabled and
 *        issued in controlled batches.
 *   (2a) The first-login credential is a 12-character random
 *        alphanumeric one-time password. It is separate from
 *        the OTP.
 *   (3a) is_active = 1 on single provision; is_active = 0 on
 *        batch provision.
 *
 * v1.1 changes (2026-10-04):
 *   - provisionForGuardian() now resolves the platform_users.email
 *     value via resolveLoginEmail(), which enforces the
 *     following rule:
 *       1. If the guardian's email is non-empty AND no live
 *          platform_users row owns it, use it as-is.
 *       2. If the guardian's email is non-empty BUT a live
 *          platform_users row owns it, use a synthetic address
 *          of the form guardian+<guardian_id>@<tenant_code>.local.
 *       3. If the guardian's email is empty, use the same
 *          synthetic address.
 *     The synthetic address is deterministic and cannot collide
 *     with a real email. The guardian's real email stays on
 *     guardians.email and is the OTP delivery target.
 *   - The rule was forced by a live collision: guardian 1 has
 *     email solomonrandy@gmail.com, which platform_users.id 87
 *     (admin011, platform_admin, tenant 7) already owns. The
 *     previous inline ternary did not handle that collision.
 *
 * DEPENDENCIES:
 *   - app/helpers/DatabaseHelper.php
 *   - app/services/GuardianCredentialsDelivery.php
 */

require_once dirname(__DIR__) . '/helpers/DatabaseHelper.php';
require_once __DIR__ . '/GuardianCredentialsDelivery.php';

if (!function_exists('uuidv4')) {
    function uuidv4(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}

class GuardianProvisioningService
{
    /** @var DatabaseHelper */
    private $db;

    /** @var GuardianCredentialsDelivery */
    private $delivery;

    /** @var object|null */
    private $logger;

    public function __construct(GuardianCredentialsDelivery $delivery, $logger = null)
    {
        $this->db       = DatabaseHelper::getInstance();
        $this->delivery = $delivery;
        $this->logger   = $logger;
    }

    /**
     * Provision one guardian.
     *
     * @param int  $guardianId  The guardians.id to provision.
     * @param bool $bulk        true for batch (is_active = 0),
     *                          false for single (is_active = 1).
     *
     * @return array {
     *     ok:      bool,
     *     user_id: int|null,
     *     username:string|null,
     *     reason:  string|null,
     *     otp_sent:bool
     * }
     */
    public function provisionForGuardian(int $guardianId, bool $bulk = false): array
    {
        if ($guardianId <= 0) {
            return $this->fail('guardian_id is required.');
        }

        $g = $this->loadGuardian($guardianId);
        if ($g === null) {
            return $this->fail('Guardian not found or soft-deleted.');
        }

        if ((int)$g['is_active'] !== 1) {
            return $this->fail('Guardian is inactive.');
        }

        $phone = trim((string)($g['primary_phone'] ?? ''));
        $email = trim((string)($g['email'] ?? ''));
        if ($phone === '' && $email === '') {
            return $this->fail('Guardian has no phone and no email.');
        }

        $existing = $this->loadLiveUserForGuardian((int)$g['tenant_id'], (int)$g['id']);
        if ($existing !== null) {
            return $this->fail('Guardian already has a platform_users row.');
        }

        $oneTimePassword = $this->generateOneTimePassword(12);
        $hash            = password_hash($oneTimePassword, PASSWORD_DEFAULT);
        $username        = $this->generateUniqueUsername(
            (string)$g['first_name'],
            (string)$g['last_name'],
            (int)$g['id']
        );
        $loginEmail      = $this->resolveLoginEmail(
            (int)$g['tenant_id'],
            (int)$g['id'],
            $email
        );

        $isActive = $bulk ? 0 : 1;

        try {
            $this->db->beginTransaction();

            $uuid = uuidv4();
            $userId = (int)$this->db->insert(
                "INSERT INTO platform_users
                    (uuid, tenant_id, user_type, guardian_id,
                     username, email, password_hash,
                     first_name, last_name, phone,
                     is_active, password_must_change)
                 VALUES (?, ?, 'guardian', ?, ?, ?, ?, ?, ?, ?, ?, 1)",
                [
                    $uuid,
                    (int)$g['tenant_id'],
                    (int)$g['id'],
                    $username,
                    $loginEmail,
                    $hash,
                    (string)$g['first_name'],
                    (string)$g['last_name'],
                    $phone !== '' ? $phone : null,
                    $isActive,
                ]
            );

            if ($userId <= 0) {
                $this->db->rollBack();
                return $this->fail('Insert into platform_users did not return an id.');
            }

            $this->db->commit();
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->log('provisionForGuardian failed for guardian ' . $guardianId . ': ' . $e->getMessage());
            return $this->fail('Database error: ' . $e->getMessage());
        }

        $channels = [];
        if ($phone !== '') $channels[] = 'sms';
        if ($email !== '') $channels[] = 'email';

        $sent = false;
        if ($bulk === false) {
            try {
                $sent = $this->delivery->deliver($userId, $oneTimePassword, $channels);
            } catch (Exception $e) {
                $this->log('deliver failed for user ' . $userId . ': ' . $e->getMessage());
                $sent = false;
            }
        }

        return [
            'ok'       => true,
            'user_id'  => $userId,
            'username' => $username,
            'reason'   => null,
            'otp_sent' => $sent,
        ];
    }

    /**
     * Provision all pending guardians for a tenant, up to $limit.
     *
     * @param int $tenantId
     * @param int $limit
     *
     * @return array {
     *     ok:       bool,
     *     attempted:int,
     *     created:  int,
     *     skipped:  int,
     *     errors:   array<int,array>
     * }
     */
    public function provisionPending(int $tenantId, int $limit = 50): array
    {
        if ($tenantId <= 0) {
            return ['ok' => false, 'attempted' => 0, 'created' => 0, 'skipped' => 0, 'errors' => []];
        }
        if ($limit <= 0 || $limit > 500) {
            $limit = 50;
        }

        $sql = "SELECT g.id
                FROM guardians g
                LEFT JOIN platform_users u
                    ON u.guardian_id = g.id
                   AND u.deleted_at IS NULL
                WHERE g.tenant_id = ?
                  AND g.deleted_at IS NULL
                  AND g.is_active = 1
                  AND u.id IS NULL
                  AND (g.primary_phone IS NOT NULL AND TRIM(g.primary_phone) <> ''
                       OR g.email IS NOT NULL AND TRIM(g.email) <> '')
                ORDER BY g.id ASC
                LIMIT ?";

        $rows = $this->db->fetchAll($sql, [$tenantId, $limit]);

        $result = [
            'ok'        => true,
            'attempted' => 0,
            'created'   => 0,
            'skipped'   => 0,
            'errors'    => [],
        ];

        foreach ($rows as $row) {
            $gid = (int)$row['id'];
            $result['attempted']++;
            $r = $this->provisionForGuardian($gid, true);
            if (!empty($r['ok'])) {
                $result['created']++;
            } else {
                $result['skipped']++;
                $result['errors'][] = ['guardian_id' => $gid, 'reason' => $r['reason']];
            }
        }

        return $result;
    }

    // ----------------------------------------------------------
    // INTERNALS
    // ----------------------------------------------------------

    private function loadGuardian(int $guardianId): ?array
    {
        try {
            return $this->db->fetchOne(
                "SELECT id, tenant_id, first_name, last_name, primary_phone, email, is_active
                 FROM guardians
                 WHERE id = ? AND deleted_at IS NULL",
                [$guardianId]
            ) ?: null;
        } catch (Exception $e) {
            $this->log('loadGuardian failed: ' . $e->getMessage());
            return null;
        }
    }

    private function loadLiveUserForGuardian(int $tenantId, int $guardianId): ?array
    {
        try {
            return $this->db->fetchOne(
                "SELECT id
                 FROM platform_users
                 WHERE tenant_id = ?
                   AND guardian_id = ?
                   AND deleted_at IS NULL
                 LIMIT 1",
                [$tenantId, $guardianId]
            ) ?: null;
        } catch (Exception $e) {
            $this->log('loadLiveUserForGuardian failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Resolve the email to store on platform_users.
     *
     * Rule (v1.1):
     *   1. If $guardianEmail is non-empty AND no live
     *      platform_users row owns it, return it lowercased.
     *   2. If $guardianEmail is non-empty BUT a live
     *      platform_users row owns it, return the synthetic
     *      address guardian+<guardianId>@<tenant_code>.local.
     *   3. If $guardianEmail is empty, return the synthetic
     *      address.
     *
     * The synthetic address is deterministic and cannot collide
     * with a real email. The guardian's real email stays on
     * guardians.email and is the OTP delivery target.
     */
    private function resolveLoginEmail(int $tenantId, int $guardianId, string $guardianEmail): string
    {
        $guardianEmail = strtolower(trim($guardianEmail));

        if ($guardianEmail !== '') {
            try {
                $taken = $this->db->fetchOne(
                    "SELECT id FROM platform_users
                     WHERE email = ? AND deleted_at IS NULL
                     LIMIT 1",
                    [$guardianEmail]
                );
                if (!$taken) {
                    return $guardianEmail;
                }
            } catch (Exception $e) {
                $this->log('resolveLoginEmail lookup failed: ' . $e->getMessage());
            }
        }

        return $this->syntheticLoginEmail($tenantId, $guardianId);
    }

    /**
     * Build a synthetic, deterministic, non-routable email of the
     * form guardian+<guardianId>@<tenant_code_lowercase>.local.
     * Falls back to tenant<tenantId> when tenant_code is empty or
     * unusable.
     */
    private function syntheticLoginEmail(int $tenantId, int $guardianId): string
    {
        $slug = '';
        try {
            $row = $this->db->fetchOne(
                "SELECT tenant_code FROM tenants WHERE id = ? AND deleted_at IS NULL LIMIT 1",
                [$tenantId]
            );
            if ($row && !empty($row['tenant_code'])) {
                $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string)$row['tenant_code']));
                $slug = trim($slug, '-');
            }
        } catch (Exception $e) {
            $this->log('syntheticLoginEmail tenant lookup failed: ' . $e->getMessage());
        }
        if ($slug === '') {
            $slug = 'tenant' . $tenantId;
        }
        return 'guardian+' . $guardianId . '@' . $slug . '.local';
    }

    private function generateOneTimePassword(int $length = 12): string
    {
        $charset = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $max = strlen($charset) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $charset[random_int(0, $max)];
        }
        return $out;
    }

    private function generateUniqueUsername(string $first, string $last, int $guardianId): string
    {
        $a = strtolower(preg_replace('/[^a-z0-9]/i', '', substr($first, 0, 3)));
        $b = strtolower(preg_replace('/[^a-z0-9]/i', '', substr($last, 0, 3)));
        if ($a === '') $a = 'grd';
        if ($b === '') $b = 'grd';

        $base = $a . $b . str_pad((string)$guardianId, 4, '0', STR_PAD_LEFT);
        $candidate = $base;
        $suffix = 0;

        while (true) {
            try {
                $existing = $this->db->fetchOne(
                    "SELECT id FROM platform_users WHERE username = ? LIMIT 1",
                    [$candidate]
                );
                if (!$existing) {
                    return $candidate;
                }
            } catch (Exception $e) {
                $this->log('generateUniqueUsername lookup failed: ' . $e->getMessage());
                return $candidate;
            }
            $suffix++;
            $candidate = $base . $suffix;
            if ($suffix > 999) {
                return $base . '_' . bin2hex(random_bytes(3));
            }
        }
    }

    private function fail(string $reason): array
    {
        return ['ok' => false, 'user_id' => null, 'username' => null, 'reason' => $reason, 'otp_sent' => false];
    }

    private function log(string $message): void
    {
        if ($this->logger && method_exists($this->logger, 'error')) {
            $this->logger->error($message);
        } else {
            error_log('GuardianProvisioningService: ' . $message);
        }
    }
}
