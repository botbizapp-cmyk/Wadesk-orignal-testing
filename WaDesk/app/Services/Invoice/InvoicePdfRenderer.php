<?php

namespace App\Services\Invoice;

use App\Models\Invoice;
use App\Models\InvoiceSetting;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Renders an Invoice to PDF bytes with vendored dompdf. Logo is read ONLY from a
 * media_storage() path and base64-embedded — dompdf's remote fetch stays OFF, so
 * a crafted logo URL can never make the server fetch an internal endpoint (§9 SSRF).
 */
class InvoicePdfRenderer
{
    public function render(Invoice $invoice): string
    {
        $settings = InvoiceSetting::forWorkspace((int) $invoice->workspace_id);
        $logoData = $this->embedImage((string) ($settings->logo_path ?? ''));
        // Signature is frozen on the invoice's seller snapshot at issue time.
        $sigPath  = (string) data_get($invoice->seller_snapshot_json, 'signature_path', '');
        $sigData  = $this->embedImage($sigPath);

        $html = view('invoices.pdf', [
            'invoice'  => $invoice->loadMissing('items', 'taxSummary'),
            'settings' => $settings,
            'logoData' => $logoData,
            'sigData'  => $sigData,
        ])->render();

        return Pdf::loadHTML($html)->setPaper('a4')->output();
    }

    /** Base64 data URI for an image at a media_storage path only. Null → none. SSRF-safe. */
    private function embedImage(string $path): ?string
    {
        $path = trim($path);
        if ($path === '' || preg_match('#^https?://#i', $path)) {
            return null; // never a remote URL — SSRF guard
        }
        try {
            $disk = media_storage();
            if (! $disk->exists($path)) {
                return null;
            }
            $bytes = $disk->get($path);
            $ext   = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'png';
            $mime  = $ext === 'svg' ? 'image/svg+xml' : ($ext === 'jpg' ? 'image/jpeg' : 'image/'.$ext);

            return 'data:'.$mime.';base64,'.base64_encode($bytes);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
