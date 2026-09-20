<?php

namespace Tests\Feature;

use App\Http\Controllers\Instagram\InstagramWebhookController;
use App\Models\Conversation;
use App\Models\InboxMessage;
use App\Services\Instagram\InstagramWebhookSignature;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InstagramWebhookInboundTest extends TestCase
{
    private const SECRET = 'feature-test-instagram-secret';

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();
        Cache::flush();
        $this->createTables();

        DB::table('system_settings')->insert([
            'key' => 'instagram_app_secret',
            'type' => 'string',
            'value' => self::SECRET,
            'description' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('instagram_accounts')->insert([
            'id' => 3,
            'workspace_id' => 17,
            'ig_user_id' => '17890000000000123',
            'username' => 'test_business',
            'name' => 'Test Business',
            'login_type' => 'facebook',
            'status' => 'connected',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_signed_self_test_reaches_webhook_and_is_stored_as_non_automating_test_inbound(): void
    {
        $trace = 'igtest_feature123';
        $messageId = 'wadesk_test_feature_123';
        $payload = $this->payload($messageId);
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES);

        $request = Request::create('/webhooks/instagram', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => InstagramWebhookSignature::make($raw, self::SECRET),
            'HTTP_X_WADESK_WEBHOOK_TEST' => '1',
            'HTTP_X_WADESK_WEBHOOK_TRACE' => $trace,
            'HTTP_X_WADESK_TEST_ACCOUNT' => '3',
        ], $raw);
        $response = app(InstagramWebhookController::class)->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([
            'ok' => true,
            'trace_id' => $trace,
            'processed' => 1,
            'ignored' => 0,
            'errors' => 0,
        ], $response->getData(true));

        $message = InboxMessage::query()->firstOrFail();
        $conversation = Conversation::query()->firstOrFail();

        $this->assertSame('in', $message->direction);
        $this->assertSame('[TEST] inbound pipeline', $message->body);
        $this->assertTrue((bool) data_get($message->meta, 'instagram.is_test'));
        $this->assertSame($trace, data_get($message->meta, 'instagram.trace_id'));
        $this->assertSame($messageId, data_get($message->meta, 'instagram.message_id'));
        $this->assertSame('ig:17890000000000123:wadesk_webhook_test', $conversation->raw_jid);
        $this->assertSame('Instagram webhook test', $conversation->title);
    }

    public function test_invalid_signature_is_rejected_before_any_inbox_write(): void
    {
        $raw = json_encode($this->payload('forged_message'), JSON_UNESCAPED_SLASHES);

        $request = Request::create('/webhooks/instagram', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => InstagramWebhookSignature::make($raw, 'wrong-secret'),
        ], $raw);
        $response = app(InstagramWebhookController::class)->handle($request);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertEquals([
            'ok' => false,
            'error' => 'invalid_signature',
        ], array_intersect_key($response->getData(true), ['ok' => true, 'error' => true]));
        $this->assertSame(0, InboxMessage::query()->count());
        $this->assertSame(0, Conversation::query()->count());
    }

    private function payload(string $messageId): array
    {
        return [
            'object' => 'instagram',
            'entry' => [[
                'id' => '17890000000000123',
                'time' => 1_700_000_000_000,
                'messaging' => [[
                    'sender' => ['id' => '990000000000000003'],
                    'recipient' => ['id' => '17890000000000123'],
                    'timestamp' => 1_700_000_000_000,
                    'message' => [
                        'mid' => $messageId,
                        'text' => '[TEST] inbound pipeline',
                        'is_self' => true,
                    ],
                ]],
            ]],
        ];
    }

    private function createTables(): void
    {
        foreach (['inbox_messages', 'conversations', 'instagram_accounts', 'system_settings'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('type')->default('string');
            $table->text('value')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('instagram_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('ig_user_id');
            $table->string('username')->nullable();
            $table->string('name')->nullable();
            $table->text('profile_pic_url')->nullable();
            $table->string('page_id')->nullable();
            $table->string('login_type')->default('facebook');
            $table->text('access_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->string('status')->default('connected');
            $table->unsignedBigInteger('followers_count')->nullable();
            $table->string('last_error')->nullable();
            $table->json('meta_json')->nullable();
            $table->timestamps();
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->string('channel')->nullable();
            $table->string('raw_jid')->nullable();
            $table->text('title')->nullable();
            $table->text('preview')->nullable();
            $table->string('provider')->nullable();
            $table->string('origin')->nullable();
            $table->string('status')->nullable();
            $table->string('inbox_status')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('last_outbound_at')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->string('contact_digits')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'channel', 'raw_jid']);
        });

        Schema::create('inbox_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            $table->string('provider')->nullable();
            $table->string('direction');
            $table->text('body')->nullable();
            $table->string('media_type')->nullable();
            $table->string('status')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
    }
}
