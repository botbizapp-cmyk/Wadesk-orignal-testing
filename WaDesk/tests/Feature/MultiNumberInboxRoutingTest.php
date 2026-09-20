<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Services\Inbox\ConversationResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Multi-number inbox mapping + outbound routing (reported by Kothari Tech).
 *
 *   Two business numbers in ONE workspace:
 *     Number 1 — coexistence (provider 'waba',    device_id 1)
 *     Number 2 — virtual     (provider 'baileys', device_id 2)
 *   The same customer (Number 3) messages BOTH.
 *
 * Required:
 *   · message to Number 1 lands in Number 1's thread
 *   · message to Number 2 lands in Number 2's thread
 *   · a reply leaves from the number the message was received on
 *
 * These tests drive the REAL resolver + stampChannel exactly the way the WABA /
 * Baileys inbound controllers do (no WhatsApp send is dispatched — the rule is
 * never to hit the wire in a test). $numberCounter is primed so the workspace
 * reports 2 connected numbers, which is what turns on the per-number split.
 */
class MultiNumberInboxRoutingTest extends TestCase
{
    private const WS = 100;
    private const NUM1_DEVICE = 1;   // coexistence
    private const NUM2_DEVICE = 2;   // virtual
    private const CUSTOMER = '15550003333';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createConversationsTable();
        ConversationResolver::forgetMultiNumber();
        // Default: pretend the workspace has 2 connected numbers.
        ConversationResolver::$numberCounter = fn (int $ws) => 2;
    }

    protected function tearDown(): void
    {
        ConversationResolver::$numberCounter = null;
        ConversationResolver::forgetMultiNumber();
        parent::tearDown();
    }

    /** Mirror the inbound webhooks: resolve-or-create for the receiving number, then stamp the channel. */
    private function inbound(int $deviceId, string $provider): Conversation
    {
        $convo = ConversationResolver::find(self::WS, self::CUSTOMER, $deviceId);
        if (! $convo) {
            $convo = Conversation::create([
                'workspace_id'    => self::WS,
                'device_id'       => $deviceId,
                'provider'        => $provider,
                'channel'         => 'whatsapp',
                'raw_jid'         => self::CUSTOMER,
                'title'           => '+' . self::CUSTOMER,
                'origin'          => 'inbox',
                'status'          => 'pending',
                'inbox_status'    => 'open',
                'last_message_at' => now(),
            ]);
        }
        ConversationResolver::stampChannel($convo, $provider, $deviceId);

        return $convo->refresh();
    }

    private function threadsForCustomer(): int
    {
        return Conversation::query()
            ->where('workspace_id', self::WS)
            ->where('contact_digits', self::CUSTOMER)
            ->count();
    }

    public function test_two_business_numbers_get_separate_threads(): void
    {
        $this->inbound(self::NUM1_DEVICE, 'waba');      // customer → Number 1
        $this->inbound(self::NUM2_DEVICE, 'baileys');   // customer → Number 2

        $this->assertSame(2, $this->threadsForCustomer(),
            'Each business number must keep its own thread.');
    }

    public function test_each_thread_stays_bound_to_its_receiving_number(): void
    {
        $t1 = $this->inbound(self::NUM1_DEVICE, 'waba');     // → Number 1 thread
        $t2 = $this->inbound(self::NUM2_DEVICE, 'baileys');  // → Number 2 thread (separate)

        $this->assertNotSame($t1->id, $t2->id, 'The two numbers must not share a thread.');
        $this->assertSame(self::NUM1_DEVICE, (int) $t1->device_id);
        $this->assertSame(self::NUM2_DEVICE, (int) $t2->device_id);

        // A LATER message from the same customer to Number 1 must land back on
        // Number 1's thread and must NOT flip it to Number 2.
        $again = $this->inbound(self::NUM1_DEVICE, 'waba');
        $this->assertSame($t1->id, $again->id, 'Reply-to number must reuse its own thread.');
        $this->assertSame(self::NUM1_DEVICE, (int) $again->device_id,
            'Reply must leave from the number the message was received on.');
    }

    public function test_single_number_workspace_still_collapses_to_one_thread(): void
    {
        // Regression guard: on a single-number workspace nothing changes — the
        // customer keeps ONE thread and the channel follows the last message.
        ConversationResolver::$numberCounter = fn (int $ws) => 1;
        ConversationResolver::forgetMultiNumber();

        $this->inbound(self::NUM1_DEVICE, 'waba');
        $last = $this->inbound(self::NUM2_DEVICE, 'baileys');

        $this->assertSame(1, $this->threadsForCustomer(),
            'Single-number workspace must keep the one-thread-per-customer behaviour.');
        $this->assertSame(self::NUM2_DEVICE, (int) $last->device_id,
            'Single-number thread still follows the last message channel.');
    }

    private function createConversationsTable(): void
    {
        Schema::dropIfExists('conversations');
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('device_id')->nullable();
            $table->string('channel')->nullable();
            $table->string('raw_jid')->nullable();
            $table->string('alt_jid')->nullable();
            $table->text('title')->nullable();
            $table->text('preview')->nullable();
            $table->string('provider')->nullable();
            $table->string('origin')->nullable();
            $table->string('status')->nullable();
            $table->string('inbox_status')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->string('contact_digits')->nullable();
            $table->timestamps();
        });
    }
}
