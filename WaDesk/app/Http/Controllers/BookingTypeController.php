<?php

namespace App\Http\Controllers;

use App\Models\BookingType;
use App\Models\WaTemplate;
use App\Models\Workspace;
use App\Services\GoogleCalendar\GoogleCalendarService;
use App\Services\Payment\PaymentGatewayManager;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Booking Types — the multi-service dimension. A 3-step wizard (Service /
 * Availability / Messaging) posts once; this controller transactionally upserts
 * the type + all its children (availability, financials, integration,
 * templates, reminders, questions). Plan-gated by access_appointment_booking.
 */
class BookingTypeController extends Controller
{
    public function __construct(private readonly GoogleCalendarService $gcal) {}

    private function wsId(): int
    {
        return (int) (Auth::user()->current_workspace_id ?? 0);
    }

    /** GET /appointments/booking-types — list. */
    public function index(): View
    {
        $types = BookingType::forWorkspace($this->wsId())
            ->withCount('appointments')
            ->with('financial')
            ->orderBy('sort_order')->orderByDesc('id')->get();

        return view('user.appointments.booking-types.index', compact('types'));
    }

    /**
     * GET /appointments/booking-types/list — lightweight JSON for pickers
     * (the flow builder's Book Appointment "Service" dropdown). Active types
     * only, current workspace.
     */
    public function apiList(Request $request): \Illuminate\Http\JsonResponse
    {
        $types = BookingType::forWorkspace($this->wsId())
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'duration_minutes', 'is_active'])
            ->map(fn ($t) => [
                'id'       => $t->id,
                'name'     => $t->name,
                'duration' => $t->duration_minutes,
                'active'   => (bool) $t->is_active,
            ])->values();

        return response()->json(['types' => $types]);
    }

    /** GET /appointments/booking-types/create — the wizard. */
    public function create(): View
    {
        return view('user.appointments.booking-types.create', $this->wizardData(null));
    }

    /** GET /appointments/booking-types/{bookingType}/edit — the wizard, repopulated. */
    public function edit(BookingType $bookingType): View
    {
        abort_unless($bookingType->workspace_id === $this->wsId(), 404);
        $bookingType->load(['availabilityRules', 'overrides', 'templates', 'reminders', 'financial', 'integration', 'questions']);

        return view('user.appointments.booking-types.edit', $this->wizardData($bookingType));
    }

    public function store(Request $request): RedirectResponse
    {
        $type = $this->upsert($request, null);

        return redirect()->route('user.appointments.booking-types.index')
            ->with('success', __('Booking type ":name" created.', ['name' => $type->name]));
    }

    public function update(Request $request, BookingType $bookingType): RedirectResponse
    {
        abort_unless($bookingType->workspace_id === $this->wsId(), 404);
        $type = $this->upsert($request, $bookingType);

        return redirect()->route('user.appointments.booking-types.index')
            ->with('success', __('Booking type ":name" updated.', ['name' => $type->name]));
    }

    public function destroy(BookingType $bookingType): RedirectResponse
    {
        abort_unless($bookingType->workspace_id === $this->wsId(), 404);
        $bookingType->delete();

        return back()->with('success', __('Booking type removed.'));
    }

    public function toggle(BookingType $bookingType): RedirectResponse
    {
        abort_unless($bookingType->workspace_id === $this->wsId(), 404);
        $bookingType->forceFill(['is_active' => ! $bookingType->is_active])->save();

        return back()->with('success', __('Updated.'));
    }

    /** Shared data for the create/edit wizard blades. */
    private function wizardData(?BookingType $type): array
    {
        $wsId = $this->wsId();
        $ws   = $wsId ? Workspace::find($wsId) : null;
        $currency = $type?->financial->currency ?? ($ws->currency ?: config('app.currency', 'USD'));

        // Active gateways for the resolved currency (empty when none configured).
        $gateways = collect();
        try {
            $gateways = app(PaymentGatewayManager::class)->activeGateways($currency)
                ->map(fn ($g) => ['slug' => $g->slug ?? $g->driver ?? (string) $g->id, 'label' => $g->title ?? $g->name ?? $g->slug]);
        } catch (\Throwable $e) {
        }

        // Templates the messaging step can pick — SCOPED to the booking channel
        // (WhatsApp: baileys/waba/twilio). Instagram / Facebook / Telegram
        // templates are a different channel and must NOT show here, or the
        // confirmation/reminder would try to send a wrong-channel template.
        $templates = WaTemplate::query()->forCurrentWorkspace()->orderByDesc('id')->get()
            ->filter(fn ($t) => ! in_array($t->engineKey(), ['instagram', 'facebook', 'telegram'], true))
            ->map(fn ($t) => ['id' => $t->id, 'name' => (string) $t->template_name, 'channel' => $t->engineKey(), 'body' => (string) $t->template_body])
            ->values();

        // Google calendars if connected (else the field is a plain text input).
        $calendars = [];
        try {
            if ($ws && $this->gcal->resolveCalendarId($ws) !== null) {
                $calendars = $this->gcal->listCalendars($ws);
            }
        } catch (\Throwable $e) {
        }

        return [
            'type'          => $type,
            'gateways'      => $gateways,
            'templates'     => $templates,
            'calendars'     => $calendars,
            'defaultCurrency' => $currency,
            'timezone'      => $ws->timezone ?: config('app.timezone'),
        ];
    }

    /** Validate + transactionally create/replace the type and all its children. */
    private function upsert(Request $request, ?BookingType $type): BookingType
    {
        $wsId = $this->wsId();
        abort_if($wsId <= 0, 422, 'No active workspace.');

        $data = $request->validate([
            'name'                  => ['required', 'string', 'max:191'],
            'slug'                  => ['nullable', 'string', 'max:191'],
            'description'           => ['nullable', 'string'],
            'location_type'         => ['nullable', 'in:address,virtual,phone'],
            'location_value'        => ['nullable', 'string'],
            'color'                 => ['nullable', 'string', 'max:9'],
            'duration_minutes'      => ['required', 'integer', 'min:5', 'max:1440'],
            'increment_minutes'     => ['nullable', 'integer', 'min:5', 'max:1440'],
            'buffer_before_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'buffer_after_minutes'  => ['nullable', 'integer', 'min:0', 'max:1440'],
            'min_notice_minutes'    => ['nullable', 'integer', 'min:0'],
            'max_advance_days'      => ['nullable', 'integer', 'min:1', 'max:365'],
            'max_per_day'           => ['nullable', 'integer', 'min:1', 'max:500'],
            'capacity'              => ['nullable', 'integer', 'min:1', 'max:500'],
            'timezone'              => ['nullable', 'string', 'max:64'],
            'is_active'             => ['nullable', 'boolean'],

            'fee_minor'             => ['nullable', 'integer', 'min:0'],
            'tax_pct'               => ['nullable', 'numeric', 'min:0', 'max:100'],
            'currency'              => ['nullable', 'string', 'size:3'],
            'gateway_slug'          => ['nullable', 'string', 'max:64'],
            'deposit_mode'          => ['nullable', 'in:none,partial,full'],
            'deposit_value_minor'   => ['nullable', 'integer', 'min:0'],
            'auto_send_link'        => ['nullable', 'boolean'],
            'cancel_fee_minor'      => ['nullable', 'integer', 'min:0'],
            'no_show_fee_minor'     => ['nullable', 'integer', 'min:0'],
            'cancel_window_minutes' => ['nullable', 'integer', 'min:0'],

            'calendar_id'           => ['nullable', 'string', 'max:191'],
            'create_meet'           => ['nullable', 'boolean'],
            'spreadsheet_id'        => ['nullable', 'string', 'max:191'],
            'sheet_range'           => ['nullable', 'string', 'max:64'],

            'intro_message'         => ['nullable', 'string'],
            'availability'          => ['nullable', 'array'],
            'overrides'             => ['nullable', 'array'],
            'templates'             => ['nullable', 'array'],
            'reminders'             => ['nullable', 'array'],
            'questions'             => ['nullable', 'array'],
        ]);

        // Reminder offsets must be strictly decreasing (mutually-exclusive bands).
        $rem = collect($data['reminders'] ?? [])
            ->map(fn ($r) => (int) ($r['offset_minutes'] ?? 0))->filter()->values();
        if ($rem->count() && $rem->count() !== $rem->unique()->count()) {
            abort(422, 'Reminder times must be distinct.');
        }

        return DB::transaction(function () use ($data, $type, $wsId) {
            $type ??= new BookingType(['workspace_id' => $wsId, 'user_id' => Auth::id()]);

            $slug = Str::slug(($data['slug'] ?? '') ?: $data['name']) ?: ('type-'.Str::random(6));
            // Ensure slug unique per workspace (excluding this row).
            $base = $slug; $n = 1;
            while (BookingType::where('workspace_id', $wsId)->where('slug', $slug)
                ->when($type->exists, fn ($q) => $q->where('id', '!=', $type->id))->exists()) {
                $slug = $base.'-'.(++$n);
            }

            $type->fill([
                'workspace_id'          => $wsId,
                'name'                  => $data['name'],
                'slug'                  => $slug,
                'description'           => $data['description'] ?? null,
                'location_type'         => $data['location_type'] ?? 'address',
                'location_value'        => $data['location_value'] ?? null,
                'color'                 => $data['color'] ?? null,
                'duration_minutes'      => (int) $data['duration_minutes'],
                'increment_minutes'     => (int) ($data['increment_minutes'] ?? $data['duration_minutes']),
                'buffer_before_minutes' => (int) ($data['buffer_before_minutes'] ?? 0),
                'buffer_after_minutes'  => (int) ($data['buffer_after_minutes'] ?? 0),
                'min_notice_minutes'    => (int) ($data['min_notice_minutes'] ?? 0),
                'max_advance_days'      => (int) ($data['max_advance_days'] ?? 30),
                'max_per_day'           => $data['max_per_day'] ?? null,
                'capacity'              => (int) ($data['capacity'] ?? 1),
                'timezone'              => $data['timezone'] ?? null,
                'intro_message'         => $data['intro_message'] ?? null,
                'is_active'             => (bool) ($data['is_active'] ?? true),
            ])->save();

            // Financials (currency seeds from workspace when blank).
            $type->financial()->updateOrCreate([], [
                'workspace_id'          => $wsId,
                'fee_minor'             => (int) ($data['fee_minor'] ?? 0),
                'tax_pct'               => (float) ($data['tax_pct'] ?? 0),
                'currency'              => strtoupper($data['currency'] ?? '') ?: (Workspace::find($wsId)->currency ?: null),
                'gateway_slug'          => $data['gateway_slug'] ?? null,
                'deposit_mode'          => $data['deposit_mode'] ?? 'none',
                'deposit_value_minor'   => (int) ($data['deposit_value_minor'] ?? 0),
                'auto_send_link'        => (bool) ($data['auto_send_link'] ?? true),
                'cancel_fee_minor'      => (int) ($data['cancel_fee_minor'] ?? 0),
                'no_show_fee_minor'     => (int) ($data['no_show_fee_minor'] ?? 0),
                'cancel_window_minutes' => (int) ($data['cancel_window_minutes'] ?? 1440),
            ]);

            // Google targeting.
            $type->integration()->updateOrCreate([], [
                'workspace_id'   => $wsId,
                'calendar_id'    => $data['calendar_id'] ?? null,
                'create_meet'    => (bool) ($data['create_meet'] ?? ($data['location_type'] ?? '') === 'virtual'),
                'spreadsheet_id' => $data['spreadsheet_id'] ?? null,
                'sheet_range'    => $data['sheet_range'] ?? 'Sheet1!A1',
            ]);

            // Children are replace-all (delete + recreate) so edits are clean.
            $type->availabilityRules()->delete();
            foreach ((array) ($data['availability'] ?? []) as $weekday => $intervals) {
                foreach ((array) $intervals as $iv) {
                    $from = $iv['from'] ?? ($iv['start'] ?? null);
                    $to   = $iv['to'] ?? ($iv['end'] ?? null);
                    if ($from && $to) {
                        $type->availabilityRules()->create([
                            'workspace_id' => $wsId, 'weekday' => (int) $weekday,
                            'start_time'   => substr((string) $from, 0, 5), 'end_time' => substr((string) $to, 0, 5),
                        ]);
                    }
                }
            }

            $type->overrides()->delete();
            foreach ((array) ($data['overrides'] ?? []) as $o) {
                if (empty($o['date'])) {
                    continue;
                }
                $type->overrides()->create([
                    'workspace_id' => $wsId, 'date' => $o['date'],
                    'is_closed'    => (bool) ($o['is_closed'] ?? false),
                    'start_time'   => $o['start_time'] ?? null, 'end_time' => $o['end_time'] ?? null,
                    'reason'       => $o['reason'] ?? null,
                ]);
            }

            $type->templates()->delete();
            foreach ((array) ($data['templates'] ?? []) as $t) {
                if (empty($t['event'])) {
                    continue;
                }
                $type->templates()->create([
                    'workspace_id'   => $wsId,
                    'event'          => $t['event'],
                    'channel'        => $t['channel'] ?? 'whatsapp',
                    'wa_template_id' => $t['wa_template_id'] ?? null,
                    'plain_body'     => $t['plain_body'] ?? null,
                    'variable_map'   => $t['variable_map'] ?? null,
                    'coupon_code'    => $t['coupon_code'] ?? null,
                    'coupon_id'      => $t['coupon_id'] ?? null,
                ]);
            }

            $type->reminders()->delete();
            $i = 0;
            foreach ((array) ($data['reminders'] ?? []) as $r) {
                $mins = (int) ($r['offset_minutes'] ?? 0);
                if ($mins <= 0 || $i >= 8) {
                    continue;
                }
                $type->reminders()->create([
                    'workspace_id' => $wsId, 'offset_index' => $i,
                    'offset_minutes' => $mins, 'label' => $r['label'] ?? null, 'is_active' => true,
                ]);
                $i++;
            }

            $type->questions()->delete();
            foreach (array_values((array) ($data['questions'] ?? [])) as $qi => $q) {
                if (empty($q['label'])) {
                    continue;
                }
                $type->questions()->create([
                    'workspace_id' => $wsId, 'label' => $q['label'],
                    'type'         => $q['type'] ?? 'text',
                    'options'      => $q['options'] ?? null,
                    'required'     => (bool) ($q['required'] ?? true),
                    'map_to_contact_field' => $q['map_to_contact_field'] ?? null,
                    'sort_order'   => $qi,
                ]);
            }

            return $type->fresh();
        });
    }
}
