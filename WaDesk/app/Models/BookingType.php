<?php

namespace App\Models;

use App\Models\Concerns\HasEngineScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A bookable service ("30-min Demo", "Salon Cut") — the per-service dimension
 * that turns the single-config appointment scaffold into a multi-service
 * booking product. Each type owns its duration/buffers, availability rules,
 * financials, lifecycle templates, reminders, questionnaire and Google target.
 */
class BookingType extends Model
{
    use HasEngineScope, SoftDeletes;

    protected $fillable = [
        'workspace_id', 'user_id', 'name', 'slug', 'description',
        'location_type', 'location_value', 'color',
        'duration_minutes', 'increment_minutes', 'buffer_before_minutes', 'buffer_after_minutes',
        'min_notice_minutes', 'max_advance_days', 'max_per_day', 'capacity',
        'timezone', 'intro_message', 'is_active', 'sort_order', 'meta',
    ];

    protected $casts = [
        'meta'                  => 'array',
        'is_active'             => 'boolean',
        'duration_minutes'      => 'int',
        'increment_minutes'     => 'int',
        'buffer_before_minutes' => 'int',
        'buffer_after_minutes'  => 'int',
        'min_notice_minutes'    => 'int',
        'max_advance_days'      => 'int',
        'max_per_day'           => 'int',
        'capacity'              => 'int',
        'sort_order'            => 'int',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $t) {
            if (empty($t->provider ?? null) && ! empty($t->workspace_id)) {
                // BookingType has no provider column; keep parity intent only if added later.
            }
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function availabilityRules(): HasMany
    {
        return $this->hasMany(BookingAvailabilityRule::class);
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(BookingAvailabilityOverride::class);
    }

    public function templates(): HasMany
    {
        return $this->hasMany(BookingTemplate::class);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(BookingReminder::class)->where('is_active', true)->orderByDesc('offset_minutes');
    }

    public function financial(): HasOne
    {
        return $this->hasOne(BookingFinancial::class);
    }

    public function integration(): HasOne
    {
        return $this->hasOne(BookingIntegration::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(BookingQuestion::class)->orderBy('sort_order');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(SlotReservation::class);
    }

    /** The type's timezone, falling back to the workspace tz, always safe. */
    public function effectiveTimezone(): string
    {
        $tz = $this->timezone ?: ($this->workspace->timezone ?? config('app.timezone'));

        return function_exists('safe_timezone') ? safe_timezone($tz) : ($tz ?: 'UTC');
    }

    public function scopeForWorkspace($q, int $wsId)
    {
        return $q->where('workspace_id', $wsId);
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }
}
