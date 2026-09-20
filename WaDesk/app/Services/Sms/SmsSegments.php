<?php

namespace App\Services\Sms;

/**
 * How many SMS a message will actually cost.
 *
 * WHY THIS IS ITS OWN CLASS. Every other channel bills per message; SMS bills
 * per 160-character SEGMENT, and the limit collapses to 70 the moment one
 * non-Latin character appears. A single emoji in an otherwise short message
 * triples the bill, and nothing in the UI would explain why.
 *
 * The numbers come from the GSM 03.38 alphabet:
 *   GSM-7   160 chars alone, 153 per part when split (7-char concat header)
 *   UCS-2    70 chars alone,  67 per part
 * Seven GSM characters are stored as TWO (they live in an escape table) — so
 * "€€€" is nine characters of budget, not three.
 */
class SmsSegments
{
    public const GSM_SINGLE = 160;

    public const GSM_MULTI = 153;

    public const UCS2_SINGLE = 70;

    public const UCS2_MULTI = 67;

    /**
     * The GSM 03.38 basic alphabet. Written out rather than computed: it is not
     * a contiguous range, and getting it wrong silently misreports cost.
     *
     * The `\$` is escaped deliberately: PHP allows bytes >= 0x80 in identifiers,
     * so an unescaped "$¥" is parsed as VARIABLE INTERPOLATION.
     */
    private const GSM_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?"
        . "¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    /** Characters that cost two units because they need an escape byte. */
    private const GSM_EXTENDED = "^{}\\[~]|€";

    /**
     * Segment count, encoding and the cost-relevant details.
     *
     * @return array{segments:int, encoding:string, units:int, per_segment:int, remaining:int}
     */
    public static function measure(string $text): array
    {
        $gsm = self::isGsm7($text);

        // Units, not characters: an escaped GSM char occupies two.
        $units = $gsm ? self::gsmUnits($text) : mb_strlen($text, 'UTF-8');

        $single = $gsm ? self::GSM_SINGLE : self::UCS2_SINGLE;
        $multi  = $gsm ? self::GSM_MULTI  : self::UCS2_MULTI;

        if ($units === 0) {
            // An empty body is still one message to the carrier; reporting 0
            // would let a caller "send nothing" free.
            $segments = 1;
            $per      = $single;
        } elseif ($units <= $single) {
            $segments = 1;
            $per      = $single;
        } else {
            $segments = (int) ceil($units / $multi);
            $per      = $multi;
        }

        return [
            'segments'    => $segments,
            'encoding'    => $gsm ? 'GSM-7' : 'UCS-2',
            'units'       => $units,
            'per_segment' => $per,
            'remaining'   => max(0, ($segments * $per) - $units),
        ];
    }

    /** Does every character survive the GSM-7 alphabet? */
    public static function isGsm7(string $text): bool
    {
        $len = mb_strlen($text, 'UTF-8');

        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($text, $i, 1, 'UTF-8');
            if (! str_contains(self::GSM_BASIC, $ch) && ! str_contains(self::GSM_EXTENDED, $ch)) {
                return false;
            }
        }

        return true;
    }

    /** GSM length in BILLED units, counting escaped characters twice. */
    private static function gsmUnits(string $text): int
    {
        $units = 0;
        $len   = mb_strlen($text, 'UTF-8');

        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($text, $i, 1, 'UTF-8');
            $units += str_contains(self::GSM_EXTENDED, $ch) ? 2 : 1;
        }

        return $units;
    }

    /**
     * A one-line explanation for the operator, or '' when there is nothing
     * worth saying. Only speaks up when the message costs more than one segment.
     */
    public static function warning(string $text): string
    {
        $m = self::measure($text);

        if ($m['segments'] <= 1) {
            return '';
        }

        return $m['encoding'] === 'UCS-2'
            ? 'This message contains characters outside the GSM alphabet (emoji or non-Latin script), '
              . 'so it is limited to ' . self::UCS2_MULTI . ' characters per SMS and will be billed as '
              . $m['segments'] . ' messages.'
            : 'This message is ' . $m['units'] . ' characters and will be billed as ' . $m['segments'] . ' messages.';
    }
}
