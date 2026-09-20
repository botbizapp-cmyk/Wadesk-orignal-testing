import ApexCharts from 'apexcharts';
import { themeColor, themeAlpha } from '../theme-colors.js';

/**
 * /facebook/insights — 14-day Messenger activity chart. Data comes from
 * window.FB_INSIGHTS ({ labels, in, out }) which the Blade injects from the
 * controller (real InboxMessage counts scoped to the selected Page). Facebook
 * blue for received, brand green for sent.
 */
export default function init() {
    const d = window.FB_INSIGHTS || { labels: [], in: [], out: [] };
    const el = document.querySelector('#fb-activity-chart');
    if (!el) return;

    const FB_BLUE = '#1877F2';
    const baseFont = { fontFamily: 'Plus Jakarta Sans, system-ui, sans-serif' };
    const muted = themeAlpha('ink-900', 0.08);

    new ApexCharts(el, {
        chart: { type: 'area', height: 260, ...baseFont, toolbar: { show: false }, animations: { enabled: true } },
        colors: [FB_BLUE, themeColor('wa-green')],
        stroke: { curve: 'smooth', width: 2.5 },
        fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.28, opacityTo: 0.02, stops: [0, 90, 100] } },
        series: [
            { name: 'Received', data: (d.in || []).map((v) => Number(v) || 0) },
            { name: 'Sent',     data: (d.out || []).map((v) => Number(v) || 0) },
        ],
        dataLabels: { enabled: false },
        grid: { borderColor: muted, strokeDashArray: 4, padding: { left: 6, right: 6 } },
        xaxis: {
            categories: d.labels || [],
            labels: { style: { fontSize: '10px' }, rotate: 0, hideOverlappingLabels: true },
            tickAmount: 7,
            axisBorder: { show: false },
            axisTicks: { show: false },
        },
        yaxis: { labels: { formatter: (v) => Math.round(v), style: { fontSize: '10px' } }, min: 0, forceNiceScale: true },
        tooltip: { y: { formatter: (v) => (Number(v) || 0).toLocaleString() + ' msg' } },
        legend: { show: false },
        markers: { size: 0, hover: { size: 5 } },
    }).render();
}
