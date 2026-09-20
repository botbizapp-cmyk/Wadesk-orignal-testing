<?php

namespace App\Models;

use App\Models\Concerns\HasEngineScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A single booked appointment tied to a workspace + contact (and
 * optionally a conversation). When confirmed, mirrors out to Google
 * Calendar — google_event_id pinpoints the calendar event we wrote.
 */
class Appointment extends Model
{
    use HasEngineScope, SoftDeletes;

    public const STATUSES = ['pending', 'confirmed', 'cancelled', 'completed', 'no_show'];

    /**
     * Auto-stamp `provider` on create from the workspace's active engine.
     */
    protected static function booted(): void
    {
        static::creating(function (self $a) {
            if (empty($a->provider) && !empty($a->workspace_id)) {
                try {
                    $a->provider = \App\Services\WorkspaceEngine::for((int) $a->workspace_id);
                } catch (\Throwable $e) {}
            }
        });
    }

    protected $fillable = [
        'workspace_id', 'provider', 'user_id', 'contact_id', 'conversation_id',
        'booking_type_id', 'staff_id',
        'title', 'description', 'location',
        'starts_at', 'ends_at', 'timezone', 'invitee_timezone',
        'status', 'payment_status', 'deposit_paid_minor', 'order_id',
        'google_event_id', 'google_calendar_id', 'meet_url',
        'meta', 'answers', 'reminder_sent_at', 'reminders_sent',
        'manage_token', 'checked_in_at', 'source', 'capacity_used', 'active_slot_key',
    ];

    protected $casts = [
        'starts_at'          => 'datetime',
        'ends_at'            => 'datetime',
        'reminder_sent_at'   => 'datetime',
        'checked_in_at'      => 'datetime',
        'meta'               => 'array',
        'answers'            => 'array',
        'reminders_sent'     => 'array',
        'deposit_paid_minor' => 'int',
        'capacity_used'      => 'int',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function bookingType(): BelongsTo
    {
        return $this->belongsTo(BookingType::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * A UNIQUE random capability token (NOT an HMAC of the id) for the public
     * manage page — possession of the unguessable token authorises management.
     */
    public function ensureManageToken(): string
    {
        if (! $this->manage_token) {
            do {
                $t = \Illuminate\Support\Str::random(48);
            } while (static::where('manage_token', $t)->exists());
            $this->manage_token = $t;
        }

        return $this->manage_token;
    }

    /**
     * The active-slot uniqueness key, or null when the booking is not active.
     * Drives the UNIQUE(active_slot_key) hard double-book guard. For capacity-N
     * types the seat ordinal is encoded so N concurrent bookings can win.
     */
    public function computeSlotKey(?int $seatOrdinal = null): ?string
    {
        if (! in_array($this->status, ['pending', 'confirmed'], true)) {
            return null;
        }
        if (! $this->booking_type_id || ! $this->starts_at) {
            return null;
        }
        $epoch = $this->starts_at->clone()->utc()->timestamp;

        return $this->booking_type_id.':'.$epoch.':'.($seatOrdinal ?? 1);
    }

    public function scopeForWorkspace($q, int $wsId)
    {
        return $q->where('workspace_id', $wsId);
    }

    public function scopeUpcoming($q)
    {
        return $q->where('starts_at', '>=', now())
                 ->whereIn('status', ['pending', 'confirmed']);
    }
}
