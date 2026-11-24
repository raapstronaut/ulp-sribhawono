<style>
    [data-pln-detail-panel][hidden] { display: none !important; }
    [data-pln-detail-root] .pln-subtabs { overflow-x: auto; flex-wrap: nowrap; }
    [data-pln-detail-root] .pln-subtab { white-space: nowrap; }
</style>
<script>
(() => {
    // Progressive enhancement: tanpa JavaScript, semua isi dan form tetap terlihat.
    const root = document.querySelector('[data-pln-detail-root]');
    if (!root) return;
    const tabs = Array.from(root.querySelectorAll('[data-pln-detail-tab]'));
    const panels = Array.from(document.querySelectorAll('[data-pln-detail-panel]'));
    const keys = new Set(tabs.map(tab => tab.dataset.plnDetailTab));
    if (!tabs.length || !panels.length) return;

    function keyFromHash() {
        const fragment = window.location.hash.slice(1);
        if (fragment.startsWith('pln-detail-')) return fragment.slice('pln-detail-'.length);
        if (keys.has(fragment)) return fragment;
        if (fragment.startsWith('temuan-') && keys.has('temuan')) return 'temuan';
        return null;
    }
    function activate(requested, updateHash = false) {
        const key = keys.has(requested) ? requested : tabs[0].dataset.plnDetailTab;
        tabs.forEach(tab => {
            const active = tab.dataset.plnDetailTab === key;
            tab.setAttribute('aria-selected', String(active));
            tab.tabIndex = active ? 0 : -1;
        });
        panels.forEach(panel => { panel.hidden = panel.dataset.plnDetailPanel !== key; });
        if (updateHash && window.history.replaceState) {
            window.history.replaceState(null, '', '#pln-detail-' + key);
        }
    }
    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => activate(tab.dataset.plnDetailTab, true));
        tab.addEventListener('keydown', event => {
            const delta = event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : 0;
            if (!delta && event.key !== 'Home' && event.key !== 'End') return;
            event.preventDefault();
            const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + delta + tabs.length) % tabs.length;
            tabs[next].focus();
            activate(tabs[next].dataset.plnDetailTab, true);
        });
    });
    window.addEventListener('hashchange', () => activate(keyFromHash() || root.dataset.plnDetailDefault));
    activate(keyFromHash() || root.dataset.plnDetailDefault);
})();
</script>
