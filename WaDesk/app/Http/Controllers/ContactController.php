<?php

namespace App\Http\Controllers;

use App\Models\ContactMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * Handles public /contact form submissions: validate → store → notify the
 * site's support inbox. The form itself is the marketing contact page
 * served by FrontendController.
 */
class ContactController extends Controller
{
    public function submit(Request $request): RedirectResponse
    {
        // Honeypot — bots fill hidden fields. Pretend success, store nothing.
        if (filled($request->input('website'))) {
            return back()->with('contact_status', 'success');
        }

        // Spam protection — reCAPTCHA. Verify only runs when an admin has
        // enabled + configured reCAPTCHA (RecaptchaService::verify returns true
        // when it's off), so the form keeps working with no config. Reads the
        // v2 checkbox token OR the v3 hidden token, same as the auth forms.
        if (! app(\App\Services\RecaptchaService::class)->verify(
            $request->input('g-recaptcha-response') ?: $request->input('recaptcha_token'),
            $request->ip(),
            'contact',
        )) {
            return back()
                ->withInput()
                ->with('contact_status', 'error')
                ->withErrors(['captcha' => __('Please complete the reCAPTCHA and try again.')])
                ->withFragment('contact-form');
        }

        $data = $request->validate([
            'name'    => ['required', 'string', 'max:120'],
            'email'   => ['required', 'email', 'max:190'],
            'company' => ['nullable', 'string', 'max:160'],
            'topic'   => ['nullable', 'in:sales,support,partnership,other'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $msg = ContactMessage::create($data + [
            'ip'         => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);

        // Notify the support inbox. Wrapped so a mail outage never breaks
        // the visitor's submission — it's already stored.
        try {
            $to = site_info('email_support', config('mail.from.address'));
            if ($to) {
                $brand = \App\Models\SystemSetting::get('app_name', config('app.name', 'WaDesk'));
                Mail::raw(
                    "New contact form submission on {$brand}\n\n"
                    . "Name:    {$msg->name}\n"
                    . "Email:   {$msg->email}\n"
                    . "Company: " . ($msg->company ?: '—') . "\n"
                    . "Topic:   " . ($msg->topic ?: '—') . "\n\n"
                    . "Message:\n{$msg->message}\n",
                    function ($m) use ($to, $msg, $brand) {
                        $m->to($to)
                          ->replyTo($msg->email, $msg->name)
                          ->subject("[{$brand}] Contact: " . ($msg->topic ?: 'enquiry') . " from {$msg->name}");
                    }
                );
            }
        } catch (\Throwable $e) {
            error_log('[Contact] notify failed: ' . $e->getMessage());
        }

        return back()->with('contact_status', 'success')->withFragment('contact-form');
    }
}
