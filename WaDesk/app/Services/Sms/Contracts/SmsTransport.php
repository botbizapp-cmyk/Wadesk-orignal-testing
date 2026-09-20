<?php

namespace App\Services\Sms\Contracts;

/**
 * One SMS provider (Twilio, MSG91, …).
 *
 * WHY THIS EXISTS. Twilio was the first provider, but it is the wrong one for
 * Indian recipients: carriers there require DLT registration, and a foreign
 * long code is filtered whatever the code does. So a second provider was always
 * coming — and the whole layer is written with `SmsClient` as the only
 * provider-aware class precisely so adding one is a new file, not a rewrite.
 *
 * IMPLEMENTATIONS MUST NOT THROW. A provider being down or misconfigured is an
 * expected outcome that belongs on the message row as a readable reason; an
 * exception would instead kill a campaign mid-run and strand its recipients.
 */
interface SmsTransport
{
    /**
     * Send one message.
     *
     * @return array{
     *   ok:bool, message_id?:string, segments?:int,
     *   error?:string, code?:int|string, bad_credentials?:bool
     * }
     */
    public function send(string $to, string $body): array;

    /** The provider slug this transport serves ('twilio', 'msg91'). */
    public function provider(): string;
}
