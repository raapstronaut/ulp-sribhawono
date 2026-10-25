import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

// Warna marker berdasarkan jenis peralatan
const MARKER_STYLES = {
    RECLOSER: {
        color: '#2563EB',
        label: 'Recloser',
    },
    LBSM: {
        color: '#F59E0B',
        label: 'LBSM',
    },
    FCO: {
        color: '#16A34A',
        label: 'FCO',
    },
};

const DEFAULT_MARKER = {
    color: '#64748B',
    label: 'Lainnya',
};

function getMarkerStyle(jenis) {
    const key = String(jenis || '').trim().toUpperCase();
    return MARKER_STYLES[key] || DEFAULT_MARKER;
}

document.addEventListener('DOMContentLoaded', async () => {
    const element = document.getElementById('admin-network-map');
    if (!element) return;

    const penyulangFilter = document.getElementById('maps-penyulang-filter');
    const jenisFilter = document.getElementById('maps-jenis-filter');
    const resetButton = document.getElementById('maps-reset-filter');
    const filterStatus = document.getElementById('maps-filter-status');

    if (!penyulangFilter || !jenisFilter || !resetButton) {
        console.error('Elemen filter Maps belum tersedia.');
        return;
    }

    // Inisialisasi peta
    const map = L.map(element).setView([-5.2, 105.8], 11);

    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors',
    }).addTo(map);

    const keypointLayer = L.layerGroup().addTo(map);

    let allKeypoints = [];

    // Status jumlah marker
    const statusControl = L.control({
        position: 'topright',
    });

    statusControl.onAdd = () => {
        const div = L.DomUtil.create('div');

        Object.assign(div.style, {
            background: 'white',
            padding: '10px 14px',
            borderRadius: '8px',
            boxShadow: '0 2px 8px rgba(0,0,0,0.15)',
            fontSize: '12px',
            fontWeight: '600',
        });

        div.textContent = 'Memuat Keypoint...';

        L.DomEvent.disableClickPropagation(div);

        return div;
    };

    statusControl.addTo(map);

    function setStatus(message) {
        const container = statusControl.getContainer();

        if (container) {
            container.textContent = message;
        }

        if (filterStatus) {
            filterStatus.textContent = message;
        }
    }

    // Legenda warna marker
    const legend = L.control({
        position: 'bottomright',
    });

    legend.onAdd = () => {
        const container = L.DomUtil.create('div');

        Object.assign(container.style, {
            background: 'white',
            padding: '12px 15px',
            borderRadius: '8px',
            boxShadow: '0 2px 8px rgba(0,0,0,0.15)',
            fontSize: '12px',
        });

        const title = document.createElement('strong');
        title.textContent = 'Jenis Peralatan';
        title.style.display = 'block';
        title.style.marginBottom = '8px';

        container.appendChild(title);

        const types = [
            ...Object.values(MARKER_STYLES),
            DEFAULT_MARKER,
        ];

        types.forEach((type) => {
            const row = document.createElement('div');

            Object.assign(row.style, {
                display: 'flex',
                alignItems: 'center',
                gap: '8px',
                marginTop: '6px',
            });

            const dot = document.createElement('span');

            Object.assign(dot.style, {
                display: 'inline-block',
                width: '12px',
                height: '12px',
                borderRadius: '50%',
                backgroundColor: type.color,
                border: '2px solid white',
                boxShadow: `0 0 0 1px ${type.color}`,
            });

            const label = document.createElement('span');
            label.textContent = type.label;

            row.append(dot, label);
            container.appendChild(row);
        });

        L.DomEvent.disableClickPropagation(container);
        L.DomEvent.disableScrollPropagation(container);

        return container;
    };

    legend.addTo(map);

    // Popup detail Keypoint
    function createPopup(point) {
        const container = document.createElement('div');

        const title = document.createElement('strong');
        title.textContent = point.nama || 'Tanpa Nama';

        Object.assign(title.style, {
            fontSize: '14px',
            display: 'block',
            marginBottom: '8px',
        });

        const jenis = document.createElement('p');
        jenis.textContent = `Jenis: ${point.jenis || '-'}`;

        const penyulang = document.createElement('p');
        penyulang.textContent = `Penyulang: ${point.penyulang || '-'}`;

        const koordinat = document.createElement('p');
        koordinat.textContent =
            `Koordinat: ${point.latitude}, ${point.longitude}`;

        container.append(title, jenis, penyulang, koordinat);

        return container;
    }

    // Isi dropdown dari data Keypoint
    function populateFilters(points) {
        const penyulangs = [
            ...new Set(
                points.map(point => point.penyulang).filter(Boolean)
            ),
        ].sort((a, b) => a.localeCompare(b, 'id'));

        const jenisPeralatan = [
            ...new Set(
                points.map(point => point.jenis).filter(Boolean)
            ),
        ].sort((a, b) => a.localeCompare(b, 'id'));

        penyulangs.forEach((penyulang) => {
            const option = document.createElement('option');
            option.value = penyulang;
            option.textContent = penyulang;
            penyulangFilter.appendChild(option);
        });

        jenisPeralatan.forEach((jenis) => {
            const option = document.createElement('option');
            option.value = jenis;
            option.textContent = jenis;
            jenisFilter.appendChild(option);
        });
    }

    // Render marker dengan warna dan filter
    function renderMarkers() {
        const selectedPenyulang = penyulangFilter.value;
        const selectedJenis = jenisFilter.value;

        keypointLayer.clearLayers();

        const filtered = allKeypoints.filter((point) => {
            const matchPenyulang =
                !selectedPenyulang ||
                point.penyulang === selectedPenyulang;

            const matchJenis =
                !selectedJenis ||
                point.jenis === selectedJenis;

            return matchPenyulang && matchJenis;
        });

        const bounds = [];

        filtered.forEach((point) => {
            const lat = Number(point.latitude);
            const lng = Number(point.longitude);

            const style = getMarkerStyle(point.jenis);

            // Marker lingkaran berwarna
            const marker = L.circleMarker([lat, lng], {
                radius: 9,
                color: '#FFFFFF',
                weight: 2,
                fillColor: style.color,
                fillOpacity: 0.95,
                bubblingMouseEvents: false,
            });

            marker.bindPopup(createPopup(point));

            marker.addTo(keypointLayer);

            bounds.push([lat, lng]);
        });

        setStatus(
            `${filtered.length} dari ${allKeypoints.length} Keypoint ditampilkan`
        );

        if (bounds.length > 0) {
            map.fitBounds(bounds, {
                padding: [40, 40],
                maxZoom: 13,
            });
        }
    }

    // Ambil data dari Laravel
    try {
        const response = await fetch('/admin/maps/keypoints', {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
            },
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const result = await response.json();

        if (!Array.isArray(result.data)) {
            throw new Error('Format data Keypoint tidak valid');
        }

        // Hanya simpan koordinat yang valid
        allKeypoints = result.data.filter((point) => {
            const lat = Number(point.latitude);
            const lng = Number(point.longitude);

            return (
                point.latitude !== null &&
                point.longitude !== null &&
                Number.isFinite(lat) &&
                Number.isFinite(lng) &&
                lat >= -90 &&
                lat <= 90 &&
                lng >= -180 &&
                lng <= 180
            );
        });

        populateFilters(allKeypoints);
        renderMarkers();

    } catch (error) {
        console.error('Gagal memuat Keypoint:', error);
        setStatus('Gagal memuat data Keypoint');
    }

    // Event filter
    penyulangFilter.addEventListener('change', renderMarkers);
    jenisFilter.addEventListener('change', renderMarkers);

    resetButton.addEventListener('click', () => {
        penyulangFilter.value = '';
        jenisFilter.value = '';
        renderMarkers();
    });

    // Klik area peta untuk menampilkan koordinat
    map.on('click', (event) => {
        const { lat, lng } = event.latlng;

        L.popup()
            .setLatLng(event.latlng)
            .setContent(
                `Latitude: ${lat.toFixed(6)}<br>
                 Longitude: ${lng.toFixed(6)}`
            )
            .openOn(map);
    });
});