<?php

/**
 * AfricasTalkingSMSProvider.php
 * Africa's Talking implementation of SMSProviderInterface.
 *
 * @package EduTrack
 * @subpackage Services\SMS
 * @version 1.0
 * @filepath app/services/SMS/AfricasTalkingSMSProvider.php
 *
 * PROVIDER-SPECIFIC NOTES:
 *
 *   API KEY FORMAT
 *     The caller passes $config['api_key'] in the form
 *         username:apiKey
 *     Africa's Talking authenticates with a custom `apiKey` header,
 *     and requires the Africa's Talking account username as a POST
 *     field named `username`. Both values are pasted by the tenant
 *     into the Integrations tab's sms_api_key field, one colon
 *     between them. This class splits on the first colon.
 *
 *   SENDER ID
 *     $config['sender_id'] is optional. When non-empty, it is sent
 *     as the `from` field. Africa's Talking accepts an approved
 *     alphanumeric sender ID or a short code. When empty, the `from`
 *     field is omitted and Africa's Talking uses the account default.
 *
 *   ENDPOINT
 *     POST https://api.africastalking.com/version1/messaging
 *     Headers: apiKey: <apiKey>, Accept: application/json
 *     Content-Type: application/x-www-form-urlencoded
 *     Body fields: username, to, message, from (optional)
 *
 *   RESPONSE
 *     201 Created on success. JSON body with
 *       SMSMessageData.Recipients[] each carrying messageId and status.
 *     4xx on failure. JSON body with a top-level `errorMessage`.
 *
 * WHAT THIS CLASS DOES NOT DO:
 *   - It does not normalise phone numbers. Africa's Talking accepts
 *     E.164 and some local formats. The number is passed through.
 *   - It does not retry.
 *   - It does not log. The caller logs.
 *   - It does not throw. Every failure path returns an array.
 */

require_once __DIR__ . '/SMSProviderInterface.php';

class AfricasTalkingSMSProvider implements SMSProviderInterface
{
    /**
     * Africa's Talking messaging endpoint.
     * @var string
     */
    private const ENDPOINT = 'https://api.africastalking.com/version1/messaging';

    /**
     * Send an SMS via Africa's Talking.
     *
     * @param string $to
     * @param string $body
     * @param array  $config  api_key (username:apiKey), sender_id, timeout
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
                'message'             => "Africa's Talking api_key is empty.",
                'provider_message_id' => null,
            ];
        }

        // api_key must be username:apiKey.
        $colonPos = strpos($apiKey, ':');
        if ($colonPos === false || $colonPos === 0 || $colonPos === strlen($apiKey) - 1) {
            return [
                'success'             => false,
                'message'             => "Africa's Talking api_key must be in the form username:apiKey.",
                'provider_message_id' => null,
            ];
        }

        $username  = substr($apiKey, 0, $colonPos);
        $realApiKey = substr($apiKey, $colonPos + 1);

        if (!function_exists('curl_init')) {
            return [
                'success'             => false,
                'message'             => 'PHP curl extension is not loaded.',
                'provider_message_id' => null,
            ];
        }

        $fields = [
            'username' => $username,
            'to'       => $to,
            'message'  => $body,
        ];
        if ($senderId !== '') {
            $fields['from'] = $senderId;
        }

        $postFields = http_build_query($fields);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, self::ENDPOINT);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'apiKey: ' . $realApiKey,
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ]);

        $raw    = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return [
                'success'             => false,
                'message'             => "Africa's Talking request failed: " . ($err ?: 'unknown cURL error'),
                'provider_message_id' => null,
            ];
        }

        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            return [
                'success'             => false,
                'message'             => "Africa's Talking returned a non-JSON response (HTTP " . $status . ").",
                'provider_message_id' => null,
            ];
        }

        // Success shape: HTTP 201 or 200 with
        // SMSMessageData.Recipients[0].messageId
        if (($status === 201 || $status === 200)
            && isset($decoded['SMSMessageData']['Recipients'][0]['messageId'])
        ) {
            $messageId = (string)$decoded['SMSMessageData']['Recipients'][0]['messageId'];
            return [
                'success'             => true,
                'message'             => "OTP sent via Africa's Talking.",
                'provider_message_id' => $messageId,
            ];
        }

        $errorMessage = isset($decoded['errorMessage'])
            ? (string)$decoded['errorMessage']
            : "Africa's Talking rejected the request (HTTP " . $status . ").";

        return [
            'success'             => false,
            'message'             => "Africa's Talking error: " . $errorMessage,
            'provider_message_id' => null,
        ];
    }
}
