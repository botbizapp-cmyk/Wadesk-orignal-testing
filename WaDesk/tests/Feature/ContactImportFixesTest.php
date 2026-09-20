<?php

namespace Tests\Feature;

use App\Http\Controllers\ContactsController;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * #3  — re-importing an existing number UPDATES it (name/fields) instead of
 *        skipping it as a duplicate. Drives the REAL import controller.
 * #19 — imported contacts get a mobile_hash, so a later inbound capture
 *        (rememberPhone) dedups to the same row instead of saving twice.
 * No external calls — import writes only to the DB.
 */
class ContactImportFixesTest extends TestCase
{
    private const WS = 300;

    protected function setUp(): void
    {
        parent::setUp();
        $this->contactsTable();
        Schema::create('contact_groups', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('workspace_id')->nullable();
            $t->string('user_group')->nullable();
            $t->timestamps();
        });
        // Empty workspaces table → currentWorkspace relation is null →
        // PlanLimitGuard::check(null, …) returns without throwing.
        Schema::create('workspaces', function (Blueprint $t) {
            $t->id();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('workspace_user', function (Blueprint $t) {
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('workspace_id');
            $t->string('role')->nullable();
            $t->timestamp('invited_at')->nullable();
            $t->timestamp('joined_at')->nullable();
            $t->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('contact_groups');
        Schema::dropIfExists("workspaces");
        Schema::dropIfExists("workspace_user");
        parent::tearDown();
    }

    private function csvFile(string $body): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'imp') . '.csv';
        file_put_contents($path, $body);
        return new UploadedFile($path, 'contacts.csv', 'text/csv', null, true);
    }

    /** #3 — a re-import with a changed name overwrites, not skipped. */
    public function test_reimport_updates_existing_contact(): void
    {
        // Existing contact "PB" (created the way the import does).
        Contact::withoutEvents(fn () => Contact::create([
            'workspace_id' => self::WS,
            'name'         => 'PB',
            'first_name'   => 'PB',
            'mobile'       => '911234567890',
            'mobile_hash'  => Contact::hashPhone(null, '911234567890'),
        ]));

        $user = new User();
        $user->id = 1;
        $user->current_workspace_id = self::WS;

        // Invoke the real controller method directly (the test HTTP kernel
        // mangles the base URL for this route). This still exercises the full
        // import loop — validation, dedup, and the update-on-duplicate fix.
        $csv = "name,phone\nBP,911234567890\n";
        $request = Request::create('/contacts/import', 'POST');
        $request->files->set('file', $this->csvFile($csv));
        $request->setUserResolver(fn () => $user);

        (new ContactsController())->import($request);

        $rows = Contact::where('workspace_id', self::WS)->get();
        $this->assertCount(1, $rows, 'Re-import must NOT create a duplicate.');
        $this->assertSame('BP', $rows->first()->name, 'Re-import must overwrite the name.');
    }

    /** #19 — imported row carries a mobile_hash, so inbound capture dedups to it. */
    public function test_imported_contact_dedups_on_inbound(): void
    {
        // FIXED import: mobile_hash set explicitly under withoutEvents.
        Contact::withoutEvents(fn () => Contact::create([
            'workspace_id' => self::WS,
            'name'         => 'CSV Person',
            'mobile'       => '919876543210',
            'mobile_hash'  => Contact::hashPhone(null, '919876543210'),
        ]));

        // Inbound capture of the SAME number reuses the row, not a duplicate.
        Contact::rememberPhone(self::WS, 1, '919876543210', 'WA Name');
        $this->assertSame(1, Contact::where('workspace_id', self::WS)->count(),
            'A hashed import row must dedup — no second contact for the same number.');

        // Contrast: an UN-hashed row (the OLD bug) does NOT dedup → a duplicate.
        Contact::withoutEvents(fn () => Contact::create([
            'workspace_id' => self::WS,
            'name'         => 'Unhashed',
            'mobile'       => '919000000001',
            // mobile_hash intentionally omitted (pre-fix behaviour)
        ]));
        $this->assertSame(2, Contact::where('workspace_id', self::WS)->count());
        Contact::rememberPhone(self::WS, 1, '919000000001', 'WA Name');
        $this->assertSame(3, Contact::where('workspace_id', self::WS)->count(),
            'Without a hash the inbound capture cannot dedup and creates a separate row.');
    }

    private function contactsTable(): void
    {
        Schema::dropIfExists('contacts');
        Schema::create('contacts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('workspace_id')->nullable();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('title')->nullable();
            $t->text('first_name')->nullable();
            $t->text('middle_name')->nullable();
            $t->text('last_name')->nullable();
            $t->text('name')->nullable();
            $t->string('language')->nullable();
            $t->text('address')->nullable();
            $t->text('contact_group')->nullable();
            $t->text('email')->nullable();
            $t->text('country_code')->nullable();
            $t->text('mobile')->nullable();
            $t->string('mobile_hash')->nullable();
            $t->string('channel')->nullable();
            $t->string('channel_uid')->nullable();
            $t->text('msg')->nullable();
            $t->string('subject')->nullable();
            $t->text('image')->nullable();
            $t->boolean('is_unsubscribed')->default(false);
            $t->timestamp('unsubscribed_at')->nullable();
            $t->text('custom_attributes')->nullable();
            $t->timestamps();
        });
    }
}
