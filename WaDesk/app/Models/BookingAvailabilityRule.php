<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One weekly availability interval for a booking type (times in the type's tz). */
class BookingAvailabilityRule extends Model
{
    protected $fillable = [
        'workspace_id', 'booking_type_id', 'staff_id', 'weekday', 'start_time', 'end_time',
    ];

    protected $casts = [
        'weekday' => 'int',
    ];

    public function bookingType(): BelongsTo
    {
        return $this->belongsTo(BookingType::class);
    }
}
