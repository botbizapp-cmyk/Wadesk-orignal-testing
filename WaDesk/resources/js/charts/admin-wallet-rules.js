import TomSelect from 'tom-select';
import 'tom-select/dist/css/tom-select.css';
import { Country } from 'country-state-city';

/**
 * Turns every <select.ts-country> on the wallet-rules page into a searchable
 * country picker (type the name, pick from the list) instead of making the
 * admin remember ISO codes. The blank option = "Any country (default)" and
 * submits an empty country_code, which the rate resolver treats as the global
 * default. data-value preselects the row's saved country.
 */
export default function () {
    const selects = document.querySelectorAll('select.ts-country');
    if (!selects.length) return;

    // Build the option list once (name → ISO-2), sorted by name.
    const countryOptions = Country.getAllCountries()
        .map((c) => ({ value: c.isoCode, text: c.name }))
        .sort((a, b) => a.text.localeCompare(b.text));

    selects.forEach((sel) => {
        const current = (sel.dataset.value || '').toUpperCase();
        new TomSelect(sel, {
            options: [{ value: '', text: 'Any country (default)' }, ...countryOptions],
            items: [current],            // preselect the saved country (or '' = default)
            maxItems: 1,
            create: false,
            allowEmptyOption: true,
            placeholder: 'Search country…',
            sortField: { field: 'text', direction: 'asc' },
        });
    });

    initMargin();
}

/**
 * Live margin readout on the per-country pricing table. For each row the
 * "You charge" input is now a money amount (platform currency), so:
 *   revenue = the "You charge" value directly
 *   margin  = revenue − Meta cost
 * Currency symbol comes from the table's data-* attribute.
 */
function initMargin() {
    const table = document.querySelector('[data-wallet-rates]');
    if (!table) return;

    const sym = table.dataset.currency || '';
    const fmt = (n) => sym + n.toFixed(4).replace(/\.?0+$/, (m) => (m.indexOf('.') === 0 ? '' : m));

    const recalc = (row) => {
        const revenue = parseFloat(row.querySelector('[data-credits]')?.value || '');
        const cost = parseFloat(row.querySelector('[data-metacost]')?.value || '');
        const cell = row.querySelector('[data-margin]');

        if (!cell) return;

        if (isNaN(cost) || isNaN(revenue)) {
            cell.textContent = '—';
            cell.className = 'px-3 py-2 text-[12px] font-mono text-ink-400';
            return;
        }
        const margin = revenue - cost;
        const pct = cost > 0 ? Math.round((margin / cost) * 100) : null;
        cell.textContent = fmt(margin) + (pct !== null ? `  (${pct >= 0 ? '+' : ''}${pct}%)` : '');
        cell.className =
            'px-3 py-2 text-[12px] font-mono ' +
            (margin > 0 ? 'text-wa-deep' : margin < 0 ? 'text-red-600' : 'text-ink-500');
    };

    table.querySelectorAll('[data-rate-row]').forEach((row) => {
        recalc(row);
        row.querySelectorAll('[data-credits], [data-metacost]').forEach((inp) => {
            inp.addEventListener('input', () => recalc(row));
        });
    });
}
