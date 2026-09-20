<?php

namespace App\Events\Inbox;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * ShouldBroadcastNow (NOT ShouldBroadcast): broadcast SYNCHRONOUSLY, inline in
 * the request. With plain ShouldBroadcast the push is a QUEUED job, so on any
 * self-hosted box where `php artisan queue:work` isn't running (the common case)
 * the job sits in the `jobs` table forever and the mobile/web client never gets
 * the message — the symptom "Pusher is on but nothing arrives in the app". The
 * Pusher trigger is a fast best-effort HTTP call (8s timeout, errors swallowed
 * in PusherHttpBroadcaster), so running it inline is safe and needs no worker.
 */
class MessageReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $messageId,
        public int $conversationId,
        public int $workspaceId,
        public string $direction,
        public ?int $userId,
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("workspace.{$this->workspaceId}.inbox"),
            new PrivateChannel("conversation.{$this->conversationId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.received';
    }

    public function broadcastWith(): array
    {
        return [
            'message_id'      => $this->messageId,
            'conversation_id' => $this->conversationId,
            'direction'       => $this->direction,
            'user_id'         => $this->userId,
        ];
    }
}
