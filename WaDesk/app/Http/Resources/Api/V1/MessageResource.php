<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public shape of a message in the customer API. Scramble reads this to
 * document the response.
 */
class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->resource['id'] ?? ($this->id ?? null),
            'to'         => $this->resource['to'] ?? ($this->to_number ?? null),
            'type'       => $this->resource['type'] ?? ($this->media_type ?? 'text'),
            'status'     => $this->resource['status'] ?? ($this->status ?? 'queued'),
            // Why a message failed — surfaced so callers can troubleshoot without
            // the UI. `failure_reason` is the human text ("#131042 — …"),
            // `error_code` is Meta's typed code. Null unless the message failed.
            'failure_reason' => is_array($this->resource)
                ? ($this->resource['failure_reason'] ?? null)
                : ($this->failure_reason ?? null),
            'error_code'     => is_array($this->resource)
                ? ($this->resource['error_code'] ?? null)
                : (is_array($this->meta ?? null) ? ($this->meta['error_code'] ?? null) : null),
            'body'       => $this->resource['body'] ?? ($this->body ?? null),
            'media_url'  => $this->resource['media_url']
                ?? ((!is_array($this->resource) && ($this->media_path ?? null)) ? media_url($this->media_path) : null),
            'created_at' => isset($this->resource['created_at'])
                ? $this->resource['created_at']
                : optional($this->created_at ?? null)?->toIso8601String(),
        ];
    }
}
