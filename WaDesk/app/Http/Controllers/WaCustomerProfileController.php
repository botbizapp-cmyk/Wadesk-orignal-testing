<?php

namespace App\Http\Controllers;

use App\Models\WaCustomerProfile;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Shop dashboard → Customers. Merchant pre-sets a customer's Name / Company /
 * delivery Address by phone, so the WhatsApp ordering flow shows it automatically
 * and the customer just replies YES (no re-typing). Read by
 * OrderingService::shippingFor().
 */
class WaCustomerProfileController extends Controller
{
    public function index(Request $request): View
    {
        $wsId = (int) Auth::user()->current_workspace_id;
        $q    = trim((string) $request->string('q')->toString());
        // Escape LIKE metacharacters so an attacker-supplied % or _ can't
        // broaden matching (defense-in-depth — mirrors DealsController).
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q);

        $rows = WaCustomerProfile::forWorkspace($wsId)
            ->when($q !== '', function ($w) use ($like) {
                $w->where(function ($x) use ($like) {
                    $x->where('name', 'like', "%{$like}%")
                      ->orWhere('phone', 'like', "%{$like}%")
                      ->orWhere('company', 'like', "%{$like}%");
                });
            })
            ->orderBy('name')->orderBy('phone')
            ->paginate(20)->withQueryString();

        return view('user.store.customers.index', compact('rows', 'q'));
    }

    /** Create OR update (keyed by phone) — one form does both. */
    public function store(Request $request): RedirectResponse
    {
        $wsId = (int) Auth::user()->current_workspace_id;
        $data = $request->validate([
            'phone'   => 'required|string|max:32',
            'name'    => 'nullable|string|max:191',
            'company' => 'nullable|string|max:191',
            'address' => 'nullable|string|max:2000',
        ]);

        $digits = WaCustomerProfile::digits($data['phone']);
        if ($digits === '') {
            return back()->with('error', __('Enter a valid phone number (with country code).'));
        }

        WaCustomerProfile::updateOrCreate(
            ['workspace_id' => $wsId, 'phone' => $digits],
            [
                'name'    => trim((string) ($data['name'] ?? '')) ?: null,
                'company' => trim((string) ($data['company'] ?? '')) ?: null,
                'address' => trim((string) ($data['address'] ?? '')) ?: null,
            ]
        );

        return back()->with('success', __('Customer saved. Their address will auto-fill when they order on WhatsApp.'));
    }

    public function destroy(int $id): RedirectResponse
    {
        $wsId    = (int) Auth::user()->current_workspace_id;
        $profile = WaCustomerProfile::forWorkspace($wsId)->whereKey($id)->first();
        if (!$profile) {
            return back()->with('success', __('Customer removed.'));
        }

        // Cascade: remove this shopper's orders too, so the Orders list stops
        // showing a customer we just deleted. Orders link to a customer only by
        // phone (no FK). The profile stores digits-only, but WaOrder.customer_phone
        // may carry formatting (+, spaces) — pre-filter on the trailing digits in
        // SQL, then confirm an exact digits match in PHP before deleting. Order
        // line items are removed automatically (wa_order_items.order_id cascade).
        $digits = WaCustomerProfile::digits((string) $profile->phone);
        if ($digits !== '') {
            $tail = substr($digits, -9);
            \App\Models\WaOrder::forWorkspace($wsId)
                ->where('customer_phone', 'like', "%{$tail}")
                ->get()
                ->each(function (\App\Models\WaOrder $order) use ($digits) {
                    if (WaCustomerProfile::digits((string) $order->customer_phone) === $digits) {
                        $order->delete();
                    }
                });
        }

        $profile->delete();
        return back()->with('success', __('Customer and their orders removed.'));
    }
}
