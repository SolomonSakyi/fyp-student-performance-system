<?php

/**
 * SMSProviderFactory.php
 * Selects the concrete SMSProviderInterface implementation for a
 * given tenant sms_provider value.
 *
 * @package EduTrack
 * @subpackage Services\SMS
 * @version 1.0
 * @filepath app/services/SMS/SMSProviderFactory.php
 *
 * PURPOSE:
 *   Maps the tenant's sms_provider setting to the class that
 *   implements SMSProviderInterface for that provider. The caller
 *   (OTPService::sendSMS()) has already read the setting; this
 *   factory takes the value and returns an instance of the matching
 *   class, or null for any value it does not recognise.
 *
 * SUPPORTED PROVIDER KEYS:
 *   'twilio'         -> TwilioSMSProvider
 *   'africastalking' -> AfricasTalkingSMSProvider
 *   'hubtel'         -> HubtelSMSProvider
 *
 *   Anything else -> null. This covers 'other', 'none', '' and any
 *   unrecognised value. The caller treats a null return as "provider
 *   not yet implemented" and returns an honest success => false.
 *
 * MATCHING RULES:
 *   The input string is trimmed and lowercased before matching.
 *   'Twilio', 'twilio', and '  Twilio  ' all resolve to the same
 *   class. Empty input returns null.
 *
 * WHAT THIS CLASS DOES NOT DO:
 *   - It does not read the database.
 *   - It does not read $_SESSION.
 *   - It does not read any settings. The caller passes the value in.
 *   - It does not throw. It returns null for anything it cannot
 *     resolve.
 */

require_once __DIR__ . '/SMSProviderInterface.php';
require_once __DIR__ . '/TwilioSMSProvider.php';
require_once __DIR__ . '/AfricasTalkingSMSProvider.php';
require_once __DIR__ . '/HubtelSMSProvider.php';

class SMSProviderFactory
{
    /**
     * Return an instance of the provider class for the given key,
     * or null if the key is not a recognised provider.
     *
     * @param string $provider The tenant's sms_provider value.
     * @return SMSProviderInterface|null
     */
    public static function make(string $provider): ?SMSProviderInterface
    {
        $key = strtolower(trim($provider));

        switch ($key) {
            case 'twilio':
                return new TwilioSMSProvider();

            case 'africastalking':
                return new AfricasTalkingSMSProvider();

            case 'hubtel':
                return new HubtelSMSProvider();

            default:
                return null;
        }
    }
}
