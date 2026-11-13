/**
 * Google Auth Tests - UI and in-browser tests.
 * Server tests run one by one through AJAX (task=tests.run); browser tests run here,
 * inside a hidden same-origin iframe that loads the site's login page.
 */
(function () {
    'use strict';

    const opts = Joomla.getOptions('com_googleauthtests') || {};
    const $ = (id) => document.getElementById(id);
    const state = { results: {}, running: false, stop: false, frame: null };
    const STATUS_ICON = { pass: '✓', fail: '✗', error: '✗', warn: '!', skip: '–', running: '', idle: '' };
    const STATUS_LABEL = { pass: 'пройден', fail: 'провален', error: 'ошибка', warn: 'предупреждение', skip: 'пропущен' };

    const el = (tag, attrs, children) => {
        const node = document.createElement(tag);
        Object.entries(attrs || {}).forEach(([k, v]) => {
            if (k === 'class') node.className = v;
            else if (k === 'text') node.textContent = v;
            else node.setAttribute(k, v);
        });
        (children || []).forEach((c) => node.appendChild(c));
        return node;
    };

    const allTests = () => opts.groups.flatMap((g) => g.tests.map((t) => Object.assign({ group: g.title }, t)));

    /* ---------- environment ---------- */
    function renderEnv() {
        const grid = $('gat-env-grid');
        (opts.env || []).forEach((section) => {
            const table = el('table');
            section.rows.forEach(([label, value, level]) => {
                const tr = el('tr', {}, [el('td', { text: label }), el('td', { class: level ? 'lvl-' + level : '', text: value })]);
                table.appendChild(tr);
            });
            grid.appendChild(el('div', { class: 'gat-env-section' }, [el('h4', { text: section.title }), table]));
        });
    }

    /* ---------- test list ---------- */
    function renderTests() {
        const root = $('gat-tests');
        opts.groups.forEach((group) => {
            const groupBox = el('input', { type: 'checkbox', checked: 'checked', 'aria-label': group.title });
            const count = el('span', { class: 'gat-count', text: group.tests.length + ' тестов' });
            const head = el('div', { class: 'gat-group-head' }, [groupBox, el('span', { text: group.title }), count]);
            const wrap = el('section', { class: 'gat-group', 'data-group': group.id }, [head]);

            groupBox.addEventListener('change', () => {
                wrap.querySelectorAll('.gat-test input[type=checkbox]').forEach((c) => { c.checked = groupBox.checked; });
            });

            group.tests.forEach((test) => {
                const cb = el('input', { type: 'checkbox', checked: 'checked', 'aria-label': test.title });
                const badge = el('span', { class: 'gat-badge idle' });
                const title = el('span', { class: 'gat-title', text: test.title });
                if (test.write) title.appendChild(el('span', { class: 'gat-tag', text: 'пишет данные' }));
                if (test.risky) title.appendChild(el('span', { class: 'gat-tag', text: 'рискованный' }));
                if (test.mode === 'browser') title.appendChild(el('span', { class: 'gat-tag', text: 'браузер' }));
                const run = el('button', { type: 'button', class: 'btn btn-outline-secondary btn-sm', text: '▶', title: 'Запустить только этот тест' });
                const time = el('span', { class: 'gat-time' });
                const row = el('div', { class: 'gat-test-row' }, [cb, badge, title, time, run]);
                if (test.desc) row.appendChild(el('div', { class: 'gat-desc', text: test.desc }));
                const details = el('div', { class: 'gat-details' });
                const box = el('div', { class: 'gat-test', 'data-id': test.id }, [row, details]);

                row.addEventListener('click', (e) => {
                    if (e.target === cb || e.target === run) return;
                    box.classList.toggle('open');
                });
                run.addEventListener('click', () => runQueue([test.id]));
                wrap.appendChild(box);
            });

            root.appendChild(wrap);
        });
    }

    const testBox = (id) => document.querySelector('.gat-test[data-id="' + id + '"]');

    function paint(id, result) {
        const box = testBox(id);
        if (!box) return;
        const badge = box.querySelector('.gat-badge');
        badge.className = 'gat-badge ' + result.status;
        badge.textContent = STATUS_ICON[result.status] || '';
        badge.title = STATUS_LABEL[result.status] || '';
        box.querySelector('.gat-time').textContent = result.duration != null ? result.duration + ' мс' : '';
        const details = box.querySelector('.gat-details');
        details.textContent = '';
        const ul = el('ul');
        (result.messages || []).forEach((m) => ul.appendChild(el('li', { class: m.level, text: m.text })));
        details.appendChild(ul);
        if (['fail', 'error', 'warn'].includes(result.status)) box.classList.add('open');
    }

    function markRunning(id) {
        const box = testBox(id);
        if (!box) return;
        const badge = box.querySelector('.gat-badge');
        badge.className = 'gat-badge running';
        badge.textContent = '';
        box.querySelector('.gat-time').textContent = '';
    }

    function summarize(total) {
        const counts = { pass: 0, fail: 0, error: 0, warn: 0, skip: 0 };
        let ms = 0;
        Object.values(state.results).forEach((r) => { counts[r.status] = (counts[r.status] || 0) + 1; ms += r.duration || 0; });
        const done = Object.keys(state.results).length;
        const box = $('gat-summary');
        box.textContent = '';
        [['✓ пройдено', counts.pass], ['✗ провалено', counts.fail + counts.error], ['! предупреждений', counts.warn], ['– пропущено', counts.skip]].forEach(([label, n]) => {
            box.appendChild(el('b', { text: label + ': ' + n }));
        });
        box.appendChild(el('span', { text: 'Выполнено ' + done + (total ? ' из ' + total : '') + ', ' + (ms / 1000).toFixed(1) + ' с' }));
    }

    /* ---------- server tests ---------- */
    async function runServer(id) {
        const body = new FormData();
        body.append('id', id);
        body.append('write', $('gat-opt-write').checked ? '1' : '0');
        body.append('risky', $('gat-opt-risky').checked ? '1' : '0');
        body.append(opts.token, '1');
        const started = performance.now();

        try {
            const response = await fetch(opts.runUrl, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            const text = await response.text();
            try {
                const data = JSON.parse(text);
                if (!data.status) throw new Error('нет поля status');
                return data;
            } catch (e) {
                return { id: id, status: 'error', duration: Math.round(performance.now() - started), messages: [{ level: 'fail', text: 'Сервер вернул не JSON (HTTP ' + response.status + '). Скорее всего, фатальная ошибка PHP в этом тесте. Начало ответа: ' + text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300) }] };
            }
        } catch (e) {
            return { id: id, status: 'error', duration: Math.round(performance.now() - started), messages: [{ level: 'fail', text: 'Сетевая ошибка: ' + e.message }] };
        }
    }

    /* ---------- browser tests ---------- */
    class Ctx {
        constructor() { this.messages = []; this.failed = false; this.warned = false; }
        ok(cond, pass, fail) { if (cond) { this.messages.push({ level: 'ok', text: pass }); } else { this.fail(fail || pass); } return cond; }
        info(t) { this.messages.push({ level: 'info', text: t }); }
        warn(t) { this.warned = true; this.messages.push({ level: 'warn', text: t }); }
        fail(t) { this.failed = true; this.messages.push({ level: 'fail', text: t }); }
        skip(t) { const e = new Error(t); e.skip = true; throw e; }
        status() { return this.failed ? 'fail' : (this.warned ? 'warn' : 'pass'); }
    }

    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

    async function loadFrame(fresh) {
        const frame = $('gat-frame');
        if (state.frame && !fresh) return state.frame;
        await new Promise((resolve, reject) => {
            const timer = setTimeout(() => reject(new Error('страница входа не загрузилась за 20 с')), 20000);
            frame.onload = () => { clearTimeout(timer); resolve(); };
            frame.src = opts.loginUrl + (opts.loginUrl.includes('?') ? '&' : '?') + 'gat=' + Date.now();
        });
        await sleep(700);
        let doc = null;
        try { doc = frame.contentDocument; } catch (e) { doc = null; }
        if (!doc || !doc.body) throw Object.assign(new Error('Страница входа не открылась во встроенном окне (возможно, X-Frame-Options/CSP запрещают iframe).'), { skip: true });
        state.frame = { frame: frame, doc: doc, win: frame.contentWindow };
        return state.frame;
    }

    const findSubmit = (form) => form && form.querySelector('button[type="submit"], input[type="submit"], button:not([type])');
    const containerForm = (c) => c.closest('form') || (c.previousElementSibling && c.previousElementSibling.tagName === 'FORM' ? c.previousElementSibling : null);

    function requireConfigured(ctx) {
        if (!opts.plugin.enabled) ctx.skip('Плагин выключен.');
        if (opts.plugin.offline) ctx.skip('Сайт в режиме offline: посетителю показывается страница «сайт закрыт», а не форма входа. Включите сайт и повторите.');
        if (!opts.plugin.configured) ctx.skip('Client ID не задан: кнопка не показывается.');
    }

    const BROWSER = {
        async 'browser.js_syntax'(ctx) {
            requireConfigured(ctx);
            const response = await fetch(opts.loginUrl, { credentials: 'omit' });
            const html = await response.text();
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const scripts = Array.from(doc.querySelectorAll('script')).filter((s) => s.textContent.includes('google-auth-container'));
            if (!scripts.length) { ctx.fail('На странице входа не найден скрипт плагина (кнопка не выводится).'); return; }
            scripts.forEach((s) => {
                try { new Function(s.textContent); ctx.ok(true, 'Скрипт плагина (' + s.textContent.length + ' символов) компилируется без ошибок'); }
                catch (e) { ctx.fail('Синтаксическая ошибка в скрипте плагина: ' + e.message); }
            });
        },

        async 'browser.placed'(ctx) {
            requireConfigured(ctx);
            const { doc } = await loadFrame(true);
            const containers = Array.from(doc.querySelectorAll('.google-auth-container'));
            if (!ctx.ok(containers.length > 0, 'Найдено кнопок: ' + containers.length, 'Кнопка Google не появилась на странице входа после выполнения скрипта')) return;
            containers.forEach((c, i) => {
                const a = c.querySelector('a');
                const rect = a ? a.getBoundingClientRect() : { width: 0, height: 0 };
                ctx.ok(rect.width > 0 && rect.height > 0, 'Кнопка #' + (i + 1) + ' видима (' + Math.round(rect.width) + '×' + Math.round(rect.height) + ' px)', 'Кнопка #' + (i + 1) + ' в DOM есть, но не видна (нулевой размер)');
                const form = containerForm(c);
                if (!form) { ctx.warn('Кнопка #' + (i + 1) + ' стоит не рядом с формой входа и не внутри неё'); return; }
                const submit = findSubmit(form);
                if (submit && !c.contains(submit)) {
                    ctx.ok(Boolean(submit.compareDocumentPosition(c) & Node.DOCUMENT_POSITION_FOLLOWING), 'Кнопка #' + (i + 1) + ' идёт после кнопки входа', 'Кнопка #' + (i + 1) + ' стоит перед кнопкой входа');
                }
            });
        },

        async 'browser.single'(ctx) {
            requireConfigured(ctx);
            const { doc, win } = await loadFrame(false);
            const count = () => doc.querySelectorAll('.google-auth-container').length;
            const before = count();
            if (before === 0) ctx.skip('Кнопки нет (см. тест «Кнопка размещена в форме входа»).');
            win.dispatchEvent(new win.Event('resize'));
            doc.body.dispatchEvent(new win.MouseEvent('click', { bubbles: true }));
            await sleep(600);
            ctx.ok(count() === before, 'После resize и клика кнопок по-прежнему ' + before, 'После resize/клика кнопок стало ' + count() + ' вместо ' + before);
            const forms = new Set();
            doc.querySelectorAll('.google-auth-container').forEach((c) => {
                const f = containerForm(c);
                if (f) { ctx.ok(!forms.has(f), 'В форме не больше одной кнопки', 'В одной форме несколько кнопок Google'); forms.add(f); }
            });
        },

        async 'browser.link'(ctx) {
            requireConfigured(ctx);
            const { doc } = await loadFrame(false);
            const a = doc.querySelector('.google-auth-container a');
            if (!a) ctx.skip('Кнопки нет.');
            const url = new URL(a.href);
            ctx.ok(url.origin === 'https://accounts.google.com' && url.pathname === '/o/oauth2/v2/auth', 'Ссылка ведёт на accounts.google.com', 'Ссылка ведёт не на Google: ' + a.href.slice(0, 80));
            ctx.ok(Boolean(url.searchParams.get('client_id')), 'В ссылке есть client_id', 'В ссылке нет client_id');
            ctx.ok(/^[a-f0-9]{64}$/.test(url.searchParams.get('state') || ''), 'В ссылке есть state из 64 hex-символов', 'У state неожиданный вид');
            ctx.ok(url.searchParams.get('redirect_uri') && url.searchParams.get('redirect_uri').startsWith('https://'), 'redirect_uri начинается с https://', 'redirect_uri не https');
        },

        async 'browser.size'(ctx) {
            requireConfigured(ctx);
            const { doc } = await loadFrame(false);
            const container = doc.querySelector('.google-auth-container');
            if (!container) ctx.skip('Кнопки нет.');
            const a = container.querySelector('a');
            const rect = a.getBoundingClientRect();
            const form = containerForm(container);
            const submit = findSubmit(form);
            const submitHeight = submit ? submit.getBoundingClientRect().height : 0;

            if (opts.plugin.button_type === 'icon') {
                const expected = opts.plugin.icon_match_height && submitHeight > 0 ? Math.round(submitHeight) : 40;
                ctx.ok(Math.abs(rect.height - expected) <= 1.5, 'Высота значка ' + Math.round(rect.height) + ' px (ожидалось ' + expected + ')', 'Высота значка ' + Math.round(rect.height) + ' px, ожидалось ' + expected);
                ctx.ok(Math.abs(rect.width - rect.height) <= 1.5, 'Значок квадратный (' + Math.round(rect.width) + '×' + Math.round(rect.height) + ')', 'Значок не квадратный: ' + Math.round(rect.width) + '×' + Math.round(rect.height));
            } else {
                ctx.ok(rect.height >= 32, 'Высота кнопки ' + Math.round(rect.height) + ' px', 'Кнопка слишком низкая: ' + Math.round(rect.height) + ' px');
                if (form) ctx.ok(rect.width >= form.getBoundingClientRect().width * 0.8, 'Ширина кнопки близка к ширине формы (' + Math.round(rect.width) + ' px)', 'Кнопка заметно уже формы: ' + Math.round(rect.width) + ' px');
            }
        },

        async 'browser.match_submit'(ctx) {
            requireConfigured(ctx);
            if (!opts.plugin.match_submit) ctx.skip('Режим копирования стиля submit выключен в настройках.');
            const { doc } = await loadFrame(false);
            const container = doc.querySelector('.google-auth-container');
            if (!container) ctx.skip('Кнопки нет.');
            const submit = findSubmit(containerForm(container));
            if (!submit) ctx.skip('Кнопка входа в форме не найдена.');
            const a = container.querySelector('a');
            const missing = Array.from(submit.classList).filter((c) => !c.includes('webauthn') && !a.classList.contains(c));
            ctx.ok(missing.length === 0, 'У кнопки Google есть все классы кнопки входа (' + Array.from(submit.classList).join(' ') + ')', 'Не скопированы классы: ' + missing.join(', '));
        },

        async 'browser.hover_css'(ctx) {
            requireConfigured(ctx);
            if (opts.plugin.match_submit) ctx.skip('Включено копирование стиля submit: ховер задаёт шаблон.');
            const { doc } = await loadFrame(false);
            const rules = [];
            Array.from(doc.styleSheets).forEach((sheet) => {
                try { Array.from(sheet.cssRules).forEach((r) => { if (r.selectorText && r.selectorText.includes('google-login-btn')) rules.push(r); }); } catch (e) { /* cross-origin sheet */ }
            });
            if (!ctx.ok(rules.length > 0, 'CSS ховера на странице: правил ' + rules.length, 'CSS ховера плагина на странице не найден')) return;
            const hover = rules.filter((r) => r.selectorText.includes(':hover'));
            ctx.ok(hover.some((r) => r.selectorText.includes('google-login-btn')), 'Есть правило наведения', 'Нет правила :hover');
            const theme = opts.plugin.button_theme;
            if (['light', 'dark', 'neutral'].includes(theme)) {
                ctx.ok(hover.some((r) => r.selectorText.includes('google-login-btn--' + theme)), 'Есть правило наведения для схемы ' + theme, 'Нет правила наведения для схемы ' + theme);
            }
            ctx.ok(!hover.some((r) => /transform/i.test(r.cssText)), 'Кнопка при наведении не сдвигается (нет transform)', 'В правилах наведения есть transform');
        },
    };

    async function runBrowser(id) {
        const ctx = new Ctx();
        const started = performance.now();
        let status;
        try {
            if (!BROWSER[id]) throw new Error('нет реализации теста ' + id);
            await BROWSER[id](ctx);
            status = ctx.status();
        } catch (e) {
            if (e && e.skip) { status = 'skip'; ctx.info(e.message); }
            else { status = 'error'; ctx.fail('Исключение: ' + (e && e.message ? e.message : e)); }
        }
        return { id: id, status: status, duration: Math.round(performance.now() - started), messages: ctx.messages };
    }

    /* ---------- queue ---------- */
    async function runQueue(ids) {
        if (state.running) return;
        state.running = true;
        state.stop = false;
        state.frame = null;
        $('gat-run-all').disabled = $('gat-run-selected').disabled = true;
        $('gat-stop').disabled = false;
        const tests = allTests();
        const byId = Object.fromEntries(tests.map((t) => [t.id, t]));
        ids.forEach((id) => { delete state.results[id]; });
        const total = ids.length;
        let done = 0;

        for (const id of ids) {
            if (state.stop) break;
            markRunning(id);
            const test = byId[id];
            const result = test.mode === 'browser' ? await runBrowser(id) : await runServer(id);
            state.results[id] = result;
            paint(id, result);
            done++;
            $('gat-progress-bar').style.width = Math.round((done / total) * 100) + '%';
            summarize(total);
            if ($('gat-opt-stop').checked && ['fail', 'error'].includes(result.status)) {
                state.stop = true;
                $('gat-summary').appendChild(el('span', { text: ' — остановлено на первой ошибке' }));
            }
        }

        // Leftover test data is removed after every run, even an interrupted one.
        try {
            const body = new FormData();
            body.append(opts.token, '1');
            await fetch(opts.cleanUrl, { method: 'POST', body: body, credentials: 'same-origin' });
        } catch (e) { /* ignore */ }

        state.running = false;
        $('gat-run-all').disabled = $('gat-run-selected').disabled = false;
        $('gat-stop').disabled = true;
        summarize(total);
    }

    const selectedIds = () => Array.from(document.querySelectorAll('.gat-test')).filter((b) => b.querySelector('.gat-test-row input').checked).map((b) => b.getAttribute('data-id'));

    /* ---------- report ---------- */
    function reportText() {
        const lines = ['Google Auth Tests ' + opts.tool, new Date().toISOString(), ''];
        (opts.env || []).forEach((s) => { lines.push('## ' + s.title); s.rows.forEach(([l, v]) => lines.push(l + ': ' + v)); lines.push(''); });
        lines.push('## Результаты');
        allTests().forEach((t) => {
            const r = state.results[t.id];
            if (!r) return;
            lines.push('[' + (STATUS_LABEL[r.status] || r.status).toUpperCase() + '] ' + t.id + ' — ' + t.title + ' (' + r.duration + ' мс)');
            (r.messages || []).filter((m) => m.level !== 'ok').forEach((m) => lines.push('    ' + m.level + ': ' + m.text));
        });
        return lines.join('\n');
    }

    async function copyReport() {
        const text = reportText();
        try { await navigator.clipboard.writeText(text); } catch (e) {
            const area = el('textarea'); area.value = text; document.body.appendChild(area); area.select(); document.execCommand('copy'); area.remove();
        }
        $('gat-copy').textContent = 'Скопировано ✓';
        setTimeout(() => { $('gat-copy').textContent = 'Скопировать отчёт'; }, 1800);
    }

    function downloadJson() {
        const blob = new Blob([JSON.stringify({ tool: opts.tool, date: new Date().toISOString(), environment: opts.env, results: state.results }, null, 2)], { type: 'application/json' });
        const a = el('a', { href: URL.createObjectURL(blob), download: 'google-auth-tests-' + Date.now() + '.json' });
        document.body.appendChild(a); a.click(); a.remove();
    }

    async function cleanupNow() {
        const body = new FormData();
        body.append(opts.token, '1');
        const response = await fetch(opts.cleanUrl, { method: 'POST', body: body, credentials: 'same-origin' });
        const data = await response.json().catch(() => null);
        $('gat-summary').textContent = data && data.messages ? data.messages.map((m) => m.text).join(' ') : 'Очистка не удалась';
    }

    function init() {
        renderEnv();
        renderTests();
        $('gat-run-all').addEventListener('click', () => runQueue(allTests().map((t) => t.id)));
        $('gat-run-selected').addEventListener('click', () => { const ids = selectedIds(); if (ids.length) runQueue(ids); });
        $('gat-stop').addEventListener('click', () => { state.stop = true; });
        const setAll = (value) => document.querySelectorAll('.gat-group input[type=checkbox]').forEach((c) => { c.checked = value; });
        $('gat-select-all').addEventListener('click', () => setAll(true));
        $('gat-select-none').addEventListener('click', () => setAll(false));
        $('gat-select-failed').addEventListener('click', () => {
            setAll(false);
            Object.entries(state.results).forEach(([id, r]) => {
                if (['fail', 'error', 'warn'].includes(r.status)) { const box = testBox(id); if (box) box.querySelector('.gat-test-row input').checked = true; }
            });
        });
        $('gat-copy').addEventListener('click', copyReport);
        $('gat-download').addEventListener('click', downloadJson);
        $('gat-cleanup').addEventListener('click', cleanupNow);
        summarize(0);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
