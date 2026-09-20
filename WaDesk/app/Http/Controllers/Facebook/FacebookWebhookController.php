<?php

namespace App\Http\Controllers\Facebook;

use App\Http\Controllers\Controller;
use App\Models\FacebookPage;
use App\Models\SystemSetting;
use App\Services\Facebook\FacebookIngestService;
use App\Services\Facebook\FacebookWebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Meta Facebook Page webhook endpoint.
 *   GET  /webhooks/facebook → subscription verification (hub.challenge)
 *   POST /webhooks/facebook → signed events (feed/comments/mentions/messages)
 *
 * P0 verifies the handshake + validates the X-Hub-Signature-256 HMAC and acks
 * fast (so the buyer can complete Meta's "Verify and Save"). Turning events
 * into inbox conversations lands in P1.
 */
class FacebookWebhookController extends Controller
{
    /** GET verify handshake — echo hub.challenge as plain text when the token matches. */
    public function verify(Request $request)
    {
        $mode = (string) $request->query('hub_mode', $request->query('hub.mode', ''));
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token', ''));
        $challenge = (string) $request->query('hub_challenge', $request->query('hub.challenge', ''));

        // Reuse the WhatsApp webhook verify token — the operator already set it
        // in System Message settings; no need to enter a Facebook-specific one.
        $expected = (string) (SystemSetting::get('fb_webhook_verify_token', '') ?: SystemSetting::get('waba_webhook_verify_token', ''));
        if ($mode === 'subscribe' && $expected !== '' && hash_equals($expected, $token)) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response('forbidden', 403);
    }

    /** POST events — verify the signature, then ack. Ingest to inbox comes in P1. */
    public function handle(Request $request)
    {
        $raw = $request->getContent();
        $sig = (string) $request->header('X-Hub-Signature-256', '');

        $matched = FacebookWebhookSignature::verify($raw, $sig);
        if ($matched === null) {
            // No secret configured yet, or a forged/garbled signature. When a
            // secret IS configured we reject; when none is set we still 200 so
            // Meta's setup handshake isn't blocked before the app secret lands.
            if (FacebookWebhookSignature::configuredSecrets() !== []) {
                Log::warning('[FB-HOOK] signature verification failed');

                return response('invalid signature', 403);
            }
        }

        $payload = $request->json()->all();
        if (($payload['object'] ?? '') !== 'page') {
            return response('ok', 200);
        }

        // Delivery is at-least-once, unordered and batched — iterate every
        // entry and each event; the ingest writer dedups on the Meta id.
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            $pageId = (string) ($entry['id'] ?? '');
            if ($pageId === '') {
                continue;
            }
            // The SAME Page can be connected in more than one workspace (unique
            // key is workspace_id+page_id). Meta delivers one webhook per Page,
            // so fan it out to EVERY workspace that connected it — else all but
            // one silently receive nothing. The ingest writer dedups per
            // workspace, so no duplication.
            $pages = FacebookPage::where('page_id', $pageId)->where('status', 'connected')->get();
            if ($pages->isEmpty()) {
                continue; // not a Page connected to any workspace here
            }

            foreach ($pages as $page) {
                // Messenger DMs arrive under entry[].messaging[].
                foreach ((array) ($entry['messaging'] ?? []) as $ev) {
                    try {
                        FacebookIngestService::messengerEvent($page, (array) $ev);
                    } catch (\Throwable $e) {
                        Log::warning('[FB-HOOK] messaging ingest failed: '.$e->getMessage(), ['page' => $page->id]);
                    }
                }

                // Feed events (new posts, comments, reactions) under entry[].changes[].
                foreach ((array) ($entry['changes'] ?? []) as $change) {
                    if (($change['field'] ?? '') !== 'feed') {
                        continue;
                    }
                    try {
                        FacebookIngestService::feedComment($page, (array) ($change['value'] ?? []));
                    } catch (\Throwable $e) {
                        Log::warning('[FB-HOOK] feed ingest failed: '.$e->getMessage(), ['page' => $page->id]);
                    }
                }
            }
        }

        return response('ok', 200);
    }
}
