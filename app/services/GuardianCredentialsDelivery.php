<?php

/**
 * GuardianCredentialsDelivery — delivery interface and null
 * implementation for guardian one-time passwords.
 *
 * @package EduTrack
 * @subpackage Services
 * @version 1.0
 * @filepath app/services/GuardianCredentialsDelivery.php
 *
 * Purpose:
 *   Define the contract that GuardianProvisioningService uses to
 *   hand a one-time password to the outside world, and provide a
 *   concrete implementation for the current phase of the
 *   user-identity milestone.
 *
 * WHY A SEPARATE FILE:
 *   The SMS provider is not yet named. Decision Q2 on 2026-10-03
 *   deferred the provider to the payments module, which will let
 *   each tenant choose its own API. Until that module is built,
 *   the provisioning service must not depend on any concrete
 *   provider. It depends on this interface.
 *
 *   When the provider is named, a second class — for example
 *   HubtelSmsDelivery or TwilioSmsDelivery — is added to this
 *   file (or a sibling file). The interface does not change. The
 *   provisioning service does not change. Only the class passed
 *   to the GuardianProvisioningService constructor changes.
 *
 * LOCKED DECISIONS (2026-10-04):
 *   Q2   The SMS provider is deferred to the payments module.
 *        This file defines the interface. It does not name a
 *        provider.
 *   (2a) The first-login credential is a 12-character random
 *        alphanumeric one-time password, separate from the OTP.
 *        The delivery interface carries that credential.
 *
 * SECURITY NOTE:
 *   NullDelivery writes the plaintext one-time password to
 *   error_log. This is deliberate: it makes the flow testable
 *   today, before an SMS provider exists. It must not be the
 *   implementation used in production. When a real provider is
 *   named, NullDelivery is either removed, or renamed and gated
 *   behind a DEBUG constant so it cannot be selected in
 *   production by mistake.
 *
 * WHAT THIS FILE DOES NOT DO:
 *   - It does not read from or write to the database.
 *   - It does not write session keys.
 *   - It does not implement any SMS or email provider.
 *   - It does not touch any other file.
 */

if (!interface_exists('GuardianCredentialsDelivery')) {

    interface GuardianCredentialsDelivery
    {
        /**
         * Deliver a one-time password to a user.
         *
         * @param int    $userId          platform_users.id
         * @param string $oneTimePassword plaintext; not stored by
         *                                the implementation
         * @param array  $channels        any of 'sms', 'email';
         *                                caller passes what is
         *                                available for the user
         *
         * @return bool True when at least one channel succeeded.
         */
        public function deliver(int $userId, string $oneTimePassword, array $channels): bool;
    }
}

if (!class_exists('NullDelivery')) {

    /**
     * NullDelivery — logs the credential to error_log and returns
     * true.
     *
     * This implementation exists so the guardian provisioning flow
     * can be exercised end to end today, without a named SMS
     * provider. It is not a production implementation.
     *
     * The log line contains:
     *   - the user id
     *   - the channels the caller made available
     *   - the plaintext one-time password
     *
     * The plaintext one-time password is logged so a developer
     * running the flow can retrieve it and complete a first login
     * without an SMS provider.
     *
     * When a real delivery class is added, NullDelivery is either
     * removed or renamed and gated behind a DEBUG constant.
     */
    class NullDelivery implements GuardianCredentialsDelivery
    {
        public function deliver(int $userId, string $oneTimePassword, array $channels): bool
        {
            $chan = empty($channels) ? 'none' : implode(',', $channels);
            error_log(sprintf(
                'NullDelivery: user_id=%d channels=%s one_time_password=%s',
                $userId,
                $chan,
                $oneTimePassword
            ));
            return true;
        }
    }
}
