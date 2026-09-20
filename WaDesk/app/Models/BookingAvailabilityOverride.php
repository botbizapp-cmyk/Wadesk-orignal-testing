<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A date-specific override — closed day or special hours for a booking type. */
class BookingAvailabilityOverride extends Model
{
    protected $fillable = [
        'workspace_id', 'booking_type_id', 'date', 'is_closed', 'start_time', 'end_time', 'reason',
    ];

    protected $casts = [
        'date'      => 'date',
        'is_closed' => 'boolean',
    ];

    public function bookingType(): BelongsTo
    {
        return $this->belongsTo(BookingType::class);
    }
}
