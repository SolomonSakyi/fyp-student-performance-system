<?php

/**
 * HubtelSMSProvider.php
 * Hubtel implementation of SMSProviderInterface.
 *
 * @package EduTrack
 * @subpackage Services\SMS
 * @version 1.0
 * @filepath app/services/SMS/HubtelSMSProvider.php
 *
 * PROVIDER-SPECIFIC NOTES:
 *
 *   API KEY FORMAT
 *     The caller passes $config['api_key'] in the form
 *         ClientId:ClientSecret
 *     Hubtel authenticates with HTTP Basic: ClientId as the
 *     username, ClientSecret as the password. Both values are
 *     pasted by the tenant into the Integrations tab's sms_api_key
 *     field, one colon between them. This class splits on the
 *     first colon.
 *
 *   SENDER ID
 *     $config['sender_id'] is required. Hubtel requires a From on
 *     every message — an approved alphanumeric sender ID or a
 *     registered short code. If $config['sender_id'] is empty, this
 *     class returns success => false without making a network call.
 *
 *   ENDPOINT
 *     POST https://smsc.hubtel.com/v1/messages/send
 *     Query string parameters: From, To, Content
 *     Basic auth: ClientId, ClientSecret
 *
 *   RESPONSE
 *     200 OK on success. JSON body with MessageId, Status, Rate.
 *     4xx on failure. JSON body with Status and Message.
 *
 * WHAT THIS CLASS DOES NOT DO:
 *   - It does not normalise phone numbers. Hubtel accepts E.164 and
 *     some local Ghanaian formats. The number is passed through.
 *   - It does not retry.
 *   - It does not log. The caller logs.
 *   - It does not throw. Every failure path returns an array.
 */

require_once __DIR__ . '/SMSProviderInterface.php';

class HubtelSMSProvider implements SMSProviderInterface
{
    /**
     * Hubtel SMS endpoint.
     * @var string
     */
    private const ENDPOINT = 'https://smsc.hubtel.com/v1/messages/send';

    /**
     * Send an SMS via Hubtel.
     *
     * @param string $to
     * @param string $body
     * @param array  $config  api_key (ClientId:ClientSecret), sender_id, timeout
     * @return array
     */
    public function send(string $to, string $body, array $config): array
    {
        $apiKey   = isset($config['api_key'])   ? trim((string)$config['api_key'])   : '';
        $senderId = isset($config['sender_id']) ? trim((string)$config['sender_id']) : '';
        $timeout  = isset($config['timeout'])   ? (int)$config['timeout']            : 10;

        if ($apiKey === '') {
            return [
                'success'             => false,
                'message'             => 'Hubtel api_key is empty.',
                'provider_message_id' => null,
            ];
        }

        // api_key must be ClientId:ClientSecret.
        $colonPos = strpos($apiKey, ':');
        if ($colonPos === false || $colonPos === 0 || $colonPos === strlen($apiKey) - 1) {
            return [
                'success'             => false,
                'message'             => 'Hubtel api_key must be in the form ClientId:ClientSecret.',
                'provider_message_id' => null,
            ];
        }

        $clientId     = substr($apiKey, 0, $colonPos);
        $clientSecret = substr($apiKey, $colonPos + 1);

        if ($senderId === '') {
            return [
                'success'             => false,
                'message'             => 'Hubtel requires a sender_id (approved alphanumeric or short code).',
                'provider_message_id' => null,
            ];
        }

        if (!function_exists('curl_init')) {
            return [
                'success'             => false,
                'message'             => 'PHP curl extension is not loaded.',
                'provider_message_id' => null,
            ];
        }

        $query = http_build_query([
            'From'    => $senderId,
            'To'      => $to,
            'Content' => $body,
        ]);

        $url = self::ENDPOINT . '?' . $query;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_USERPWD, $clientId . ':' . $clientSecret);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
        ]);

        $raw    = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return [
                'success'             => false,
                'message'             => 'Hubtel request failed: ' . ($err ?: 'unknown cURL error'),
                'provider_message_id' => null,
            ];
        }

        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            return [
                'success'             => false,
                'message'             => 'Hubtel returned a non-JSON response (HTTP ' . $status . ').',
                'provider_message_id' => null,
            ];
        }

        if ($status === 200 && !empty($decoded['MessageId'])) {
            return [
                'success'             => true,
                'message'             => 'OTP sent via Hubtel.',
                'provider_message_id' => (string)$decoded['MessageId'],
            ];
        }

        $errorMessage = isset($decoded['Message'])
            ? (string)$decoded['Message']
            : (isset($decoded['Status']) ? (string)$decoded['Status'] : 'Hubtel rejected the request.');

        return [
            'success'             => false,
            'message'             => 'Hubtel error: ' . $errorMessage,
            'provider_message_id' => null,
        ];
    }
}
