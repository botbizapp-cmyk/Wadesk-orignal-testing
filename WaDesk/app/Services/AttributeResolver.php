<?php

namespace App\Services;

use App\Models\Attribute;

/**
 * Resolves {{1}}, {{2}}, … positional placeholders into the
 * workspace's attribute values.
 *
 * Frontend flow (see resources/js/attribute-picker.js):
 *   1. Operator types `/` in the composer → picker opens with workspace
 *      attributes.
 *   2. Picking "Company" inserts `{{1}}` into the textarea AND records
 *      `{"1":"promo_key"}` into the hidden `data-attr-map` field.
 *   3. The reply / voice / media endpoint receives BOTH the body and
 *      the JSON map.
 *   4. This service walks the map, looks up each attribute by `key`
 *      under the current workspace, and substitutes the value.
 *
 * Also supports a "named" fallback — if the body has `{{promo_key}}`
 * directly (no positional indirection), we'll resolve that too. Makes
 * the helper safe to call even from code paths that don't carry a map.
 */
class AttributeResolver
{
    /**
     * @param  string $body         The raw message text with placeholders.
     * @param  array  $variableMap  ["1" => "promo_key", "2" => "order_id"]
     * @param  int    $workspaceId
     * @param  \App\Models\Contact|null $contact  When given, the recipient's OWN
     *         fields ({{name}}, {{mobile}}, …) and per-contact custom_attributes
     *         take precedence over the workspace-wide attribute default — so
     *         placeholders personalize per recipient instead of resolving to the
     *         same value (or blank) for everyone.
     * @return string               Body with placeholders resolved.
     */
    public function resolve(string $body, array $variableMap, int $workspaceId, ?\App\Models\Contact $contact = null): string
    {
        if ($body === '') return $body;
        if (!str_contains($body, '{{')) return $body; // fast path

        // Normalize shape — templates persist variable_map as the NESTED
        // ['header'=>[{num,key}], 'body'=>[{num,key}]] form, but this resolver
        // works on a FLAT {slot => key} map. Flatten it (a flat map passes
        // through untouched) so positional {{1}} resolves on the campaign path,
        // not only on the broadcast varsForRecipient path.
        $variableMap = $this->normalizeVariableMap($variableMap);

        // Collect every attribute key we might need — from the positional
        // map, plus any direct {{key}} matches the body uses. Single DB
        // round-trip for all of them.
        $keysFromMap = array_values(array_unique(array_filter(array_map(
            fn ($k) => is_string($k) ? trim($k) : null,
            $variableMap
        ))));
        $namedMatches = [];
        if (preg_match_all('/\{\{\s*([a-zA-Z_][\w.-]*)\s*\}\}/u', $body, $m)) {
            $namedMatches = array_unique($m[1]);
        }
        $allKeys = array_values(array_unique(array_merge($keysFromMap, $namedMatches)));
        if (empty($allKeys)) return $body;

        $values = Attribute::query()
            ->forWorkspace($workspaceId)
            ->whereIn('attribute_key', $allKeys)
            ->get(['attribute_key', 'attribute_value'])
            ->mapWithKeys(fn ($a) => [$a->attribute_key => (string) $a->attribute_value])
            ->all();

        // Per-contact overrides win over the workspace-wide default. Only
        // non-empty values override, so a blank contact field falls back to the
        // workspace attribute rather than wiping the placeholder.
        if ($contact) {
            foreach ($this->contactValues($contact) as $k => $v) {
                if ($v !== '' && $v !== null) $values[$k] = (string) $v;
            }
        }

        // 1) Positional resolution — {{N}} → variableMap[N] → values[key]
        $body = preg_replace_callback('/\{\{\s*(\d+)\s*\}\}/u', function ($m) use ($variableMap, $values) {
            $slot = $m[1];
            $key  = $variableMap[$slot] ?? $variableMap[(int) $slot] ?? null;
            if (!$key) return $m[0]; // unmapped — leave literal so the operator notices
            return $values[$key] ?? $m[0];
        }, $body);

        // 2) Named resolution — {{order_id}} → values['order_id']
        $body = preg_replace_callback('/\{\{\s*([a-zA-Z_][\w.-]*)\s*\}\}/u', function ($m) use ($values) {
            $key = $m[1];
            return $values[$key] ?? $m[0];
        }, $body);

        return $body;
    }

    /**
     * The recipient's own field values, keyed so both {{name}} and a positional
     * {{1}} mapped to "name" resolve to this contact. Built-in fields plus the
     * contact's custom_attributes (where CSV-imported columns live).
     *
     * @return array<string,string>
     */
    private function contactValues(\App\Models\Contact $contact): array
    {
        $out = [
            'name'         => (string) ($contact->name ?? ''),
            'first_name'   => (string) ($contact->first_name ?? ''),
            'last_name'    => (string) ($contact->last_name ?? ''),
            'mobile'       => (string) ($contact->mobile ?? ''),
            'phone'        => (string) ($contact->mobile ?? ''),
            'email'        => (string) ($contact->email ?? ''),
            'country_code' => (string) ($contact->country_code ?? ''),
            'language'     => (string) ($contact->language ?? ''),
        ];
        if ($out['name'] === '') {
            $out['name'] = trim($out['first_name'] . ' ' . $out['last_name']);
        }
        $custom = is_array($contact->custom_attributes ?? null) ? $contact->custom_attributes : [];
        foreach ($custom as $k => $v) {
            if (is_scalar($v)) $out[(string) $k] = (string) $v;
        }
        return $out;
    }

    /**
     * Accept BOTH variable_map shapes:
     *   - flat        ["1" => "promo_key", "2" => "order_id"]      (used here)
     *   - nested/stored ['header'=>[{num,key}], 'body'=>[{num,key}]]  (templates)
     * Flattens the nested form to {slot => key}; a flat map returns unchanged.
     * On a header/body slot-number collision, body wins (it's resolved last).
     */
    private function normalizeVariableMap(array $map): array
    {
        if (!isset($map['header']) && !isset($map['body'])) {
            return $map; // already flat
        }
        $flat = [];
        foreach (['header', 'body'] as $section) {
            if (empty($map[$section]) || !is_array($map[$section])) continue;
            foreach ($map[$section] as $entry) {
                if (is_array($entry) && isset($entry['num'], $entry['key']) && $entry['key'] !== '') {
                    $flat[(string) $entry['num']] = (string) $entry['key'];
                }
            }
        }
        return $flat;
    }
}
