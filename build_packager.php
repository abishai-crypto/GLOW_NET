<?php
// build_packager.php - Generates glownet_v3_packager.html with all 16 production files
$files = [
    'glownet/index.php' => 'index.php',
    'glownet/config.php' => 'config.php',
    'glownet/login.php' => 'login.php',
    'glownet/branch_dashboard.php' => 'branch_dashboard.php',
    'glownet/api_engine.php' => 'api_engine.php',
    'glownet/dashboard.php' => 'dashboard.php',
    'glownet/portal.php' => 'portal.php',
    'glownet/PaymentGateway.php' => 'PaymentGateway.php',
    'glownet/RouterBridge.php' => 'RouterBridge.php',
    'glownet/payment_webhook.php' => 'payment_webhook.php',
    'glownet/cron_disconnect.php' => 'cron_disconnect.php',
    'glownet/print_vouchers.php' => 'print_vouchers.php',
    'glownet/reports.php' => 'reports.php',
    'glownet/glownet_db.sql' => 'glownet_db.sql',
    'glownet/logout.php' => 'logout.php',
    'glownet/mikrotik_login.html' => 'mikrotik_login.html',
    'glownet/MAELEKEZO_YA_KUWASHA.txt' => 'MAELEKEZO_YA_KUWASHA.txt'
];

$html = '<!DOCTYPE html>
<html lang="sw">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GLOW NET V4 - Pakua Mfumo Wenye Ripoti na Grafu za Mapato (.ZIP)</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
</head>
<body class="bg-slate-950 text-white min-h-screen flex items-center justify-center p-4 font-sans">

    <div class="max-w-2xl w-full bg-slate-900 border border-slate-800 rounded-3xl p-8 shadow-2xl space-y-6">
        <div class="flex items-center space-x-4">
            <div class="w-14 h-14 rounded-2xl bg-amber-500 text-slate-950 font-black text-2xl flex items-center justify-center shadow-lg shadow-amber-500/30">
                GN
            </div>
            <div>
                <h1 class="text-2xl font-extrabold text-amber-400">GLOW NET V4 PACKAGER</h1>
                <p class="text-xs text-slate-400">Pamoja na Grafu za Mapato (Chart.js), Ripoti za Kina za Matawi & Branch Subscription</p>
            </div>
        </div>

        <div class="bg-slate-950 border border-slate-800 rounded-2xl p-4 text-xs text-slate-300 space-y-2">
            <p class="font-bold text-emerald-400 text-sm">Mafaili 17 Yaliyomo Ndani ya ZIP Hii (Production Code):</p>
            <div class="grid grid-cols-2 gap-1.5 font-mono text-[11px] text-slate-400">
                <div>1. glownet/index.php</div>
                <div class="text-emerald-400 font-bold">2. glownet/config.php (Auto-Check)</div>
                <div>3. glownet/login.php</div>
                <div class="text-amber-400 font-bold">4. glownet/branch_dashboard.php (Sub Pay)</div>
                <div class="text-amber-400 font-bold">5. glownet/api_engine.php (Renew API)</div>
                <div class="text-sky-400 font-bold">6. glownet/dashboard.php (Charts & Reports)</div>
                <div class="text-emerald-400 font-bold">7. glownet/portal.php (Graceful Expiry)</div>
                <div>8. glownet/PaymentGateway.php</div>
                <div>9. glownet/RouterBridge.php</div>
                <div>10. glownet/payment_webhook.php</div>
                <div class="text-emerald-400 font-bold">11. glownet/cron_disconnect.php</div>
                <div>12. glownet/print_vouchers.php</div>
                <div class="text-amber-400 font-bold">13. glownet/reports.php (Financial Reports)</div>
                <div>14. glownet/glownet_db.sql</div>
                <div>15. glownet/logout.php</div>
                <div>16. glownet/mikrotik_login.html</div>
                <div>17. glownet/MAELEKEZO_YA_KUWASHA.txt</div>
            </div>
        </div>

        <button id="downloadBtn" onclick="generateAndDownloadZip()" class="w-full py-4 rounded-2xl bg-gradient-to-r from-amber-500 to-yellow-400 text-slate-950 font-black text-base shadow-xl shadow-amber-500/20 hover:opacity-95 transition cursor-pointer">
            PAKUA (DOWNLOAD) GLOW_NET_V4_SYSTEM.zip SASA
        </button>

        <p id="statusText" class="text-center text-xs text-emerald-400 hidden"></p>
    </div>
';

foreach ($files as $zipName => $localName) {
    if (file_exists(__DIR__ . '/' . $localName)) {
        $content = file_get_contents(__DIR__ . '/' . $localName);
        $content = str_replace('</script>', '<\/script>', $content);
        $html .= "\n<script type=\"text/plain\" data-filename=\"$zipName\">\n" . $content . "\n</script>\n";
    }
}

$html .= '
<script>
async function generateAndDownloadZip() {
    const btn = document.getElementById("downloadBtn");
    const statusText = document.getElementById("statusText");
    btn.disabled = true;
    btn.innerText = "Inapakia mafaili 16 kwenye ZIP...";

    const zip = new JSZip();
    document.querySelectorAll("script[type=\"text/plain\"][data-filename]").forEach(s => {
        zip.file(s.getAttribute("data-filename"), s.textContent.trim() + "\n");
    });

    const blob = await zip.generateAsync({ type: "blob" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = "GLOW_NET_V4_SYSTEM.zip";
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);

    btn.disabled = false;
    btn.innerText = "PAKUA TENA GLOW_NET_V4_SYSTEM.zip";
    statusText.classList.remove("hidden");
    statusText.innerText = "✓ Faili la GLOW_NET_V4_SYSTEM.zip limepakuliwa kikamilifu!";
}
</script>
</body>
</html>';

file_put_contents(__DIR__ . '/glownet_v4_packager.html', $html);
echo "PACKAGER_HTML_SUCCESS (" . strlen($html) . " bytes)\n";
?>
