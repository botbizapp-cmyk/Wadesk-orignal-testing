<?php

namespace App\Services\Sms;

use App\Services\Sms\Contracts\SmsTransport;
use App\Services\Sms\Transports\Msg91Transport;
use App\Services\Sms\Transports\TwilioTransport;

/**
 * Picks the transport for a sending number and forwards to it.
 *
 * None of the callers (dispatchSms, campaign send) should know or care which
 * provider a number belongs to — that is the entire point of the seam. Adding a
 * third provider stays a new file plus one line in `transportFor()`.
 *
 * Never throws: a returned error becomes a visible failure reason; an exception
 * would kill a campaign mid-run.
 */
class SmsClient implements SmsTransport
{
    private SmsTransport $transport;

    public function __construct(private SmsSender $sender)
    {
        $this->transport = self::transportFor($sender);
    }

    public static function transportFor(SmsSender $sender): SmsTransport
    {
        return match (strtolower(trim($sender->provider))) {
            'msg91' => new Msg91Transport($sender),
            default => new TwilioTransport($sender),
        };
    }

    public function provider(): string
    {
        return $this->transport->provider();
    }

    public function send(string $to, string $body): array
    {
        if (! $this->sender->isSendable()) {
            return ['ok' => false, 'error' => 'This SMS number has no credentials — reconnect it on the devices page.'];
        }

        return $this->transport->send($to, $body);
    }

    /**
     * Confirm credentials before storing them, for whichever provider.
     *
     * @return array{ok:bool, error?:string}
     */
    public static function verify(string $accountSid, string $authToken, string $provider = 'twilio'): array
    {
        return strtolower(trim($provider)) === 'msg91'
            // MSG91 authenticates on a single auth key; there is no account id,
            // so the token field carries it and the sid field is unused.
            ? Msg91Transport::verify($authToken !== '' ? $authToken : $accountSid)
            : TwilioTransport::verify($accountSid, $authToken);
    }

    /** Digits to +E164. Kept here because callers outside the transports use it. */
    public static function e164(string $number): string
    {
        return TwilioTransport::e164($number);
    }
}
