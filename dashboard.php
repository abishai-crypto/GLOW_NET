<?php
// dashboard.php - GLOW NET Master Multi-Branch Control & Revenue Center V2
require_once 'config.php';

// Ruhusu Master Admin pekee
if (!isset($_SESSION['glownet_role'])) {
    header('Location: login.php');
    exit;
}
if ($_SESSION['glownet_role'] === 'branch_owner') {
    header('Location: branch_dashboard.php');
    exit;
}

$msg = '';
$msg_type = 'success';

// 1. KUPANGA BUNDLE MPYA KWA KITUO CHOCHOTE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'add_bundle') {
    $stmt = $pdo->prepare("INSERT INTO bundles (station_id, bundle_name, price, duration_minutes, speed_limit) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
        $_POST['station_id'],
        trim($_POST['bundle_name']),
        (float)$_POST['price'],
        (int)$_POST['duration_minutes'],
        trim($_POST['speed_limit'])
    ]);
    $msg = "Kifurushi kipya kimehifadhiwa kikamilifu!";
}

// 2. KUZALISHA VOCHA ZA JUMLA (BULK BATCH VOUCHERS)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'generate_vouchers') {
    $station_id = (int)$_POST['station_id'];
    $bundle_id  = (int)$_POST['bundle_id'];
    $qty        = min(500, max(1, (int)$_POST['quantity']));
    $prefix     = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $_POST['voucher_prefix'] ?? 'GN'));
    $batch_no   = 'BATCH-' . strtoupper(substr(md5(time()), 0, 6));

    $stmt = $pdo->prepare("INSERT IGNORE INTO vouchers (station_id, bundle_id, batch_no, voucher_code) VALUES (?, ?, ?, ?)");
    $createdCount = 0;
    for ($i = 0; $i < $qty; $i++) {
        $code = $prefix . rand(100000, 999999);
        if ($stmt->execute([$station_id, $bundle_id, $batch_no, $code])) {
            $createdCount++;
        }
    }
    $msg = "Vocha $createdCount zimezalishwa kikamilifu! (Batch: $batch_no)";
}

// 3. KUSAJILI KITUO / TAWI JIPYA PAMOJA NA ROUTER CREDENTIALS & ADA YA MWEZI
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'add_station') {
    $owner_pass  = trim($_POST['owner_password'] ?? '1234');
    $monthly_fee = (float)($_POST['monthly_fee'] ?? 10000.00);
    $stmt = $pdo->prepare("
        INSERT INTO stations (station_code, station_name, owner_name, owner_phone, owner_password, location, hardware_type, router_ip, router_port, router_user, router_pass, subscription_status, subscription_expires_at, monthly_fee) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', DATE_ADD(NOW(), INTERVAL 30 DAY), ?)
    ");
    $stmt->execute([
        strtoupper(trim($_POST['station_code'])),
        $_POST['station_name'],
        $_POST['owner_name'],
        $_POST['owner_phone'],
        $owner_pass,
        $_POST['location'],
        $_POST['hardware_type'],
        $_POST['router_ip'] ?: '192.168.88.1',
        (int)($_POST['router_port'] ?: 8728),
        $_POST['router_user'] ?: 'admin',
        $_POST['router_pass'] ?: '',
        $monthly_fee
    ]);
    $msg = "Kituo kipya kimesajiliwa! Mmiliki anaweza kuingia kwa kutumia: " . strtoupper(trim($_POST['station_code'])) . " na Password: " . htmlspecialchars($owner_pass);
}

// 4. ADMIN ACTIONS: ONGEZA MUDA WA SUBSCRIPTION AU BADILI HALI
if (isset($_GET['renew_station'])) {
    $stId = (int)$_GET['renew_station'];
    $days = (int)($_GET['days'] ?? 30);
    $st = $pdo->prepare("SELECT * FROM stations WHERE id = ?");
    $st->execute([$stId]);
    $stRow = $st->fetch();
    if ($stRow) {
        $cur = $stRow['subscription_expires_at'];
        $base = (strtotime($cur) > time()) ? strtotime($cur) : time();
        $newExp = date('Y-m-d H:i:s', strtotime("+$days days", $base));
        $pdo->prepare("UPDATE stations SET subscription_status = 'active', subscription_expires_at = ? WHERE id = ?")->execute([$newExp, $stId]);
        header('Location: dashboard.php?msg=renewed');
        exit;
    }
}
if (isset($_GET['toggle_status'])) {
    $stId = (int)$_GET['toggle_status'];
    $target = $_GET['to'] ?? 'active';
    if (in_array($target, ['active', 'suspended', 'expired'])) {
        $pdo->prepare("UPDATE stations SET subscription_status = ? WHERE id = ?")->execute([$target, $stId]);
        if ($target !== 'active') {
            $pdo->prepare("UPDATE active_sessions SET status = 'disconnected' WHERE station_id = ? AND status = 'online'")->execute([$stId]);
        }
    }
    header('Location: dashboard.php?msg=status_updated');
    exit;
}

if (($_GET['msg'] ?? '') === 'renewed') {
    $msg = "Subscription ya kituo imeongezwa kikamilifu!";
} elseif (($_GET['msg'] ?? '') === 'status_updated') {
    $msg = "Hali ya kituo imesasishwa kikamilifu!";
}

// JUMLA KUU YA MAPATO YA MFUMO WOTE (LEO, WIKI, MWEZI, JUMLA KUU, ADA ZA MATAWI)
$revStats = $pdo->query("
    SELECT 
        COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() THEN amount ELSE 0 END), 0) AS mapato_leo,
        COALESCE(SUM(CASE WHEN YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1) THEN amount ELSE 0 END), 0) AS mapato_wiki,
        COALESCE(SUM(CASE WHEN YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE()) THEN amount ELSE 0 END), 0) AS mapato_mwezi,
        COALESCE(SUM(amount), 0) AS jumla_kuu,
        COALESCE(SUM(CASE WHEN payment_type = 'branch_subscription' THEN amount ELSE 0 END), 0) AS mapato_ada_matawi,
        COALESCE(SUM(CASE WHEN payment_type != 'branch_subscription' THEN amount ELSE 0 END), 0) AS mapato_vifurushi_wifi,
        COUNT(id) AS jumla_miamala
    FROM transactions
    WHERE status = 'completed'
")->fetch();

// TAKWIMU KWA KILA KITUO (PAMOJA NA SUBSCRIPTION STATUS & EXPIRY)
$stationRevenues = $pdo->query("
    SELECT 
        s.id, s.station_code, s.station_name, s.owner_name, s.owner_phone, s.owner_password, s.location, s.hardware_type, s.router_ip, s.router_port, s.subscription_status, s.subscription_expires_at, s.monthly_fee, s.last_ping_status,
        COALESCE(SUM(CASE WHEN DATE(t.created_at) = CURDATE() AND t.status = 'completed' AND t.payment_type != 'branch_subscription' THEN t.amount ELSE 0 END), 0) AS siku,
        COALESCE(SUM(CASE WHEN YEARWEEK(t.created_at, 1) = YEARWEEK(CURDATE(), 1) AND t.status = 'completed' AND t.payment_type != 'branch_subscription' THEN t.amount ELSE 0 END), 0) AS wiki,
        COALESCE(SUM(CASE WHEN YEAR(t.created_at) = YEAR(CURDATE()) AND MONTH(t.created_at) = MONTH(CURDATE()) AND t.status = 'completed' AND t.payment_type != 'branch_subscription' THEN t.amount ELSE 0 END), 0) AS mwezi,
        (SELECT COUNT(*) FROM active_sessions a WHERE a.station_id = s.id AND a.status = 'online' AND a.expires_at > NOW()) AS online_users
    FROM stations s
    LEFT JOIN transactions t ON s.id = t.station_id
    GROUP BY s.id
    ORDER BY mwezi DESC
")->fetchAll();

// GRAFU YA SIKU 7 YA MAPATO YA MFUMO MZIMA
$chartStmt = $pdo->query("
    SELECT DATE(created_at) as tarehe, COALESCE(SUM(amount), 0) as kiasi
    FROM transactions
    WHERE status = 'completed' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(created_at)
    ORDER BY DATE(created_at) ASC
")->fetchAll();

$chartLabels = [];
$chartValues = [];
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i days"));
    $chartLabels[] = date('D d M', strtotime($day));
    $found = 0;
    foreach ($chartStmt as $rc) {
        if ($rc['tarehe'] === $day) {
            $found = (float)$rc['kiasi'];
            break;
        }
    }
    $chartValues[] = $found;
}

$allBundles = $pdo->query("SELECT b.*, s.station_name FROM bundles b JOIN stations s ON b.station_id = s.id ORDER BY s.id, b.price ASC")->fetchAll();
$allTransactions = $pdo->query("
    SELECT t.*, s.station_name, COALESCE(b.bundle_name, 'Ada ya Mwezi (Tawi)') AS bundle_name 
    FROM transactions t 
    JOIN stations s ON t.station_id = s.id 
    LEFT JOIN bundles b ON t.bundle_id = b.id 
    WHERE t.status = 'completed' 
    ORDER BY t.id DESC LIMIT 25
")->fetchAll();

// 2. RIPOTI YA KINA YA MWENENDO WA KILA TAWI (DETAILED BRANCH REPORT)
$branchDetailedReports = $pdo->query("
    SELECT 
        s.id, s.station_code, s.station_name, s.location, s.owner_name, s.owner_phone, s.subscription_status,
        COUNT(CASE WHEN t.status = 'completed' THEN t.id ELSE NULL END) AS total_transactions,
        COALESCE(SUM(CASE WHEN t.payment_type = 'mobile_money' AND t.status = 'completed' THEN t.amount ELSE 0 END), 0) AS mobile_revenue,
        COALESCE(SUM(CASE WHEN t.payment_type = 'voucher' AND t.status = 'completed' THEN t.amount ELSE 0 END), 0) AS voucher_revenue,
        COALESCE(SUM(CASE WHEN t.payment_type = 'branch_subscription' AND t.status = 'completed' THEN t.amount ELSE 0 END), 0) AS sub_revenue,
        COALESCE(SUM(CASE WHEN t.status = 'completed' THEN t.amount ELSE 0 END), 0) AS grand_total
    FROM stations s
    LEFT JOIN transactions t ON s.id = t.station_id
    GROUP BY s.id
    ORDER BY grand_total DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="sw">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GLOW NET V4 | Master Admin & Ripoti za Kina</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #030712; }
        @media print {
            body { background: #ffffff !important; color: #000000 !important; }
            nav, .print-hide, button, a { display: none !important; }
            .print-card { border: 1px solid #cbd5e1 !important; background: #ffffff !important; color: #000000 !important; box-shadow: none !important; }
            table { width: 100% !important; border-collapse: collapse !important; }
            th, td { border: 1px solid #e2e8f0 !important; color: #0f172a !important; }
        }
    </style>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen font-sans antialiased">

    <!-- Top Admin Bar -->
    <nav class="bg-slate-900/90 backdrop-blur-md border-b border-slate-800 px-6 py-4 sticky top-0 z-50 flex flex-wrap justify-between items-center print-hide">
        <div class="flex items-center space-x-3.5">
            <div class="w-11 h-11 rounded-2xl bg-gradient-to-tr from-amber-500 to-yellow-400 text-slate-950 font-black flex items-center justify-center text-xl shadow-lg shadow-amber-500/20">
                GN
            </div>
            <div>
                <h1 class="text-base sm:text-lg font-black text-amber-400">GLOW NET MASTER ADMIN <span class="text-xs bg-amber-500/20 text-amber-300 px-2 py-0.5 rounded-full ml-1">V4</span></h1>
                <p class="text-xs text-slate-400">Pamoja na Grafu za Mapato (Chart.js) & Ripoti za Kina za Matawi</p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2 mt-3 sm:mt-0">
            <a href="reports.php" class="px-4 py-2 rounded-xl bg-gradient-to-r from-amber-500 to-yellow-400 hover:from-amber-400 text-slate-950 font-black text-xs transition flex items-center space-x-1.5 shadow-md shadow-amber-500/20">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                <span>Fungua Ukurasa wa Ripoti</span>
            </a>
            <a href="#ripoti-matawi" class="px-3.5 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-amber-300 border border-slate-700 text-xs font-bold transition flex items-center space-x-1">
                <span>Jedwali la Ripoti ↓</span>
            </a>
            <button onclick="window.print()" class="px-3.5 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 border border-slate-700 text-xs font-bold transition flex items-center space-x-1.5 shadow-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
                <span>Chapisha (PDF)</span>
            </button>
            <a href="index.php" class="px-3.5 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-bold transition">Ukurasa Mkuu</a>
            <a href="logout.php" class="px-3 py-2 rounded-xl bg-rose-500/15 hover:bg-rose-500/25 border border-rose-500/30 text-rose-300 text-xs font-bold transition">Toka</a>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto p-4 sm:p-6 space-y-6">

        <?php if ($msg): ?>
        <div class="p-4 rounded-2xl bg-emerald-500/15 border border-emerald-500/30 text-emerald-300 text-sm font-semibold">
            <?= htmlspecialchars($msg) ?>
        </div>
        <?php endif; ?>

        <!-- BANNER LA RIPOTI ZA KINA ZA MAPATO (V4 REPORTS CALLOUT) -->
        <div class="p-4 sm:p-5 rounded-3xl bg-gradient-to-r from-amber-500/15 via-slate-900 to-yellow-500/10 border-2 border-amber-500/40 flex flex-col sm:flex-row items-center justify-between gap-4 shadow-xl print-hide">
            <div class="flex items-center space-x-3.5">
                <div class="w-12 h-12 rounded-2xl bg-amber-500 text-slate-950 flex items-center justify-center font-black text-2xl shrink-0 shadow-lg shadow-amber-500/30">
                    📑
                </div>
                <div>
                    <h2 class="text-sm sm:text-base font-extrabold text-white flex items-center gap-2">
                        <span>Ripoti za Kina za Kifedha (GLOW NET V4)</span>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-black bg-amber-400 text-slate-950 uppercase">Mpya</span>
                    </h2>
                    <p class="text-xs text-slate-300">Tazama mwenendo wa mauzo kwa matawi yote, chuja kwa siku/wiki/mwezi, au pakua PDF.</p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2 shrink-0">
                <a href="reports.php" class="px-4 py-2.5 rounded-xl bg-gradient-to-r from-amber-500 to-yellow-400 hover:from-amber-400 text-slate-950 font-black text-xs shadow-md shadow-amber-500/20 transition flex items-center space-x-1.5">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    <span>Fungua Ukurasa wa Ripoti (reports.php) →</span>
                </a>
                <a href="#ripoti-matawi" class="px-3.5 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-amber-300 border border-slate-700 text-xs font-bold transition">
                    Jedwali la Ripoti Chini ↓
                </a>
            </div>
        </div>

        <!-- METRICS CARDS: MAPATO YA MFUMO WOTE -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-slate-900/80 border border-slate-800 p-5 rounded-3xl">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Mapato ya Leo (Matawi Yote)</p>
                <h2 class="text-3xl font-black text-emerald-400 mt-2">Tsh <?= number_format($revStats['mapato_leo'], 0) ?></h2>
                <p class="text-[11px] text-slate-500 mt-1">Mauzo ya leo mtandao mzima</p>
            </div>

            <div class="bg-slate-900/80 border border-slate-800 p-5 rounded-3xl">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Mapato ya Wiki Hii</p>
                <h2 class="text-3xl font-black text-amber-400 mt-2">Tsh <?= number_format($revStats['mapato_wiki'], 0) ?></h2>
                <p class="text-[11px] text-slate-500 mt-1">Siku 7 zilizopita</p>
            </div>

            <div class="bg-slate-900/80 border border-slate-800 p-5 rounded-3xl">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Mapato ya Mwezi Huu</p>
                <h2 class="text-3xl font-black text-sky-400 mt-2">Tsh <?= number_format($revStats['mapato_mwezi'], 0) ?></h2>
                <p class="text-[11px] text-slate-500 mt-1">Mwezi huu mzima</p>
            </div>

            <div class="bg-slate-900/80 border border-slate-800 p-5 rounded-3xl">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Jumla Kuu ya Mapato</p>
                <h2 class="text-3xl font-black text-purple-400 mt-2">Tsh <?= number_format($revStats['jumla_kuu'], 0) ?></h2>
                <div class="flex items-center space-x-2 mt-1 text-[11px]">
                    <span class="text-emerald-400 font-bold">Wi-Fi: <?= number_format($revStats['mapato_vifurushi_wifi'], 0) ?></span>
                    <span class="text-slate-600">•</span>
                    <span class="text-amber-400 font-bold">Ada za Matawi: <?= number_format($revStats['mapato_ada_matawi'], 0) ?></span>
                </div>
            </div>
        </div>

        <!-- GRAFU YA MAPATO YA MFUMO WOTE -->
        <div class="bg-slate-900/80 border border-slate-800 p-5 rounded-3xl print-card">
            <h3 class="font-extrabold text-white text-base mb-1">Mwenendo wa Mauzo ya Matawi Yote (Siku 7 Zilizopita)</h3>
            <p class="text-xs text-slate-400 mb-4">Takwimu za moja kwa moja kutoka kwenye database ya GLOW NET</p>
            <div class="h-64">
                <canvas id="masterChart"></canvas>
            </div>
        </div>

        <!-- RIPOTI YA KINA YA MWENENDO WA KILA TAWI (DETAILED BRANCH REPORT) -->
        <div id="ripoti-matawi" class="bg-slate-900/80 border border-slate-800 rounded-3xl overflow-hidden print-card scroll-mt-24">
            <div class="p-5 border-b border-slate-800 flex flex-wrap justify-between items-center gap-2">
                <div>
                    <h3 class="text-base font-extrabold text-white flex items-center space-x-2">
                        <span>Ripoti ya Kina ya Miamala na Mapato kwa Kila Tawi</span>
                        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-black bg-amber-500/20 text-amber-400 border border-amber-500/30">V4 Report</span>
                    </h3>
                    <p class="text-xs text-slate-400">Uchanganuzi wa kina wa mapato kupitia Simu (Mobile Money), Vocha za Cash, na Ada za Matawi.</p>
                </div>
                <div class="print-hide">
                    <button onclick="window.print()" class="px-3.5 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-amber-400 border border-slate-700 text-xs font-bold transition flex items-center space-x-1.5 cursor-pointer">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
                        <span>Print Ripoti (PDF)</span>
                    </button>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs sm:text-sm">
                    <thead class="bg-slate-950 text-slate-400 uppercase text-xs font-mono">
                        <tr>
                            <th class="p-4">Tawi / Kituo</th>
                            <th class="p-4">Mmiliki & Simu</th>
                            <th class="p-4">Jumla ya Miamala</th>
                            <th class="p-4">Mapato ya Simu</th>
                            <th class="p-4">Mapato ya Vocha</th>
                            <th class="p-4">Ada za Subscription</th>
                            <th class="p-4 text-right">Jumla Kuu ya Tawi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        <?php 
                        $sumTrans = 0; $sumMobile = 0; $sumVoucher = 0; $sumSub = 0; $sumGrand = 0;
                        foreach ($branchDetailedReports as $br): 
                            $sumTrans += (int)$br['total_transactions'];
                            $sumMobile += (float)$br['mobile_revenue'];
                            $sumVoucher += (float)$br['voucher_revenue'];
                            $sumSub += (float)$br['sub_revenue'];
                            $sumGrand += (float)$br['grand_total'];
                        ?>
                        <tr class="hover:bg-slate-800/30 transition">
                            <td class="p-4">
                                <div class="font-bold text-white text-sm"><?= htmlspecialchars($br['station_name']) ?></div>
                                <div class="text-xs text-slate-400"><?= htmlspecialchars($br['location']) ?> (<span class="font-mono text-amber-400"><?= htmlspecialchars($br['station_code']) ?></span>)</div>
                            </td>
                            <td class="p-4 text-xs">
                                <div class="font-semibold text-slate-200"><?= htmlspecialchars($br['owner_name']) ?></div>
                                <div class="text-slate-400 font-mono text-[11px]"><?= htmlspecialchars($br['owner_phone']) ?></div>
                            </td>
                            <td class="p-4 font-mono font-bold text-slate-300">
                                <span class="px-2 py-0.5 rounded-md bg-slate-950 border border-slate-800">
                                    <?= number_format($br['total_transactions']) ?> miamala
                                </span>
                            </td>
                            <td class="p-4 font-black text-emerald-400 font-mono">
                                Tsh <?= number_format($br['mobile_revenue'], 0) ?>
                            </td>
                            <td class="p-4 font-black text-sky-400 font-mono">
                                Tsh <?= number_format($br['voucher_revenue'], 0) ?>
                            </td>
                            <td class="p-4 font-black text-purple-400 font-mono">
                                Tsh <?= number_format($br['sub_revenue'], 0) ?>
                            </td>
                            <td class="p-4 text-right font-black text-amber-400 font-mono text-base">
                                Tsh <?= number_format($br['grand_total'], 0) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="bg-slate-950/90 font-bold border-t border-slate-700 text-slate-200">
                        <tr>
                            <td class="p-4 uppercase text-xs tracking-wider font-extrabold" colspan="2">Jumla Kuu ya Mfumo:</td>
                            <td class="p-4 font-mono font-black text-white"><?= number_format($sumTrans) ?> miamala</td>
                            <td class="p-4 font-mono font-black text-emerald-400">Tsh <?= number_format($sumMobile, 0) ?></td>
                            <td class="p-4 font-mono font-black text-sky-400">Tsh <?= number_format($sumVoucher, 0) ?></td>
                            <td class="p-4 font-mono font-black text-purple-400">Tsh <?= number_format($sumSub, 0) ?></td>
                            <td class="p-4 text-right font-mono font-black text-amber-400 text-lg">Tsh <?= number_format($sumGrand, 0) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- JEDWALI LA MATAWI, HALI YA ROUTER, SUBSCRIPTION & MAPATO -->
        <div class="bg-slate-900/80 border border-slate-800 rounded-3xl overflow-hidden">
            <div class="p-5 border-b border-slate-800 flex justify-between items-center">
                <div>
                    <h3 class="text-base font-extrabold text-white">Vituo Vilivyosajiliwa, Hali ya Subscription & Mapato</h3>
                    <p class="text-xs text-slate-400">Dhibiti leseni, ongeza siku za huduma, au simamisha kituo chochote kile.</p>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs sm:text-sm">
                    <thead class="bg-slate-950 text-slate-400 uppercase text-xs font-mono">
                        <tr>
                            <th class="p-4">Kituo / Tawi</th>
                            <th class="p-4">Login ya Mmiliki</th>
                            <th class="p-4">Router & Ping</th>
                            <th class="p-4">Hali ya Subscription</th>
                            <th class="p-4">Hewani</th>
                            <th class="p-4">Mauzo ya Wi-Fi</th>
                            <th class="p-4 text-right">Vitendo vya Haraka</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        <?php foreach ($stationRevenues as $st): 
                            $expTime = strtotime($st['subscription_expires_at'] ?? '2000-01-01');
                            $diffSec = $expTime - time();
                            $daysLeft = (int)ceil($diffSec / 86400);
                            $isExp = ($st['subscription_status'] !== 'active' || $diffSec <= 0);
                            $isSoon = (!$isExp && $daysLeft <= 5);
                        ?>
                        <tr class="hover:bg-slate-800/30 transition">
                            <td class="p-4">
                                <div class="font-bold text-white text-sm"><?= htmlspecialchars($st['station_name']) ?></div>
                                <div class="text-xs text-slate-400"><?= htmlspecialchars($st['location']) ?></div>
                            </td>
                            <td class="p-4 text-xs font-mono">
                                <div>ID: <strong class="text-amber-400"><?= htmlspecialchars($st['station_code']) ?></strong></div>
                                <div>Pass: <span class="text-emerald-400 font-bold"><?= htmlspecialchars($st['owner_password']) ?></span></div>
                                <div class="text-slate-400">Simu: <?= htmlspecialchars($st['owner_phone']) ?></div>
                            </td>
                            <td class="p-4">
                                <div class="font-mono text-xs text-slate-300"><?= htmlspecialchars($st['router_ip']) ?>:<?= $st['router_port'] ?></div>
                                <div class="flex items-center space-x-1.5 mt-1.5">
                                    <button onclick="pingStationRouter(<?= $st['id'] ?>)" id="ping-btn-<?= $st['id'] ?>" class="px-2 py-0.5 rounded bg-slate-800 hover:bg-slate-700 text-[10px] font-bold text-slate-300 border border-slate-700">
                                        Jaribu Ping
                                    </button>
                                    <span id="ping-badge-<?= $st['id'] ?>" class="text-[10px] font-bold uppercase text-slate-400">
                                        <?= $st['last_ping_status'] ?? 'unknown' ?>
                                    </span>
                                </div>
                            </td>
                            <td class="p-4">
                                <div>
                                    <?php if ($st['subscription_status'] === 'suspended'): ?>
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-rose-500/20 text-rose-400 uppercase">IMESIMAMISHWA</span>
                                    <?php elseif ($isExp): ?>
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-rose-500 text-slate-950 uppercase animate-pulse">IMEKWISHA</span>
                                    <?php elseif ($isSoon): ?>
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-amber-400 text-slate-950 uppercase">INAISHA (Siku <?= $daysLeft ?>)</span>
                                    <?php else: ?>
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-emerald-500/20 text-emerald-400 uppercase">ACTIVE (Siku <?= $daysLeft ?>)</span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-[11px] text-slate-400 mt-1 font-mono">
                                    Tarehe: <?= date('d/m/Y', $expTime) ?>
                                </div>
                                <div class="text-[10px] text-amber-400/90 font-mono">
                                    Ada: Tsh <?= number_format($st['monthly_fee'] ?? 10000, 0) ?>/mwezi
                                </div>
                            </td>
                            <td class="p-4 font-black text-purple-400"><?= $st['online_users'] ?></td>
                            <td class="p-4">
                                <div class="font-bold text-emerald-400 text-xs">Leo: Tsh <?= number_format($st['siku'], 0) ?></div>
                                <div class="font-bold text-sky-400 text-xs">Mwezi: Tsh <?= number_format($st['mwezi'], 0) ?></div>
                            </td>
                            <td class="p-4 text-right space-y-1">
                                <div class="flex justify-end items-center space-x-1.5">
                                    <a href="?renew_station=<?= $st['id'] ?>&days=30" onclick="return confirm('Ongeza siku 30 kwa <?= htmlspecialchars($st['station_name']) ?>?')" class="px-2.5 py-1 rounded-lg bg-emerald-500/15 text-emerald-400 border border-emerald-500/30 text-[11px] font-bold hover:bg-emerald-500/25">
                                        +30 Siku
                                    </a>
                                    <a href="?renew_station=<?= $st['id'] ?>&days=90" onclick="return confirm('Ongeza siku 90 kwa <?= htmlspecialchars($st['station_name']) ?>?')" class="px-2.5 py-1 rounded-lg bg-amber-500/15 text-amber-400 border border-amber-500/30 text-[11px] font-bold hover:bg-amber-500/25">
                                        +90 Siku
                                    </a>
                                </div>
                                <div class="flex justify-end items-center space-x-1.5 pt-0.5">
                                    <?php if ($st['subscription_status'] === 'active'): ?>
                                        <a href="?toggle_status=<?= $st['id'] ?>&to=suspended" onclick="return confirm('Simamisha kituo hiki?')" class="px-2 py-0.5 rounded bg-rose-500/10 text-rose-400 border border-rose-500/20 text-[10px] font-semibold hover:bg-rose-500/20">
                                            Simamisha
                                        </a>
                                    <?php else: ?>
                                        <a href="?toggle_status=<?= $st['id'] ?>&to=active" class="px-2 py-0.5 rounded bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 text-[10px] font-semibold hover:bg-emerald-500/20">
                                            Fungulia
                                        </a>
                                    <?php endif; ?>
                                    <a href="print_vouchers.php?station_id=<?= $st['id'] ?>" target="_blank" class="px-2 py-0.5 rounded bg-slate-800 text-slate-300 text-[10px] font-semibold">
                                        Vocha
                                    </a>
                                    <a href="portal.php?station=<?= urlencode($st['station_code']) ?>" target="_blank" class="px-2 py-0.5 rounded bg-slate-800 text-amber-400 text-[10px] font-semibold">
                                        Portal
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- FOMU ZA USIMAMIZI: BUNDLE, BULK VOUCHERS, NA KUSAJILI KITUO KIPYA -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            
            <!-- 1. Panga Bundle kwa Kituo Chochote -->
            <div class="bg-slate-900/80 border border-slate-800 p-5 rounded-3xl">
                <h3 class="font-extrabold text-amber-400 text-base mb-3">1. Panga Bundle kwa Kituo</h3>
                <form method="POST" class="space-y-3 text-sm">
                    <input type="hidden" name="form_type" value="add_bundle">
                    <select name="station_id" class="w-full p-3 rounded-xl bg-slate-950 border border-slate-800 text-white">
                        <?php foreach ($stationRevenues as $st): ?>
                            <option value="<?= $st['id'] ?>"><?= htmlspecialchars($st['station_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="bundle_name" required placeholder="Mfano: Masaa 12 Bila Kikomo" class="w-full p-3 rounded-xl bg-slate-950 border border-slate-800 text-white">
                    <div class="grid grid-cols-2 gap-2">
                        <input type="number" name="price" required placeholder="Bei (500)" class="w-full p-3 rounded-xl bg-slate-950 border border-slate-800 text-white">
                        <select name="duration_minutes" class="w-full p-3 rounded-xl bg-slate-950 border border-slate-800 text-white">
                            <option value="180">Masaa 3</option>
                            <option value="720">Masaa 12</option>
                            <option value="1440">Siku 1 (24h)</option>
                            <option value="10080">Wiki 1 (Siku 7)</option>
                            <option value="43200">Mwezi 1 (Siku 30)</option>
                        </select>
                    </div>
                    <input type="text" name="speed_limit" value="5M/5M" class="w-full p-3 rounded-xl bg-slate-950 border border-slate-800 text-white">
                    <button type="submit" class="w-full py-3 bg-amber-500 hover:bg-amber-400 text-slate-950 font-black rounded-xl text-xs transition">
                        Hifadhi Kifurushi
                    </button>
                </form>
            </div>

            <!-- 2. Zalisha Vocha za Jumla (Bulk Batch) -->
            <div class="bg-slate-900/80 border border-slate-800 p-5 rounded-3xl">
                <h3 class="font-extrabold text-emerald-400 text-base mb-3">2. Zalisha Vocha za Jumla</h3>
                <form method="POST" class="space-y-3 text-sm">
                    <input type="hidden" name="form_type" value="generate_vouchers">
                    <select name="station_id" class="w-full p-3 rounded-xl bg-slate-950 border border-slate-800 text-white">
                        <?php foreach ($stationRevenues as $st): ?>
                            <option value="<?= $st['id'] ?>"><?= htmlspecialchars($st['station_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="bundle_id" class="w-full p-3 rounded-xl bg-slate-950 border border-slate-800 text-white">
                        <?php foreach ($allBundles as $b): ?>
                            <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['station_name']) ?> - <?= htmlspecialchars($b['bundle_name']) ?> (Tsh <?= number_format($b['price'], 0) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <div class="grid grid-cols-2 gap-2">
                        <input type="text" name="voucher_prefix" value="GN" class="w-full p-3 rounded-xl bg-slate-950 border border-slate-800 text-white uppercase font-mono">
                        <input type="number" name="quantity" value="16" min="1" max="500" class="w-full p-3 rounded-xl bg-slate-950 border border-slate-800 text-white">
                    </div>
                    <button type="submit" class="w-full py-3 bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-black rounded-xl text-xs transition">
                        Zalisha Vocha Hizi
                    </button>
                </form>
            </div>

            <!-- 3. Sajili Tawi Jipya & Router Credentials -->
            <div class="bg-slate-900/80 border border-slate-800 p-5 rounded-3xl">
                <h3 class="font-extrabold text-sky-400 text-base mb-3">3. Sajili Tawi Jipya & Router</h3>
                <form method="POST" class="space-y-2.5 text-sm">
                    <input type="hidden" name="form_type" value="add_station">
                    <div class="grid grid-cols-2 gap-2">
                        <input type="text" name="station_code" required placeholder="Kodi (GLOW-POSTA)" class="p-2.5 rounded-xl bg-slate-950 border border-slate-800 text-white font-mono uppercase">
                        <select name="hardware_type" class="p-2.5 rounded-xl bg-slate-950 border border-slate-800 text-white">
                            <option value="mikrotik">MikroTik Router</option>
                            <option value="generic_ap">Generic AP</option>
                        </select>
                    </div>
                    <input type="text" name="station_name" required placeholder="Jina la Kituo (GlowNet Posta Hub)" class="w-full p-2.5 rounded-xl bg-slate-950 border border-slate-800 text-white">
                    <div class="grid grid-cols-2 gap-2">
                        <input type="text" name="owner_name" required placeholder="Jina la Mmiliki" class="p-2.5 rounded-xl bg-slate-950 border border-slate-800 text-white">
                        <input type="text" name="owner_phone" required placeholder="Simu ya Mmiliki" class="p-2.5 rounded-xl bg-slate-950 border border-slate-800 text-white">
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <input type="text" name="owner_password" required placeholder="Password" value="1234" class="p-2.5 rounded-xl bg-slate-950 border border-slate-800 text-white">
                        <input type="text" name="location" required placeholder="Eneo (Posta, DSM)" class="p-2.5 rounded-xl bg-slate-950 border border-slate-800 text-white">
                    </div>
                    <div class="grid grid-cols-3 gap-2">
                        <input type="text" name="router_ip" placeholder="IP (192.168.88.1)" value="192.168.88.1" class="col-span-2 p-2.5 rounded-xl bg-slate-950 border border-slate-800 text-white font-mono">
                        <input type="number" name="router_port" placeholder="Port (8728)" value="8728" class="p-2.5 rounded-xl bg-slate-950 border border-slate-800 text-white font-mono">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-400 mb-1">Ada ya Mwezi ya Kituo (Tsh):</label>
                        <input type="number" name="monthly_fee" value="10000" step="500" class="w-full p-2.5 rounded-xl bg-slate-950 border border-slate-800 text-amber-400 font-mono font-bold">
                    </div>
                    <button type="submit" class="w-full py-2.5 bg-sky-500 hover:bg-sky-400 text-slate-950 font-black rounded-xl text-xs transition">
                        Sajili Tawi Hili
                    </button>
                </form>
            </div>
        </div>

        <!-- MIAMALA 20 YA HIVI KARIBUNI YA MTANDAO MZIMA -->
        <div class="bg-slate-900/80 border border-slate-800 rounded-3xl p-5">
            <h3 class="font-extrabold text-white text-base mb-3">Miamala ya Hivi Karibuni ya Matawi Yote</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-slate-950 text-slate-400 uppercase font-mono">
                        <tr>
                            <th class="p-3">Reference / Mtandao</th>
                            <th class="p-3">Kituo / Tawi</th>
                            <th class="p-3">Kifurushi</th>
                            <th class="p-3">Kiasi</th>
                            <th class="p-3">Muda</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        <?php foreach ($allTransactions as $t): ?>
                        <tr>
                            <td class="p-3">
                                <div class="font-mono text-slate-200"><?= htmlspecialchars($t['reference_no']) ?></div>
                                <div class="text-[10px] text-slate-400"><?= htmlspecialchars($t['payment_network'] ?? ($t['payment_type'] === 'voucher' ? 'Vocha' : 'Simu')) ?></div>
                            </td>
                            <td class="p-3 text-white font-bold"><?= htmlspecialchars($t['station_name']) ?></td>
                            <td class="p-3 text-slate-300"><?= htmlspecialchars($t['bundle_name']) ?></td>
                            <td class="p-3 font-black text-emerald-400">Tsh <?= number_format($t['amount'], 0) ?></td>
                            <td class="p-3 text-slate-400 font-mono"><?= htmlspecialchars($t['created_at']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <script>
        // Grafu ya Chart.js
        const ctx = document.getElementById('masterChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?= json_encode($chartLabels) ?>,
                datasets: [{
                    label: 'Mapato ya Siku (Tsh)',
                    data: <?= json_encode($chartValues) ?>,
                    borderColor: '#f59e0b',
                    backgroundColor: 'rgba(245, 158, 11, 0.15)',
                    borderWidth: 2.5,
                    fill: true,
                    tension: 0.35,
                    pointBackgroundColor: '#fbbf24',
                    pointRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(255, 255, 255, 0.05)' },
                        ticks: { color: '#94a3b8' }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: '#94a3b8' }
                    }
                }
            }
        });

        // Ping Router Script
        async function pingStationRouter(stationId) {
            const badge = document.getElementById('ping-badge-' + stationId);
            const btn = document.getElementById('ping-btn-' + stationId);
            badge.innerText = 'Testing...';
            btn.disabled = true;

            try {
                const res = await fetch('api_engine.php?action=test_router_ping&station_id=' + stationId);
                const data = await res.json();
                badge.innerText = data.status.toUpperCase();
                badge.className = data.status === 'online' ? 'text-[10px] font-bold uppercase text-emerald-400' : 'text-[10px] font-bold uppercase text-rose-400';
            } catch(e) {
                badge.innerText = 'ERROR';
            }
            btn.disabled = false;
        }
    </script>
</body>
</html>
