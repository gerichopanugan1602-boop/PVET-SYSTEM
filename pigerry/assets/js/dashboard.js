/* Dashboard: charts (Chart.js) and the date calendar. Data comes from #dash-data in dashboard.php. */
(() => {
    'use strict';

    const $ = (id) => document.getElementById(id);
    const el = (tag, cls, txt) => {
        const n = document.createElement(tag);
        if (cls) n.className = cls;
        if (txt !== undefined) n.textContent = txt;
        return n;
    };
    const D = JSON.parse($('dash-data').textContent);

    /* ------------------------------------------------------------ Charts */
    if (window.Chart && D.total > 0) {
        // Colors come from dashboard.css, so changing the theme there re-colors the charts too.
        const css = (name) => getComputedStyle(document.body).getPropertyValue(name).trim();
        const accent = css('--d-accent');
        const palette = [accent, '#4FB3BF', '#E8C468', '#86E6AF', '#3D8F6B', '#B5CCBF'];

        Chart.defaults.color = css('--d-soft');
        Chart.defaults.borderColor = css('--d-line');
        Chart.defaults.font.family = "'Plus Jakarta Sans', system-ui, sans-serif";

        /* kind: line | bar | hbar | pie | doughnut.   base: what the % in the tooltip is out of. */
        const draw = (id, kind, rows, base) => {
            const canvas = $(id);
            if (!canvas) return;
            const round = kind === 'pie' || kind === 'doughnut';
            if (round) rows = rows.filter((r) => r.count > 0);
            const of = base || rows.reduce((s, r) => s + r.count, 0) || 1;
            const axis = kind === 'hbar' ? 'x' : 'y';

            new Chart(canvas, {
                type: kind === 'hbar' ? 'bar' : kind,
                data: {
                    labels: rows.map((r) => r.label),
                    datasets: [{
                        data: rows.map((r) => r.count),
                        backgroundColor: round ? palette : kind === 'line' ? accent + '33' : accent,
                        borderColor: round ? css('--d-card') : accent,
                        borderWidth: round ? 2 : kind === 'line' ? 2 : 0,
                        borderRadius: round || kind === 'line' ? 0 : 6,
                        fill: kind === 'line',
                        tension: 0.35,
                        pointRadius: kind === 'line' ? 3 : 0,
                    }],
                },
                options: {
                    maintainAspectRatio: false,
                    indexAxis: kind === 'hbar' ? 'y' : 'x',
                    cutout: kind === 'doughnut' ? '62%' : undefined,
                    plugins: {
                        legend: { display: round, position: 'right', labels: { usePointStyle: true, boxWidth: 8 } },
                        tooltip: {
                            callbacks: {
                                label: (c) => kind === 'line'
                                    ? ` ${c.raw}`
                                    : ` ${round ? c.label + ': ' : ''}${c.raw} (${Math.round(c.raw * 100 / of)}%)`,
                            },
                        },
                    },
                    scales: round ? {} : {
                        [axis]: { beginAtZero: true, ticks: { precision: 0 } },
                        [axis === 'x' ? 'y' : 'x']: { grid: { display: false } },
                    },
                },
            });
        };

        draw('chDays', 'line', D.days);
        draw('chGroups', 'doughnut', D.groups);
        draw('chMuni', 'bar', D.muni);
        draw('chSwine', 'pie', D.swine);
        draw('chDis', 'hbar', D.diseases, D.total);       // % = share of all farms
        draw('chBio', 'hbar', D.bio, D.total);
        draw('chChal', 'hbar', D.challenges, D.total);
        draw('chBrgy', 'hbar', D.barangays);
    }

    /* ---------------------------------------------------------- Calendar */
    const grid = $('calGrid');
    if (!grid) return;

    const months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    const counts = D.calendar;
    const today = D.today;                                   // YYYY-MM-DD from the database clock
    const view = { y: +today.slice(0, 4), m: +today.slice(5, 7) - 1 };
    const thisMonth = { ...view };
    const pad = (n) => String(n).padStart(2, '0');
    let selected = today;
    let request = 0;

    const render = () => {
        $('calTitle').textContent = `${months[view.m]} ${view.y}`;
        grid.textContent = '';
        for (let b = new Date(view.y, view.m, 1).getDay(); b > 0; b--) grid.append(el('span'));

        for (let d = 1, n = new Date(view.y, view.m + 1, 0).getDate(); d <= n; d++) {
            const key = `${view.y}-${pad(view.m + 1)}-${pad(d)}`;
            const btn = el('button', 'cal-day', d);
            btn.type = 'button';
            btn.dataset.date = key;
            btn.disabled = key > today;                      // nothing can exist in the future
            btn.classList.toggle('has-data', !!counts[key]);
            btn.classList.toggle('is-today', key === today);
            btn.classList.toggle('is-selected', key === selected);
            grid.append(btn);
        }
        $('calNext').disabled = view.y === thisMonth.y && view.m === thisMonth.m;
    };

    const show = (data) => {
        const list = data.records;
        const body = $('dayBody');
        $('dayTitle').textContent = data.label;
        $('dayCount').textContent = `${list.length} ${list.length === 1 ? 'record' : 'records'}`;
        body.textContent = '';
        if (!list.length) return body.append(el('p', 'd-empty', 'No assessments were submitted on this date.'));

        const ul = el('ul', 'rec-list');
        list.forEach((r, i) => {
            const li = el('li', 'rec');
            const main = el('div', 'rec-main');
            const side = el('div', 'rec-side');
            const ref = el('a', 'rec-ref', r.ref);
            const open = el('a', 'text-link', 'View answers');
            ref.href = open.href = r.url;
            li.style.animationDelay = `${i * 40}ms`;
            main.append(ref, el('span', 'rec-meta', `${r.time}  ·  ${r.place}`));
            side.append(el('span', 'd-tag', r.group), open);
            li.append(main, side);
            ul.append(li);
        });
        body.append(ul);
    };

    const load = (date) => {
        const id = ++request;
        const body = $('dayBody');
        $('dayCount').textContent = '';
        body.replaceChildren(el('p', 'd-empty', 'Loading…'));
        fetch('dashboard.php?day=' + encodeURIComponent(date))
            .then((res) => (res.ok ? res.json() : Promise.reject()))
            .then((data) => id === request && show(data))
            .catch(() => id === request && body.replaceChildren(el('p', 'd-empty', 'The records for this date could not be loaded.')));
    };

    const pick = (date) => {
        selected = date;
        view.y = +date.slice(0, 4);
        view.m = +date.slice(5, 7) - 1;
        render();
        load(date);
    };

    grid.addEventListener('click', (e) => {
        const btn = e.target.closest('.cal-day');
        if (btn && !btn.disabled) pick(btn.dataset.date);
    });
    $('calPrev').addEventListener('click', () => { if (--view.m < 0) { view.m = 11; view.y--; } render(); });
    $('calNext').addEventListener('click', () => { if (++view.m > 11) { view.m = 0; view.y++; } render(); });
    $('calToday').addEventListener('click', () => pick(today));

    render();
    load(today);
})();