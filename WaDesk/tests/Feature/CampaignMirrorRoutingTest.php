<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\InboxMessage;
use App\Services\Inbox\ConversationResolver;
use App\Services\Inbox\InboxMirror;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * #21 — a campaign sent from number A must mirror into number A's thread, not
 * the customer's other-number thread. Drives the REAL InboxMirror + resolver;
 * no WhatsApp send (the mirror only writes the inbox row).
 */
class CampaignMirrorRoutingTest extends TestCase
{
    private const WS = 200;
    private const NUM_A = 1;   // sending number
    private const NUM_B = 2;   // other number
    private const CUSTOMER = '15550009999';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tables();
        ConversationResolver::forgetMultiNumber();
        ConversationResolver::$numberCounter = fn (int $ws) => 2; // multi-number workspace
    }

    protected function tearDown(): void
    {
        ConversationResolver::$numberCounter = null;
        ConversationResolver::forgetMultiNumber();
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('inbox_messages');
        parent::tearDown();
    }

    private function thread(int $deviceId, string $provider): Conversation
    {
        return Conversation::create([
            'workspace_id'    => self::WS,
            'device_id'       => $deviceId,
            'provider'        => $provider,
            'channel'         => 'whatsapp',
            'raw_jid'         => self::CUSTOMER,
            'contact_digits'  => self::CUSTOMER,
            'title'           => '+' . self::CUSTOMER,
            'origin'          => 'inbox',
            'status'          => 'open',
            'inbox_status'    => 'open',
            'last_message_at' => now(),
        ]);
    }

    public function test_campaign_send_lands_in_the_sending_numbers_thread(): void
    {
        $threadA = $this->thread(self::NUM_A, 'waba');     // customer ↔ number A
        $threadB = $this->thread(self::NUM_B, 'baileys');  // customer ↔ number B (older/other)

        // Campaign sends from number A → pass its device via meta (as the fix does).
        app(InboxMirror::class)->appendOutboundToOpenConversation(
            self::WS,
            self::CUSTOMER,
            'Your promo is live',
            'wamid-A-1',
            'waba',
            ['type' => 'template', 'receiving_device_id' => self::NUM_A],
        );

        // The bubble must be on A's thread, and NOT on B's.
        $this->assertSame(1, InboxMessage::where('conversation_id', $threadA->id)->count(),
            'Campaign from number A must appear in A\'s thread.');
        $this->assertSame(0, InboxMessage::where('conversation_id', $threadB->id)->count(),
            'Campaign from number A must NOT appear in number B\'s thread.');
    }

    private function tables(): void
    {
        Schema::dropIfExists('conversations');
        Schema::create('conversations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('workspace_id');
            $t->unsignedBigInteger('device_id')->nullable();
            $t->string('channel')->nullable();
            $t->string('raw_jid')->nullable();
            $t->string('alt_jid')->nullable();
            $t->string('contact_digits')->nullable();
            $t->text('title')->nullable();
            $t->text('preview')->nullable();
            $t->string('provider')->nullable();
            $t->string('origin')->nullable();
            $t->string('status')->nullable();
            $t->string('inbox_status')->nullable();
            $t->timestamp('last_message_at')->nullable();
            $t->timestamp('last_outbound_at')->nullable();
            $t->unsignedInteger('unread_count')->default(0);
            $t->timestamps();
        });

        Schema::dropIfExists('inbox_messages');
        Schema::create('inbox_messages', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('conversation_id');
            $t->string('direction')->nullable();
            $t->text('to_number')->nullable();
            $t->text('from_number')->nullable();
            $t->text('body')->nullable();
            $t->string('status')->nullable();
            $t->string('provider')->nullable();
            $t->json('meta')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
    }
}
