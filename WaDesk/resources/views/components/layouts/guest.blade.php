@props([
    'title' => null,
    'page' => null,
])
@php $title = $title ?? brand_name(); @endphp

<!DOCTYPE html>
{{-- `dir` is REQUIRED, not optional. postcss-rtlcss runs in `combined` mode, so
     the compiled stylesheet carries ~350 directional rules scoped to [dir=ltr]
     and ~350 to [dir=rtl]. With no dir attribute on <html>, NEITHER set matches
     and every one of those rules is silently dropped — which is what left the
     sign-in page unstyled while user/admin/frontend (which all set dir) looked
     fine. Keep this in sync with the other layouts. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    dir="{{ \App\Support\LocaleSettings::directionFor(app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Sub-folder base path (e.g. /public) so client-side AJAX honours the deploy location under a sub-directory. --}}
    <meta name="app-base" content="{{ wd_base() }}">
    @php $defCountry = app_default_country(); @endphp
    <meta name="default-country-code" content="{{ $defCountry['code'] }}">
    <meta name="default-country-iso"  content="{{ $defCountry['iso'] }}">
    @php $__brandName = (string) brand_name(); @endphp
    <title>{{ $title }} — {{ $__brandName }}</title>
    {{-- SEO meta block — single source at /admin/settings/seo. The
 guest layout is what marketing/login pages render with, so
 this is the surface search engines see most. --}}
    @include('partials.seo-meta', ['seoOverrides' => ['title' => $title . ' — ' . $__brandName]])
    @include('partials.pwa-meta')
    @include('partials.site-analytics')
    <x-theme-bootstrap />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.app-font')
</head>

<body @if ($page) data-page="{{ $page }}" @endif
    class="min-h-screen font-sans antialiased bg-paper-50 text-ink-900 overflow-x-clip">
    {{ $slot }}

    {{-- GDPR cookie consent — applies to login, marketing, public pages. --}}
    @include('partials.cookie-consent')

    @stack('scripts')

    {{-- On-page auth editor — injected ONLY for an authed platform admin who
         opened this page with ?fc_edit=1 (see fc_editing()). Lets them click
         text to edit it and set the side image/video. Visitors never get it. --}}
    @if (fc_editing())
        <script src="{{ asset('js/auth-editor-bridge.js') }}"
            data-save-text="{{ route('admin.settings.auth-pages.inline') }}"
            data-save-media="{{ route('admin.settings.auth-pages.inline-media') }}"
            data-clear-media="{{ route('admin.settings.auth-pages.inline-media-clear') }}"
            data-csrf="{{ csrf_token() }}"></script>
    @endif
</body>

</html>
