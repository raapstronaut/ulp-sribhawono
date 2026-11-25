{{-- V2.4 - tab browser lokal, tidak mengubah route atau data server. --}}
<script>
(() => {
    'use strict';
    const strip = document.getElementById('pln-workspace-strip');
    if (!strip) return;

    // Setiap akun dan setiap tab browser mempunyai riwayat tab sendiri.
    const storeKey = 'pln-workspace-tabs-v24:' + (strip.dataset.userKey || 'admin');
    const MAX_TABS = 20;

    function validTarget(raw) {
        try {
            const url = new URL(raw, window.location.origin);
            if (url.origin !== window.location.origin || !url.pathname.startsWith('/admin/')) return null;
            return { id: url.pathname, url: url.pathname + url.search };
        } catch (_) {
            return null;
        }
    }

    function cleanLabel(value) {
        const text = typeof value === 'string' ? value.trim() : '';
        return (text || 'Halaman Admin').slice(0, 90);
    }

    const dashboard = validTarget(strip.dataset.dashboardUrl);
    const current = validTarget(strip.dataset.currentUrl);
    if (!dashboard || !current) return; // Fallback HTML dari Laravel tetap dapat dipakai.

    let previous = [];
    try {
        const saved = JSON.parse(window.sessionStorage.getItem(storeKey) || '[]');
        if (Array.isArray(saved)) previous = saved;
    } catch (_) { /* Mode privat dapat memblokir sessionStorage. */ }

    const tabs = [{ ...dashboard, label: 'Dashboard' }];
    for (const entry of previous) {
        if (!entry || typeof entry !== 'object') continue;
        const target = validTarget(entry.url);
        if (!target || target.id === dashboard.id || tabs.some(tab => tab.id === target.id)) continue;
        tabs.push({ ...target, label: cleanLabel(entry.label) });
        if (tabs.length >= MAX_TABS) break;
    }

    if (current.id !== dashboard.id) {
        const existing = tabs.findIndex(tab => tab.id === current.id);
        if (existing >= 0) {
            // Pencarian/filter memperbarui URL tab, bukan membuka tab duplikat.
            tabs[existing].url = current.url;
            tabs[existing].label = cleanLabel(strip.dataset.currentLabel);
        } else {
            if (tabs.length >= MAX_TABS) tabs.splice(1, 1);
            tabs.push({ ...current, label: cleanLabel(strip.dataset.currentLabel) });
        }
    }

    function persist() {
        try { window.sessionStorage.setItem(storeKey, JSON.stringify(tabs)); }
        catch (_) { /* Tanpa storage, navigasi normal tetap bekerja. */ }
    }

    function render() {
        strip.replaceChildren();
        let selected = null;
        for (const tab of tabs) {
            const active = tab.id === current.id;
            const holder = document.createElement('div');
            holder.className = 'pln-browser-tab' + (active ? ' pln-workspace-current' : '') + (tab.id === dashboard.id ? ' pln-dashboard-tab' : '');

            const link = document.createElement('a');
            link.href = tab.url;
            link.textContent = tab.label;
            link.title = tab.label;
            if (active) link.setAttribute('aria-current', 'location');
            holder.appendChild(link);

            if (tab.id !== dashboard.id) {
                const close = document.createElement('button');
                close.type = 'button';
                close.textContent = '\u00d7';
                close.title = 'Tutup tab ' + tab.label;
                close.setAttribute('aria-label', 'Tutup tab ' + tab.label);
                close.addEventListener('click', () => {
                    const index = tabs.findIndex(item => item.id === tab.id);
                    if (index < 0) return;
                    tabs.splice(index, 1);
                    persist();
                    if (active) {
                        // Seperti tab browser: tutup aktif => pindah ke tab di kiri.
                        const fallback = tabs[Math.max(0, index - 1)] || tabs[0];
                        window.location.assign(fallback.url);
                    } else {
                        render();
                    }
                });
                holder.appendChild(close);
            }
            strip.appendChild(holder);
            if (active) selected = holder;
        }
        // Scroll hanya bar tab secara horizontal, jangan menggeser halaman utama.
        if (selected) {
            const selectedRect = selected.getBoundingClientRect();
            const stripRect = strip.getBoundingClientRect();
            if (selectedRect.left < stripRect.left) strip.scrollLeft -= stripRect.left - selectedRect.left;
            if (selectedRect.right > stripRect.right) strip.scrollLeft += selectedRect.right - stripRect.right;
        }
    }

    persist();
    render();
    const logout = document.querySelector('form[action$="/logout"]');
    logout?.addEventListener('submit', () => {
        try { window.sessionStorage.removeItem(storeKey); } catch (_) { /* noop */ }
    });
})();
</script>
