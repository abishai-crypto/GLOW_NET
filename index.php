<?php
// index.php - GLOW NET V2 Control Center & Launchpad
require_once 'config.php';

// Chukua vituo vilivyopo
$stations = $pdo->query("SELECT * FROM stations ORDER BY id ASC")->fetchAll();
$totalOnline = $pdo->query("SELECT COUNT(*) FROM active_sessions WHERE status = 'online' AND expires_at > NOW()")->fetchColumn();
$totalStations = count($stations);
?>
<!DOCTYPE html>
<html lang="sw" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GLOW NET V2 | Hotspot Billing & Multi-Branch Network</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: #030712;
            background-image: 
                radial-gradient(at 0% 0%, rgba(245, 158, 11, 0.12) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(16, 185, 129, 0.1) 0px, transparent 50%);
            background-attachment: fixed;
        }
    </style>
</head>
<body class="text-slate-100 min-h-screen flex items-center justify-center p-4 sm:p-6 antialiased">

    <div class="max-w-4xl w-full bg-slate-900/80 backdrop-blur-xl border border-slate-800 rounded-3xl p-6 sm:p-10 shadow-2xl space-y-8">
        
        <!-- Header -->
        <div class="flex flex-wrap items-center justify-between border-b border-slate-800 pb-6 gap-4">
            <div class="flex items-center space-x-4">
                <div class="w-16 h-16 rounded-3xl bg-gradient-to-tr from-amber-500 to-yellow-400 text-slate-950 font-black text-3xl flex items-center justify-center shadow-xl shadow-amber-500/25">
                    GN
                </div>
                <div>
                    <h1 class="text-2xl sm:text-3xl font-black text-white">GLOW NET <span class="text-amber-400">V4</span></h1>
                    <p class="text-xs text-slate-400 mt-1">Multi-Branch Wi-Fi Billing, Grafu za Mapato (Chart.js), Ripoti za Kina & Branch Subscription</p>
                </div>
            </div>
            <div class="flex items-center space-x-2">
                <span class="px-3 py-1.5 rounded-full bg-emerald-500/15 border border-emerald-500/30 text-emerald-400 text-xs font-bold flex items-center space-x-1.5">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-ping"></span>
                    <span>MySQL: Port <?= $connected_port ?></span>
                </span>
                <span class="px-3 py-1.5 rounded-full bg-purple-500/15 border border-purple-500/30 text-purple-400 text-xs font-bold">
                    Online: <?= $totalOnline ?>
                </span>
            </div>
        </div>

        <!-- Download Banner -->
        <div class="p-4 sm:p-5 rounded-2xl bg-gradient-to-r from-amber-500/15 via-slate-900 to-emerald-500/15 border border-amber-500/30 flex flex-col sm:flex-row justify-between items-center gap-4">
            <div>
                <span class="text-[10px] font-black uppercase tracking-wider px-2 py-0.5 rounded-full bg-amber-500 text-slate-950">V4 Package</span>
                <h3 class="font-extrabold text-white text-sm mt-1">Pakua Mfumo Kamili wa GLOW NET V4 (.ZIP)</h3>
                <p class="text-xs text-slate-400">Pamoja na Grafu za Mapato (Chart.js), Ripoti za Kina za Matawi, na Branch Subscription.</p>
            </div>
            <a href="download_system_zip.php" class="px-5 py-3 rounded-xl bg-gradient-to-r from-amber-500 to-yellow-400 hover:opacity-95 text-slate-950 font-black text-xs shadow-lg shadow-amber-500/20 transition flex items-center space-x-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                <span>Pakua GLOW_NET_V4_SYSTEM.zip</span>
            </a>
        </div>

        <!-- Grid of Access Points -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            
            <a href="login.php" class="p-6 rounded-3xl bg-slate-950/70 border-2 border-amber-500/60 hover:border-amber-400 hover:bg-slate-950 transition group">
                <div class="flex justify-between items-start">
                    <div class="text-amber-400 font-black text-lg group-hover:underline">1. Ingia Kwenye Mfumo (Login)</div>
                    <span class="px-2.5 py-0.5 rounded-md text-[10px] font-extrabold uppercase bg-amber-500/20 text-amber-300">Mlango Mkuu</span>
                </div>
                <p class="text-xs text-slate-400 mt-2 leading-relaxed">
                    Ingia kama <b>Master Admin</b> (`admin` / `admin123`) au kama <b>Mmiliki wa Kituo</b> (`GLOW-KIMARA` / `1234`).
                </p>
            </a>

            <a href="dashboard.php" class="p-6 rounded-3xl bg-slate-950/70 border border-slate-800 hover:border-sky-500 hover:bg-slate-950 transition group">
                <div class="flex justify-between items-start">
                    <div class="text-sky-400 font-black text-lg group-hover:underline">2. Master Admin & Subscriptions</div>
                    <span class="px-2.5 py-0.5 rounded-md text-[10px] font-extrabold uppercase bg-sky-500/20 text-sky-300">Usimamizi Mkuu</span>
                </div>
                <p class="text-xs text-slate-400 mt-2 leading-relaxed">
                    Jopo kuu la kuona matawi yote (<?= $totalStations ?>), kusimamia ada za mwezi (Subscriptions), router za MikroTik, na miamala yote.
                </p>
            </a>

            <a href="branch_dashboard.php" class="p-6 rounded-3xl bg-slate-950/70 border border-slate-800 hover:border-emerald-500 hover:bg-slate-950 transition group">
                <div class="flex justify-between items-start">
                    <div class="text-emerald-400 font-black text-lg group-hover:underline">3. Dashboard ya Mmiliki wa Tawi</div>
                    <span class="px-2.5 py-0.5 rounded-md text-[10px] font-extrabold uppercase bg-emerald-500/20 text-emerald-300">Tawi Binafsi</span>
                </div>
                <p class="text-xs text-slate-400 mt-2 leading-relaxed">
                    Dashboard ya mmiliki: lipia ada ya mwezi ya kituo kwa USSD Push, zalisha vocha, angalia mauzo, na wateja waliopo hewani.
                </p>
            </a>

            <a href="print_vouchers.php" target="_blank" class="p-6 rounded-3xl bg-slate-950/70 border border-slate-800 hover:border-purple-500 hover:bg-slate-950 transition group">
                <div class="flex justify-between items-start">
                    <div class="text-purple-400 font-black text-lg group-hover:underline">4. Uchapishaji wa Vocha (Print)</div>
                    <span class="px-2.5 py-0.5 rounded-md text-[10px] font-extrabold uppercase bg-purple-500/20 text-purple-300">POS & A4</span>
                </div>
                <p class="text-xs text-slate-400 mt-2 leading-relaxed">
                    Chapisha vocha kwa mashine ya risiti ya Bluetooth/USB (Thermal 58mm/80mm) au karatasi ya A4 yenye kadi na QR Code.
                </p>
            </a>

            <a href="reports.php" class="p-6 rounded-3xl bg-slate-950/70 border-2 border-yellow-500/50 hover:border-yellow-400 hover:bg-slate-900 transition group md:col-span-2">
                <div class="flex justify-between items-start">
                    <div class="text-yellow-400 font-black text-lg group-hover:underline flex items-center space-x-2">
                        <span>📑 5. Kituo Kikuu cha Ripoti za Fedha (Financial Reports & Analytics)</span>
                        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-black bg-yellow-500 text-slate-950">V4 Mpya</span>
                    </div>
                    <span class="px-2.5 py-0.5 rounded-md text-[10px] font-extrabold uppercase bg-yellow-500/20 text-yellow-300">Ripoti & PDF</span>
                </div>
                <p class="text-xs text-slate-300 mt-2 leading-relaxed">
                    Uchanganuzi wa kina wa mauzo ya kila kituo: chuja kwa <b>Leo, Wiki, Mwezi au Tarehe Maalum</b>, mgawanyiko wa mitandao (<b>M-Pesa, Tigo, Airtel, Halopesa, Vocha</b>), na kitufe cha <b>Kupakua/Kuchapisha kama PDF</b>.
                </p>
            </a>

        </div>

        <!-- Portals za Wateja wa Wi-Fi -->
        <div class="border-t border-slate-800 pt-6">
            <h3 class="text-sm font-extrabold text-white mb-3">Mifano ya Portals za Wateja Kulingana na Eneo:</h3>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
                <?php foreach ($stations as $st): ?>
                <a href="portal.php?station=<?= urlencode($st['station_code']) ?>" target="_blank" class="p-3.5 rounded-2xl bg-slate-950 border border-slate-800 hover:border-amber-400 transition flex items-center justify-between">
                    <div>
                        <div class="font-bold text-white"><?= htmlspecialchars($st['station_name']) ?></div>
                        <div class="text-[11px] text-slate-400"><?= htmlspecialchars($st['location']) ?></div>
                    </div>
                    <span class="text-amber-400 font-mono font-bold text-[11px]">Fungua &rarr;</span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Footer -->
        <div class="text-center text-xs text-slate-500 border-t border-slate-800 pt-5">
            GLOW NET Hotspot Billing V3 &copy; <?= date('Y') ?>. Imesanifiwa kwa ajili ya biashara za Wi-Fi Hotspot, Matawi, na MikroTik.
        </div>
    </div>

</body>
</html>
