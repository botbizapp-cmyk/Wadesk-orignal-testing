<?php

namespace Tests\Unit;

use App\Services\Instagram\InstagramWebhookSignature;
use Tests\TestCase;

class InstagramWebhookSignatureTest extends TestCase
{
    public function test_it_creates_and_accepts_a_meta_sha256_signature(): void
    {
        $body = '{"object":"instagram","entry":[]}';
        $secret = 'test-app-secret';
        $signature = InstagramWebhookSignature::make($body, $secret);

        $this->assertSame('sha256='.hash_hmac('sha256', $body, $secret), $signature);
        $this->assertSame(
            'instagram_app_secret',
            InstagramWebhookSignature::verifyAgainst($body, $signature, [
                'instagram_app_secret' => $secret,
                'waba_app_secret' => 'different-secret',
            ])
        );
    }

    public function test_it_rejects_missing_wrong_and_body_mismatched_signatures(): void
    {
        $body = '{"object":"instagram"}';
        $secrets = ['instagram_app_secret' => 'correct-secret'];

        $this->assertNull(InstagramWebhookSignature::verifyAgainst($body, '', $secrets));
        $this->assertNull(InstagramWebhookSignature::verifyAgainst($body, 'sha1=abc', $secrets));
        $this->assertNull(InstagramWebhookSignature::verifyAgainst(
            $body,
            InstagramWebhookSignature::make($body, 'wrong-secret'),
            $secrets
        ));
        $this->assertNull(InstagramWebhookSignature::verifyAgainst(
            $body.' ',
            InstagramWebhookSignature::make($body, 'correct-secret'),
            $secrets
        ));
    }
}
