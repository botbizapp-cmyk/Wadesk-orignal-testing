<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A short-lived soft-lock on slot seats while a chat booker finishes booking. */
class SlotReservation extends Model
{
    protected $fillable = [
        'workspace_id', 'booking_type_id', 'starts_at', 'ends_at',
        'session_ref', 'channel', 'seats', 'expires_at',
    ];

    protected $casts = [
        'starts_at'  => 'datetime',
        'ends_at'    => 'datetime',
        'expires_at' => 'datetime',
        'seats'      => 'int',
    ];

    public function bookingType(): BelongsTo
    {
        return $this->belongsTo(BookingType::class);
    }

    /** Only reservations that have not yet expired. */
    public function scopeLive($q)
    {
        return $q->where('expires_at', '>', now());
    }
}
