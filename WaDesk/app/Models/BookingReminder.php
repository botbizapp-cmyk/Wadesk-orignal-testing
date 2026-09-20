<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One reminder offset in a booking type's cadence (stable offset_index handle). */
class BookingReminder extends Model
{
    protected $fillable = [
        'workspace_id', 'booking_type_id', 'offset_index', 'offset_minutes',
        'template_event', 'label', 'is_active',
    ];

    protected $casts = [
        'offset_index'   => 'int',
        'offset_minutes' => 'int',
        'is_active'      => 'boolean',
    ];

    public function bookingType(): BelongsTo
    {
        return $this->belongsTo(BookingType::class);
    }
}
