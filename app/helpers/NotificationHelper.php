<?php

/**
 * NotificationHelper — central producer API for in-app notifications.
 *
 * @package EduTrack
 * @filepath app/helpers/NotificationHelper.php
 * @version 1.1  (S17g-2: recipient resolution helpers added)
 *
 * Usage (inside a producer, e.g. discipline.create):
 *
 *   require_once $projectRoot . '/app/helpers/NotificationHelper.php';
 *   NotificationHelper::notify($db, $tenantId, [
 *       'recipients'   => ['staff' => [1, 2], 'guardians' => [10]],
 *       'type'         => 'discipline',
 *       'priority'     => 'high',
 *       'title'        => 'New discipline incident recorded',
 *       'message'      => 'Fight at lunch for Kwame Mensah (JHS 2).',
 *       'link'         => '/platform/tenant/students/discipline.php?id=42',
 *       'related_type' => 'student_discipline',
 *       'related_id'   => 42,
 *       'created_by'   => $userId,
 *   ]);
 *
 * Session S17g decisions:
 *   NS2A  extended type enum
 *   NS3C  recipient_role enum with nullable user_id
 *   NS4A  helper fans out one row per recipient
 *   NS5C  defaults live here, per-tenant overrides arrive later
 *   NS7D  channel = 'inapp' only for now
 *
 * Session S17g-2 additions:
 *   guardianForStudent(), classTeacherForStudent(), staffByRoleName(),
 *   headTeachers(), allGuardiansForTenant(), allStaffForTenant()
 *
 * NOTE: every call is wrapped in try/catch. A failed notification MUST NOT
 * break the caller's business transaction. Errors are error_log()ed only.
 */
class NotificationHelper
{
    /**
     * Type → priority default map. Producers may override priority.
     */
    private static array $typePriority = [
        'rejected'     => 'high',
        'discipline'   => 'high',
        'behaviour'    => 'high',
        'escalation'   => 'urgent',
        'promotion'    => 'high',
        'finance'      => 'normal',
        'result'       => 'normal',
        'reminder'     => 'normal',
        'registration' => 'low',
        'credentials'  => 'normal',
        'approved'     => 'normal',
        'attendance'   => 'normal',
        'biometric'    => 'low',
        'academic'     => 'normal',
        'system'       => 'low',
        'info'         => 'low',
    ];

    /**
     * Allowed enum values (must match the migration).
     */
    private static array $allowedTypes = [
        'approved',
        'rejected',
        'info',
        'system',
        'discipline',
        'behaviour',
        'attendance',
        'finance',
        'academic',
        'promotion',
        'biometric',
        'registration',
        'credentials',
        'reminder',
        'result',
        'escalation',
    ];
    private static array $allowedPriorities = ['low', 'normal', 'high', 'urgent'];
    private static array $allowedChannels   = ['inapp', 'email', 'sms'];
    private static array $allowedRoles      = ['staff', 'guardian', 'system'];

    /**
     * Fan out one notification row per recipient.
     *
     * @param mixed $db       DatabaseHelper instance
     * @param int   $tenantId Tenant id
     * @param array $opts     See usage above.
     * @return int            Number of rows inserted
     */
    public static function notify($db, int $tenantId, array $opts): int
    {
        try {
            if ($tenantId <= 0) return 0;

            // ----- Normalise core fields -----
            $type = (string)($opts['type'] ?? 'info');
            if (!in_array($type, self::$allowedTypes, true)) $type = 'info';

            $priority = (string)($opts['priority'] ?? (self::$typePriority[$type] ?? 'normal'));
            if (!in_array($priority, self::$allowedPriorities, true)) $priority = 'normal';

            $channel = (string)($opts['channel'] ?? 'inapp');
            if (!in_array($channel, self::$allowedChannels, true)) $channel = 'inapp';

            $title       = trim((string)($opts['title'] ?? ''));
            $message     = trim((string)($opts['message'] ?? ''));
            $link        = trim((string)($opts['link'] ?? ''));
            $relatedType = trim((string)($opts['related_type'] ?? ''));
            $relatedId   = isset($opts['related_id']) ? (int)$opts['related_id'] : null;
            $createdBy   = isset($opts['created_by']) ? (int)$opts['created_by'] : null;

            if ($title === '') return 0;

            // ----- Normalise recipients -----
            $recipients = $opts['recipients'] ?? [];
            $staffIds    = [];
            $guardianIds = [];

            if (is_array($recipients)) {
                if (isset($recipients['staff']) && is_array($recipients['staff'])) {
                    foreach ($recipients['staff'] as $id) {
                        $id = (int)$id;
                        if ($id > 0) $staffIds[] = $id;
                    }
                }
                if (isset($recipients['guardians']) && is_array($recipients['guardians'])) {
                    foreach ($recipients['guardians'] as $id) {
                        $id = (int)$id;
                        if ($id > 0) $guardianIds[] = $id;
                    }
                }
                // Shorthand: ['recipients' => [1,2,3]] → treat as staff
                if (!$staffIds && !$guardianIds && array_values($recipients) === $recipients) {
                    foreach ($recipients as $id) {
                        $id = (int)$id;
                        if ($id > 0) $staffIds[] = $id;
                    }
                }
            }

            $staffIds    = array_values(array_unique($staffIds));
            $guardianIds = array_values(array_unique($guardianIds));

            if (!$staffIds && !$guardianIds) return 0;

            $inserted = 0;
            foreach ($staffIds as $uid) {
                $inserted += self::insertOne($db, $tenantId, [
                    'user_id'        => $uid,
                    'recipient_role' => 'staff',
                    'type'           => $type,
                    'priority'       => $priority,
                    'channel'        => $channel,
                    'title'          => $title,
                    'message'        => $message,
                    'link'           => $link,
                    'related_type'   => $relatedType,
                    'related_id'     => $relatedId,
                    'created_by'     => $createdBy,
                ]);
            }

            foreach ($guardianIds as $gid) {
                $inserted += self::insertOne($db, $tenantId, [
                    'user_id'        => $gid,
                    'recipient_role' => 'guardian',
                    'type'           => $type,
                    'priority'       => $priority,
                    'channel'        => $channel,
                    'title'          => $title,
                    'message'        => $message,
                    'link'           => $link,
                    'related_type'   => $relatedType,
                    'related_id'     => $relatedId,
                    'created_by'     => $createdBy,
                ]);
            }

            return $inserted;
        } catch (Exception $e) {
            error_log('NotificationHelper::notify failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Insert one row. Wrapped so a single failure doesn't abort the fan-out.
     */
    private static function insertOne($db, int $tenantId, array $row): int
    {
        try {
            $uuid = self::uuidv4();
            $db->insert(
                "INSERT INTO notifications
                    (uuid, user_id, tenant_id, title, message, type,
                     priority, channel, recipient_role,
                     link, related_type, related_id,
                     is_read, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, NOW(), NOW())",
                [
                    $uuid,
                    $row['user_id'] ?: null,
                    $tenantId,
                    $row['title'],
                    $row['message'],
                    $row['type'],
                    $row['priority'],
                    $row['channel'],
                    $row['recipient_role'],
                    $row['link'] !== '' ? $row['link'] : null,
                    $row['related_type'] !== '' ? $row['related_type'] : null,
                    $row['related_id'] ?: null,
                    $row['created_by'] ?: null,
                ]
            );
            return 1;
        } catch (Exception $e) {
            error_log('NotificationHelper insert failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Minimal uuidv4 (self-contained; producers don't need to include the page's helper).
     */
    private static function uuidv4(): string
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

    /**
     * Return user_ids whose role_name matches, scoped to a tenant.
     */
    public static function staffByRoleName($db, int $tenantId, string $roleName): array
    {
        try {
            $rows = $db->fetchAll(
                "SELECT DISTINCT pu.id
                   FROM platform_users pu
                   JOIN platform_user_roles pur ON pur.user_id = pu.id
                   JOIN roles r ON r.id = pur.role_id
                  WHERE pu.tenant_id = ?
                    AND pu.is_active = 1
                    AND pu.deleted_at IS NULL
                    AND r.role_name = ?",
                [$tenantId, $roleName]
            );
            return array_map(fn($r) => (int)$r['id'], $rows);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Return the guardian_person_id for a student, or null.
     * Reads students.guardian_person_id — confirmed to exist on the real schema.
     */
    public static function guardianForStudent($db, int $tenantId, int $studentId): ?int
    {
        try {
            $row = $db->fetchOne(
                "SELECT guardian_person_id
                   FROM students
                  WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$studentId, $tenantId]
            );
            if ($row && !empty($row['guardian_person_id'])) {
                return (int)$row['guardian_person_id'];
            }
            return null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Return the class teacher's user_id for a student's active enrollment
     * in the given year/term, or null.
     *
     * Reads teacher_assignments joined to class_offerings. If those tables are
     * not present or the query fails, returns null rather than guessing.
     */
    public static function classTeacherForStudent($db, int $tenantId, int $studentId, int $yearId, int $termId = 0): ?int
    {
        try {
            $sql = "SELECT ta.staff_id AS teacher_id
                      FROM enrollments e
                      JOIN class_offerings co ON co.id = e.class_offering_id
                      JOIN teacher_assignments ta
                        ON ta.class_offering_id = co.id
                       AND ta.tenant_id = co.tenant_id
                       AND ta.deleted_at IS NULL
                     WHERE e.tenant_id = ?
                       AND e.student_id = ?
                       AND e.status = 'active'
                       AND e.deleted_at IS NULL
                       AND co.deleted_at IS NULL";
            $params = [$tenantId, $studentId];
            if ($yearId > 0) {
                $sql .= " AND e.academic_year_id = ?";
                $params[] = $yearId;
            }
            if ($termId > 0) {
                $sql .= " AND (e.academic_term_id IS NULL OR e.academic_term_id = ?)";
                $params[] = $termId;
            }
            $sql .= " ORDER BY ta.id ASC LIMIT 1";

            $row = $db->fetchOne($sql, $params);
            if ($row && !empty($row['teacher_id'])) {
                return (int)$row['teacher_id'];
            }
            return null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Convenience: every head teacher user_id for a tenant.
     */
    public static function headTeachers($db, int $tenantId): array
    {
        return self::staffByRoleName($db, $tenantId, 'Head Teacher');
    }

    /**
     * Every distinct guardian_person_id for active students in a tenant.
     * Used for broadcast announcements.
     */
    public static function allGuardiansForTenant($db, int $tenantId): array
    {
        try {
            $rows = $db->fetchAll(
                "SELECT DISTINCT guardian_person_id
                   FROM students
                  WHERE tenant_id = ?
                    AND deleted_at IS NULL
                    AND is_active = 1
                    AND guardian_person_id IS NOT NULL",
                [$tenantId]
            );
            return array_map(fn($r) => (int)$r['guardian_person_id'], $rows);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Every staff user_id for a tenant (used for broadcast to staff).
     */
    public static function allStaffForTenant($db, int $tenantId): array
    {
        try {
            $rows = $db->fetchAll(
                "SELECT id FROM platform_users
                  WHERE tenant_id = ?
                    AND is_active = 1
                    AND deleted_at IS NULL",
                [$tenantId]
            );
            return array_map(fn($r) => (int)$r['id'], $rows);
        } catch (Exception $e) {
            return [];
        }
    }
}
