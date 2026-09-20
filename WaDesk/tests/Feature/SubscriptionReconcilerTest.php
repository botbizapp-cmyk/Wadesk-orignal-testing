<?php

namespace Tests\Feature;

use App\Models\PaymentGateway;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Services\Payment\AbstractGatewayDriver;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\SubscriptionReconciler;
use App\Services\Payment\SubscriptionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

/**
 * Smoke test for the cron-free renewal reconciler. Proves that an expired
 * workspace whose gateway subscription HAS actually been charged again is
 * restored WITHOUT any webhook — the whole point of the fix.
 */
class SubscriptionReconcilerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        foreach (['workspaces', 'subscriptions', 'notifications'] as $t) {
            Schema::dropIfExists($t);
        }

        // Workspace::created fires a notification observer.
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
            $t->string('status')->nullable();
            $t->timestamps();
        });

        Schema::create('workspaces', function (Blueprint $t) {
            $t->id();
            $t->string('plan')->nullable();
            $t->string('name')->nullable();
            $t->unsignedBigInteger('owner_user_id')->nullable();
            $t->timestamp('trial_ends_at')->nullable();
            $t->timestamp('plan_ends_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('package_id')->nullable();
            $t->string('plan_id')->nullable();
            $t->string('gateway')->nullable();
            $t->string('gateway_subscription_id')->nullable();
            $t->string('gateway_plan_id')->nullable();
            $t->string('gateway_customer_id')->nullable();
            $t->string('billing_cycle')->nullable();
            $t->string('status')->nullable();
            $t->decimal('amount', 10, 2)->nullable();
            $t->string('currency')->nullable();
            $t->timestamp('current_period_end')->nullable();
            $t->integer('renewals_count')->default(0);
            $t->text('meta')->nullable();
            $t->timestamp('canceled_at')->nullable();
            $t->timestamps();
        });
    }

    /** Build a reconciler whose gateway driver returns a canned poll result. */
    private function reconcilerReturning(?array $state): SubscriptionReconciler
    {
        $driver = new class(new PaymentGateway()) extends AbstractGatewayDriver {
            public ?array $canned = null;
            public static function credentialFields(): array { return []; }
            public function initiate($order, string $callbackUrl): \App\Services\Payment\PaymentResult
            { return \App\Services\Payment\PaymentResult::failed('n/a'); }
            public function handleCallback(array $payload): \App\Services\Payment\PaymentResult
            { return \App\Services\Payment\PaymentResult::failed('n/a'); }
            public function fetchSubscription(string $id): ?array { return $this->canned; }
        };
        $driver->canned = $state;

        $manager = new class($driver) extends PaymentGatewayManager {
            private $d;
            public function __construct($d) { $this->d = $d; }
            public function driver(string $slug): AbstractGatewayDriver { return $this->d; }
        };

        return new SubscriptionReconciler($manager, app(SubscriptionService::class));
    }

    public function test_expired_workspace_is_restored_when_gateway_shows_a_new_paid_period(): void
    {
        $ws = Workspace::create([
            'plan' => 'basic-monthly',
            'name' => 'Manish test',
            'plan_ends_at' => now()->subDay(),          // lapsed → would read as Free
        ]);
        $sub = Subscription::create([
            'workspace_id' => $ws->id,
            'plan_id' => 'basic-monthly',
            'gateway' => 'razorpay',
            'gateway_subscription_id' => 'sub_TESTREAL',
            'status' => Subscription::STATUS_ACTIVE,
            'renewals_count' => 0,
        ]);

        // Gateway says: charged again, paid through 20 days out.
        $newEnd = now()->addDays(20);
        $out = $this->reconcilerReturning([
            'status' => 'active',
            'period_end' => $newEnd->timestamp,
        ])->reconcile($sub);

        $this->assertSame('renewed', $out);
        $ws->refresh();
        $this->assertTrue($ws->plan_ends_at->greaterThan(now()->addDays(19)), 'plan window rolled forward');
        $this->assertFalse($ws->planExpired(), 'workspace no longer expired');
        $this->assertSame(1, (int) $sub->fresh()->renewals_count);
    }

    public function test_one_time_purchase_with_no_gateway_sub_id_is_reported_not_renewed(): void
    {
        $ws = Workspace::create(['plan' => 'basic-monthly', 'plan_ends_at' => now()->subDay()]);
        $sub = Subscription::create([
            'workspace_id' => $ws->id,
            'gateway' => 'razorpay',
            'gateway_subscription_id' => null,          // one-time charge, no mandate
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $out = $this->reconcilerReturning(['status' => 'active', 'period_end' => now()->addDays(20)->timestamp])
            ->reconcile($sub);

        $this->assertSame('no_gateway_sub', $out);
        $this->assertTrue($ws->fresh()->planExpired(), 'still expired — nothing to renew');
    }

    public function test_healthy_midcycle_plan_is_not_polled(): void
    {
        $ws = Workspace::create(['plan' => 'basic-monthly', 'plan_ends_at' => now()->addDays(20)]);
        $sub = Subscription::create([
            'workspace_id' => $ws->id,
            'gateway' => 'razorpay',
            'gateway_subscription_id' => 'sub_X',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        // If it polled, this canned "ahead" result would extend it; it must NOT.
        $out = $this->reconcilerReturning(['status' => 'active', 'period_end' => now()->addDays(60)->timestamp])
            ->reconcile($sub);

        $this->assertSame('skip_healthy', $out);
    }

    public function test_second_poll_within_window_is_throttled(): void
    {
        $ws = Workspace::create(['plan' => 'basic-monthly', 'plan_ends_at' => now()->subDay()]);
        $sub = Subscription::create([
            'workspace_id' => $ws->id,
            'gateway' => 'razorpay',
            'gateway_subscription_id' => 'sub_Y',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        // Gateway reports nothing new (period end not ahead of our expired
        // window) → 'up_to_date', and the plan stays expired so the row is still
        // "interesting" on the next call — which must then be throttled, proving
        // we don't hammer the gateway API on every request.
        $r = $this->reconcilerReturning(['status' => 'active', 'period_end' => now()->subDays(2)->timestamp]);
        $this->assertSame('up_to_date', $r->reconcile($sub));
        $this->assertSame('skip_throttled', $r->reconcile($sub->fresh()));
    }
}
