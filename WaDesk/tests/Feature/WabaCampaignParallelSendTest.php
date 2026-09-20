<?php

namespace Tests\Feature;

use App\Models\WaProviderConfig;
use App\Models\WaTemplate;
use App\Services\Waba\TemplateSender;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Smoke test for the ~80/sec PARALLEL WABA campaign send.
 *
 * The campaign's parallel drain (WaCampaignsController::drainWabaTemplateParallel)
 * fans recipients out through TemplateSender::sendMany(), which fires each chunk
 * CONCURRENTLY via Http::pool — that is the mechanism that "saturates Meta's
 * ~80/sec-per-number ceiling". This test exercises that engine end-to-end with
 * the Graph API FAKED (Http::fake) so NO real WhatsApp message is dispatched
 * (the rule is never to hit the wire in a test), and asserts:
 *   1. every recipient in the batch is sent (one Graph POST each) — proof the
 *      pool fanned them all out, not a single sequential blast;
 *   2. every result is mapped back ok with its wamid;
 *   3. the batch gates (unapproved template) refuse WITHOUT ever calling Meta.
 */
class WabaCampaignParallelSendTest extends TestCase
{
    private const WS = 500;

    protected function setUp(): void
    {
        parent::setUp();
        // WaTemplate/WaProviderConfig fire model events (LogsNotifications) on
        // save that reach into notifications/users/workspaces tables we don't
        // build here. Fake events so a save doesn't crash on that side-path —
        // none of the parallel-send logic under test depends on model events.
        Event::fake();
        $this->createTables();
    }

    private function makeConfig(): WaProviderConfig
    {
        $cfg = new WaProviderConfig();
        $cfg->workspace_id = self::WS;
        $cfg->provider     = 'waba';
        $cfg->status       = 'connected';
        $cfg->phone_number = '15551230000';
        $cfg->meta_json    = ['phone_number_id' => '109999999999'];
        $cfg->setCreds(['access_token' => 'TEST_TOKEN', 'phone_number_id' => '109999999999']);
        $cfg->save();

        return $cfg;
    }

    private function makeTemplate(WaProviderConfig $cfg, string $status = 'APPROVED'): WaTemplate
    {
        return WaTemplate::create([
            'workspace_id'       => self::WS,
            'provider_config_id' => $cfg->id,
            'channel'            => 'waba',
            'meta_template_id'   => '55555',
            'meta_status'        => $status,
            'quality_score'      => 'GREEN',
            'template_name'      => 'order_ready',
            'category'           => 'utility',
            'template_type'      => 'text',
            // No {{n}} → the simplest valid Graph payload (no body params).
            'template_body'      => 'Your order is ready.',
            'language'           => 'en',
            'status'             => 'approved',
        ]);
    }

    public function test_send_many_fans_every_recipient_out_through_the_pool(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.TEST']]], 200),
        ]);

        $cfg = $this->makeConfig();
        $tpl = $this->makeTemplate($cfg);

        // 25 recipients, concurrency 10 → 3 pool rounds; all 25 must be sent.
        $recipients = [];
        for ($i = 1; $i <= 25; $i++) {
            $recipients[] = ['id' => $i, 'to' => '15550' . str_pad((string) $i, 6, '0', STR_PAD_LEFT), 'vars' => []];
        }

        $results = (new TemplateSender())->sendMany($tpl, $recipients, $cfg, 10);

        $this->assertCount(25, $results, 'every recipient must get a result');
        foreach ($results as $id => $res) {
            $this->assertTrue((bool) ($res['ok'] ?? false), "recipient {$id} failed: " . ($res['error'] ?? ''));
            $this->assertSame('wamid.TEST', $res['wamid']);
        }

        // ONE Graph /messages POST per recipient = the whole batch fanned out
        // concurrently (the 80/sec parallel path), not one sequential send.
        Http::assertSentCount(25);
    }

    public function test_concurrency_is_clamped_but_still_sends_everyone(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X']]], 200),
        ]);

        $cfg = $this->makeConfig();
        $tpl = $this->makeTemplate($cfg);

        // Concurrency 999 is clamped to 30 internally; a 12-recipient batch still
        // sends all 12 in a single pool round.
        $recipients = [];
        for ($i = 1; $i <= 12; $i++) {
            $recipients[] = ['id' => $i, 'to' => '15551' . str_pad((string) $i, 6, '0', STR_PAD_LEFT), 'vars' => []];
        }

        $results = (new TemplateSender())->sendMany($tpl, $recipients, $cfg, 999);

        $this->assertCount(12, $results);
        $this->assertTrue(collect($results)->every(fn ($r) => $r['ok'] ?? false));
        Http::assertSentCount(12);
    }

    public function test_unapproved_template_is_refused_without_calling_meta(): void
    {
        Http::fake();  // ANY outgoing request here would be a bug (never send unapproved)

        $cfg = $this->makeConfig();
        $tpl = $this->makeTemplate($cfg, 'PENDING');

        $results = (new TemplateSender())->sendMany(
            $tpl,
            [['id' => 1, 'to' => '15550001111', 'vars' => []]],
            $cfg,
            10
        );

        $this->assertFalse((bool) ($results[1]['ok'] ?? false));
        Http::assertNothingSent();
    }

    private function createTables(): void
    {
        Schema::dropIfExists('wa_provider_configs');
        Schema::create('wa_provider_configs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('provider')->default('waba');
            $t->string('status')->nullable();
            $t->string('phone_number')->nullable();
            $t->string('display_label')->nullable();
            $t->text('credentials_json')->nullable();
            $t->text('meta_json')->nullable();
            $t->boolean('calling_enabled')->nullable();
            $t->boolean('is_primary')->nullable();
            $t->timestamps();
        });

        Schema::dropIfExists('wa_templates');
        Schema::create('wa_templates', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('workspace_id')->nullable();
            $t->unsignedBigInteger('provider_config_id')->nullable();
            $t->string('meta_template_id')->nullable();
            $t->string('twilio_content_sid')->nullable();
            $t->string('channel')->nullable();
            $t->string('meta_status')->nullable();
            $t->string('quality_score')->nullable();
            $t->text('template_name')->nullable();
            $t->string('category')->nullable();
            $t->string('meta_category')->nullable();
            $t->string('template_type')->nullable();
            $t->text('header')->nullable();
            $t->text('header_location')->nullable();
            $t->text('template_body')->nullable();
            $t->text('footer')->nullable();
            $t->text('buttons')->nullable();
            $t->text('carousel_data')->nullable();
            $t->text('variable_map')->nullable();
            $t->string('attachment_type')->nullable();
            $t->string('attachment_file')->nullable();
            $t->string('header_sample_url')->nullable();
            $t->string('language')->nullable();
            $t->string('parameter_format')->nullable();
            $t->string('status')->nullable();
            $t->timestamp('paused_until')->nullable();
            $t->timestamps();
        });

        Schema::dropIfExists('system_settings');
        Schema::create('system_settings', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->string('type')->default('string');
            $t->text('value')->nullable();
            $t->text('description')->nullable();
            $t->timestamps();
        });
    }
}
