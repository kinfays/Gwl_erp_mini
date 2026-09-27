// Chart.js for the pages that draw charts. Components load it through Livewire's @assets, so it
// is bundled and version-locked by package-lock.json instead of fetched from a CDN on every page.
import Chart from 'chart.js/auto';

window.Chart = Chart;
