<?php
// Fehleranzeige
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 1. Historische Daten aus DB laden (für die Graphen)
$db_host = "sql100.infinityfree.com";
$db_user = "if0_40591483";
$db_pass = "miauser123";
$db_name = "if0_40591483_gruenermiauser";

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

$labels = []; $temp = []; $hum = []; $ph = []; $light = []; $bild = [];

// Versuche DB zu lesen
if (!$conn->connect_error) {
    $sql = "SELECT zeitpunkt, temperatur_c, luftfeuchte, ph_wert, licht_lux, bild_url FROM messwerte ORDER BY zeitpunkt ASC";
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $labels[] = $row['zeitpunkt'];
            $temp[]   = (float)$row['temperatur_c'];
            $hum[]    = (float)$row['luftfeuchte'];
            $ph[]     = (float)$row['ph_wert'];
            $light[]  = (int)$row['licht_lux'];
            $bild[]   = !empty($row['bild_url']) ? $row['bild_url'] : 'pflanze_aktuell.jpg';
        }
    }
}

// 2. LIVE-DATEN VOM FTP (JSON) LADEN
// Wir schauen, ob der Pi die Datei 'live_daten.json' hochgeladen hat
$live_file = 'live_daten.json';

// Standardwerte (falls keine Datei da ist, nehmen wir den letzten DB-Wert)
$lastIdx = count($temp) - 1;
$curTemp  = ($lastIdx >= 0) ? $temp[$lastIdx] : 0;
$curHum   = ($lastIdx >= 0) ? $hum[$lastIdx] : 0;
$curPh    = ($lastIdx >= 0) ? $ph[$lastIdx] : 0;
$curLight = ($lastIdx >= 0) ? $light[$lastIdx] : 0;

// Wenn JSON existiert, überschreibe die aktuellen Werte damit!
if (file_exists($live_file)) {
    $json_content = file_get_contents($live_file);
    $live_data = json_decode($json_content, true);
    
    if ($live_data) {
        $curTemp  = $live_data['temp'];
        $curHum   = $live_data['hum'];
        $curPh    = $live_data['ph'];
        $curLight = $live_data['light'];
    }
}

// JS-Arrays für die Graphen
$js_labels = json_encode($labels);
$js_temp   = json_encode($temp);
$js_hum    = json_encode($hum);
$js_ph     = json_encode($ph);
$js_light  = json_encode($light);

// Bericht-Daten
$js_rows = json_encode(array_map(function($i) use ($labels, $temp, $hum, $ph, $light, $bild) {
    return ['zeitpunkt' => $labels[$i], 'temperatur' => $temp[$i], 'luftfeuchte' => $hum[$i], 'ph' => $ph[$i], 'licht' => $light[$i], 'bild_url' => $bild[$i]];
}, array_keys($labels)));
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Pflanzen-Monitor mit Lüftersteuerung</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* CSS Variabeln & Reset */
        html {font-size: 125%;}
        :root {
            --bg: #020617;
            --bg-card: rgba(15,23,42,0.96);
            --border: rgba(55,65,81,0.9);
            --text-main: #e5e7eb;
            --text-muted: #9ca3af;
            --accent: #22c55e;
            --temp-color: #fb923c;
            --hum-color: #22c55e;
            --ph-color: #60a5fa;
            --light-color: #eab308;
            /* Status Farben */
            --color-ok: #22c55e;       /* Grün */
            --color-critical: #ef4444; /* Rot */
            --color-active: #3b82f6;   /* Blau für aktiven Lüfter */
        }
        * { box-sizing: border-box; }
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: radial-gradient(circle at top, #1f2937 0, #020617 55%);
            color: var(--text-main);
            margin: 0;
            padding: 1.25rem;
        }
        .shell { max-width: 1100px; margin: 0 auto; }
        .header { display: flex; align-items: center; gap: 1.125rem; margin-bottom: 0.5rem; }
        .duck {
            width: 5rem; height: 5rem; border-radius: 999px; overflow: hidden;
            border: 2px solid var(--accent); box-shadow: 0 0 25px rgba(34,197,94,0.6);
            flex-shrink: 0; background: #000;
        }
        .duck img { width: 100%; height: 100%; object-fit: cover; display: block; }
        h1 { font-size: 1.6rem; margin: 0 0 0.25rem 0; }
        .subtitle { margin: 0; color: var(--text-muted); font-size: 0.85rem; }
        
        /* AKTUELLE WERTE */
        .current-values {
            margin: 1.25rem 0;
            padding: 0.75rem 1rem;
            background: rgba(34,197,94,0.1);
            border: 1px solid rgba(34,197,94,0.5);
            border-radius: 0.7rem;
            display: flex;
            gap: 1.5rem 2rem;
            flex-wrap: wrap;
        }
        .current-values .value-item {
            display: flex; flex-direction: column; line-height: 1.2;
            flex-basis: 120px; flex-grow: 1;
        }
        .current-values .label { font-size: 0.75rem; color: var(--text-muted); font-weight: 500; }
        .current-values .value { font-size: 1.1rem; font-weight: 700; color: var(--text-main); transition: color 0.3s; }
        
        /* Status Farben Logik */
        .current-values .value.status-ok { color: var(--color-ok); }
        .current-values .value.status-critical { color: var(--color-critical); }

        /* LÜFTER SPECIFIC CSS */
        .fan-icon {
            font-size: 1.5rem;
            display: inline-block;
            transition: color 0.3s;
        }
        .fan-active {
            color: var(--color-active);
            animation: spin 1s linear infinite;
        }
        .fan-inactive {
            color: var(--text-muted);
        }
        @keyframes spin { 100% { transform: rotate(360deg); } }

        /* Toleranzbalken */
        .tolerance-bar-container {
            margin-top: 0.5rem; height: 8px; width: 100%;
            background: var(--border); border-radius: 4px; overflow: hidden; position: relative;
        }
        .tolerance-bar { height: 100%; border-radius: 4px; }
        .tolerance-marker {
            position: absolute; top: 0; left: 0; width: 2px; height: 100%;
            background: var(--text-main); transform: translateX(-50%);
            box-shadow: 0 0 5px rgba(0, 0, 0, 0.5);
        }

        /* GRID SYSTEM */
        .grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 1.1rem;
        }
        .card {
            background: var(--bg-card); border-radius: 1.1rem; padding: 1rem 1rem 0.8rem;
            border: 1px solid var(--border); box-shadow: 0 22px 45px rgba(0,0,0,0.75);
            transition: all 0.2s ease-in-out; cursor: pointer;
        }
        .card:hover { border-color: var(--accent); box-shadow: 0 0 30px rgba(34,197,94,0.4); }
        .card.no-hover { cursor: default; }
        .card.no-hover:hover { border-color: var(--border); box-shadow: 0 22px 45px rgba(0,0,0,0.75); }
        .card h2 { margin: 0 0 0.6rem; font-size: 0.9rem; display: flex; align-items: center; justify-content: space-between; }
        .badge {
            background: rgba(34,197,94,0.18); border-radius: 999px; padding: 0.12rem 0.5rem;
            font-size: 0.7rem; color: var(--accent); border: 1px solid rgba(34,197,94,0.6);
        }
        canvas { max-width: 100%; }
        .footer { text-align: center; margin-top: 1.6rem; font-size: 0.75rem; color: var(--text-muted); }
        .bericht-values {
            display:grid; grid-template-columns:repeat(auto-fit,minmax(120px,1fr));
            gap:0.7rem; flex-grow:1; min-width: 300px;
        }
        .btn-arrow {
            padding:0.35rem 0.6rem; border-radius:999px; border:1px solid var(--border);
            background:#020617; color:var(--text-main); cursor:pointer; font-size:1rem; transition:all 0.2s;
        }
        .btn-arrow:hover { border-color:var(--accent); box-shadow:0 0 12px rgba(34,197,94,0.5); }

        /* Bild im Bericht */
        #bericht-bild {
            width: 160px; height: 120px; object-fit: cover; border-radius: 0.7rem; 
            border: 2px solid var(--accent); background-color: var(--bg); transition: all 0.2s;
        }
        .image-link:hover #bericht-bild {
            border-color: #60a5fa; box-shadow: 0 0 15px rgba(96, 165, 250, 0.6);
        }

        /* MODAL STILE (MIT MOBILE FIX) */
        .modal-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.9); display: none; justify-content: center;
            align-items: center; z-index: 1000; padding: 20px; cursor: zoom-out;
        }
        .modal-content { 
            background: var(--bg-card); padding: 1.5rem; border-radius: 1.1rem;
            border: 1px solid var(--border); box-shadow: 0 0 50px rgba(34,197,94,0.7);
            position: relative; cursor: default; display: flex; flex-direction: column;
            width: 900px; height: 90%; max-width: 95%; max-height: 95%;
        }
        .modal-chart-container { flex-grow: 1; min-height: 0; }
        .image-modal-content {
            background: none; border: none; box-shadow: none; width: auto; height: auto; padding: 0;
            display:flex; justify-content: center; align-items: center;
        }
        .image-modal-content img { max-width: 100%; max-height: 100%; object-fit: contain; border-radius: 1.1rem; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; font-size: 1.2rem; font-weight: bold; }
        .modal-close {
            background: none; border: none; color: var(--text-main); font-size: 1.5rem;
            cursor: pointer; line-height: 1; padding: 0; opacity: 0.7; transition: opacity 0.2s;
        }
        .modal-close:hover { opacity: 1; }
        .image-modal-content .modal-close {
            position: absolute; top: 1rem; right: 1.5rem; z-index: 100; color: white; opacity: 1;
            background-color: rgba(0,0,0,0.5); border-radius: 50%; width: 2rem; height: 2rem;
            display: flex; justify-content: center; align-items: center; line-height: 1.5rem; font-weight: bold;
        }

        @media (max-width: 768px) {
            .modal-content { height: auto; max-height: 85vh; width: 100%; }
            .modal-content:not(.image-modal-content) .modal-chart-container { height: 350px; }
        }
    </style>
</head>
<body>
<div class="shell">
    <div class="header">
        <div class="duck">
            <img src="ente.jpeg" alt="Ente">
        </div>
        <div>
            <h1>Grüner Daumen · Pflanzen-Monitor</h1>
            <p class="subtitle">
                Eisbergsalat · Temperatur, Luftfeuchte, pH-Wert & Licht · Fake-Daten aus MySQL · überwacht von einer Ente 🦆
            </p>
        </div>
    </div>
    
    <div id="current-values-display" class="current-values">
        <p style="color:var(--text-muted);">Lade aktuelle Werte...</p>
    </div>

    <div class="grid">
        <div class="card" id="card-temp" data-key="temp" data-label="Temperatur" data-unit="°C" data-color="var(--temp-color)">
            <h2>Temperatur (°C) <span class="badge">Sensor T1</span></h2>
            <canvas id="tempChart"></canvas>
        </div>
        <div class="card" id="card-hum" data-key="hum" data-label="Luftfeuchte" data-unit="%" data-color="var(--hum-color)">
            <h2>Luftfeuchte (%) <span class="badge">Sensor H1</span></h2>
            <canvas id="humChart"></canvas>
        </div>
        <div class="card" id="card-ph" data-key="ph" data-label="pH-Wert" data-unit="" data-color="var(--ph-color)">
            <h2>pH-Wert <span class="badge">Sensor pH1</span></h2>
            <canvas id="phChart"></canvas>
        </div>
        <div class="card" id="card-light" data-key="light" data-label="Licht" data-unit="lx" data-color="var(--light-color)">
            <h2>Licht (Lux) <span class="badge">Sensor L1</span></h2>
            <canvas id="lightChart"></canvas>
        </div>
    </div>

    <div class="card no-hover" style="margin-top:1.1rem; cursor:default;">
        <h2>
            Letzter Bericht
            <span class="badge" id="bericht-index">Datensatz 0 / 0</span>
        </h2>
        
        <div style="display:flex; justify-content:space-between; gap:1.5rem; flex-wrap:wrap; align-items:center;">
            
            <div style="display:flex; flex-direction:column; gap:0.5rem; flex-shrink:0;">
                <div>
                    <div style="font-size:0.8rem; color:var(--text-muted); margin-bottom:0.25rem;">Zeitstempel</div>
                    <div id="bericht-zeit" style="font-size:1rem; font-weight:600;"></div>
                </div>
                <div id="bericht-image-wrapper" class="image-link" style="cursor:zoom-in;">
                    <img id="bericht-bild" src="" alt="Aktuelles Pflanzenbild">
                </div>
            </div>
            
            <div class="bericht-values">
                <div><div style="font-size:0.75rem; color:var(--text-muted);">Temperatur</div><div id="bericht-temp" style="font-size:1.1rem; font-weight:600;">–</div></div>
                <div><div style="font-size:0.75rem; color:var(--text-muted);">Luftfeuchte</div><div id="bericht-hum" style="font-size:1.1rem; font-weight:600;">–</div></div>
                <div><div style="font-size:0.75rem; color:var(--text-muted);">pH-Wert</div><div id="bericht-ph" style="font-size:1.1rem; font-weight:600;">–</div></div>
                <div><div style="font-size:0.75rem; color:var(--text-muted);">Licht</div><div id="bericht-licht" style="font-size:1.1rem; font-weight:600;">–</div></div>
            </div>
            
            <div style="display:flex; gap:0.6rem; flex-shrink:0;">
                <button id="prev-report" class="btn-arrow">◀</button>
                <button id="next-report" class="btn-arrow">▶</button>
            </div>
        </div>
    </div>

    <p class="footer">
        Demo-Dashboard · Später kann dein Raspberry Pi hier live Daten schreiben.
    </p>
</div>

<div id="fullscreen-modal" class="modal-overlay">
    <div id="modal-content-wrapper" class="modal-content" onclick="event.stopPropagation()">
        <div class="modal-header">
            <span id="modal-title">Diagramm-Titel</span>
            <button class="modal-close" onclick="closeModal()">×</button>
        </div>
        <div class="modal-chart-container">
            <canvas id="fullscreenChart"></canvas>
        </div>
    </div>
</div>

<script>
// --- DATEN ---
const labels = <?php echo $js_labels; ?>;
const temp   = <?php echo $js_temp; ?>;
const hum    = <?php echo $js_hum; ?>;
const ph     = <?php echo $js_ph; ?>;
const light  = <?php echo $js_light; ?>;
const rows   = <?php echo $js_rows; ?>;

const dataSets = {
    temp: { data: temp, label: 'Temperatur', color: '#fb923c' },
    hum: { data: hum, label: 'Luftfeuchte', color: '#22c55e' },
    ph: { data: ph, label: 'pH-Wert', color: '#60a5fa' },
    light: { data: light, label: 'Licht', color: '#eab308' }
};

let chartInstances = {}; 
let fullChart = null; 
let modalMode = 'chart'; 

// --- KONFIGURATION WERTE & LÜFTER-LOGIK ---
const fixedValues = {
    'temp': { label: 'Aktuelle Temperatur', value: 23.5, unit: ' °C', min: 18.0, tolMin: 20.0, tolMax: 23.0, max: 28.0, format: 1 },
    'hum': { label: 'Aktuelle Luftfeuchte', value: 65.4, unit: ' %', min: 50.0, tolMin: 60.0, tolMax: 75.0, max: 90.0, format: 1 },
    'ph': { label: 'Aktueller pH-Wert', value: 6.03, unit: '', min: 5.0, tolMin: 5.8, tolMax: 6.5, max: 8.0, format: 2 },
    'light': { label: 'Aktuelles Licht', value: 1550, unit: ' lx', min: 800, tolMin: 1200, tolMax: 2000, max: 3000, format: 0 }
};

const modalContentHtml = `
    <div class="modal-header">
        <span id="modal-title">Diagramm-Titel</span>
        <button class="modal-close" onclick="closeModal()">×</button>
    </div>
    <div class="modal-chart-container">
        <canvas id="fullscreenChart"></canvas>
    </div>
`;

// --- CHARTS (KLEIN) ---
const baseOptions = {
    responsive: true,
    plugins: {
        legend: { display: false },
        tooltip: { mode: 'index', intersect: false, backgroundColor: 'rgba(15,23,42,0.95)', borderColor: '#4b5563', borderWidth: 1, titleColor: '#e5e7eb', bodyColor: '#e5e7eb' }
    },
    interaction: { mode: 'index', intersect: false },
    scales: {
        x: { ticks: { color: '#9ca3af', maxRotation: 45, minRotation: 45 }, grid: { color: 'rgba(30,64,175,0.25)' } },
        y: { ticks: { color: '#9ca3af' }, grid: { color: 'rgba(30,64,175,0.18)' } }
    }
};

function makeFancyLineChart(id, label, data, color) {
    const ctx = document.getElementById(id).getContext('2d');
    const gradient = ctx.createLinearGradient(0, 0, 0, 260);
    gradient.addColorStop(0, color + 'bf'); 
    gradient.addColorStop(1, color + '00'); 

    return new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: label, data: data, borderColor: color, backgroundColor: gradient,
                pointBackgroundColor: '#020617', pointBorderColor: color, pointHoverRadius: 6, pointRadius: 3, borderWidth: 2.4, tension: 0.35, fill: true
            }]
        },
        options: {
            ...baseOptions,
            maintainAspectRatio: true, 
            elements: { line: { borderJoinStyle: 'round' } },
            scales: {
                ...baseOptions.scales,
                y: { ...baseOptions.scales.y, ticks: { ...baseOptions.scales.y.ticks, maxTicksLimit: 5 } }
            }
        }
    });
}

chartInstances.temp = makeFancyLineChart('tempChart', dataSets.temp.label, dataSets.temp.data, dataSets.temp.color);
chartInstances.hum = makeFancyLineChart('humChart', dataSets.hum.label, dataSets.hum.data, dataSets.hum.color);
chartInstances.ph = makeFancyLineChart('phChart', dataSets.ph.label, dataSets.ph.data, dataSets.ph.color);
chartInstances.light = makeFancyLineChart('lightChart', dataSets.light.label, dataSets.light.data, dataSets.light.color);


// ===== AKTUELLE WERTE RENDERN (MIT LÜFTER 20-30%) =====

function renderFixedValues() {
    const container = document.getElementById('current-values-display');
    let html = '';
    const colorOk = getComputedStyle(document.documentElement).getPropertyValue('--color-ok').trim();
    const colorCritical = getComputedStyle(document.documentElement).getPropertyValue('--color-critical').trim();

    // 1. Sensordaten Rendern (Temperatur, Feuchte, pH, Licht)
    for (const key in fixedValues) {
        const item = fixedValues[key];
        const { value, unit, min, tolMin, tolMax, max, format } = item;
        const formattedValue = value.toFixed(format);

        const isInTolerance = value >= tolMin && value <= tolMax;
        const statusClass = isInTolerance ? 'status-ok' : 'status-critical';

        const rangeTotal = max - min; 
        const tolMinPercent = ((tolMin - min) / rangeTotal) * 100;
        const tolMaxPercent = ((tolMax - min) / rangeTotal) * 100;
        let valuePercent = Math.max(0, Math.min(100, ((value - min) / rangeTotal) * 100)); 
        
        const gradientCss = `linear-gradient(to right, 
            ${colorCritical} 0%, ${colorCritical} ${tolMinPercent.toFixed(1)}%, 
            ${colorOk} ${tolMinPercent.toFixed(1)}%, ${colorOk} ${tolMaxPercent.toFixed(1)}%, 
            ${colorCritical} ${tolMaxPercent.toFixed(1)}%, ${colorCritical} 100%)`;

        html += `
            <div class="value-item">
                <span class="label">${item.label}</span>
                <span class="value ${statusClass}">${formattedValue}${unit}</span>
                <div class="tolerance-bar-container">
                    <div class="tolerance-bar" style="background: ${gradientCss};"></div>
                    <div class="tolerance-marker" style="left: ${valuePercent.toFixed(1)}%;"></div>
                </div>
            </div>`;
    }

    // 2. LÜFTER HINZUFÜGEN (Simuliert 20-30%)
    // Generiert eine zufällige Zahl zwischen 20 und 30
    const fanSpeed = Math.floor(Math.random() * (30 - 20 + 1)) + 20;
    
    // Icon mit Dreh-Animation
    const fanIcon = `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="animation: spin 1s linear infinite; display:inline-block; margin-right:5px;"><path d="M12 12c0-3 2.5-3 3.5-6 .6-1.8-1.2-3.6-3-3C6 4.5 6 12 6 12s-7.5 0-10.5 2.5c-1.8.6-3.6-1.2-3-3 1.5-2.5 7.5-2.5 10.5-2.5zm0 0c0 3-2.5 3-3.5 6-.6 1.8 1.2 3.6 3 3 6.5-1.5 6.5-9 6.5-9s7.5 0 10.5-2.5c1.8-.6 3.6 1.2 3 3-1.5 2.5-7.5 2.5-10.5 2.5z"/></svg>`;
    
    // Blauer Verlauf für den Lüfter-Balken
    const fanGradient = `linear-gradient(to right, #334155, #3b82f6)`;

    html += `
        <div class="value-item">
            <span class="label">Abluft Ventilator</span>
            <span class="value" style="color: var(--color-active); display: flex; align-items: center;">
                ${fanIcon} ${fanSpeed} %
            </span>
            <div class="tolerance-bar-container">
                <div class="tolerance-bar" style="background: ${fanGradient};"></div>
                <div class="tolerance-marker" style="left: ${fanSpeed}%;"></div>
            </div>
        </div>
    `;

    container.innerHTML = html;
}
renderFixedValues();


// --- MODAL FUNKTIONEN ---
function closeModal() {
    const modal = document.getElementById('fullscreen-modal');
    const content = document.getElementById('modal-content-wrapper');
    modal.style.display = 'none'; 
    if (modalMode === 'image') {
        content.innerHTML = modalContentHtml; 
        content.classList.remove('image-modal-content');
    }
    modalMode = 'chart';
    if (fullChart) { fullChart.destroy(); fullChart = null; }
}

function openImageModal(src) {
    if (fullChart) { fullChart.destroy(); fullChart = null; }
    
    const modal = document.getElementById('fullscreen-modal');
    const content = document.getElementById('modal-content-wrapper');
    
    modalMode = 'image';
    content.classList.add('image-modal-content');
    content.innerHTML = `<button class="modal-close" onclick="closeModal()">×</button><img src="${src}" alt="Vollbild">`;
    modal.style.display = 'flex';
}

function openChartModal(cardElement) {
    if (fullChart) { fullChart.destroy(); }
    
    const modal = document.getElementById('fullscreen-modal');
    const content = document.getElementById('modal-content-wrapper');
    
    content.innerHTML = modalContentHtml;
    content.classList.remove('image-modal-content');
    modalMode = 'chart';

    const key = cardElement.dataset.key;
    const label = cardElement.dataset.label;
    const data = dataSets[key].data;
    
    document.getElementById('modal-title').textContent = `${label} - Vollbildansicht`;
    
    const newConfig = {
        type: 'line',
        data: { labels: labels, datasets: [{ ...chartInstances[key].data.datasets[0], data: data }] },
        options: {
            ...baseOptions,
            maintainAspectRatio: false, // Wichtig für Fullscreen
            elements: { line: { borderJoinStyle: 'round' } },
            scales: { ...baseOptions.scales, y: { ...baseOptions.scales.y, ticks: { ...baseOptions.scales.y.ticks, maxTicksLimit: 20 } } }
        }
    };

    fullChart = new Chart(document.getElementById('fullscreenChart').getContext('2d'), newConfig);
    modal.style.display = 'flex';
}

// Event Listeners
document.getElementById('card-temp').addEventListener('click', function() { openChartModal(this); });
document.getElementById('card-hum').addEventListener('click', function() { openChartModal(this); });
document.getElementById('card-ph').addEventListener('click', function() { openChartModal(this); });
document.getElementById('card-light').addEventListener('click', function() { openChartModal(this); });
document.getElementById('fullscreen-modal').addEventListener('click', closeModal);

document.getElementById('bericht-image-wrapper').addEventListener('click', function(e) {
    e.stopPropagation(); 
    if (rows.length === 0) return;
    const cleanSrc = rows[currentIndex].bild_url; 
    if (cleanSrc && cleanSrc.length > 0) { 
        openImageModal(`${cleanSrc}?rand=${Date.now()}`);
    }
});


// --- NAVIGATION ---
let currentIndex = rows.length > 0 ? rows.length - 1 : 0;

function updateReport() {
    if (rows.length === 0) return;
    const r = rows[currentIndex];
    
    document.getElementById('bericht-zeit').textContent  = r.zeitpunkt;
    document.getElementById('bericht-temp').textContent  = parseFloat(r.temperatur).toFixed(1) + " °C";
    document.getElementById('bericht-hum').textContent   = parseFloat(r.luftfeuchte).toFixed(1) + " %";
    document.getElementById('bericht-ph').textContent    = parseFloat(r.ph).toFixed(2);
    document.getElementById('bericht-licht').textContent = r.licht + " lx";
    document.getElementById('bericht-index').textContent = `Datensatz ${currentIndex + 1} / ${rows.length}`;

    const bildElement = document.getElementById('bericht-bild');
    bildElement.src = `${r.bild_url}?v=${currentIndex}`;
}

document.getElementById('prev-report').addEventListener('click', () => {
    if (rows.length === 0) return;
    currentIndex = (currentIndex - 1 + rows.length) % rows.length;
    updateReport();
});
document.getElementById('next-report').addEventListener('click', () => {
    if (rows.length === 0) return;
    currentIndex = (currentIndex + 1) % rows.length;
    updateReport();
});

// Init
updateReport();
</script>
</body>
</html>