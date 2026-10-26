import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const COLORS = {
    RECLOSER: '#2563eb',
    LBSM: '#f59e0b',
    FCO: '#16a34a',
    APKUK: '#7c3aed',
    OTHER: '#64748b',
};
const PAGE_SIZE = 40;
const formatCount = (number) => Number(number || 0).toLocaleString('id-ID');
const normalize = (value) => String(value ?? '').trim().replace(/\s+/g, ' ').toLocaleLowerCase('id-ID');
const text = (value) => value === null || value === undefined || value === '' ? '—' : String(value);

function markerColor(item) {
    return item.layer === 'apkuk'
        ? COLORS.APKUK
        : (COLORS[String(item.jenis || '').trim().toUpperCase()] || COLORS.OTHER);
}

function textElement(tag, value, className = '') {
    const element = document.createElement(tag);
    element.textContent = text(value);
    if (className) element.className = className;
    return element;
}

function detailRow(label, value) {
    const row = document.createElement('div');
    row.className = 'mt-1 text-xs';
    row.append(textElement('span', `${label}: `, 'font-semibold'), textElement('span', value));
    return row;
}

function popupFor(item) {
    const content = document.createElement('div');
    content.style.minWidth = '190px';
    content.appendChild(textElement('strong', item.label, 'block mb-2 text-sm'));
    content.appendChild(detailRow('Kategori', item.layer === 'apkuk' ? 'APKUK' : text(item.jenis)));
    content.appendChild(detailRow('Penyulang', item.penyulang));
    if (item.no_tiang) content.appendChild(detailRow('No. Tiang', item.no_tiang));
    if (item.status) content.appendChild(detailRow('Status', item.status));
    if (item.tanggal) content.appendChild(detailRow('Tanggal', item.tanggal));
    if (item.kurang_bh !== null && item.kurang_bh !== undefined) content.appendChild(detailRow('Kurang bh', item.kurang_bh));
    if (item.note) content.appendChild(detailRow('Catatan', item.note));
    content.appendChild(detailRow('Koordinat', `${item.latitude}, ${item.longitude}`));
    return content;
}

document.addEventListener('DOMContentLoaded', () => {
    const element = document.getElementById('admin-network-map');
    if (!element) return;

    const $ = (id) => document.getElementById(id);
    const search = $('maps-search');
    const feeder = $('maps-penyulang-filter');
    const jenis = $('maps-jenis-filter');
    const keypointToggle = $('maps-layer-keypoint');
    const apkukToggle = $('maps-layer-apkuk');
    const status = $('maps-filter-status');
    const resultsElement = $('maps-results');
    const moreButton = $('maps-load-more');
    const tabLocations = $('maps-tab-locations');
    const tabGangguan = $('maps-tab-gangguan');
    const caption = $('maps-list-caption');
    const refreshButton = $('maps-refresh');
    const updated = $('maps-updated');

    const map = L.map(element, { preferCanvas: true }).setView([-5.2, 105.8], 11);
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors',
    }).addTo(map);
    const canvas = L.canvas({ padding: 0.4 });
    const markerLayer = L.layerGroup().addTo(map);

    let allLocations = [];
    let allGangguan = [];
    let currentLocations = [];
    let currentGangguan = [];
    let markerById = new Map();
    let activeTab = 'locations';
    let visibleRows = PAGE_SIZE;
    let loaded = false;
    let fetchInProgress = false;

    const statusText = (message) => { if (status) status.textContent = message; };
    const stats = (field, value) => { const target = $(field); if (target) target.textContent = formatCount(value); };

    function buildLocations(payload) {
        const data = [];
        for (const point of payload.keypoints || []) {
            data.push({
                ...point,
                layer: 'keypoint',
                uid: `keypoint-${point.id}`,
                label: text(point.nama),
            });
        }
        for (const point of payload.apkuks || []) {
            data.push({
                ...point,
                layer: 'apkuk',
                uid: `apkuk-${point.id}`,
                label: text(point.no_tiang || point.source_id || `APKUK ${point.id}`),
            });
        }
        return data.filter((point) => {
            const lat = Number(point.latitude);
            const lon = Number(point.longitude);
            return point.latitude !== null && point.longitude !== null
                && Number.isFinite(lat) && Number.isFinite(lon)
                && lat >= -90 && lat <= 90 && lon >= -180 && lon <= 180;
        });
    }

    function updateFilterOptions() {
        const currentFeeder = feeder.value;
        const currentJenis = jenis.value;
        const feeders = [...new Set([
            ...allLocations.map((item) => item.penyulang),
            ...allGangguan.map((item) => item.penyulang),
        ].filter(Boolean))].sort((a, b) => a.localeCompare(b, 'id'));
        const types = [...new Set(allLocations
            .filter((item) => item.layer === 'keypoint')
            .map((item) => item.jenis).filter(Boolean))]
            .sort((a, b) => a.localeCompare(b, 'id'));

        feeder.replaceChildren(new Option('Semua Penyulang', ''));
        jenis.replaceChildren(new Option('Semua Jenis', ''));
        for (const value of feeders) feeder.add(new Option(value, value));
        for (const value of types) jenis.add(new Option(value, value));
        feeder.value = feeders.includes(currentFeeder) ? currentFeeder : '';
        jenis.value = types.includes(currentJenis) ? currentJenis : '';
    }

    function currentMatches(item) {
        const q = normalize(search.value);
        if (feeder.value && item.penyulang !== feeder.value) return false;
        if (q && !normalize([
            item.label, item.no_tiang, item.source_id, item.peralatan, item.id,
        ].filter(Boolean).join(' ')).includes(q)) return false;
        return true;
    }

    function filterData() {
        currentLocations = allLocations.filter((item) => {
            if (item.layer === 'keypoint' && !keypointToggle.checked) return false;
            if (item.layer === 'apkuk' && !apkukToggle.checked) return false;
            if (jenis.value && item.layer === 'keypoint' && item.jenis !== jenis.value) return false;
            return currentMatches(item);
        });
        currentGangguan = allGangguan.filter(currentMatches);
    }

    function fitToPoints() {
        if (!currentLocations.length) return;
        const bounds = L.latLngBounds(currentLocations.map((item) => [Number(item.latitude), Number(item.longitude)]));
        if (bounds.isValid()) map.fitBounds(bounds.pad(0.06), { maxZoom: 14, animate: false });
    }

    function renderMap({ fit = true } = {}) {
        markerLayer.clearLayers();
        markerById = new Map();
        for (const item of currentLocations) {
            const marker = L.circleMarker([Number(item.latitude), Number(item.longitude)], {
                renderer: canvas,
                radius: item.layer === 'apkuk' ? 5 : 8,
                color: '#ffffff',
                weight: 1.5,
                fillColor: markerColor(item),
                fillOpacity: 0.92,
                bubblingMouseEvents: false,
            });
            marker.bindPopup(() => popupFor(item), { maxWidth: 330 });
            marker.addTo(markerLayer);
            markerById.set(item.uid, marker);
        }
        stats('maps-stat-visible', currentLocations.length);
        statusText(`${formatCount(currentLocations.length)} marker di peta · ${formatCount(currentGangguan.length)} Gangguan dalam daftar`);
        if (fit) {
            fitToPoints();
            // Pertahankan perilaku lama: bila pencarian menemukan satu titik,
            // buka popup-nya otomatis.
            if (normalize(search.value) && currentLocations.length === 1) {
                markerById.get(currentLocations[0].uid)?.openPopup();
            }
        }
    }

    function listItemForLocation(item) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'block w-full rounded-lg border border-slate-200 px-3 py-2.5 text-left hover:border-blue-300 hover:bg-blue-50 focus:outline-none focus:ring-2 focus:ring-blue-300';
        const top = document.createElement('div');
        top.className = 'flex items-start gap-2';
        const dot = document.createElement('span');
        dot.className = 'mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full';
        dot.style.backgroundColor = markerColor(item);
        const body = document.createElement('div');
        body.className = 'min-w-0';
        body.append(textElement('p', item.label, 'break-words text-sm font-semibold text-slate-900'));
        body.append(textElement('p', `${item.layer === 'apkuk' ? 'APKUK' : text(item.jenis)} · ${text(item.penyulang)}`, 'mt-1 text-xs text-slate-500'));
        if (item.layer === 'apkuk' && item.status) body.append(textElement('p', `Status: ${item.status}`, 'mt-1 text-xs text-slate-500'));
        top.append(dot, body);
        button.append(top);
        button.addEventListener('click', () => {
            const marker = markerById.get(item.uid);
            if (!marker) return;
            map.setView(marker.getLatLng(), Math.max(15, map.getZoom()));
            marker.openPopup();
        });
        return button;
    }

    function listItemForGangguan(item) {
        const container = document.createElement('div');
        container.className = 'rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5';
        container.append(textElement('p', item.peralatan || item.no_tiang || item.id, 'text-sm font-semibold text-slate-800'));
        container.append(textElement('p', `${text(item.penyulang)} · ${text(item.tanggal)}`, 'mt-1 text-xs text-slate-500'));
        container.append(textElement('p', `No. Tiang: ${text(item.no_tiang)} · ${text(item.sifat)}`, 'mt-1 text-xs text-slate-500'));
        return container;
    }

    function renderList() {
        const locationsTab = activeTab === 'locations';
        const source = locationsTab ? currentLocations : currentGangguan;
        tabLocations.setAttribute('aria-selected', String(locationsTab));
        tabGangguan.setAttribute('aria-selected', String(!locationsTab));
        for (const [button, selected] of [[tabLocations, locationsTab], [tabGangguan, !locationsTab]]) {
            button.className = selected
                ? 'flex-1 border-b-2 border-blue-600 px-3 py-3 text-sm font-bold text-blue-700'
                : 'flex-1 border-b-2 border-transparent px-3 py-3 text-sm font-semibold text-slate-600 hover:bg-slate-50';
        }
        caption.textContent = locationsTab
            ? `${formatCount(source.length)} lokasi cocok. Klik nama untuk menuju marker. Hasil pertama ditampilkan lebih dahulu.`
            : `${formatCount(source.length)} laporan cocok. Gangguan belum memiliki koordinat untuk dipetakan.`;
        resultsElement.replaceChildren();
        if (!source.length) {
            resultsElement.append(textElement('p', loaded ? 'Tidak ada hasil yang cocok.' : 'Memuat data...', 'p-4 text-sm text-slate-500'));
        } else {
            const slice = source.slice(0, visibleRows);
            for (const item of slice) {
                const row = locationsTab ? listItemForLocation(item) : listItemForGangguan(item);
                row.setAttribute('role', 'listitem');
                resultsElement.appendChild(row);
            }
        }
        moreButton.classList.toggle('hidden', source.length <= visibleRows);
        moreButton.textContent = `Tampilkan Lebih Banyak (${formatCount(source.length - visibleRows)} tersisa)`;
    }

    function applyFilters({ fit = true } = {}) {
        visibleRows = PAGE_SIZE;
        filterData();
        renderMap({ fit });
        renderList();
    }

    async function loadData() {
        if (fetchInProgress) return;
        fetchInProgress = true;
        refreshButton.disabled = true;
        refreshButton.textContent = 'Memuat...';
        statusText('Mengambil data terbaru dari Neon...');
        try {
            const response = await fetch(element.dataset.endpoint, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                cache: 'no-store',
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const payload = await response.json();
            if (!Array.isArray(payload.keypoints) || !Array.isArray(payload.apkuks) || !Array.isArray(payload.gangguans)) {
                throw new Error('Format respons Maps tidak sesuai');
            }
            allLocations = buildLocations(payload);
            allGangguan = payload.gangguans;
            const meta = payload.meta || {};
            stats('maps-stat-keypoint', meta.keypoint_mapped ?? payload.keypoints.length);
            stats('maps-stat-apkuk', meta.apkuk_mapped ?? payload.apkuks.length);
            stats('maps-stat-gangguan', meta.gangguan_total ?? payload.gangguans.length);
            updated.textContent = `Data dibaca: ${text(meta.generated_at)} · APKUK tanpa GPS: ${formatCount(meta.apkuk_without_coordinates)}`;
            loaded = true;
            updateFilterOptions();
            applyFilters();
        } catch (error) {
            console.error('Gagal membaca data Peta Jaringan:', error);
            statusText(`Gagal memuat data: ${error.message}. Coba Segarkan Data.`);
            if (!loaded) {
                resultsElement.replaceChildren(textElement('p', 'Data belum tersedia. Pastikan Laravel dan Neon aktif.', 'p-4 text-sm text-rose-700'));
            }
        } finally {
            fetchInProgress = false;
            refreshButton.disabled = false;
            refreshButton.textContent = 'Segarkan Data';
        }
    }

    search.addEventListener('input', () => applyFilters());
    feeder.addEventListener('change', () => applyFilters());
    jenis.addEventListener('change', () => applyFilters());
    keypointToggle.addEventListener('change', () => applyFilters());
    apkukToggle.addEventListener('change', () => applyFilters());

    $('maps-reset-filter').addEventListener('click', () => {
        search.value = '';
        feeder.value = '';
        jenis.value = '';
        keypointToggle.checked = true;
        apkukToggle.checked = false;
        activeTab = 'locations';
        applyFilters();
    });
    $('maps-fit').addEventListener('click', fitToPoints);
    refreshButton.addEventListener('click', loadData);
    moreButton.addEventListener('click', () => { visibleRows += PAGE_SIZE; renderList(); });
    tabLocations.addEventListener('click', () => { activeTab = 'locations'; visibleRows = PAGE_SIZE; renderList(); });
    tabGangguan.addEventListener('click', () => { activeTab = 'gangguan'; visibleRows = PAGE_SIZE; renderList(); });

    map.on('click', (event) => {
        const { lat, lng } = event.latlng;
        L.popup().setLatLng(event.latlng)
            .setContent(`Latitude: ${lat.toFixed(6)}<br>Longitude: ${lng.toFixed(6)}`)
            .openOn(map);
    });

    loadData();
    // Data sinkronisasi bisa berubah melalui cron Railway; pembaruan peta saat halaman terbuka.
    window.setInterval(loadData, 15 * 60 * 1000);
});
