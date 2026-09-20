<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A Facebook Messenger broadcast — a one-off message to the Page's reachable
 * audience (existing inbox threads still inside Meta's 24h messaging window).
 * Messenger has no phone-number audience, so it ships its own broadcast pipeline
 * separate from core WhatsApp campaigns. Mirrors {@see TelegramBroadcast}.
 */
class FacebookBroadcast extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENDING = 'sending';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'workspace_id', 'facebook_page_id', 'user_id', 'name', 'template_id', 'body', 'buttons',
        'status', 'total', 'sent', 'failed', 'blocked', 'last_error', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'body'        => 'encrypted',
        'buttons'     => 'encrypted:array',
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    protected $hidden = ['body'];

    public function recipients()
    {
        return $this->hasMany(FacebookBroadcastRecipient::class);
    }

    public function page()
    {
        return $this->belongsTo(FacebookPage::class, 'facebook_page_id');
    }

    public function hasPending(): bool
    {
        return $this->recipients()->where('status', FacebookBroadcastRecipient::STATUS_PENDING)->exists();
    }

    /** Progress % (blocked counts as done — nothing more to do for them). */
    public function progress(): int
    {
        $total = max(1, (int) $this->total);

        return (int) round((($this->sent + $this->failed + $this->blocked) / $total) * 100);
    }
}
