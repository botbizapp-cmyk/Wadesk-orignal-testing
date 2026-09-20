<?php

namespace App\Http\Controllers;

use App\Models\AiCallLog;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves call recordings to the /call-logs UI. Node writes raw
 * 48 kHz mono Int16 LE PCM to `public/uploads/call-recordings/{call}_{side}.pcm`
 * during the call (so the disk write is hot-path-cheap). On first
 * playback we lazily wrap a WAV header + cache the result, so the
 * operator's <audio> tag gets a playable file in one round-trip.
 *
 * Workspace-scoped: a row's caller is enforced via the parent
 * AiCallLog's workspace_id — operators in other workspaces 404.
 */
class CallRecordingController extends Controller
{
    private const SAMPLE_RATE = 48000;                        // Node records raw PCM at 48 kHz
    private const CHANNELS    = 1;
    private const BITS        = 16;
    // Playback/storage output rate. Voice is fine at 16 kHz — this is a 3×
    // size cut vs the 48 kHz source before compression. DECIM = 48000/16000.
    private const OUTPUT_RATE = 16000;
    private const DECIM       = 3;

    /**
     * GET /call-logs/{id}/audio/{side}
     *  side: agent | user | mixed (mixed = both interleaved; falls back to agent if user missing)
     */
    public function audio(int $id, string $side)
    {
        $wsId = (int) (Auth::user()?->current_workspace_id ?? 0);
        $log = AiCallLog::where('workspace_id', $wsId)->findOrFail($id);

        if (!in_array($side, ['agent', 'user', 'mixed'], true)) {
            abort(404);
        }

        // Resolve the underlying call's meta_call_id — that's what
        // Node uses to name the .pcm files.
        $metaCallId = (string) ($log->twilio_call_sid ?: '');
        if ($metaCallId === '') {
            abort(404, 'no recording reference');
        }

        // Node writes the raw .pcm into the public web root, where it is
        // fetchable as a static file (bypassing this workspace-scoped
        // controller). On first authorized playback we migrate the recording
        // media into a NON-public storage dir and serve it only from there, so
        // the sensitive voice PII stops being reachable outside this method.
        $privateDir = storage_path('app/call-recordings');
        if (!is_dir($privateDir)) {
            @mkdir($privateDir, 0755, true);
        }

        $userPcm  = $this->relocateRecording($metaCallId . '_user.pcm', $privateDir);
        $agentPcm = $this->relocateRecording($metaCallId . '_agent.pcm', $privateDir);

        $base    = $privateDir . DIRECTORY_SEPARATOR . $metaCallId . '_' . $side;
        $wavPath = $base . '.wav';
        $mp3Path = $base . '.mp3';

        // Purge any legacy WAV that a previous version built inside the web root.
        $legacyWav = public_path('uploads/call-recordings') . DIRECTORY_SEPARATOR . $metaCallId . '_' . $side . '.wav';
        if (is_file($legacyWav)) @unlink($legacyWav);

        // If a compact file was already built for this side we serve it and do
        // NOT gate on the raw PCM — the PCM may already have been deleted (it is
        // the real storage hog and we drop it after the first playback).
        if (!is_file($mp3Path) && !is_file($wavPath)) {
            if ($side === 'user'  && !is_file($userPcm))                         abort(404, 'user recording missing');
            if ($side === 'agent' && !is_file($agentPcm))                        abort(404, 'agent recording missing');
            if ($side === 'mixed' && !is_file($userPcm) && !is_file($agentPcm))  abort(404, 'no recording on disk');
            try {
                // Build a compact 16 kHz WAV for EVERY side, then drop the raw
                // 48 kHz PCM — kept forever at 48 kHz, that is what ran storage
                // into GBs (~5.8 MB/min per side).
                $this->buildAllSides($metaCallId, $privateDir, $userPcm, $agentPcm);
            } catch (\Throwable $e) {
                Log::warning('[REC] wav build failed: ' . $e->getMessage());
                abort(500, 'recording build failed');
            }
            @unlink($userPcm);
            @unlink($agentPcm);
        }

        // Best-effort MP3 (ffmpeg, mono ~32 kbps) — a fraction of the WAV size,
        // landing typical calls in the 10–15 MB range. Falls back to WAV when
        // ffmpeg isn't installed (dev), so playback always works.
        if (!is_file($mp3Path) && is_file($wavPath)) {
            $this->toMp3($wavPath, $mp3Path);
        }

        $serve = is_file($mp3Path) ? $mp3Path : $wavPath;
        if (!is_file($serve)) abort(404, 'recording missing');
        $isMp3 = str_ends_with($serve, '.mp3');
        return response()->file($serve, [
            'Content-Type'        => $isMp3 ? 'audio/mpeg' : 'audio/wav',
            'Content-Disposition' => 'inline; filename="' . $metaCallId . '_' . $side . ($isMp3 ? '.mp3' : '.wav') . '"',
            'Cache-Control'       => 'private, max-age=86400',
        ]);
    }

    /**
     * Return the private path for a recording file, migrating any copy Node
     * left in the public web root into the non-public storage dir first. This
     * keeps voice PII out of the docroot so it can only be reached through this
     * workspace-scoped controller. Idempotent and best-effort.
     */
    private function relocateRecording(string $filename, string $privateDir): string
    {
        $private = $privateDir . DIRECTORY_SEPARATOR . $filename;
        $public  = public_path('uploads/call-recordings') . DIRECTORY_SEPARATOR . $filename;

        if (is_file($public)) {
            // Prefer @rename (atomic on same volume); if a private copy already
            // exists just drop the exposed public one.
            if (is_file($private)) {
                @unlink($public);
            } elseif (!@rename($public, $private)) {
                // Cross-device or locked file — fall back to copy + unlink.
                if (@copy($public, $private)) {
                    @unlink($public);
                }
            }
        }

        return $private;
    }

    /**
     * Stream a raw 48 kHz PCM file into a compact 16 kHz WAV — decimating by
     * DECIM (averaging each group so speech stays clean) so long calls don't
     * balloon storage or OOM the process. Chunk is a whole number of DECIM
     * groups so grouping never straddles a read boundary.
     */
    private function writeWav(string $pcmPath, string $wavPath): void
    {
        $in = @fopen($pcmPath, 'rb');
        if (!$in) throw new \RuntimeException('read pcm failed');
        $tmp = $wavPath . '.tmp';
        $out = fopen($tmp, 'wb');
        fwrite($out, str_repeat("\x00", 44)); // header placeholder

        $CHUNK = 8190 * 2; // 8190 samples (divisible by DECIM=3) × 2 bytes
        while (($buf = fread($in, $CHUNK)) !== false && $buf !== '') {
            if (strlen($buf) % 2 === 1) $buf = substr($buf, 0, -1);
            if ($buf === '') break;
            $dec = $this->decimate(unpack('s*', $buf));
            if ($dec) fwrite($out, pack('s*', ...$dec));
        }
        fclose($in);

        $dataLen = ftell($out) - 44;
        fseek($out, 0);
        fwrite($out, $this->wavHeader($dataLen));
        fclose($out);
        @rename($tmp, $wavPath);
    }

    /** Average consecutive groups of DECIM 16-bit samples → one output sample
     *  (crude low-pass + downsample). Trailing <DECIM samples are dropped. */
    private function decimate(array $samples): array
    {
        $vals = array_values($samples); // normalise 1-indexed (unpack) or 0-indexed
        $out = [];
        $n = count($vals);
        for ($i = 0; $i + self::DECIM <= $n; $i += self::DECIM) {
            $sum = 0;
            for ($j = 0; $j < self::DECIM; $j++) $sum += $vals[$i + $j];
            $out[] = intdiv($sum, self::DECIM);
        }
        return $out;
    }

    /** Build the WAV for every side that has source PCM, so the raw PCM can be
     *  deleted afterwards. Missing/failed sides are skipped, never fatal. */
    private function buildAllSides(string $metaCallId, string $privateDir, string $userPcm, string $agentPcm): void
    {
        $mk = function (string $side) use ($privateDir, $metaCallId, $userPcm, $agentPcm) {
            $wav = $privateDir . DIRECTORY_SEPARATOR . $metaCallId . '_' . $side . '.wav';
            $mp3 = $privateDir . DIRECTORY_SEPARATOR . $metaCallId . '_' . $side . '.mp3';
            if (is_file($wav) || is_file($mp3)) return;
            try {
                if ($side === 'mixed') {
                    if (is_file($userPcm) || is_file($agentPcm)) $this->writeMixedWav($userPcm, $agentPcm, $wav);
                } else {
                    $p = $side === 'user' ? $userPcm : $agentPcm;
                    if (is_file($p)) $this->writeWav($p, $wav);
                }
            } catch (\Throwable $e) {
                Log::warning('[REC] build ' . $side . ' failed: ' . $e->getMessage());
            }
        };
        $mk('user'); $mk('agent'); $mk('mixed');
    }

    /** Best-effort ffmpeg WAV→MP3 (mono 32 kbps). Deletes the WAV on success.
     *  No-op (returns null) when ffmpeg / exec() aren't available. */
    private function toMp3(string $wavPath, string $mp3Path): ?string
    {
        $bin = $this->ffmpegBin();
        if (!$bin) return null;
        try {
            $cmd = escapeshellarg($bin) . ' -y -loglevel error -i ' . escapeshellarg($wavPath)
                 . ' -ac 1 -b:a 32k ' . escapeshellarg($mp3Path) . ' 2>&1';
            @exec($cmd, $o, $code);
            if ($code === 0 && is_file($mp3Path) && filesize($mp3Path) > 0) {
                @unlink($wavPath);
                return $mp3Path;
            }
        } catch (\Throwable $e) {
            Log::warning('[REC] mp3 encode failed: ' . $e->getMessage());
        }
        return null;
    }

    /** Resolve an ffmpeg binary once, or null if unavailable/exec() disabled. */
    private function ffmpegBin(): ?string
    {
        static $bin = false;
        if ($bin !== false) return $bin;
        if (!function_exists('exec')) return $bin = null;
        foreach (['ffmpeg', '/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg'] as $c) {
            try {
                @exec(escapeshellarg($c) . ' -version 2>&1', $o, $r);
                if ($r === 0) return $bin = $c;
            } catch (\Throwable $e) { /* try next */ }
        }
        return $bin = null;
    }

    /**
     * Sample-by-sample mix of user + agent PCM. Both sides are
     * 16-bit signed mono @ 48 kHz, so the average of each pair is
     * the mixed sample. Pads the shorter file with silence so the
     * timeline lines up with `started_at + duration_seconds`.
     */
    private function writeMixedWav(string $userPcm, string $agentPcm, string $wavPath): void
    {
        $uSize = is_file($userPcm)  ? (int) filesize($userPcm)  : 0;
        $aSize = is_file($agentPcm) ? (int) filesize($agentPcm) : 0;
        $len   = max($uSize, $aSize);
        if ($len === 0) throw new \RuntimeException('no pcm to mix');

        // Cap to ~30 min of 48 kHz mono 16-bit so a corrupt/oversized side
        // can't make the mix hang or OOM (that timeout is why the "Full call"
        // player showed 0:00). Even byte count for clean 16-bit samples.
        $maxBytes = 30 * 60 * self::SAMPLE_RATE * (self::BITS / 8);
        $len = (int) min($len, $maxBytes);
        if ($len % 2 === 1) $len--;

        $uf = $uSize ? fopen($userPcm, 'rb')  : null;
        $af = $aSize ? fopen($agentPcm, 'rb') : null;

        // STREAM the mix in 8 KB chunks → bounded memory (vs. loading whole
        // files + a per-sample substr/unpack loop, which timed out on long
        // recordings). Header written last once we know the data length.
        $tmp = $wavPath . '.tmp';
        $out = fopen($tmp, 'wb');
        fwrite($out, str_repeat("\x00", 44)); // header placeholder

        $CHUNK = 8190 * 2; // 8190 samples (divisible by DECIM=3) × 2 bytes
        $done = 0;
        while ($done < $len) {
            $want = (int) min($CHUNK, $len - $done);
            $ub = $uf ? (string) fread($uf, $want) : '';
            $ab = $af ? (string) fread($af, $want) : '';
            $ub = str_pad(substr($ub, 0, $want), $want, "\x00");
            $ab = str_pad(substr($ab, 0, $want), $want, "\x00");
            $us = unpack('s*', $ub);
            $as = unpack('s*', $ab);
            $n  = min(count($us), count($as));
            $mix = [];
            for ($i = 1; $i <= $n; $i++) {
                $m = $us[$i] + $as[$i];
                $mix[] = $m < -32768 ? -32768 : ($m > 32767 ? 32767 : $m);
            }
            // Downsample the mixed 48 kHz chunk → 16 kHz before writing.
            $mix = $this->decimate($mix);
            if ($mix) fwrite($out, pack('s*', ...$mix));
            $done += $want;
        }
        if ($uf) fclose($uf);
        if ($af) fclose($af);

        $dataLen = ftell($out) - 44;
        fseek($out, 0);
        fwrite($out, $this->wavHeader($dataLen)); // backfill real header
        fclose($out);
        @rename($tmp, $wavPath);
    }

    /** Minimal 44-byte WAV header — OUTPUT rate (16 kHz), since the writers
     *  decimate the 48 kHz source down before it reaches here. */
    private function wavHeader(int $dataLen): string
    {
        $byteRate   = self::OUTPUT_RATE * self::CHANNELS * (self::BITS / 8);
        $blockAlign = self::CHANNELS * (self::BITS / 8);
        return 'RIFF'
            . pack('V', 36 + $dataLen)
            . 'WAVE'
            . 'fmt '
            . pack('V', 16)
            . pack('v', 1)                // PCM
            . pack('v', self::CHANNELS)
            . pack('V', self::OUTPUT_RATE)
            . pack('V', $byteRate)
            . pack('v', $blockAlign)
            . pack('v', self::BITS)
            . 'data'
            . pack('V', $dataLen);
    }
}
