<?php

namespace Tests\Feature;

use App\Http\Controllers\WaCampaignsController;
use App\Models\Appointment;
use App\Models\Attribute;
use App\Models\Contact;
use App\Models\SystemSetting;
use App\Services\Appointments\AppointmentReminderScheduler;
use App\Services\AttributeResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Executable coverage for the bug-batch fixes that are pure logic (no live
 * WhatsApp/Meta/Node bridge needed):
 *   #38 / #20  AttributeResolver personalizes from the recipient Contact
 *   #20        parseCsvNumbers keeps the CSV's extra columns as attributes
 *   #58        AppointmentReminderScheduler::unschedule cancels the reminder ids
 * Never hits the wire — Http is faked.
 */
class BugBatchFixesTest extends TestCase
{
    private const WS = 777;

    protected function setUp(): void
    {
        parent::setUp();

        // Minimal `attributes` table the resolver queries (workspace defaults).
        Schema::create('attributes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('workspace_id')->nullable();
            $t->string('attribute_name')->nullable();
            $t->string('attribute_key')->nullable();
            $t->text('attribute_value')->nullable();
            $t->string('description')->nullable();
            $t->string('type')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });

        // Minimal `system_settings` table so SystemSetting::set/get work.
        Schema::create('system_settings', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->text('value')->nullable();
            $t->string('type')->nullable();
            $t->string('description')->nullable();
            $t->timestamps();
        });

        // Attribute::create fires a notification observer that writes here.
        Schema::create('notifications', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('workspace_id')->nullable();
            $t->text('notification_title')->nullable();
            $t->text('notification_msg')->nullable();
            $t->string('category')->nullable();
            $t->string('severity')->nullable();
            $t->string('icon')->nullable();
            $t->string('source_type')->nullable();
            $t->string('source_id')->nullable();
            $t->string('verb')->nullable();
            $t->text('action_url')->nullable();
            $t->boolean('is_urgent')->default(false);
            $t->string('status')->nullable();
            $t->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('attributes');
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('notifications');
        parent::tearDown();
    }

    /** #38 / #20 — the recipient's own name + a CSV attribute resolve per contact. */
    public function test_resolver_uses_per_contact_values(): void
    {
        $contact = new Contact();
        $contact->name = 'Alice';
        $contact->custom_attributes = ['city' => 'Paris'];

        $resolver = new AttributeResolver();

        // {{name}} → contact name, positional {{1}} (mapped to "city") → Paris.
        $out = $resolver->resolve('Hi {{name}}, from {{1}}', ['1' => 'city'], self::WS, $contact);
        $this->assertSame('Hi Alice, from Paris', $out);

        // Without a contact, {{name}} has no source and {{1}} is unmapped to any
        // workspace attribute → placeholders survive (the pre-fix behaviour).
        $bare = $resolver->resolve('Hi {{name}}, from {{1}}', ['1' => 'city'], self::WS, null);
        $this->assertStringContainsString('{{name}}', $bare);
    }

    /** #38 — a per-contact value beats the workspace-wide attribute default. */
    public function test_contact_value_overrides_workspace_attribute(): void
    {
        Attribute::create([
            'workspace_id'    => self::WS,
            'attribute_name'  => 'City',
            'attribute_key'   => 'city',
            'attribute_value' => 'Berlin',   // workspace default
            'type'            => 'text',
            'status'          => 'active',
        ]);

        $resolver = new AttributeResolver();

        $contact = new Contact();
        $contact->custom_attributes = ['city' => 'Paris'];
        $this->assertSame('Paris', $resolver->resolve('{{1}}', ['1' => 'city'], self::WS, $contact));

        // No contact → falls back to the workspace default.
        $this->assertSame('Berlin', $resolver->resolve('{{1}}', ['1' => 'city'], self::WS, null));
    }

    /** #20 — parseCsvNumbers keeps every non-phone column as a per-phone attribute. */
    public function test_parse_csv_captures_extra_columns(): void
    {
        $csv = "phone,name,Promo Code\n+91 12345 67890,Bob,SAVE20\n";
        $path = tempnam(sys_get_temp_dir(), 'csv') . '.csv';
        file_put_contents($path, $csv);
        $file = new UploadedFile($path, 'r.csv', 'text/csv', null, true);

        $controller = app(WaCampaignsController::class);
        $ref = new \ReflectionMethod(WaCampaignsController::class, 'parseCsvNumbers');
        $ref->setAccessible(true);
        $attrs = [];
        $args  = [$file, &$attrs];
        $phones = $ref->invokeArgs($controller, $args);

        @unlink($path);

        $this->assertCount(1, $phones);
        $digits = '911234567890';
        $this->assertArrayHasKey($digits, $attrs, 'CSV columns must be captured for the phone.');
        // parseCsvNumbers lower-cases headers, so keys arrive lower-cased.
        $this->assertSame('Bob', $attrs[$digits]['name']);
        $this->assertSame('SAVE20', $attrs[$digits]['promo code']);
        // Stored under a snake_case form too, so either placeholder spelling matches.
        $this->assertSame('SAVE20', $attrs[$digits]['promo_code']);
    }

    /** #58 — cancelling unschedules BOTH reminder id schemes on the Node bridge. */
    public function test_appointment_unschedule_cancels_reminder_ids(): void
    {
        SystemSetting::set('baileys_server_url', 'http://node.test', 'string');
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $appt = new Appointment();
        $appt->id = 42;

        app(AppointmentReminderScheduler::class)->unschedule($appt);

        // Single-reminder id: -1000000 - 42
        Http::assertSent(fn ($req) =>
            $req->method() === 'DELETE'
            && str_contains($req->url(), '/api/cancel-scheduled-message/-1000042'));

        // Widened multi-offset id, offset 0: -2000000000 - 42*100 - 0
        Http::assertSent(fn ($req) =>
            str_contains($req->url(), '/api/cancel-scheduled-message/-2000004200'));
    }
}
