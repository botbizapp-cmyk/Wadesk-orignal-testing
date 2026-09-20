<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A questionnaire field asked in the chat booking flow for a booking type. */
class BookingQuestion extends Model
{
    protected $fillable = [
        'workspace_id', 'booking_type_id', 'label', 'type', 'options',
        'required', 'map_to_contact_field', 'sort_order',
    ];

    protected $casts = [
        'options'    => 'array',
        'required'   => 'boolean',
        'sort_order' => 'int',
    ];

    public function bookingType(): BelongsTo
    {
        return $this->belongsTo(BookingType::class);
    }
}
