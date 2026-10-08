<?php

/**
 * SMSProviderInterface.php
 * Contract every SMS provider implementation must satisfy.
 *
 * @package EduTrack
 * @subpackage Services\SMS
 * @version 1.0
 * @filepath app/services/SMS/SMSProviderInterface.php
 *
 * PURPOSE:
 *   A single-method contract for SMS delivery. Each concrete
 *   provider (Twilio, Africa's Talking, Hubtel) implements this
 *   interface. The SMSProviderFactory selects the concrete class
 *   by the tenant's sms_provider setting. OTPService::sendSMS()
 *   calls send() through this interface.
 *
 * CONVENTIONS:
 *   - The method never throws. Every failure path returns an array
 *     with 'success' => false and a human-readable message.
 *   - The method never logs. The caller logs.
 *   - The method never reads $_SESSION. Every value it needs is in
 *     the $config array it receives.
 *   - The method never writes to the database. It delivers.
 *
 * RETURN SHAPE:
 *   [
 *       'success'             => bool,
 *       'message'             => string,
 *       'provider_message_id' => ?string,
 *   ]
 *
 *   provider_message_id is the provider's identifier for the sent
 *   message, when the provider returns one. It is null when no ID
 *   is available or when the send failed.
 *
 * $config KEYS THE CALLER SUPPLIES:
 *   - api_key   : string  the decrypted sms_api_key from the tenant's
 *                         integrations settings. Format is provider
 *                         specific. See each implementation.
 *   - sender_id : ?string the sms_sender_id, or null if unset.
 *   - timeout   : int     the HTTP timeout in seconds.
 */

interface SMSProviderInterface
{
    /**
     * Send an SMS message.
     *
     * @param string $to      The destination phone number. E.164
     *                        preferred (e.g. +233201234567). The
     *                        provider may normalise it.
     * @param string $body    The message text.
     * @param array  $config  Provider configuration. Keys:
     *                          api_key   => string
     *                          sender_id => ?string
     *                          timeout   => int
     *
     * @return array {
     *     success: bool,
     *     message: string,
     *     provider_message_id: ?string
     * }
     */
    public function send(string $to, string $body, array $config): array;
}
