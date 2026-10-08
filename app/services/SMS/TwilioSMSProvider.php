<?php

/**
 * TwilioSMSProvider.php
 * Twilio implementation of SMSProviderInterface.
 *
 * @package EduTrack
 * @subpackage Services\SMS
 * @version 1.0
 * @filepath app/services/SMS/TwilioSMSProvider.php
 *
 * PROVIDER-SPECIFIC NOTES:
 *
 *   API KEY FORMAT
 *     The caller passes $config['api_key'] in the form
 *         AccountSid:AuthToken
 *     Twilio authenticates with HTTP Basic: AccountSid as the
 *     username, AuthToken as the password. The tenant pastes the
 *     pair into the Integrations tab's sms_api_key field, one colon
 *     between them. This class splits on the first colon.
 *
 *   SENDER ID
 *     $config['sender_id'] is required. Twilio requires a From on
 *     every message. It is either a Twilio phone number in E.164
 *     form (e.g. +1234567890) or an approved alphanumeric sender
 *     ID. If $config['sender_id'] is empty, this class returns
 *     success => false without making a network call.
 *
 *   ENDPOINT
 *     POST https://api.twilio.com/2010-04-01/Accounts/{AccountSid}/Messages.json
 *     Content-Type: application/x-www-form-urlencoded
 *     Body fields: From, To, Body
 *
 *   RESPONSE
 *     201 Created on success. JSON body with 'sid' and 'status'.
 *     4xx on failure. JSON body with 'code', 'message', 'more_info'.
 *
 * WHAT THIS CLASS DOES NOT DO:
 *   - It does not normalise phone numbers. Twilio requires E.164;
 *     a non-conforming number is rejected by Twilio and the
 *     rejection is returned as success => false.
 *   - It does not retry.
 *   - It does not log. The caller logs.
 *   - It does not throw. Every failure path returns an array.
 */

require_once __DIR__ . '/SMSProviderInterface.php';

class TwilioSMSProvider implements SMSProviderInterface
{
    /**
     * Twilio Messages endpoint base.
     * @var string
     */
    private const ENDPOINT_BASE = 'https://api.twilio.com/2010-04-01/Accounts/';

    /**
     * Send an SMS via Twilio.
     *
     * @param string $to
     * @param string $body
     * @param array  $config  api_key (AccountSid:AuthToken), sender_id, timeout
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
                'message'             => 'Twilio api_key is empty.',
                'provider_message_id' => null,
            ];
        }

        // api_key must be AccountSid:AuthToken.
        $colonPos = strpos($apiKey, ':');
        if ($colonPos === false || $colonPos === 0 || $colonPos === strlen($apiKey) - 1) {
            return [
                'success'             => false,
                'message'             => 'Twilio api_key must be in the form AccountSid:AuthToken.',
                'provider_message_id' => null,
            ];
        }

        $accountSid = substr($apiKey, 0, $colonPos);
        $authToken  = substr($apiKey, $colonPos + 1);

        if ($senderId === '') {
            return [
                'success'             => false,
                'message'             => 'Twilio requires a sender_id (From number or approved alphanumeric sender).',
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

        $url = self::ENDPOINT_BASE . rawurlencode($accountSid) . '/Messages.json';

        $postFields = http_build_query([
            'From' => $senderId,
            'To'   => $to,
            'Body' => $body,
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_USERPWD, $accountSid . ':' . $authToken);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ]);

        $raw    = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return [
                'success'             => false,
                'message'             => 'Twilio request failed: ' . ($err ?: 'unknown cURL error'),
                'provider_message_id' => null,
            ];
        }

        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            return [
                'success'             => false,
                'message'             => 'Twilio returned a non-JSON response (HTTP ' . $status . ').',
                'provider_message_id' => null,
            ];
        }

        if ($status === 201 && !empty($decoded['sid'])) {
            return [
                'success'             => true,
                'message'             => 'OTP sent via Twilio.',
                'provider_message_id' => (string)$decoded['sid'],
            ];
        }

        $errorMessage = isset($decoded['message']) ? (string)$decoded['message'] : 'Twilio rejected the request.';
        $errorCode    = isset($decoded['code'])    ? (string)$decoded['code']    : '';

        return [
            'success'             => false,
            'message'             => 'Twilio error' . ($errorCode !== '' ? ' ' . $errorCode : '') . ': ' . $errorMessage,
            'provider_message_id' => null,
        ];
    }
}
