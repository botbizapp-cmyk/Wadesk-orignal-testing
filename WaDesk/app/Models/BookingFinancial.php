<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Per-booking-type pricing/deposit config. Amounts in minor units. */
class BookingFinancial extends Model
{
    protected $fillable = [
        'workspace_id', 'booking_type_id', 'fee_minor', 'tax_pct', 'currency',
        'gateway_slug', 'deposit_mode', 'deposit_value_minor', 'auto_send_link',
        'cancel_fee_minor', 'no_show_fee_minor', 'cancel_window_minutes',
    ];

    protected $casts = [
        'fee_minor'             => 'int',
        'deposit_value_minor'   => 'int',
        'tax_pct'               => 'decimal:2',
        'cancel_fee_minor'      => 'int',
        'no_show_fee_minor'     => 'int',
        'cancel_window_minutes' => 'int',
        'auto_send_link'        => 'boolean',
    ];

    public function bookingType(): BelongsTo
    {
        return $this->belongsTo(BookingType::class);
    }

    /** Fee + tax, in minor units. */
    public function totalMinor(): int
    {
        return (int) round($this->fee_minor * (1 + ((float) $this->tax_pct) / 100));
    }

    /** Amount the customer must pay now to book (full / deposit / nothing). */
    public function dueNowMinor(): int
    {
        return match ($this->deposit_mode) {
            'full'    => $this->totalMinor(),
            'partial' => (int) $this->deposit_value_minor,
            default   => 0,
        };
    }
}
