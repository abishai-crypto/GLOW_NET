<?php
// portal.php - GLOW NET Premium Glassmorphic Captive Portal
require_once 'config.php';

$station_code = $_GET['station'] ?? 'GLOW-KIMARA';
$mac_address  = strtoupper(trim($_GET['mac'] ?? '44:65:0E:11:22:33'));
$ip_address   = trim($_GET['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));

$stmt = $pdo->prepare("SELECT * FROM stations WHERE station_code = ? LIMIT 1");
$stmt->execute([$station_code]);
$station = $stmt->fetch();

if (!$station) {
    $station = $pdo->query("SELECT * FROM stations LIMIT 1")->fetch();
}

if (!$station) {
    die("<div style='font-family:sans-serif;max-width:500px;margin:80px auto;padding:30px;background:#0f172a;color:#fff;border-radius:20px;text-align:center;'>Hakuna kituo kilichosajiliwa.</div>");
}

// Hakiki kama subscription ya kituo hiki imekwisha au kimesimamishwa
$isStationExpired = ($station['subscription_status'] !== 'active' || strtotime($station['subscription_expires_at']) <= time());

if ($isStationExpired) {
    ?>
    <!DOCTYPE html>
    <html lang="sw" class="dark">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
        <title>GLOW NET Wi-Fi | Huduma Imesimamishwa</title>
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
        <script src="https://cdn.tailwindcss.com"></script>
        <style>body { font-family: 'Plus Jakarta Sans', sans-serif; }</style>
    </head>
    <body class="bg-slate-950 text-slate-100 min-h-screen flex items-center justify-center p-4">
        <div class="max-w-md w-full bg-slate-900/90 border border-slate-800 rounded-3xl p-6 sm:p-8 shadow-2xl text-center space-y-6">
            <div class="w-16 h-16 mx-auto rounded-3xl bg-rose-500/15 border border-rose-500/30 flex items-center justify-center text-rose-400">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
            </div>
            <div>
                <span class="px-3 py-1 rounded-full text-[10px] font-black bg-rose-500/20 text-rose-400 border border-rose-500/30 uppercase tracking-widest">
                    Huduma Imesimamishwa kwa Muda
                </span>
                <h2 class="text-xl font-black text-white mt-3"><?= htmlspecialchars($station['station_name']) ?></h2>
                <p class="text-xs text-slate-400 mt-2 leading-relaxed">
                    Huduma ya Wi-Fi katika eneo hili imesimamishwa kwa muda kwa ajili ya maboresho au usasishaji wa ada ya mwezi ya kituo.
                </p>
            </div>

            <div class="p-4 rounded-2xl bg-slate-950 border border-slate-800 text-left text-xs space-y-2">
                <div class="flex justify-between">
                    <span class="text-slate-400">Kituo:</span>
                    <span class="font-bold text-white"><?= htmlspecialchars($station['station_name']) ?></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-400">Eneo:</span>
                    <span class="text-slate-300"><?= htmlspecialchars($station['location']) ?></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-400">Msimamizi wa Kituo:</span>
                    <span class="text-slate-300"><?= htmlspecialchars($station['owner_name']) ?></span>
                </div>
            </div>

            <div class="space-y-2 pt-2">
                <a href="tel:<?= htmlspecialchars($station['owner_phone']) ?>" class="w-full py-3.5 rounded-2xl bg-amber-500 hover:bg-amber-400 text-slate-950 font-black text-xs flex items-center justify-center space-x-2 transition shadow-lg shadow-amber-500/20">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path></svg>
                    <span>Piga Simu kwa Mmiliki: <?= htmlspecialchars($station['owner_phone']) ?></span>
                </a>
                <a href="login.php" class="block py-2 text-xs text-slate-500 hover:text-slate-300 underline transition">
                    Wewe ni Mmiliki wa Kituo? Ingia Hapa Kulipia Ada
                </a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$bStmt = $pdo->prepare("SELECT * FROM bundles WHERE station_id = ? AND is_active = 1 ORDER BY price ASC");
$bStmt->execute([$station['id']]);
$bundles = $bStmt->fetchAll();

// Angalia kama MAC hii tayari ina session ya hewani inayofanya kazi
$activeCheck = $pdo->prepare("
    SELECT a.*, b.bundle_name, b.speed_limit 
    FROM active_sessions a 
    JOIN bundles b ON a.bundle_id = b.id 
    WHERE a.station_id = ? AND a.mac_address = ? AND a.status = 'online' AND a.expires_at > NOW() 
    ORDER BY a.id DESC LIMIT 1
");
$activeCheck->execute([$station['id'], $mac_address]);
$currentSession = $activeCheck->fetch();
$hasActiveSession = $currentSession ? true : false;
$remainingSeconds = $hasActiveSession ? max(0, strtotime($currentSession['expires_at']) - time()) : 0;
?>
<!DOCTYPE html>
<html lang="sw" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>GLOW NET Wi-Fi | <?= htmlspecialchars($station['station_name']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['"Plus Jakarta Sans"', 'system-ui', 'sans-serif'],
                    },
                    colors: {
                        brand: {
                            50: '#fffbeb',
                            100: '#fef3c7',
                            400: '#fbbf24',
                            500: '#f59e0b',
                            600: '#d97706',
                        }
                    }
                }
            }
        }
    </script>
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: #030712;
            background-image: 
                radial-gradient(at 0% 0%, rgba(245, 158, 11, 0.15) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(16, 185, 129, 0.12) 0px, transparent 50%),
                radial-gradient(at 50% 50%, rgba(14, 165, 233, 0.08) 0px, transparent 50%);
            background-attachment: fixed;
        }
        .glass-card {
            background: rgba(15, 23, 42, 0.75);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
        .glass-card-glow {
            box-shadow: 0 0 35px -5px rgba(245, 158, 11, 0.18);
        }
        .pulse-dot {
            animation: pulseDot 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
        @keyframes pulseDot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: .4; transform: scale(1.15); }
        }
    </style>
</head>
<body class="text-slate-100 min-h-screen flex flex-col justify-between p-4 antialiased">

    <!-- Top Header -->
    <header class="w-full max-w-md mx-auto pt-4 pb-2 text-center">
        <div class="flex items-center justify-between mb-4 px-1">
            <div class="flex items-center space-x-2">
                <span class="relative flex h-2.5 w-2.5">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-emerald-500"></span>
                </span>
                <span class="text-[11px] font-semibold text-emerald-400 uppercase tracking-wider">Wi-Fi Ipo Hewani</span>
            </div>
            <div class="text-[11px] font-mono text-slate-400 bg-slate-900/80 px-2.5 py-1 rounded-full border border-slate-800">
                MAC: <?= htmlspecialchars($mac_address) ?>
            </div>
        </div>

        <div class="inline-flex items-center justify-center w-16 h-16 rounded-3xl bg-gradient-to-tr from-amber-500 to-yellow-400 text-slate-950 font-black text-3xl shadow-xl shadow-amber-500/25 mb-3">
            GN
        </div>
        <h1 class="text-2xl font-black text-white tracking-tight">GLOW NET <span class="text-amber-400">HOTSPOT</span></h1>
        <p class="text-xs text-slate-400 mt-1 flex items-center justify-center space-x-1">
            <svg class="w-3.5 h-3.5 text-amber-400 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
            <span>Kituo: <strong class="text-slate-200"><?= htmlspecialchars($station['station_name']) ?></strong></span>
        </p>
    </header>

    <main class="w-full max-w-md mx-auto my-auto space-y-4">

        <!-- 1. KADI YA MWANANCHI ALIYEKWISHAUNGANISHWA (ACTIVE SESSION VIEW) -->
        <div id="connectedCard" class="<?= $hasActiveSession ? '' : 'hidden' ?> glass-card glass-card-glow rounded-3xl p-6 text-center border-emerald-500/40">
            <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 mb-3">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
            </div>
            <h2 class="text-xl font-extrabold text-emerald-400">UMEUNGANISHWA KWENYE INTERNET!</h2>
            <p id="connBundleName" class="text-sm font-bold text-slate-200 mt-1"><?= $hasActiveSession ? htmlspecialchars($currentSession['bundle_name']) : '' ?></p>
            <p class="text-xs text-slate-400">Kasi ya Mtandao: <span id="connSpeed" class="text-amber-400 font-semibold"><?= $hasActiveSession ? htmlspecialchars($currentSession['speed_limit']) : '5M/5M' ?></span></p>

            <div class="my-5 p-5 rounded-2xl bg-slate-950/80 border border-slate-800">
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1">Muda Wako Uliobaki:</p>
                <div id="countdownTimer" class="text-3xl font-black font-mono text-amber-400 tracking-wider">00:00:00</div>
                <p id="connExpiry" class="text-[11px] text-slate-500 mt-2 font-mono">
                    Inamalizika: <?= $hasActiveSession ? htmlspecialchars($currentSession['expires_at']) : '' ?>
                </p>
            </div>

            <div class="flex space-x-2">
                <button onclick="location.reload()" class="w-full py-3 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 font-bold text-xs transition">
                    Onyesha Vifurushi Vingine
                </button>
                <button onclick="disconnectSession('<?= $hasActiveSession ? htmlspecialchars($currentSession['auth_reference']) : '' ?>')" class="py-3 px-4 rounded-xl bg-rose-500/20 hover:bg-rose-500/30 text-rose-300 font-bold text-xs border border-rose-500/40 transition">
                    Toka
                </button>
            </div>
        </div>

        <!-- 2. KADI YA KUNUNUA / KUWEKA VOCHA (PURCHASE CARD) -->
        <div id="purchaseContainer" class="<?= $hasActiveSession ? 'hidden' : '' ?> space-y-4">
            
            <!-- Navigation Switcher -->
            <div class="grid grid-cols-2 p-1.5 rounded-2xl bg-slate-900/90 border border-slate-800 backdrop-blur-md">
                <button id="tabBtnPay" onclick="switchTab('pay')" class="py-2.5 text-xs sm:text-sm font-extrabold rounded-xl bg-amber-500 text-slate-950 transition flex items-center justify-center space-x-1.5 shadow-md">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"></path></svg>
                    <span>Lipa kwa Simu</span>
                </button>
                <button id="tabBtnVoucher" onclick="switchTab('voucher')" class="py-2.5 text-xs sm:text-sm font-extrabold rounded-xl text-slate-400 hover:text-white transition flex items-center justify-center space-x-1.5">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z"></path></svg>
                    <span>Vocha (PIN / QR)</span>
                </button>
            </div>

            <div id="statusAlert" class="hidden p-4 rounded-2xl text-xs font-semibold"></div>

            <!-- TAB 1: LIPA KWA SIMU (MOBILE MONEY) -->
            <div id="sectionPay" class="glass-card rounded-3xl p-5 shadow-2xl space-y-4">
                <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                    <h3 class="text-sm font-extrabold text-white">Chagua Kifurushi Chako:</h3>
                    <span class="text-[10px] text-amber-400 font-bold bg-amber-500/10 border border-amber-500/30 px-2 py-0.5 rounded-full">Bila Kikomo</span>
                </div>

                <form id="mobilePayForm" onsubmit="processMobilePayment(event)" class="space-y-4">
                    <input type="hidden" name="action" value="pay_bundle">
                    <input type="hidden" name="station_id" value="<?= $station['id'] ?>">
                    <input type="hidden" name="mac_address" value="<?= htmlspecialchars($mac_address) ?>">

                    <div class="space-y-2 max-h-56 overflow-y-auto pr-1">
                        <?php foreach ($bundles as $index => $b): ?>
                        <label class="relative flex items-center justify-between p-3.5 rounded-2xl border border-slate-800 bg-slate-950/60 hover:border-amber-500/60 cursor-pointer transition group">
                            <div class="flex items-center space-x-3">
                                <input type="radio" name="bundle_id" value="<?= $b['id'] ?>" <?= $index === 0 ? 'checked' : '' ?> class="w-4 h-4 text-amber-500 focus:ring-amber-500 bg-slate-900 border-slate-700">
                                <div>
                                    <div class="font-extrabold text-white text-xs sm:text-sm group-hover:text-amber-400 transition"><?= htmlspecialchars($b['bundle_name']) ?></div>
                                    <div class="text-[11px] text-slate-400">
                                        Muda: <?= round($b['duration_minutes']/60, 1) ?>h • Kasi: <span class="text-slate-300 font-semibold"><?= htmlspecialchars($b['speed_limit']) ?></span>
                                    </div>
                                </div>
                            </div>
                            <span class="bg-amber-500/15 border border-amber-500/30 text-amber-400 font-black px-3 py-1 rounded-xl text-xs">
                                Tsh <?= number_format($b['price'], 0) ?>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>

                    <!-- Input ya Simu yenye Utambuzi wa Mtandao Otomatiki -->
                    <div class="space-y-1.5">
                        <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-wider">Namba ya Simu ya Malipo:</label>
                        <div class="relative">
                            <input type="tel" name="phone_number" id="phone_input" oninput="detectNetwork(this.value)" required placeholder="Mfano: 0755 123 456 au 0714..."
                                class="w-full px-4 py-3.5 rounded-2xl bg-slate-950 border border-slate-800 text-white placeholder-slate-600 focus:outline-none focus:border-amber-500 text-sm font-semibold tracking-wide">
                            <div id="networkBadge" class="absolute right-3 top-3 hidden px-2 py-1 rounded-lg text-[10px] font-extrabold uppercase"></div>
                        </div>
                        <p class="text-[10px] text-slate-500">Inakubali: <b>Vodacom (M-Pesa)</b>, <b>Tigo/Mixx</b>, <b>Airtel Money</b> na <b>Halopesa</b>.</p>
                    </div>

                    <button type="submit" id="btnPaySubmit" class="w-full py-4 rounded-2xl bg-gradient-to-r from-amber-500 to-yellow-400 text-slate-950 font-black text-sm shadow-xl shadow-amber-500/20 hover:opacity-95 transition flex items-center justify-center space-x-2">
                        <span>LIPA SASA & UNGANISHA WI-FI</span>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                    </button>
                </form>
            </div>

            <!-- TAB 2: WEKA VOCHA (PIN / CAMERA SCANNER) -->
            <div id="sectionVoucher" class="hidden glass-card rounded-3xl p-5 shadow-2xl space-y-4">
                <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                    <h3 class="text-sm font-extrabold text-white">Ingiza Vocha ya Kimwili (PIN)</h3>
                    <button type="button" onclick="toggleCameraScanner()" class="text-[11px] text-emerald-400 font-bold bg-emerald-500/10 border border-emerald-500/30 px-2.5 py-1 rounded-full flex items-center space-x-1">
                        <svg class="w-3.5 h-3.5 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"></path></svg>
                        <span id="qrBtnText">Scan Vocha kwa Camera</span>
                    </button>
                </div>

                <!-- QR Scanner Area -->
                <div id="qrScannerContainer" class="hidden rounded-2xl overflow-hidden border border-slate-800 bg-slate-950 p-2">
                    <div id="qrReader" style="width: 100%;"></div>
                </div>

                <form id="voucherForm" onsubmit="processVoucher(event)" class="space-y-4">
                    <input type="hidden" name="action" value="redeem_voucher">
                    <input type="hidden" name="station_id" value="<?= $station['id'] ?>">
                    <input type="hidden" name="mac_address" value="<?= htmlspecialchars($mac_address) ?>">
                    <input type="hidden" name="ip_address" value="<?= htmlspecialchars($ip_address) ?>">

                    <div>
                        <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-1.5">Weka Kodi ya Vocha (PIN):</label>
                        <input type="text" name="voucher_code" id="voucher_code_input" required placeholder="Mfano: GN100200"
                            class="w-full px-4 py-4 rounded-2xl bg-slate-950 border border-slate-800 text-center uppercase font-mono text-xl font-black text-amber-400 placeholder-slate-600 focus:outline-none focus:border-emerald-500 tracking-widest">
                    </div>

                    <button type="submit" id="btnVoucherSubmit" class="w-full py-4 rounded-2xl bg-gradient-to-r from-emerald-500 to-teal-400 text-slate-950 font-black text-sm shadow-xl shadow-emerald-500/20 hover:opacity-95 transition flex items-center justify-center space-x-2">
                        <span>THIBITISHA VOCHA & UNGANISHA</span>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                    </button>
                </form>
            </div>

        </div>

        <!-- Quick Demo Voucher Helper (Kwa Majaribio ya Haraka) -->
        <div class="glass-card rounded-2xl p-3 border-dashed border-slate-800 text-center">
            <p class="text-[11px] text-slate-400 font-medium">Vocha za Jaribio kwa kituo cha <b><?= htmlspecialchars($station['station_name']) ?></b>:</p>
            <div class="flex items-center justify-center space-x-2 mt-2">
                <button type="button" onclick="fillVoucher('GN100100')" class="px-2.5 py-1 rounded-lg bg-slate-950 border border-slate-800 text-amber-400 text-xs font-mono font-bold hover:border-amber-400">GN100100</button>
                <button type="button" onclick="fillVoucher('GN100200')" class="px-2.5 py-1 rounded-lg bg-slate-950 border border-slate-800 text-amber-400 text-xs font-mono font-bold hover:border-amber-400">GN100200</button>
                <button type="button" onclick="fillVoucher('GN300400')" class="px-2.5 py-1 rounded-lg bg-slate-950 border border-slate-800 text-amber-400 text-xs font-mono font-bold hover:border-amber-400">GN300400</button>
            </div>
        </div>

    </main>

    <!-- Footer -->
    <footer class="w-full max-w-md mx-auto pt-4 pb-2 text-center text-xs text-slate-500 space-y-1">
        <div>
            <a href="login.php" class="text-amber-400 hover:underline font-semibold">Login ya Mmiliki wa Kituo</a> • 
            <a href="index.php" class="text-slate-400 hover:underline">Mwanzo</a>
        </div>
        <p class="text-[10px] text-slate-600">GLOW NET Hotspot Billing V2 &copy; <?= date('Y') ?>. Haki zote zimehifadhiwa.</p>
    </footer>

    <script>
        let countdownInterval = null;
        let html5QrScanner = null;
        let isScannerOpen = false;

        <?php if ($hasActiveSession): ?>
        // Anzisha countdown moja kwa moja kama session ipo
        startCountdown(<?= (int)$remainingSeconds ?>);
        <?php endif; ?>

        function switchTab(t) {
            document.getElementById('sectionPay').classList.toggle('hidden', t !== 'pay');
            document.getElementById('sectionVoucher').classList.toggle('hidden', t !== 'voucher');
            
            const btnPay = document.getElementById('tabBtnPay');
            const btnVoucher = document.getElementById('tabBtnVoucher');

            if (t === 'pay') {
                btnPay.className = "py-2.5 text-xs sm:text-sm font-extrabold rounded-xl bg-amber-500 text-slate-950 transition flex items-center justify-center space-x-1.5 shadow-md";
                btnVoucher.className = "py-2.5 text-xs sm:text-sm font-extrabold rounded-xl text-slate-400 hover:text-white transition flex items-center justify-center space-x-1.5";
            } else {
                btnPay.className = "py-2.5 text-xs sm:text-sm font-extrabold rounded-xl text-slate-400 hover:text-white transition flex items-center justify-center space-x-1.5";
                btnVoucher.className = "py-2.5 text-xs sm:text-sm font-extrabold rounded-xl bg-emerald-500 text-slate-950 transition flex items-center justify-center space-x-1.5 shadow-md";
            }
        }

        function showAlert(msg, ok) {
            const b = document.getElementById('statusAlert');
            b.classList.remove('hidden');
            b.className = ok 
                ? "p-4 rounded-2xl text-xs font-semibold bg-emerald-500/20 border border-emerald-500/40 text-emerald-300"
                : "p-4 rounded-2xl text-xs font-semibold bg-rose-500/20 border border-rose-500/40 text-rose-300";
            b.innerHTML = msg;
        }

        async function detectNetwork(phone) {
            const badge = document.getElementById('networkBadge');
            if (phone.length < 3) {
                badge.classList.add('hidden');
                return;
            }
            try {
                const res = await fetch('api_engine.php?action=detect_network&phone=' + encodeURIComponent(phone));
                const data = await res.json();
                if (data.success && data.network.name !== 'Mobile Money') {
                    badge.classList.remove('hidden');
                    badge.style.backgroundColor = data.network.bg;
                    badge.style.color = data.network.color;
                    badge.innerText = data.network.name;
                } else {
                    badge.classList.add('hidden');
                }
            } catch(e) {}
        }

        async function processMobilePayment(e) {
            e.preventDefault();
            const btn = document.getElementById('btnPaySubmit');
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="animate-spin inline-block w-4 h-4 border-2 border-slate-950 border-t-transparent rounded-full mr-2"></span> Inatuma Ombi la Malipo...';

            try {
                const fd = new FormData(document.getElementById('mobilePayForm'));
                const res = await fetch('api_engine.php', { method: 'POST', body: fd });
                const data = await res.json();
                showAlert(data.message, data.success);

                if (data.success && data.reference_no) {
                    // Poll na kuanzisha uthibitisho
                    let checkCount = 0;
                    const poller = setInterval(async () => {
                        checkCount++;
                        const r2 = await fetch('api_engine.php?action=check_payment_status&reference_no=' + data.reference_no);
                        const d2 = await r2.json();
                        if (d2.status === 'completed') {
                            clearInterval(poller);
                            showAlert(d2.message, true);
                            setTimeout(() => {
                                showConnected(d2.session_info, data.reference_no);
                            }, 800);
                        }
                        if (checkCount > 15) {
                            clearInterval(poller);
                            btn.disabled = false;
                            btn.innerHTML = originalText;
                        }
                    }, 1500);
                } else {
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                }
            } catch(err) {
                showAlert('Hitilafu ya mtandao: ' + err.message, false);
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        }

        async function processVoucher(e) {
            if (e) e.preventDefault();
            const btn = document.getElementById('btnVoucherSubmit');
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="animate-spin inline-block w-4 h-4 border-2 border-slate-950 border-t-transparent rounded-full mr-2"></span> Inathibitisha Vocha...';

            try {
                const fd = new FormData(document.getElementById('voucherForm'));
                const res = await fetch('api_engine.php', { method: 'POST', body: fd });
                const data = await res.json();
                showAlert(data.message, data.success);

                if (data.success) {
                    setTimeout(() => {
                        showConnected(data.session_info, data.auth_ref);
                    }, 800);
                } else {
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                }
            } catch (err) {
                showAlert('Hitilafu ya mtandao: ' + err.message, false);
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        }

        function showConnected(info, ref) {
            document.getElementById('purchaseContainer').classList.add('hidden');
            const card = document.getElementById('connectedCard');
            card.classList.remove('hidden');
            document.getElementById('connBundleName').innerText = info.bundle_name;
            document.getElementById('connSpeed').innerText = info.speed_limit;
            document.getElementById('connExpiry').innerText = "Inamalizika: " + info.expires_at;
            startCountdown(parseInt(info.remaining_seconds || 3600, 10));
        }

        function startCountdown(seconds) {
            let rem = seconds;
            if (countdownInterval) clearInterval(countdownInterval);

            function updateDisplay() {
                if (rem <= 0) {
                    clearInterval(countdownInterval);
                    document.getElementById('countdownTimer').innerText = "00:00:00";
                    alert('Muda wako wa Wi-Fi umemalizika!');
                    location.reload();
                    return;
                }
                const h = Math.floor(rem / 3600);
                const m = Math.floor((rem % 3600) / 60);
                const s = rem % 60;
                document.getElementById('countdownTimer').innerText = 
                    String(h).padStart(2, '0') + ":" + 
                    String(m).padStart(2, '0') + ":" + 
                    String(s).padStart(2, '0');
                rem--;
            }

            updateDisplay();
            countdownInterval = setInterval(updateDisplay, 1000);
        }

        async function disconnectSession(ref) {
            if (!confirm('Je, unataka kukata muunganisho wa Wi-Fi?')) return;
            const fd = new FormData();
            fd.append('action', 'disconnect_session');
            fd.append('auth_ref', ref);
            await fetch('api_engine.php', { method: 'POST', body: fd });
            location.reload();
        }

        function fillVoucher(code) {
            switchTab('voucher');
            document.getElementById('voucher_code_input').value = code;
        }

        // Camera QR Code Scanner Functionality
        function toggleCameraScanner() {
            const container = document.getElementById('qrScannerContainer');
            const btnText = document.getElementById('qrBtnText');

            if (!isScannerOpen) {
                container.classList.remove('hidden');
                btnText.innerText = "Funga Camera";
                isScannerOpen = true;

                html5QrScanner = new Html5Qrcode("qrReader");
                html5QrScanner.start(
                    { facingMode: "environment" },
                    { fps: 10, qrbox: { width: 220, height: 220 } },
                    (decodedText) => {
                        // Scan success
                        document.getElementById('voucher_code_input').value = decodedText.trim().toUpperCase();
                        toggleCameraScanner();
                        processVoucher();
                    },
                    (errorMessage) => {}
                ).catch((err) => {
                    showAlert("Camera haikuweza kufunguka: " + err, false);
                    toggleCameraScanner();
                });
            } else {
                if (html5QrScanner) {
                    html5QrScanner.stop().then(() => {
                        container.classList.add('hidden');
                        btnText.innerText = "Scan Vocha kwa Camera";
                        isScannerOpen = false;
                    });
                } else {
                    container.classList.add('hidden');
                    btnText.innerText = "Scan Vocha kwa Camera";
                    isScannerOpen = false;
                }
            }
        }
    </script>
</body>
</html>
