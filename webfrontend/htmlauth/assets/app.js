/**
 * HitWatch4Lox – UI helpers (iframe-isoliert, kein jQuery)
 *
 * Patterns:
 *   - API-Buttons:  <button data-sl-action="ACTION" data-confirm="Msg?">
 *   - Meta-CSRF:    <meta name="sl-csrf" content="TOKEN">
 */

const SL = (() => {

    function csrf() {
        const m = document.querySelector('meta[name="sl-csrf"]');
        return m ? m.content : '';
    }

    function toast(msg, kind = 'ok', ms = null) {
        if (ms === null) ms = (kind === 'err') ? 5000 : 2500;
        if (window.parent !== window) {
            try {
                window.parent.postMessage({
                    type: 'sl-toast', message: String(msg),
                    kind: String(kind), duration: Number(ms) || 2500,
                }, '*');
                return;
            } catch (e) { /* cross-origin – Fallback auf lokales Toast */ }
        }
        const el = document.getElementById('sl-toast');
        if (!el) return;
        el.textContent = msg;
        el.className = 'sl-toast show ' + kind;
        setTimeout(() => el.classList.remove('show'), ms);
    }

    async function api(action, payload = {}) {
        const res = await fetch('ajax.php?action=' + encodeURIComponent(action), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
            body: JSON.stringify(payload),
        });
        let data = null;
        try { data = await res.json(); } catch (e) { /* non-JSON */ }
        if (!res.ok || !data || data.ok === false) {
            const msg = (data && data.error) ? data.error : ('HTTP ' + res.status);
            toast(msg, 'err', 4000);
            throw new Error(msg);
        }
        return data;
    }

    function serializeForm(form) {
        const out = {};
        form.querySelectorAll('[name]').forEach(el => {
            const name = el.getAttribute('name');
            if (!name || el.disabled) return;
            let val;
            if (el.type === 'checkbox') {
                val = el.checked;
            } else if (el.type === 'number' || el.dataset.num !== undefined) {
                val = el.value === '' ? null : Number(el.value);
            } else if (el.dataset.bool !== undefined) {
                val = el.value === 'true' || el.value === '1';
            } else if (el.tagName === 'SELECT' && el.multiple) {
                val = [...el.selectedOptions].map(o => o.value);
            } else {
                val = el.value;
            }
            if (el.dataset.arr !== undefined || (name in out && !Array.isArray(out[name]))) {
                if (!(name in out)) { out[name] = []; }
                else if (!Array.isArray(out[name])) { out[name] = [out[name]]; }
                out[name].push(val);
            } else if (Array.isArray(out[name])) {
                out[name].push(val);
            } else {
                out[name] = val;
            }
        });
        return out;
    }

    document.addEventListener('click', async (ev) => {
        const t = ev.target.closest('[data-sl-action]');
        if (!t) return;
        ev.preventDefault();
        const action = t.dataset.slAction;
        const id     = t.dataset.pId || '';
        const cfm    = t.dataset.confirm;
        if (cfm && !confirm(cfm)) return;
        const wasDisabled = t.disabled;
        t.disabled = true;
        try {
            const res = await api(action, { id });
            toast(res.message || 'OK', 'ok');
            if (t.dataset.reload !== 'false') setTimeout(() => location.reload(), 400);
            else t.disabled = wasDisabled;
        } catch (e) {
            t.disabled = wasDisabled;
        }
    });

    function reportHeight() {
        if (window.parent === window) return;
        const h = Math.max(
            document.documentElement.scrollHeight,
            document.body ? document.body.scrollHeight : 0
        );
        try { window.parent.postMessage({ type: 'sl-height', value: h }, '*'); } catch (e) {}
    }

    let _rhTimer = null;
    function scheduleHeight() {
        if (_rhTimer) return;
        _rhTimer = setTimeout(() => { _rhTimer = null; reportHeight(); }, 50);
    }

    window.addEventListener('load', reportHeight);
    window.addEventListener('resize', scheduleHeight);

    if (window.MutationObserver) {
        const mo = new MutationObserver(scheduleHeight);
        document.addEventListener('DOMContentLoaded', () => {
            if (document.body) {
                mo.observe(document.body, {
                    childList: true, subtree: true,
                    attributes: true, attributeFilter: ['hidden', 'class', 'style'],
                });
            }
            reportHeight();
        });
    }
    setInterval(reportHeight, 1500);

    // Card-Akkordeon (click auf .sl-card-head togglet collapsed-Klasse)
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.sl-card-head').forEach(head => {
            head.addEventListener('click', () => {
                head.closest('.sl-card').classList.toggle('collapsed');
                scheduleHeight();
            });
        });
    });

    return { csrf, toast, api, serializeForm, reportHeight };
})();
