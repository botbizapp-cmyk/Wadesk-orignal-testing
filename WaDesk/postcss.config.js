import postcssRTLCSS from 'postcss-rtlcss';

// RTL support — post-processes the compiled Tailwind/CSS and adds `[dir="rtl"]`
// counterparts for every directional rule (left/right, margin-*, padding-*,
// text-align, float, positioning, etc.) into the SAME stylesheet. Because the
// user/admin layouts already stamp dir="rtl" on <html> for Arabic/Hebrew/Urdu,
// the flipped rules apply automatically — no separate sheet, no conditional
// loading. LTR stays the default. To keep a specific rule from flipping (phone
// numbers, code, brand marks), add a /* rtl:ignore */ control comment in the
// source CSS or set dir="ltr" on that element.
//
// REVERT: delete this file (and `npm rm postcss-rtlcss`), then rebuild.
export default {
    plugins: [
        postcssRTLCSS({
            // 'combined' keeps the original rule and appends a [dir] variant, so
            // one stylesheet serves both directions.
            mode: 'combined',
            // Prefix selectors with the [dir] attribute already on <html>.
            useCalc: false,
        }),
    ],
};
