/* Tableau de bord : graphiques ApexCharts alimentés par les attributs data-chart (aucune donnée en dur). */
document.addEventListener('DOMContentLoaded', function () {
    const FONT = 'Plus Jakarta Sans, Segoe UI, system-ui, sans-serif';
    const PALETTE = ['#072F1F', '#B4F105', '#F97316', '#1A3E30', '#6C7E75'];

    const readChart = function (element) {
        try {
            return JSON.parse(element.dataset.chart || '{}');
        } catch (error) {
            return {};
        }
    };

    const formatNumber = function (value) {
        return new Intl.NumberFormat('fr-FR').format(value);
    };

    const shortDay = function (iso) {
        const parts = iso.split('-');
        return parts[2] + '/' + parts[1];
    };

    // ---- Encaissements par jour (barres) -------------------------------
    const revenue = document.querySelector('#revenue-chart');
    if (revenue) {
        const data = readChart(revenue);
        new ApexCharts(revenue, {
            series: [{ name: 'Encaissé', data: data.values || [] }],
            chart: { type: 'bar', height: 240, toolbar: { show: false }, zoom: { enabled: false }, fontFamily: FONT },
            colors: [PALETTE[0]],
            plotOptions: { bar: { columnWidth: '55%', borderRadius: 3 } },
            dataLabels: { enabled: false },
            states: { hover: { filter: { type: 'none' } } },
            grid: { borderColor: '#E9EFEF', strokeDashArray: 4, xaxis: { lines: { show: false } } },
            xaxis: {
                categories: (data.days || []).map(shortDay),
                tickAmount: 10,
                labels: { rotate: 0, style: { colors: '#6C7E75', fontSize: '11px', fontWeight: 500 } },
                axisBorder: { show: false },
                axisTicks: { show: false },
            },
            yaxis: { labels: { style: { colors: '#6C7E75', fontSize: '11px' }, formatter: formatNumber } },
            tooltip: { theme: 'dark', y: { formatter: function (value) { return formatNumber(value) + ' FCFA'; } } },
        }).render();
    }

    // ---- Moyens de paiement (anneau) -----------------------------------
    const methods = document.querySelector('#methods-chart');
    if (methods) {
        const data = readChart(methods);
        new ApexCharts(methods, {
            series: data.values || [],
            labels: data.labels || [],
            chart: { type: 'donut', height: 240, fontFamily: FONT },
            colors: PALETTE,
            legend: { show: false },
            dataLabels: { enabled: false },
            stroke: { width: 0 },
            plotOptions: { pie: { donut: { size: '68%' } } },
            tooltip: { y: { formatter: function (value) { return formatNumber(value) + ' FCFA'; } } },
        }).render();
    }

    // ---- Mini courbes des cartes --------------------------------------
    const sparkline = function (selector, color, suffix) {
        const element = document.querySelector(selector);
        if (!element) {
            return;
        }

        const data = readChart(element);
        new ApexCharts(element, {
            series: [{ data: data.values || [] }],
            chart: { type: 'area', height: 70, sparkline: { enabled: true }, fontFamily: FONT },
            colors: [color],
            stroke: { curve: 'smooth', width: 2 },
            fill: { type: 'gradient', gradient: { opacityFrom: 0.35, opacityTo: 0.02 } },
            tooltip: {
                theme: 'dark',
                x: { formatter: function (value, options) { return shortDay((data.days || [])[options.dataPointIndex] || '0-0-0'); } },
                y: { formatter: function (value) { return formatNumber(value) + suffix; } },
            },
        }).render();
    };

    sparkline('#collected-sparkline', '#072F1F', ' FCFA');
    sparkline('#members-sparkline', '#6BA304', ' adhésion(s)');

    // ---- Sélecteur de période -----------------------------------------
    const trigger = document.querySelector('#date-picker-trigger');
    const input = document.querySelector('#period-input');
    const form = document.querySelector('#period-form');

    if (trigger && input && form && typeof flatpickr === 'function') {
        const french = {
            weekdays: {
                shorthand: ['dim', 'lun', 'mar', 'mer', 'jeu', 'ven', 'sam'],
                longhand: ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'],
            },
            months: {
                shorthand: ['janv', 'févr', 'mars', 'avr', 'mai', 'juin', 'juil', 'août', 'sept', 'oct', 'nov', 'déc'],
                longhand: ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'],
            },
            firstDayOfWeek: 1,
            rangeSeparator: ' au ',
            weekAbbreviation: 'Sem',
            scrollTitle: 'Défiler pour augmenter',
            toggleTitle: 'Cliquer pour basculer',
        };

        const picker = flatpickr(input, {
            mode: 'range',
            dateFormat: 'Y-m-d',
            locale: french,
            maxDate: 'today',
            defaultDate: [input.dataset.from, input.dataset.to],
            positionElement: trigger,
            onClose: function (dates, text, instance) {
                if (dates.length === 2) {
                    document.querySelector('#period-from').value = instance.formatDate(dates[0], 'Y-m-d');
                    document.querySelector('#period-to').value = instance.formatDate(dates[1], 'Y-m-d');
                    form.submit();
                }
            },
        });

        trigger.addEventListener('click', function () {
            picker.open();
        });
    }
});
