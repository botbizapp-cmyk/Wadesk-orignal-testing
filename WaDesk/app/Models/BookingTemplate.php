<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A lifecycle message template mapping for a booking type + event + channel. */
class BookingTemplate extends Model
{
    protected $fillable = [
        'workspace_id', 'booking_type_id', 'event', 'channel',
        'wa_template_id', 'plain_body', 'variable_map', 'coupon_code', 'coupon_id',
    ];

    protected $casts = [
        'variable_map' => 'array',
    ];

    public function bookingType(): BelongsTo
    {
        return $this->belongsTo(BookingType::class);
    }

    public function waTemplate(): BelongsTo
    {
        return $this->belongsTo(WaTemplate::class, 'wa_template_id');
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class, 'coupon_id');
    }
}
