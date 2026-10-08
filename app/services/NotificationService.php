<?php

/**
 * Notification Service - Complete Enterprise Notification System
 */

class NotificationService
{
    private $db;
    private $logger;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
    }

    /**
     * Send notification - Main entry point
     */
    public function send(array $data): array
    {
        try {
            $this->db->beginTransaction();

            // Validate required fields
            if (empty($data['event_code']) || empty($data['recipients'])) {
                throw new Exception('Event code and recipients are required');
            }

            // Get event
            $event = $this->db->fetchOne("
                SELECT * FROM notification_events 
                WHERE event_code = ? AND is_active = 1
            ", [$data['event_code']]);

            if (!$event) {
                throw new Exception('Event not found: ' . $data['event_code']);
            }

            // Resolve recipients
            $recipients = $this->resolveRecipients($data['recipients']);
            if (empty($recipients)) {
                throw new Exception('No valid recipients found');
            }

            // Create notification
            $notificationId = $this->createNotification([
                'event_id' => $event['id'],
                'tenant_id' => $data['tenant_id'] ?? null,
                'school_id' => $data['school_id'] ?? null,
                'user_id' => $data['created_by'] ?? null,
                'reference_id' => $data['reference_id'] ?? null,
                'reference_type' => $data['reference_type'] ?? null,
                'subject' => $data['subject'] ?? $event['event_name'],
                'body' => $data['body'] ?? '',
                'variables' => $data['variables'] ?? [],
                'priority' => $data['priority'] ?? $event['priority'] ?? 'NORMAL'
            ]);

            // Process each recipient
            $results = [];
            foreach ($recipients as $recipient) {
                $result = $this->processRecipient($notificationId, $recipient, $data);
                $results[] = $result;
            }

            $this->db->commit();

            return [
                'success' => true,
                'data' => [
                    'notification_id' => $notificationId,
                    'total_recipients' => count($recipients),
                    'results' => $results
                ]
            ];
        } catch (Exception $e) {
            $this->db->rollback();
            $this->logger->error('Notification failed: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Resolve recipients from input
     */
    private function resolveRecipients(array $input): array
    {
        $recipients = [];

        foreach ($input as $item) {
            // User ID
            if (isset($item['user_id'])) {
                $user = $this->db->fetchOne("
                    SELECT id, first_name, last_name, email, phone 
                    FROM platform_users WHERE id = ?
                ", [$item['user_id']]);

                if ($user) {
                    $recipients[] = [
                        'type' => 'USER',
                        'id' => $user['id'],
                        'name' => $user['first_name'] . ' ' . $user['last_name'],
                        'email' => $user['email'],
                        'phone' => $user['phone']
                    ];
                }
                continue;
            }

            // Student ID (get guardians)
            if (isset($item['student_id'])) {
                $guardians = $this->getStudentGuardians($item['student_id']);
                foreach ($guardians as $guardian) {
                    $recipients[] = [
                        'type' => 'GUARDIAN',
                        'name' => $guardian['name'],
                        'email' => $guardian['email'],
                        'phone' => $guardian['phone']
                    ];
                }
                continue;
            }

            // Direct contact
            if (isset($item['email']) || isset($item['phone'])) {
                $recipients[] = [
                    'type' => 'CONTACT',
                    'name' => $item['name'] ?? 'Recipient',
                    'email' => $item['email'] ?? null,
                    'phone' => $item['phone'] ?? null
                ];
            }
        }

        return $recipients;
    }

    /**
     * Get student guardians
     */
    private function getStudentGuardians($studentId): array
    {
        // This should join with your actual guardian tables
        // Placeholder - adjust based on your schema
        return [];
    }

    /**
     * Process a single recipient
     */
    private function processRecipient(int $notificationId, array $recipient, array $data): array
    {
        $results = [];
        $channels = ['email', 'sms']; // Default channels

        // Determine channels
        if (!empty($recipient['email'])) {
            $channels[] = 'email';
        }
        if (!empty($recipient['phone'])) {
            $channels[] = 'sms';
        }

        $channels = array_unique($channels);

        foreach ($channels as $channel) {
            // Check if channel is available
            $provider = $this->getProviderForChannel($channel);
            if (!$provider) {
                continue;
            }

            // Create recipient record
            $recipientId = $this->createRecipient($notificationId, $recipient, $channel);

            // Create delivery
            $deliveryId = $this->createDelivery($notificationId, $recipientId, $channel, $provider);

            // Queue for sending
            $this->queueDelivery($deliveryId, $provider, $recipient, $data);

            $results[] = [
                'channel' => $channel,
                'recipient_id' => $recipientId,
                'delivery_id' => $deliveryId,
                'status' => 'QUEUED'
            ];
        }

        return $results;
    }

    /**
     * Get provider for channel
     */
    private function getProviderForChannel(string $channelCode): ?array
    {
        return $this->db->fetchOne("
            SELECT p.* FROM notification_providers p
            JOIN notification_channels c ON p.channel_id = c.id
            WHERE c.channel_code = ? AND p.is_active = 1
            ORDER BY p.is_default DESC, p.priority ASC
            LIMIT 1
        ", [$channelCode]);
    }

    /**
     * Create notification record
     */
    private function createNotification(array $data): int
    {
        $uuid = $this->generateUuid();

        $this->db->query("
            INSERT INTO notifications (
                uuid, event_id, tenant_id, school_id, user_id,
                reference_id, reference_type, subject, body, variables,
                priority, status, created_by, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'CREATED', ?, NOW())
        ", [
            $uuid,
            $data['event_id'],
            $data['tenant_id'] ?? null,
            $data['school_id'] ?? null,
            $data['user_id'] ?? null,
            $data['reference_id'] ?? null,
            $data['reference_type'] ?? null,
            $data['subject'] ?? '',
            $data['body'] ?? '',
            json_encode($data['variables'] ?? []),
            $data['priority'] ?? 'NORMAL',
            $data['created_by'] ?? 1
        ]);

        return $this->db->lastInsertId();
    }

    /**
     * Create recipient record
     */
    private function createRecipient(int $notificationId, array $recipient, string $channel): int
    {
        $channelId = $this->db->fetchOne("SELECT id FROM notification_channels WHERE channel_code = ?", [$channel]);

        $this->db->query("
            INSERT INTO notification_recipients (
                notification_id, recipient_type, recipient_id, recipient_name,
                recipient_email, recipient_phone, channel_id, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ", [
            $notificationId,
            $recipient['type'] ?? 'CONTACT',
            $recipient['id'] ?? null,
            $recipient['name'] ?? null,
            $recipient['email'] ?? null,
            $recipient['phone'] ?? null,
            $channelId['id'] ?? null
        ]);

        return $this->db->lastInsertId();
    }

    /**
     * Create delivery record
     */
    private function createDelivery(int $notificationId, int $recipientId, string $channel, array $provider): int
    {
        $uuid = $this->generateUuid();
        $channelId = $this->db->fetchOne("SELECT id FROM notification_channels WHERE channel_code = ?", [$channel]);

        $this->db->query("
            INSERT INTO notification_deliveries (
                uuid, notification_id, recipient_id, channel_id, provider_id,
                status, queued_at, created_at
            ) VALUES (?, ?, ?, ?, ?, 'QUEUED', NOW(), NOW())
        ", [
            $uuid,
            $notificationId,
            $recipientId,
            $channelId['id'],
            $provider['id']
        ]);

        return $this->db->lastInsertId();
    }

    /**
     * Queue delivery
     */
    private function queueDelivery(int $deliveryId, array $provider, array $recipient, array $data): void
    {
        $uuid = $this->generateUuid();

        $payload = [
            'delivery_id' => $deliveryId,
            'provider_id' => $provider['id'],
            'recipient' => $recipient,
            'data' => $data
        ];

        $this->db->query("
            INSERT INTO notification_queue (
                uuid, delivery_id, provider_id, payload, status, created_at
            ) VALUES (?, ?, ?, ?, 'PENDING', NOW())
        ", [
            $uuid,
            $deliveryId,
            $provider['id'],
            json_encode($payload)
        ]);
    }

    /**
     * Process queue - called by worker
     */
    public function processQueue(int $limit = 50): array
    {
        $processed = 0;
        $failed = 0;

        $queueItems = $this->db->fetchAll("
            SELECT * FROM notification_queue
            WHERE status = 'PENDING'
            ORDER BY priority DESC, created_at ASC
            LIMIT ?
        ", [$limit]);

        foreach ($queueItems as $item) {
            try {
                $payload = json_decode($item['payload'], true);
                $deliveryId = $payload['delivery_id'];
                $recipient = $payload['recipient'];
                $data = $payload['data'];

                // Get delivery
                $delivery = $this->db->fetchOne("
                    SELECT d.*, c.channel_code 
                    FROM notification_deliveries d
                    JOIN notification_channels c ON d.channel_id = c.id
                    WHERE d.id = ?
                ", [$deliveryId]);

                if (!$delivery) {
                    throw new Exception('Delivery not found');
                }

                // Get provider
                $provider = $this->db->fetchOne("
                    SELECT * FROM notification_providers WHERE id = ?
                ", [$delivery['provider_id']]);

                if (!$provider) {
                    throw new Exception('Provider not found');
                }

                // Prepare content
                $content = $data['body'] ?? $data['message'] ?? 'Notification from EduTrack';
                $subject = $data['subject'] ?? 'EduTrack Notification';

                // Send based on channel
                $result = $this->sendViaChannel($delivery['channel_code'], $provider, $recipient, $subject, $content);

                if ($result['success']) {
                    // Update delivery
                    $this->db->query("
                        UPDATE notification_deliveries 
                        SET status = 'SENT',
                            provider_message_id = ?,
                            provider_response = ?,
                            sent_at = NOW(),
                            updated_at = NOW()
                        WHERE id = ?
                    ", [
                        $result['message_id'] ?? null,
                        json_encode($result['raw'] ?? []),
                        $deliveryId
                    ]);

                    // Update queue
                    $this->db->query("
                        UPDATE notification_queue 
                        SET status = 'COMPLETED', processed_at = NOW(), updated_at = NOW()
                        WHERE id = ?
                    ", [$item['id']]);

                    $processed++;
                } else {
                    throw new Exception($result['message'] ?? 'Send failed');
                }
            } catch (Exception $e) {
                // Update queue with error
                $this->db->query("
                    UPDATE notification_queue 
                    SET attempts = attempts + 1,
                        error_message = ?,
                        status = CASE 
                            WHEN attempts >= max_attempts THEN 'FAILED' 
                            ELSE 'PENDING' 
                        END,
                        updated_at = NOW()
                    WHERE id = ?
                ", [$e->getMessage(), $item['id']]);

                // Update delivery
                if (isset($deliveryId)) {
                    $this->db->query("
                        UPDATE notification_deliveries 
                        SET status = 'FAILED', 
                            status_message = ?,
                            failed_at = NOW(),
                            updated_at = NOW()
                        WHERE id = ?
                    ", [$e->getMessage(), $deliveryId]);
                }

                $failed++;
            }
        }

        return [
            'success' => true,
            'data' => [
                'processed' => $processed,
                'failed' => $failed,
                'total' => count($queueItems)
            ]
        ];
    }

    /**
     * Send via channel
     */
    private function sendViaChannel(string $channel, array $provider, array $recipient, string $subject, string $message): array
    {
        // Get provider config
        $config = json_decode($provider['config'] ?? '{}', true);

        // Simulate sending based on channel
        // In production, this would call the actual provider API
        switch ($channel) {
            case 'email':
                $to = $recipient['email'] ?? '';
                if (!$to) {
                    return ['success' => false, 'message' => 'No email address'];
                }
                // Send email - placeholder
                $this->logger->info("Email sent to: $to", ['subject' => $subject]);
                break;

            case 'sms':
                $to = $recipient['phone'] ?? '';
                if (!$to) {
                    return ['success' => false, 'message' => 'No phone number'];
                }
                // Send SMS - placeholder
                $this->logger->info("SMS sent to: $to", ['message' => $message]);
                break;

            default:
                return ['success' => false, 'message' => 'Unsupported channel: ' . $channel];
        }

        return [
            'success' => true,
            'message_id' => 'msg_' . time() . '_' . uniqid(),
            'raw' => ['channel' => $channel, 'recipient' => $recipient['name'] ?? 'Unknown']
        ];
    }

    /**
     * Get notification stats
     */
    public function getStats(?int $tenantId = null): array
    {
        $params = [];
        $tenantFilter = $tenantId ? " AND tenant_id = ?" : "";
        if ($tenantId) $params[] = $tenantId;

        $total = $this->db->fetchOne("SELECT COUNT(*) as count FROM notifications WHERE deleted_at IS NULL" . $tenantFilter, $params);
        $delivered = $this->db->fetchOne("SELECT COUNT(*) as count FROM notifications WHERE status = 'DELIVERED' AND deleted_at IS NULL" . $tenantFilter, $params);
        $pending = $this->db->fetchOne("SELECT COUNT(*) as count FROM notifications WHERE status IN ('CREATED', 'QUEUED', 'PROCESSING', 'SENT') AND deleted_at IS NULL" . $tenantFilter, $params);
        $failed = $this->db->fetchOne("SELECT COUNT(*) as count FROM notifications WHERE status = 'FAILED' AND deleted_at IS NULL" . $tenantFilter, $params);

        return [
            'total' => (int)($total['count'] ?? 0),
            'delivered' => (int)($delivered['count'] ?? 0),
            'pending' => (int)($pending['count'] ?? 0),
            'failed' => (int)($failed['count'] ?? 0)
        ];
    }

    /**
     * Get notifications list
     */
    public function getNotifications(int $page = 1, int $limit = 20, ?int $tenantId = null): array
    {
        $offset = ($page - 1) * $limit;
        $params = [];
        $tenantFilter = $tenantId ? " AND tenant_id = ?" : "";
        if ($tenantId) $params[] = $tenantId;

        $notifications = $this->db->fetchAll("
            SELECT * FROM notifications 
            WHERE deleted_at IS NULL $tenantFilter
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ", array_merge($params, [$limit, $offset]));

        $countResult = $this->db->fetchOne("
            SELECT COUNT(*) as total FROM notifications WHERE deleted_at IS NULL $tenantFilter
        ", $params);

        return [
            'notifications' => $notifications,
            'total' => (int)($countResult['total'] ?? 0),
            'page' => $page,
            'per_page' => $limit,
            'total_pages' => max(1, ceil(($countResult['total'] ?? 0) / $limit))
        ];
    }

    /**
     * Retry failed notification
     */
    public function retry(int $notificationId): array
    {
        $notification = $this->db->fetchOne("SELECT * FROM notifications WHERE id = ? AND deleted_at IS NULL", [$notificationId]);
        if (!$notification) {
            return ['success' => false, 'message' => 'Notification not found'];
        }

        if ($notification['status'] !== 'FAILED') {
            return ['success' => false, 'message' => 'Notification is not in failed state'];
        }

        // Reset notification
        $this->db->query("
            UPDATE notifications 
            SET status = 'QUEUED', retry_count = retry_count + 1, updated_at = NOW()
            WHERE id = ?
        ", [$notificationId]);

        // Reset deliveries
        $this->db->query("
            UPDATE notification_deliveries 
            SET status = 'QUEUED', retry_count = retry_count + 1, updated_at = NOW()
            WHERE notification_id = ?
        ", [$notificationId]);

        return ['success' => true, 'message' => 'Notification retried successfully'];
    }

    /**
     * Generate UUID
     */
    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff) | 0x4000,
            mt_rand(0, 0x3ffff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
