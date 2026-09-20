<?php

namespace App\Services\Telegram;

use App\Models\SystemSetting;
use App\Models\TelegramBot;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Laravel → Node handoff for Telegram flows. Mirrors FbFlowBridge / TtFlowBridge:
 * all channel flows run on the long-lived Node runtime
 * (node/services/telegramFlowService.js at /api/telegram-flow/inbound) so a
 * Delay/Wait node is a real `await` — no PHP job, no queue.
 *
 * Node answers SYNCHRONOUSLY whether a flow consumed the message (`consumed`),
 * letting the webhook skip PHP keyword/AI auto-reply so the customer never gets
 * a double reply. The recipient key is the Telegram chat id.
 */
class TgFlowBridge
{
    /**
     * @param  array|null  $flow  flow_data — REQUIRED to START, omitted to RESUME.
     * @return bool  true when a flow consumed the message.
     */
    public static function handoff(
        TelegramBot $bot,
        string $chatId,
        string $text,
        ?array $flow = null,
        $flowId = null,
        array $vars = []
    ): bool {
        $nodeUrl = (string) (SystemSetting::get('baileys_server_url', '') ?: env('SERVER_URL', ''));
        if ($nodeUrl === '' || $chatId === '') {
            return false;
        }

        try {
            $r = Http::withHeaders(['X-Node-Token' => node_token()])
                ->timeout(15)
                ->acceptJson()
                ->post(rtrim($nodeUrl, '/').'/api/telegram-flow/inbound', array_filter([
                    'botId'       => $bot->id,
                    'workspaceId' => $bot->workspace_id,
                    'chatId'      => $chatId,
                    'text'        => $text,
                    'auth'        => [
                        'base'         => 'https://api.telegram.org',
                        'token'        => $bot->bot_token,
                        // Payment-node provider token (from @BotFather → Payments).
                        // Empty when the bot has no payments set up — the node then
                        // tells the customer instead of failing silently.
                        'paymentToken' => (string) ($bot->payment_provider_token ?? ''),
                    ],
                    'flow'      => $flow,
                    'flowId'    => $flowId,
                    'vars'      => (object) $vars,
                    'appDomain' => rtrim((string) (config('app.url') ?: url('/')), '/'),
                ], fn ($v) => $v !== null));

            if (! $r->successful()) {
                Log::warning('[TG-FLOW-BRIDGE] Node '.$r->status().': '.mb_substr((string) $r->body(), 0, 150));

                return false;
            }

            return (bool) $r->json('consumed', false);
        } catch (\Throwable $e) {
            Log::warning('[TG-FLOW-BRIDGE] Node unreachable: '.mb_substr($e->getMessage(), 0, 150));

            return false;
        }
    }

    /** Resume a flow parked at a block-until-paid Payment node after a payment. */
    public static function resumePaid(TelegramBot $bot, string $chatId, array $payment = []): bool
    {
        return self::handoff($bot, $chatId, '__tg_paid__', null, null, [
            'paid'          => true,
            'paid_amount'   => (string) ($payment['amount_display'] ?? ''),
            'paid_currency' => (string) ($payment['currency'] ?? ''),
            'paid_charge_id'=> (string) ($payment['telegram_payment_charge_id'] ?? ''),
            'paid_payload'  => (string) ($payment['invoice_payload'] ?? ''),
        ]);
    }
}
