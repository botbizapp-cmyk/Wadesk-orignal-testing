<?php

namespace Tests\Feature;

use App\Helpers\NotificationHelper;
use App\Models\Notification;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Reproduces the cross-workspace notification leak: a record created in
 * workspace A, owned by an admin who is CURRENTLY viewing workspace B, must
 * notify workspace A's bell — not B's. Before the fix, NotificationHelper
 * stamped the recipient's current_workspace_id (B), so A's events leaked into
 * B's notification dropdown.
 */
class NotificationWorkspaceStampTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['notifications', 'workspaces', 'users'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('workspaces', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->text('notification_prefs')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->unsignedBigInteger('current_workspace_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
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
            $t->unsignedBigInteger('source_id')->nullable();
            $t->string('verb')->nullable();
            $t->text('action_url')->nullable();
            $t->boolean('is_urgent')->default(0);
            $t->boolean('status')->default(1);
            $t->timestamps();
        });
    }

    /** A minimal workspace-scoped model that uses the notification helper path. */
    private function fakeRecord(int $workspaceId, ?int $userId, string $name): Model
    {
        return new class($workspaceId, $userId, $name) extends Model {
            public function __construct($ws = null, $uid = null, $nm = null)
            {
                parent::__construct();
                $this->forceFill(['id' => 7, 'workspace_id' => $ws, 'user_id' => $uid, 'name' => $nm]);
            }
            public function getKey() { return 7; }
        };
    }

    public function test_notification_is_stamped_with_the_records_workspace_not_the_viewers(): void
    {
        $wsA = Workspace::create(['name' => 'Media City']);   // owns the record
        $wsB = Workspace::create(['name' => 'NextLine']);     // what the admin is viewing

        $uid = DB::table('users')->insertGetId([
            'name' => 'Admin', 'email' => 'a@x.co', 'current_workspace_id' => $wsB->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->be(User::find($uid));

        $record = $this->fakeRecord($wsA->id, $uid, 'Pawan Kumar');
        NotificationHelper::record($record, 'created');

        $row = Notification::query()->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame($wsA->id, (int) $row->workspace_id, 'stamped with the OWNING workspace (A)');
        $this->assertNotSame($wsB->id, (int) $row->workspace_id, 'NOT the viewer current workspace (B)');
    }

    public function test_leaked_event_does_not_appear_in_the_other_workspaces_bell(): void
    {
        $wsA = Workspace::create(['name' => 'Media City']);
        $wsB = Workspace::create(['name' => 'NextLine']);
        $uid = DB::table('users')->insertGetId([
            'name' => 'Admin', 'email' => 'a@x.co', 'current_workspace_id' => $wsB->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->be(User::find($uid));

        NotificationHelper::record($this->fakeRecord($wsA->id, $uid, 'Raju'), 'created');

        // Admin is viewing B → the A-owned event must NOT show in B's bell.
        $this->assertSame(0, Notification::query()->forCurrentWorkspace()->count());

        // Switch to A → now it shows.
        $u = User::find($uid);
        $u->current_workspace_id = $wsA->id;
        $u->save();
        $this->be(User::find($uid));
        $this->assertSame(1, Notification::query()->forCurrentWorkspace()->count());
    }
}
