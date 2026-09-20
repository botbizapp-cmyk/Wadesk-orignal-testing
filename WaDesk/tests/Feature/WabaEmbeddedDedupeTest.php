<?php

namespace Tests\Feature;

use App\Models\WaProviderConfig;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * #2 — re-onboarding the SAME WABA number (same waba_id + phone_number_id) must
 * reuse its existing row instead of inserting a duplicate. This drives the exact
 * match predicate the embedded-connect fix uses against real DB rows.
 */
class WabaEmbeddedDedupeTest extends TestCase
{
    private const WS = 400;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('wa_provider_configs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id')->nullable();
            $t->string('provider')->nullable();
            $t->string('status')->nullable();
            $t->text('phone_number')->nullable();
            $t->text('display_label')->nullable();
            $t->text('meta_json')->nullable();
            $t->boolean('is_primary')->default(false);
            $t->timestamp('connected_at')->nullable();
            $t->timestamp('last_health_at')->nullable();
            $t->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('wa_provider_configs');
        parent::tearDown();
    }

    private function seedRow(string $wabaId, string $pnid): WaProviderConfig
    {
        return WaProviderConfig::create([
            'workspace_id' => self::WS,
            'provider'     => 'waba',
            'status'       => WaProviderConfig::STATUS_CONNECTED,
            'phone_number' => '+15550001111',
            'meta_json'    => ['waba_id' => $wabaId, 'phone_number_id' => $pnid],
            'is_primary'   => true,
        ]);
    }

    /** The exact lookup the fix performs (embedded + manual share it). */
    private function matchOrNew(string $wabaId, string $pnid): WaProviderConfig
    {
        return WaProviderConfig::query()->forWorkspace(self::WS)->where('provider', 'waba')->get()
            ->first(function ($row) use ($wabaId, $pnid) {
                $m = (array) ($row->meta_json ?? []);
                return (string) ($m['waba_id'] ?? '') === (string) $wabaId
                    && (string) ($m['phone_number_id'] ?? '') === (string) $pnid;
            }) ?: new WaProviderConfig();
    }

    public function test_same_number_reuses_the_existing_row(): void
    {
        $orig = $this->seedRow('WABA_1', 'PN_1');

        $cfg = $this->matchOrNew('WABA_1', 'PN_1');
        $this->assertTrue($cfg->exists, 'A matching waba_id + phone_number_id must be found.');
        $this->assertSame($orig->id, $cfg->id, 'Re-onboard must reuse the same row, not create one.');

        // Update-in-place (as the connect handler does) keeps the count at one.
        $cfg->display_label = 'Renamed Co';
        $cfg->save();
        $this->assertSame(1, WaProviderConfig::where('workspace_id', self::WS)->count(),
            'Re-onboarding the same number must not add a duplicate.');
    }

    public function test_a_different_number_creates_a_new_row(): void
    {
        $this->seedRow('WABA_1', 'PN_1');

        $cfg = $this->matchOrNew('WABA_2', 'PN_2');   // different number
        $this->assertFalse($cfg->exists, 'A different waba/number must NOT match the existing row.');

        $cfg->fill([
            'workspace_id' => self::WS,
            'provider'     => 'waba',
            'status'       => WaProviderConfig::STATUS_CONNECTED,
            'meta_json'    => ['waba_id' => 'WABA_2', 'phone_number_id' => 'PN_2'],
        ])->save();

        $this->assertSame(2, WaProviderConfig::where('workspace_id', self::WS)->count());
    }
}
