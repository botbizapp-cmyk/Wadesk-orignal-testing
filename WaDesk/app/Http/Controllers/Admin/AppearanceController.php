<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\Request;

/**
 * Global dashboard appearance — lets the platform admin recolour EVERY theme
 * token (Tailwind v4 @theme vars) across BOTH the user + admin dashboards.
 * Values save to SystemSetting `theme.color.*` and are injected live by
 * theme_css() into each layout's <head> (no rebuild). Empty = shipped default.
 */
class AppearanceController extends Controller
{
    public function index()
    {
        $palette = theme_palette();
        $values  = [];
        foreach (array_keys($palette) as $k) {
            $values[$k] = theme_color($k);
        }

        $metrics       = theme_metrics();
        $metricValues  = [];
        foreach (array_keys($metrics) as $k) {
            $metricValues[$k] = theme_metric($k);
        }

        $userLayout = \App\Support\UserNav::layout(); // 'topbar' | 'sidebar'
        $sidebarColor = (string) SystemSetting::get('user_sidebar_color', '');       // '' = default dark
        $sidebarTextColor = (string) SystemSetting::get('user_sidebar_text_color', ''); // '' = auto-contrast
        $sidebarAccentColor = (string) SystemSetting::get('user_sidebar_accent_color', ''); // '' = default green

        return view('admin.settings.appearance', compact('palette', 'values', 'metrics', 'metricValues', 'userLayout', 'sidebarColor', 'sidebarTextColor', 'sidebarAccentColor'));
    }

    public function update(Request $request)
    {
        $colors = (array) $request->input('colors', []);
        foreach (array_keys(theme_palette()) as $k) {
            $val = trim((string) ($colors[$k] ?? ''));
            $ok  = $val !== '' && preg_match('/^#[0-9A-Fa-f]{3,8}$/', $val);
            SystemSetting::set('theme.color.' . $k, $ok ? $val : '', 'string', 'Dashboard theme colour override');
        }

        // Metrics are clamped to the range declared in theme_metrics() rather
        // than trusted from the request — the sliders enforce min/max in the
        // browser, but a hand-crafted POST could otherwise set zoom to 0 and
        // leave the admin with an unusable, un-fixable dashboard.
        $sliders = (array) $request->input('metrics', []);
        foreach (theme_metrics() as $k => $meta) {
            $raw = $sliders[$k] ?? null;
            if ($raw === null || $raw === '' || ! is_numeric($raw)) {
                continue;
            }
            $clamped = max((int) $meta[2], min((int) $meta[3], (int) $raw));
            SystemSetting::set('theme.metric.' . $k, (string) $clamped, 'string', 'Dashboard appearance metric');
        }

        // User dashboard shell — classic top bar (default) vs the admin-style
        // left sidebar. Global for all user accounts.
        $layout = $request->input('user_dashboard_layout');
        if (in_array($layout, ['topbar', 'sidebar'], true)) {
            SystemSetting::set('user_dashboard_layout', $layout, 'string', 'User dashboard shell (topbar|sidebar)');
        }

        // Sidebar rail colours (sidebar layout). Blank = default / auto.
        foreach ([
            'user_sidebar_color'        => 'User sidebar rail background (hex, blank=default)',
            'user_sidebar_text_color'   => 'User sidebar text colour (hex, blank=auto-contrast)',
            'user_sidebar_accent_color' => 'User sidebar accent colour (hex, blank=default green)',
        ] as $key => $desc) {
            $raw = trim((string) $request->input($key, ''));
            // A hidden "_use" flag lets the reset button clear a value even though
            // <input type=color> always posts a hex — without it, reset couldn't blank it.
            if ($request->input($key . '_use') === '0') {
                $raw = '';
            }
            $val = ($raw !== '' && preg_match('/^#[0-9A-Fa-f]{6}$/', $raw)) ? $raw : '';
            SystemSetting::set($key, $val, 'string', $desc);
        }

        return back()->with('status', __('Appearance saved — the whole dashboard has been updated.'));
    }

    public function reset(Request $request)
    {
        foreach (array_keys(theme_palette()) as $k) {
            SystemSetting::set('theme.color.' . $k, '', 'string', 'Dashboard theme colour override (reset)');
        }
        foreach (theme_metrics() as $k => $meta) {
            SystemSetting::set('theme.metric.' . $k, (string) (int) $meta[1], 'string', 'Dashboard appearance metric (reset)');
        }
        return back()->with('status', __('Appearance reset to the shipped defaults.'));
    }
}
