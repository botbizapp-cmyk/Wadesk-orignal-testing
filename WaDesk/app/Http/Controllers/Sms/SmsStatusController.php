<?php

namespace App\Http\Controllers\Sms;

use App\Http\Controllers\Controller;
use App\Models\InboxMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * SMS delivery-status callback (Twilio).
 *
 * WHY NOT REUSE /api/twilio/status. That receiver resolves the workspace from
 * wa_provider_configs provider='twilio' and only knows about WhatsApp-over-Twilio
 * sends, so it can't update an SMS row. This one matches the outbound InboxMessage
 * by the provider id core stored in meta.wa_message_id when the reply was sent.
 *
 * Never 500s — Twilio retries hard and disables a repeatedly-failing webhook.
 */
class SmsStatusController extends Controller
{
    /** Twilio's MessageStatus vocabulary → ours. */
    private const MAP = [
        'sent'        => 'sent',
        'delivered'   => 'delivered',
        'undelivered' => 'failed',
        'failed'      => 'failed',
    ];

    /**
     * Normalise a Twilio text status OR an MSG91 numeric/text report code to our
     * vocabulary (delivered | failed | sent), or null for "nothing to record yet".
     * MSG91 codes vary by account, so match by keyword/number defensively.
     */
    private function mapStatus(string $status): ?string
    {
        $s = strtolower(trim($status));
        if ($s === '') return null;
        if (isset(self::MAP[$s])) return self::MAP[$s];

        // Failed FIRST — so 'undelivered' (contains 'deliv') doesn't match delivered.
        // MSG91 'failed'/'ndnd'/'rejected'/'expired'/'blocked'/codes 2/5/6/9/13/17/25…
        if (in_array($s, ['2', '5', '6', '9', '13', '17', '25'], true)
            || str_contains($s, 'fail') || str_contains($s, 'undeliv')
            || str_contains($s, 'reject') || str_contains($s, 'expire')
            || str_contains($s, 'block') || str_contains($s, 'ndnd')) {
            return 'failed';
        }
        // Delivered: Twilio 'delivered'; MSG91 'delivrd'/'delivered'/code 1 — 'deliv' catches both.
        if ($s === '1' || str_contains($s, 'deliv')) return 'delivered';
        if ($s === 'sent' || str_contains($s, 'sent')) return 'sent';

        return null; // queued / sending / submitted — no state change yet.
    }

    /**
     * Extract [requestId, status] from an MSG91 delivery-report webhook. MSG91
     * DLRs post either flat ({requestId, status/report/deliveryStatus, number}) or
     * as {requestId, report:[{number,status,…}]}. The requestId matches the id we
     * stored at send time. Operator points MSG91's DLR webhook at /api/sms/status.
     */
    private function msg91Dlr(Request $request): array
    {
        $all = $request->all();
        if (isset($all['data']) && is_array($all['data'])) {
            $all = array_merge($all, $all['data']);
        }
        $sid = (string) ($request->input('requestId', $request->input('request_id', $all['requestId'] ?? $all['request_id'] ?? '')));
        $status = (string) ($request->input('status', $request->input('deliveryStatus', $all['status'] ?? $all['deliveryStatus'] ?? '')));

        // report can be a nested array of per-number reports.
        if ($status === '' && isset($all['report'])) {
            $rep = $all['report'];
            if (is_array($rep)) {
                $first = is_array(reset($rep)) ? reset($rep) : $rep;
                if (is_array($first)) {
                    $status = (string) ($first['status'] ?? $first['deliveryStatus'] ?? $first['desc'] ?? '');
                    if ($sid === '') $sid = (string) ($first['requestId'] ?? $first['request_id'] ?? '');
                }
            } else {
                $status = (string) $rep;
            }
        }

        return [$sid, $status];
    }

    public function handle(Request $request): Response
    {
        // Twilio DLR field names.
        $sid    = (string) $request->input('MessageSid', $request->input('SmsSid', ''));
        $status = (string) $request->input('MessageStatus', $request->input('SmsStatus', ''));

        // MSG91 DLR uses requestId + a numeric/text report — map it defensively.
        if ($sid === '' || $status === '') {
            [$sid2, $status2] = $this->msg91Dlr($request);
            if ($sid === '')    $sid    = $sid2;
            if ($status === '') $status = $status2;
        }

        if ($sid === '' || $status === '') {
            return response('', 204);
        }

        $mapped = $this->mapStatus($status);
        if ($mapped === null) {
            // queued / sending / accepted — nothing to record yet.
            return response('', 204);
        }

        try {
            $msg = InboxMessage::query()
                ->where('provider', 'sms')
                ->where('meta->wa_message_id', $sid)
                ->latest('id')
                ->first();

            if ($msg) {
                $fields = ['status' => $mapped];
                if ($mapped === 'delivered' && empty($msg->delivered_at)) {
                    $fields['delivered_at'] = now();
                }
                if ($mapped === 'failed') {
                    $err = trim((string) $request->input('ErrorCode', '') . ' ' . $request->input('ErrorMessage', ''));
                    $meta = is_array($msg->meta) ? $msg->meta : [];
                    $meta['sms'] = array_merge($meta['sms'] ?? [], array_filter(['error' => $err]));
                    $fields['meta'] = $meta;
                }
                $msg->forceFill($fields)->save();
            }
        } catch (\Throwable $e) {
            Log::warning('[SMS] status update failed: ' . $e->getMessage());
        }

        // Roll the receipt into SMS-campaign analytics — the per-recipient row
        // carries this SID in whatsapp_message_id (set by SmsCampaignRunner). Only
        // delivered/failed matter here; 'sent' was recorded at send time. A
        // recompute keeps the campaign KPI columns + detail page in sync.
        try {
            $cc = \App\Models\WpCampaignContact::query()->where('whatsapp_message_id', $sid)->first();
            if ($cc) {
                $ccFields = ['status' => $mapped];
                if ($mapped === 'delivered' && empty($cc->delivered_at)) {
                    $ccFields['delivered_at'] = now();
                }
                if ($mapped === 'failed') {
                    $ccFields['error_message'] = trim((string) $request->input('ErrorCode', '') . ' ' . $request->input('ErrorMessage', '')) ?: 'SMS undelivered';
                }
                $cc->forceFill($ccFields)->save();
                optional($cc->campaign)->recomputeAggregates();
            }
        } catch (\Throwable $e) {
            Log::warning('[SMS] campaign status roll-up failed: ' . $e->getMessage());
        }

        return response('', 204);
    }
}
