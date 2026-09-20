<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Non-secret Google targeting for a booking type (tokens live in appointment_settings). */
class BookingIntegration extends Model
{
    protected $fillable = [
        'workspace_id', 'booking_type_id', 'calendar_id', 'create_meet', 'spreadsheet_id', 'sheet_range',
    ];

    protected $casts = [
        'create_meet' => 'boolean',
    ];

    public function bookingType(): BelongsTo
    {
        return $this->belongsTo(BookingType::class);
    }
}
