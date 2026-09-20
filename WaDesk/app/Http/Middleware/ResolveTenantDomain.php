<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * White-label custom-domain tenancy (Phase 2). When the request host is a
 * workspace's VERIFIED custom_domain, this locks the ENTIRE request to that one
 * workspace:
 *   - shares the tenant with views (nav hides the workspace switcher, branding),
 *   - a logged-in user MUST be a member of that workspace — otherwise they are
 *     signed out on this domain and sent to its login (no cross-tenant access),
 *   - forces current_workspace_id IN MEMORY so every forCurrentWorkspace() scope
 *     reads the tenant, WITHOUT clobbering the user's saved workspace on the main
 *     platform domain (a custom domain has its own session cookie).
 *
 * No-ops for the platform's own host and any unrecognised host, so nothing
 * outside a verified custom domain is affected.
 */
class ResolveTenantDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        // FRESH INSTALL: /install runs before the .env has any DB credentials, so
        // touching the database here died with SQLSTATE[HY000] [1045] "Access
        // denied for user ''@'localhost'" and the installer could never load —
        // the wizard 500s on its very first page. `storage/installed` is the same
        // signal EnsureInstalled and EnsureTrialActive gate on.
        if (! is_file(storage_path('installed'))) {
            return $next($request);
        }

        $host = strtolower($request->getHost());

        // Cheap short-circuit for the platform's own host / empty host.
        $rootHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        if ($host === '' || $host === $rootHost) {
            return $next($request);
        }

        // Custom-domain routing is a convenience, never worth a 500. A missing
        // table (mid-migration), unreachable database, or half-written .env must
        // fall through to a normal request instead of taking every page down.
        try {
            $tenant = Workspace::query()
                ->where('custom_domain', $host)
                ->where('cname_verified', true)
                ->first();
        } catch (\Throwable $e) {
            return $next($request);
        }

        if (! $tenant) {
            return $next($request); // not a tenant domain — normal request
        }

        // Expose the tenant to the container + every view (switcher hide, branding).
        app()->instance('tenant.workspace', $tenant);
        View::share('tenantWorkspace', $tenant);
        View::share('isTenantDomain', true);

        $user = $request->user();
        if ($user) {
            $isMember = (int) $tenant->owner_user_id === (int) $user->id
                || $user->workspaces()->where('workspaces.id', $tenant->id)->exists();

            if (! $isMember) {
                // Someone else's white-label domain — never expose it to them.
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')->with(
                    'error',
                    __('This site belongs to a different workspace. Sign in with an account that has access.')
                );
            }

            // Lock this request to the tenant workspace. IN-MEMORY only: the saved
            // current_workspace_id (used on the platform domain) is left untouched
            // — this domain has its own session cookie anyway.
            if ((int) $user->current_workspace_id !== (int) $tenant->id) {
                $user->current_workspace_id = $tenant->id;
                $user->syncOriginalAttribute('current_workspace_id');
            }
        }

        return $next($request);
    }

    /** True when the current request is being served on a white-label tenant domain. */
    public static function isTenant(): bool
    {
        return app()->bound('tenant.workspace');
    }

    /** The tenant workspace for this request, or null on the platform domain. */
    public static function tenant(): ?Workspace
    {
        return app()->bound('tenant.workspace') ? app('tenant.workspace') : null;
    }
}
