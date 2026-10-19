<?php
// branch_dashboard.php - GLOW NET Branch Owner Dashboard V2
require_once 'config.php';

if (!isset($_SESSION['glownet_role']) || $_SESSION['glownet_role'] !== 'branch_owner') {
    header('Location: login.php');
    exit;
}

$station_id = (int)$_SESSION['station_id'];
$msg = '';
$msg_type = 'success';

// Chukua taarifa za kituo hiki pekee
$stStmt = $pdo->prepare("SELECT * FROM stations WHERE id = ?");
$stStmt->execute([$station_id]);
$station = $stStmt->fetch();

if (!$station) {
    header('Location: logout.php');
    exit;
}

// 1. ONGEZA KIFURUSHI KIPYA CHA KITUO HIKI
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['form_type'] ?? '') === 'add_branch_bundle') {
    $stmt = $pdo->prepare("INSERT INTO bundles (station_id, bundle_name, price, duration_minutes, speed_limit) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
        $station_id,
        trim($_POST['bundle_name']),
        (float)$_POST['price'],
        (int)$_POST['duration_minutes'],
        trim($_POST['speed_limit'])
    ]);
    $msg = "Kifurushi kipya cha Tsh " . number_format($_POST['price'], 0) . " kimeongezwa kikamilifu!";
}

// 2. FUTA KIFURUSHI CHA KITUO HIKI
if (isset($_GET['delete_bundle'])) {
    $bId = (int)$_GET['delete_bundle'];
    $pdo->prepare("DELETE FROM bundles WHERE id = ? AND station_id = ?")->execute([$bId, $station_id]);
    header('Location: branch_dashboard.php?msg=bundle_deleted');
    exit;
}

// 3. ZALISHA BATCH YA VOCHA ZA KITUO HIKI
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['form_type'] ?? '') === 'generate_branch_vouchers') {
    $bundle_id = (int)$_POST['bundle_id'];
    $qty       = min(200, max(1, (int)$_POST['quantity']));
    $prefix    = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $_POST['voucher_prefix'] ?? 'GN'));
    $batch_no  = 'BATCH-' . strtoupper(substr(md5(time()), 0, 6));

    $checkB = $pdo->prepare("SELECT id, bundle_name FROM bundles WHERE id = ? AND station_id = ?");
    $checkB->execute([$bundle_id, $station_id]);
    $bundleInfo = $checkB->fetch();

    if ($bundleInfo) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO vouchers (station_id, bundle_id, batch_no, voucher_code) VALUES (?, ?, ?, ?)");
        $generated = 0;
        for ($i = 0; $i < $qty; $i++) {
            $code = $prefix . rand(100000, 999999);
            if ($stmt->execute([$station_id, $bundle_id, $batch_no, $code])) {
                $generated++;
            }
        }
        $msg = "Vocha $generated za '{$bundleInfo['bundle_name']}' zimezalishwa kikamilifu! (Batch: $batch_no)";
    }
}

// 4. BADILISHA NENOSIRI LA MMILIKI WA KITUO
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['form_type'] ?? '') === 'change_password') {
    $newPass = trim($_POST['new_password'] ?? '');
    if (strlen($newPass) >= 4) {
        $pdo->prepare("UPDATE stations SET owner_password = ? WHERE id = ?")->execute([$newPass, $station_id]);
        $msg = "Nenosiri lako jipya limehifadhiwa kikamilifu!";
    } else {
        $msg = "Nenosiri lazima liwe na angalau herufi au tarakimu 4!";
        $msg_type = 'error';
    }
}

// TAKWIMU ZA MAPATO YA KITUO HIKI (SIKU, WIKI, MWEZI, JUMLA - HAUHUSISHI ADA YA MWEZI)
$revStmt = $pdo->prepare("
    SELECT 
        COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() AND payment_type != 'branch_subscription' THEN amount ELSE 0 END), 0) AS siku,
        COALESCE(SUM(CASE WHEN YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1) AND payment_type != 'branch_subscription' THEN amount ELSE 0 END), 0) AS wiki,
        COALESCE(SUM(CASE WHEN YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE()) AND payment_type != 'branch_subscription' THEN amount ELSE 0 END), 0) AS mwezi,
        COALESCE(SUM(CASE WHEN payment_type != 'branch_subscription' THEN amount ELSE 0 END), 0) AS jumla_yote,
        COUNT(CASE WHEN payment_type != 'branch_subscription' THEN id ELSE NULL END) AS jumla_miamala
    FROM transactions
    WHERE station_id = ? AND status = 'completed'
");
$revStmt->execute([$station_id]);
$rev = $revStmt->fetch();

// TAARIFA ZA SUBSCRIPTION YA KITUO HIKI
$nowTime        = time();
$expiresAtTime  = strtotime($station['subscription_expires_at'] ?? '2000-01-01');
$diffSeconds    = $expiresAtTime - $nowTime;
$daysRemaining  = (int)ceil($diffSeconds / 86400);
$isExpired      = ($station['subscription_status'] !== 'active' || $diffSeconds <= 0);
$isExpiringSoon = (!$isExpired && $daysRemaining <= 5);
$monthlyFee     = (float)($station['monthly_fee'] ?? 10000.00);
if ($monthlyFee <= 0) $monthlyFee = 10000.00;

// HISTORIA YA MALIPO YA SUBSCRIPTION YA KITUO HIKI
$subTxStmt = $pdo->prepare("
    SELECT * FROM transactions 
    WHERE station_id = ? AND payment_type = 'branch_subscription' AND status = 'completed' 
    ORDER BY id DESC LIMIT 10
");
$subTxStmt->execute([$station_id]);
$subInvoices = $subTxStmt->fetchAll();

// TAKWIMU ZA MAPATO YA SIKU 7 ZILIZOPITA KWA AJILI YA GRAFU (CHART.JS - WATEJA WA WI-FI)
$chartStmt = $pdo->prepare("
    SELECT DATE(created_at) as tarehe, COALESCE(SUM(amount), 0) as kiasi
    FROM transactions
    WHERE station_id = ? AND status = 'completed' AND payment_type != 'branch_subscription' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(created_at)
    ORDER BY DATE(created_at) ASC
");
$chartStmt->execute([$station_id]);
$rawChartData = $chartStmt->fetchAll();

// Jaza siku 7 zilizopita hata kama hakuna mauzo siku fulani
$chartLabels = [];
$chartValues = [];
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i days"));
    $chartLabels[] = date('D d M', strtotime($day));
    $found = 0;
    foreach ($rawChartData as $rc) {
        if ($rc['tarehe'] === $day) {
            $found = (float)$rc['kiasi'];
            break;
        }
    }
    $chartValues[] = $found;
}

// WATEJA WALIOPO HEWANI KWENYE KITUO HIKI
$onlineStmt = $pdo->prepare("
    SELECT a.*, b.bundle_name, b.speed_limit
    FROM active_sessions a
    JOIN bundles b ON a.bundle_id = b.id
    WHERE a.station_id = ? AND a.status = 'online' AND a.expires_at > NOW()
    ORDER BY a.id DESC
");
$onlineStmt->execute([$station_id]);
$onlineUsers = $onlineStmt->fetchAll();

// VIFURUSHI VYA KITUO HIKI
$bStmt = $pdo->prepare("SELECT * FROM bundles WHERE station_id = ? ORDER BY price ASC");
$bStmt->execute([$station_id]);
$myBundles = $bStmt->fetchAll();

// VOCHA ZISIZOTUMIKA ZA KITUO HIKI
$vStmt = $pdo->prepare("
    SELECT v.*, b.bundle_name, b.price
    FROM vouchers v
    JOIN bundles b ON v.bundle_id = b.id
    WHERE v.station_id = ? AND v.status = 'unused'
    ORDER BY v.id DESC LIMIT 30
");
$vStmt->execute([$station_id]);
$myVouchers = $vStmt->fetchAll();

// MIAMALA 15 YA HIVI KARIBUNI
$txStmt = $pdo->prepare("
    SELECT t.*, b.bundle_name
    FROM transactions t
    JOIN bundles b ON t.bundle_id = b.id
    WHERE t.station_id = ? AND t.status = 'completed'
    ORDER BY t.id DESC LIMIT 15
");
$txStmt->execute([$station_id]);
$myTransactions = $txStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="sw">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($station['station_name']) ?> | GLOW NET Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #030712; }
    </style>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen font-sans antialiased">

    <!-- Top Navigation Bar -->
    <nav class="bg-slate-900/90 backdrop-blur-md border-b border-slate-800 px-6 py-4 sticky top-0 z-50 flex flex-wrap justify-between items-center">
        <div class="flex items-center space-x-3.5">
            <div class="w-11 h-11 rounded-2xl bg-gradient-to-tr from-emerald-500 to-teal-400 text-slate-950 font-black flex items-center justify-center text-xl shadow-lg shadow-emerald-500/20">
                GN
            </div>
            <div>
                <h1 class="text-base sm:text-lg font-black text-white"><?= htmlspecialchars($station['station_name']) ?></h1>
                <p class="text-xs text-slate-400">
                    Mmiliki: <strong class="text-slate-200"><?= htmlspecialchars($station['owner_name']) ?></strong> • 
                    Kodi: <span class="font-mono text-amber-400 font-bold"><?= htmlspecialchars($station['station_code']) ?></span>
                </p>
            </div>
        </div>

        <div class="flex items-center space-x-2 mt-3 sm:mt-0">
            <button onclick="openSubscriptionModal()" class="px-3.5 py-2 rounded-xl <?= $isExpired ? 'bg-rose-500 text-slate-950 animate-pulse' : 'bg-slate-800 border border-slate-700 text-amber-400 hover:bg-slate-700' ?> text-xs font-black transition flex items-center space-x-1.5 shadow-md cursor-pointer">
                <span class="w-2 h-2 rounded-full <?= $isExpired ? 'bg-slate-950' : 'bg-emerald-400' ?>"></span>
                <span><?= $isExpired ? 'Lipa Ada ya Mwezi!' : 'Subscription: Bado Siku ' . $daysRemaining ?></span>
            </button>
            <a href="reports.php" class="px-4 py-2 rounded-xl bg-gradient-to-r from-amber-500 to-yellow-400 hover:from-amber-400 text-slate-950 text-xs font-black transition flex items-center space-x-1.5 shadow-md shadow-amber-500/20">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                <span>Ripoti ya Mauzo (PDF)</span>
            </a>
            <a href="portal.php?station=<?= urlencode($station['station_code']) ?>" target="_blank" class="px-3.5 py-2 rounded-xl bg-amber-500/20 hover:bg-amber-500/30 text-amber-300 border border-amber-500/30 text-xs font-bold transition flex items-center space-x-1.5">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
                <span>Portal ya Wi-Fi</span>
            </a>
            <a href="print_vouchers.php?station_id=<?= $station['id'] ?>" target="_blank" class="px-3.5 py-2 rounded-xl bg-emerald-500/20 hover:bg-emerald-500/30 text-emerald-300 border border-emerald-500/30 text-xs font-bold transition flex items-center space-x-1.5">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
                <span>Print Vocha</span>
            </a>
            <a href="logout.php" class="px-3 py-2 rounded-xl bg-rose-500/15 hover:bg-rose-500/25 border border-rose-500/30 text-rose-300 text-xs font-bold transition">
                Toka
            </a>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto p-4 sm:p-6 space-y-6">

        <?php if ($msg): ?>
        <div class="p-4 rounded-2xl <?= $msg_type === 'success' ? 'bg-emerald-500/15 border border-emerald-500/30 text-emerald-300' : 'bg-rose-500/15 border border-rose-500/30 text-rose-300' ?> text-xs sm:text-sm font-semibold flex items-center space-x-2">
            <span><?= htmlspecialchars($msg) ?></span>
        </div>
        <?php endif; ?>

        <!-- HALI YA SUBSCRIPTION YA KITUO (BRANCH SUBSCRIPTION BANNER) -->
        <div class="p-5 sm:p-6 rounded-3xl border transition-all duration-300 <?= $isExpired ? 'bg-gradient-to-r from-rose-950/70 via-slate-900 to-rose-950/40 border-rose-500/50 shadow-xl shadow-rose-950/30' : ($isExpiringSoon ? 'bg-gradient-to-r from-amber-950/60 via-slate-900 to-amber-950/30 border-amber-500/50 shadow-xl shadow-amber-950/20' : 'bg-gradient-to-r from-slate-900 via-slate-900 to-emerald-950/40 border-slate-800') ?>">
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-5">
                <div class="space-y-2">
                    <div class="flex flex-wrap items-center gap-2.5">
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Ada ya Mwezi ya Kituo (Branch Subscription):</span>
                        <?php if ($isExpired): ?>
                            <span class="px-3 py-1 rounded-full text-xs font-black bg-rose-500 text-slate-950 animate-pulse flex items-center space-x-1.5">
                                <span class="w-2 h-2 rounded-full bg-slate-950"></span>
                                <span>IMEKWISHA KUISHIA</span>
                            </span>
                        <?php elseif ($isExpiringSoon): ?>
                            <span class="px-3 py-1 rounded-full text-xs font-black bg-amber-400 text-slate-950 flex items-center space-x-1.5">
                                <span class="w-2 h-2 rounded-full bg-slate-950 animate-ping"></span>
                                <span>INAISHA HIVI KARIBUNI (Siku <?= $daysRemaining ?>)</span>
                            </span>
                        <?php else: ?>
                            <span class="px-3 py-1 rounded-full text-xs font-black bg-emerald-500 text-slate-950 flex items-center space-x-1.5">
                                <span class="w-2 h-2 rounded-full bg-slate-950"></span>
                                <span>ACTIVE (Siku <?= $daysRemaining ?> zimebaki)</span>
                            </span>
                        <?php endif; ?>
                    </div>

                    <p class="text-xs sm:text-sm text-slate-300 leading-relaxed max-w-3xl">
                        <?php if ($isExpired): ?>
                            <strong class="text-rose-400">Ilani Muhimu:</strong> Subscription ya kituo chako iliisha tarehe <b class="text-white"><?= date('d M Y, H:i', $expiresAtTime) ?></b>. Wateja wa Wi-Fi hawawezi kupata intaneti hadi ulipie ada ya mwezi ya Tsh <?= number_format($monthlyFee, 0) ?>.
                        <?php elseif ($isExpiringSoon): ?>
                            <strong class="text-amber-400">Tahadhari:</strong> Subscription ya kituo chako inaisha tarehe <b class="text-white"><?= date('d M Y, H:i', $expiresAtTime) ?></b> (Bado siku <?= $daysRemaining ?>). Lipia mapema ili kuzuia wateja wako kukatikiwa na huduma.
                        <?php else: ?>
                            Kituo chako kipo hewani na huduma ya Wi-Fi inafanya kazi kwa ukamilifu. Subscription inaisha tarehe: <b class="text-white"><?= date('d M Y, H:i', $expiresAtTime) ?></b>. Ada ya kawaida ni <b class="text-amber-400 font-mono">Tsh <?= number_format($monthlyFee, 0) ?>/mwezi</b>.
                        <?php endif; ?>
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <button onclick="openSubscriptionModal()" class="px-5 py-3.5 rounded-2xl bg-gradient-to-r from-amber-500 to-yellow-400 hover:from-amber-400 hover:to-yellow-300 text-slate-950 font-black text-xs sm:text-sm shadow-xl shadow-amber-500/20 hover:scale-[1.02] active:scale-[0.98] transition flex items-center space-x-2 cursor-pointer">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        <span><?= $isExpired ? 'Lipa Ada ya Mwezi Sasa (Tsh ' . number_format($monthlyFee, 0) . ')' : 'Ongeza Muda / Lipia Ada' ?></span>
                    </button>
                </div>
            </div>
        </div>

        <!-- BANNER LA RIPOTI YA MAUZO YA KITUO (BRANCH REPORT CALLOUT) -->
        <div class="p-4 sm:p-5 rounded-3xl bg-gradient-to-r from-amber-500/15 via-slate-900 to-yellow-500/10 border-2 border-amber-500/40 flex flex-col sm:flex-row items-center justify-between gap-4 shadow-xl">
            <div class="flex items-center space-x-3.5">
                <div class="w-12 h-12 rounded-2xl bg-amber-500 text-slate-950 flex items-center justify-center font-black text-2xl shrink-0 shadow-lg shadow-amber-500/30">
                    📑
                </div>
                <div>
                    <h2 class="text-sm sm:text-base font-extrabold text-white flex items-center gap-2">
                        <span>Ripoti Kamili ya Mauzo ya Tawi Lako (<?= htmlspecialchars($station['station_name']) ?>)</span>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-black bg-amber-400 text-slate-950 uppercase">V4 Ripoti</span>
                    </h2>
                    <p class="text-xs text-slate-300">Tazama uchanganuzi wa miamala ya M-Pesa, Tigo, Halopesa, Airtel na Vocha. Chuja kwa siku, wiki, mwezi, na chapisha PDF.</p>
                </div>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <a href="reports.php" class="px-5 py-2.5 rounded-xl bg-gradient-to-r from-amber-500 to-yellow-400 hover:from-amber-400 text-slate-950 font-black text-xs shadow-md shadow-amber-500/20 transition flex items-center space-x-1.5">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    <span>Fungua Ripoti Hapa (reports.php) →</span>
                </a>
            </div>
        </div>

        <!-- KADI ZA MAPATO YA KITUO (METRICS CARDS) -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-slate-900/80 border border-slate-800 p-5 rounded-3xl relative overflow-hidden">
                <div class="flex justify-between items-center">
                    <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Mapato ya Leo</p>
                    <span class="w-2.5 h-2.5 rounded-full bg-emerald-400 animate-pulse"></span>
                </div>
                <h2 class="text-3xl font-black text-emerald-400 mt-2">Tsh <?= number_format($rev['siku'], 0) ?></h2>
                <p class="text-[11px] text-slate-500 mt-1">Mauzo ya leo kituo chako</p>
            </div>

            <div class="bg-slate-900/80 border border-slate-800 p-5 rounded-3xl">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Mapato ya Wiki Hii</p>
                <h2 class="text-3xl font-black text-amber-400 mt-2">Tsh <?= number_format($rev['wiki'], 0) ?></h2>
                <p class="text-[11px] text-slate-500 mt-1">Jumla ya siku 7 zilizopita</p>
            </div>

            <div class="bg-slate-900/80 border border-slate-800 p-5 rounded-3xl">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Mapato ya Mwezi Huu</p>
                <h2 class="text-3xl font-black text-sky-400 mt-2">Tsh <?= number_format($rev['mwezi'], 0) ?></h2>
                <p class="text-[11px] text-slate-500 mt-1">Miamala yote: <?= $rev['jumla_miamala'] ?></p>
            </div>

            <div class="bg-slate-900/80 border border-slate-800 p-5 rounded-3xl">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Wateja Hewani Sasa</p>
                <h2 class="text-3xl font-black text-purple-400 mt-2"><?= count($onlineUsers) ?> Online</h2>
                <p class="text-[11px] text-slate-500 mt-1">Kifaa: <?= $station['hardware_type'] === 'mikrotik' ? 'MikroTik (' . htmlspecialchars($station['router_ip']) . ')' : 'Generic AP' ?></p>
            </div>
        </div>

        <!-- GRAFU YA MAPATO YA SIKU 7 & KADI YA ROUTER STATUS -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            
            <!-- Grafu ya Siku 7 (Chart.js) -->
            <div class="lg:col-span-2 bg-slate-900/80 border border-slate-800 p-5 rounded-3xl">
                <div class="flex justify-between items-center mb-4">
                    <div>
                        <h3 class="font-extrabold text-white text-base">Mwenendo wa Mauzo ya Siku 7 Zilizopita</h3>
                        <p class="text-xs text-slate-400">Mauzo ya Vocha za Fedha Taslimu (Cash) na Simu (Mobile Money)</p>
                    </div>
                </div>
                <div class="h-64">
                    <canvas id="revenueChart"></canvas>
                </div>
            </div>

            <!-- Mipangilio ya Haraka na Password -->
            <div class="bg-slate-900/80 border border-slate-800 p-5 rounded-3xl space-y-4">
                <h3 class="font-extrabold text-amber-400 text-base">Mipangilio ya Mmiliki</h3>
                
                <div class="p-3.5 rounded-2xl bg-slate-950 border border-slate-800 text-xs space-y-1.5">
                    <div class="flex justify-between">
                        <span class="text-slate-400">Kodi ya Tawi:</span>
                        <span class="font-mono font-bold text-amber-400"><?= htmlspecialchars($station['station_code']) ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-400">Namba ya Simu:</span>
                        <span class="font-mono text-slate-200"><?= htmlspecialchars($station['owner_phone']) ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-400">Eneo:</span>
                        <span class="text-slate-200"><?= htmlspecialchars($station['location']) ?></span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-slate-400">Hali ya Subscription:</span>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold <?= $isExpired ? 'bg-rose-500 text-slate-950' : 'bg-emerald-500/20 text-emerald-400' ?> uppercase">
                            <?= $isExpired ? 'IMEKWISHA' : 'ACTIVE' ?>
                        </span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-slate-400">Inaisha:</span>
                        <span class="font-mono text-slate-200"><?= date('d/m/Y', $expiresAtTime) ?> (<?= $isExpired ? 'Imekwisha' : "Siku $daysRemaining" ?>)</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-slate-400">Ada ya Mwezi:</span>
                        <span class="font-mono font-bold text-amber-400">Tsh <?= number_format($monthlyFee, 0) ?></span>
                    </div>
                    <div class="pt-1">
                        <button type="button" onclick="openSubscriptionModal()" class="w-full py-1.5 rounded-lg bg-amber-500/15 hover:bg-amber-500/25 border border-amber-500/30 text-amber-300 font-extrabold text-[11px] transition">
                            <?= $isExpired ? 'Lipa Tsh ' . number_format($monthlyFee, 0) . ' Sasa' : '+ Ongeza Muda wa Kituo' ?>
                        </button>
                    </div>
                </div>

                <form method="POST" class="space-y-3 pt-2">
                    <input type="hidden" name="form_type" value="change_password">
                    <label class="block text-xs font-bold text-slate-400">Badilisha Password Yako:</label>
                    <input type="text" name="new_password" required placeholder="Weka password mpya" value="<?= htmlspecialchars($station['owner_password']) ?>"
                        class="w-full p-3 rounded-xl bg-slate-950 border border-slate-700 text-sm text-white focus:outline-none focus:border-amber-500">
                    <button type="submit" class="w-full py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-bold transition">
                        Hifadhi Password Mpya
                    </button>
                </form>
            </div>
        </div>

        <!-- USIMAMIZI WA VIFURUSHI & KUTENGENEZA VOCHA -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <!-- 1. Fomu ya Kuongeza Kifurushi -->
            <div class="bg-slate-900/80 border border-slate-800 p-5 rounded-3xl">
                <h3 class="font-extrabold text-amber-400 text-base mb-1">1. Ongeza Kifurushi Kipya</h3>
                <p class="text-xs text-slate-400 mb-4">Weka vifurushi vya bei kulingana na wateja wa kituo chako.</p>
                <form method="POST" class="space-y-3 text-sm">
                    <input type="hidden" name="form_type" value="add_branch_bundle">
                    <div>
                        <label class="text-xs text-slate-400 font-bold">Jina la Kifurushi:</label>
                        <input type="text" name="bundle_name" required placeholder="Mfano: Masaa 12 Bila Kikomo" class="w-full mt-1 p-3 rounded-xl bg-slate-950 border border-slate-800 text-white">
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="text-xs text-slate-400 font-bold">Bei (Tsh):</label>
                            <input type="number" name="price" required placeholder="500" class="w-full mt-1 p-3 rounded-xl bg-slate-950 border border-slate-800 text-white">
                        </div>
                        <div>
                            <label class="text-xs text-slate-400 font-bold">Muda:</label>
                            <select name="duration_minutes" class="w-full mt-1 p-3 rounded-xl bg-slate-950 border border-slate-800 text-white">
                                <option value="180">Masaa 3</option>
                                <option value="720">Masaa 12</option>
                                <option value="1440">Siku 1 (24h)</option>
                                <option value="10080">Wiki 1 (Siku 7)</option>
                                <option value="43200">Mwezi 1 (Siku 30)</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="text-xs text-slate-400 font-bold">Kasi (Rate-limit):</label>
                        <input type="text" name="speed_limit" value="5M/5M" class="w-full mt-1 p-3 rounded-xl bg-slate-950 border border-slate-800 text-white">
                    </div>
                    <button type="submit" class="w-full py-3 bg-amber-500 hover:bg-amber-400 text-slate-950 font-black rounded-xl transition text-xs">
                        Hifadhi Kifurushi
                    </button>
                </form>
            </div>

            <!-- 2. Orodha ya Vifurushi -->
            <div class="bg-slate-900/80 border border-slate-800 p-5 rounded-3xl">
                <h3 class="font-extrabold text-white text-base mb-1">2. Vifurushi vya Kituo Chako (<?= count($myBundles) ?>)</h3>
                <p class="text-xs text-slate-400 mb-4">Vifurushi vinavyoonekana kwenye ukurasa wa wateja wako.</p>
                <div class="space-y-2 max-h-72 overflow-y-auto pr-1">
                    <?php foreach ($myBundles as $b): ?>
                    <div class="flex items-center justify-between p-3 rounded-2xl bg-slate-950 border border-slate-800 text-xs">
                        <div>
                            <div class="font-extrabold text-white text-sm"><?= htmlspecialchars($b['bundle_name']) ?></div>
                            <div class="text-slate-400">Muda: <?= round($b['duration_minutes']/60, 1) ?>h • Kasi: <?= htmlspecialchars($b['speed_limit']) ?></div>
                        </div>
                        <div class="text-right flex items-center space-x-2">
                            <span class="font-black text-amber-400">Tsh <?= number_format($b['price'], 0) ?></span>
                            <a href="?delete_bundle=<?= $b['id'] ?>" onclick="return confirm('Futa kifurushi hiki?')" class="text-rose-400 hover:text-rose-300 ml-2 font-bold">×</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- 3. Zalisha Vocha kwa Ajili ya Mauzo ya Cash -->
            <div class="bg-slate-900/80 border border-slate-800 p-5 rounded-3xl">
                <h3 class="font-extrabold text-emerald-400 text-base mb-1">3. Zalisha Vocha za Dukani (Cash)</h3>
                <p class="text-xs text-slate-400 mb-4">Chapisha vocha kwa ajili ya wateja wanaolipa pesa taslimu mkononi.</p>
                <form method="POST" class="space-y-3 text-sm">
                    <input type="hidden" name="form_type" value="generate_branch_vouchers">
                    <div>
                        <label class="text-xs text-slate-400 font-bold">Chagua Kifurushi:</label>
                        <select name="bundle_id" required class="w-full mt-1 p-3 rounded-xl bg-slate-950 border border-slate-800 text-white">
                            <?php foreach ($myBundles as $b): ?>
                                <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['bundle_name']) ?> (Tsh <?= number_format($b['price'], 0) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="text-xs text-slate-400 font-bold">Herufi za Mwanzo (Prefix):</label>
                            <input type="text" name="voucher_prefix" value="GN" class="w-full mt-1 p-3 rounded-xl bg-slate-950 border border-slate-800 text-white font-mono uppercase">
                        </div>
                        <div>
                            <label class="text-xs text-slate-400 font-bold">Idadi ya Vocha:</label>
                            <input type="number" name="quantity" value="12" min="1" max="200" class="w-full mt-1 p-3 rounded-xl bg-slate-950 border border-slate-800 text-white">
                        </div>
                    </div>
                    <button type="submit" class="w-full py-3 bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-black rounded-xl transition text-xs">
                        Zalisha Vocha Hizi
                    </button>
                </form>
            </div>
        </div>

        <!-- WATEJA WALIOPO HEWANI & MIAMALA YA HIVI KARIBUNI -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

            <!-- Wateja Waliopo Hewani & Kitufe cha Kick -->
            <div class="bg-slate-900/80 border border-slate-800 rounded-3xl p-5">
                <div class="flex justify-between items-center mb-3">
                    <h3 class="font-extrabold text-white text-base">Wateja Waliopo Hewani Sasa (<?= count($onlineUsers) ?>)</h3>
                    <span class="text-[11px] text-purple-400 font-bold bg-purple-500/10 px-2.5 py-1 rounded-full border border-purple-500/20">Live Hotspot</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-950 text-slate-400 uppercase font-mono">
                            <tr>
                                <th class="p-3">MAC / Reference</th>
                                <th class="p-3">Bundle</th>
                                <th class="p-3">Inaisha</th>
                                <th class="p-3 text-right">Kitendo</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            <?php if (empty($onlineUsers)): ?>
                            <tr><td colspan="4" class="p-5 text-center text-slate-500">Hakuna mteja aliyepo hewani kwa sasa.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($onlineUsers as $u): ?>
                            <tr>
                                <td class="p-3">
                                    <div class="font-mono text-emerald-400 font-bold"><?= htmlspecialchars($u['mac_address']) ?></div>
                                    <div class="text-[10px] text-slate-500 font-mono"><?= htmlspecialchars($u['auth_reference']) ?></div>
                                </td>
                                <td class="p-3">
                                    <div class="text-white font-semibold"><?= htmlspecialchars($u['bundle_name']) ?></div>
                                    <div class="text-[10px] text-slate-400"><?= htmlspecialchars($u['speed_limit']) ?></div>
                                </td>
                                <td class="p-3 text-amber-400 font-mono"><?= htmlspecialchars($u['expires_at']) ?></td>
                                <td class="p-3 text-right">
                                    <button onclick="kickUser('<?= htmlspecialchars($u['auth_reference']) ?>')" class="px-2.5 py-1 rounded-lg bg-rose-500/15 hover:bg-rose-500/25 border border-rose-500/30 text-rose-300 text-[11px] font-bold transition">
                                        Kata (Kick)
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Miamala ya Hivi Karibuni -->
            <div class="bg-slate-900/80 border border-slate-800 rounded-3xl p-5">
                <h3 class="font-extrabold text-white text-base mb-3">Miamala ya Hivi Karibuni Kituoni</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-950 text-slate-400 uppercase font-mono">
                            <tr>
                                <th class="p-3">Reference / Njia</th>
                                <th class="p-3">Kifurushi</th>
                                <th class="p-3">Kiasi</th>
                                <th class="p-3">Muda</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            <?php if (empty($myTransactions)): ?>
                            <tr><td colspan="4" class="p-5 text-center text-slate-500">Bado hakuna muamala uliokamilika.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($myTransactions as $t): ?>
                            <tr>
                                <td class="p-3">
                                    <div class="font-mono text-slate-200"><?= htmlspecialchars($t['reference_no']) ?></div>
                                    <div class="text-[10px] text-slate-400"><?= htmlspecialchars($t['payment_network'] ?? ($t['payment_type'] === 'voucher' ? 'Vocha' : 'Simu')) ?></div>
                                </td>
                                <td class="p-3 font-semibold text-white"><?= htmlspecialchars($t['bundle_name']) ?></td>
                                <td class="p-3 font-black text-emerald-400">Tsh <?= number_format($t['amount'], 0) ?></td>
                                <td class="p-3 text-slate-400 font-mono"><?= htmlspecialchars($t['created_at']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

        <!-- VOCHA ZILIZO TAYARI KUUZWA AU KUCHAPISHWA -->
        <div class="bg-slate-900/80 border border-slate-800 rounded-3xl p-5">
            <div class="flex flex-wrap justify-between items-center mb-4 gap-2">
                <div>
                    <h3 class="font-extrabold text-white text-base">Vocha za Kituo Chako Zisizotumika (<?= count($myVouchers) ?>)</h3>
                    <p class="text-xs text-slate-400">Hizi zinaweza kuchapishwa kwenye risiti za POS au kadi za karatasi.</p>
                </div>
                <div class="flex space-x-2">
                    <a href="print_vouchers.php?station_id=<?= $station['id'] ?>&type=pos" target="_blank" class="px-3.5 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-bold transition">
                        Print POS (Thermal 58mm/80mm)
                    </a>
                    <a href="print_vouchers.php?station_id=<?= $station['id'] ?>&type=cards" target="_blank" class="px-3.5 py-1.5 rounded-xl bg-amber-500 hover:bg-amber-400 text-slate-950 text-xs font-black transition">
                        Print Kadi za A4 (Grid)
                    </a>
                </div>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
                <?php foreach ($myVouchers as $v): ?>
                <div class="p-3 rounded-2xl border border-slate-800 bg-slate-950 text-center relative group hover:border-amber-500/50 transition">
                    <div class="text-[10px] text-slate-400 truncate"><?= htmlspecialchars($v['bundle_name']) ?></div>
                    <div class="font-mono text-sm font-black text-emerald-400 my-1 tracking-wider"><?= htmlspecialchars($v['voucher_code']) ?></div>
                    <div class="text-[10px] font-black text-amber-400">Tsh <?= number_format($v['price'], 0) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- HISTORIA YA MALIPO YA ADA YA KITUO (BRANCH SUBSCRIPTION RECEIPTS) -->
        <div class="bg-slate-900/80 border border-slate-800 rounded-3xl p-5">
            <div class="flex flex-wrap justify-between items-center mb-4 gap-2">
                <div>
                    <h3 class="font-extrabold text-white text-base">Historia ya Malipo ya Ada ya Kituo (Subscription Receipts)</h3>
                    <p class="text-xs text-slate-400">Risiti za ada ya mwezi zilizolipwa kwa ajili ya leseni na matengenezo ya kituo chako.</p>
                </div>
                <button onclick="openSubscriptionModal()" class="px-4 py-2 rounded-xl bg-amber-500/20 border border-amber-500/40 text-amber-300 text-xs font-black hover:bg-amber-500/30 transition">
                    + Lipia / Ongeza Muda Sasa
                </button>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-slate-950 text-slate-400 uppercase font-mono">
                        <tr>
                            <th class="p-3">Nambari ya Risiti (Ref)</th>
                            <th class="p-3">Mtandao & Simu</th>
                            <th class="p-3">Kiasi Kilicholipwa</th>
                            <th class="p-3">Tarehe ya Malipo</th>
                            <th class="p-3">Hali</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        <?php if (empty($subInvoices)): ?>
                        <tr><td colspan="5" class="p-5 text-center text-slate-500">Bado hakuna rekodi ya malipo ya ada ya kituo hiki.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($subInvoices as $sub): ?>
                        <tr>
                            <td class="p-3 font-mono font-bold text-amber-400"><?= htmlspecialchars($sub['reference_no']) ?></td>
                            <td class="p-3">
                                <div class="font-semibold text-white"><?= htmlspecialchars($sub['payment_network'] ?: 'Mobile Money') ?></div>
                                <div class="font-mono text-slate-400 text-[11px]"><?= htmlspecialchars($sub['customer_phone'] ?: '-') ?></div>
                            </td>
                            <td class="p-3 font-black text-emerald-400 text-sm">Tsh <?= number_format($sub['amount'], 0) ?></td>
                            <td class="p-3 font-mono text-slate-300"><?= htmlspecialchars($sub['created_at']) ?></td>
                            <td class="p-3">
                                <span class="px-2.5 py-1 rounded-full text-[10px] font-black bg-emerald-500/20 text-emerald-400 uppercase">
                                    <?= htmlspecialchars($sub['status']) ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <!-- MODAL YA KULIPIA ADA YA MWEZI (BRANCH SUBSCRIPTION MODAL) -->
    <div id="subModal" class="fixed inset-0 bg-slate-950/85 backdrop-blur-md hidden flex items-center justify-center p-4 z-50 transition-opacity">
        <div class="max-w-lg w-full bg-slate-900 border border-slate-700/80 rounded-3xl p-6 sm:p-7 shadow-2xl space-y-5 relative">
            <div class="flex justify-between items-start">
                <div>
                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-black bg-amber-500/20 text-amber-400 uppercase tracking-wider">GLOW NET V3</span>
                    <h3 class="text-xl font-black text-white mt-1">Lipia Ada ya Mwezi ya Kituo</h3>
                    <p class="text-xs text-slate-400 mt-0.5">Kituo: <strong class="text-slate-200"><?= htmlspecialchars($station['station_name']) ?></strong> (<?= htmlspecialchars($station['station_code']) ?>)</p>
                </div>
                <button type="button" onclick="closeSubscriptionModal()" class="w-8 h-8 rounded-full bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white flex items-center justify-center text-sm font-bold transition">
                    ✕
                </button>
            </div>

            <!-- Hali ya sasa -->
            <div class="p-3.5 rounded-2xl bg-slate-950 border border-slate-800 text-xs flex justify-between items-center">
                <span class="text-slate-400">Subscription ya Sasa:</span>
                <span class="font-mono <?= $isExpired ? 'text-rose-400' : 'text-emerald-400' ?> font-bold">
                    <?= $isExpired ? 'Ilikwisha tarehe ' . date('d/m/Y', $expiresAtTime) : 'Hai hadi ' . date('d/m/Y, H:i', $expiresAtTime) ?>
                </span>
            </div>

            <div id="subAlert" class="hidden p-3.5 rounded-2xl text-xs font-semibold leading-relaxed"></div>

            <form id="subForm" onsubmit="submitSubscription(event)" class="space-y-4">
                <input type="hidden" name="action" value="pay_branch_subscription">
                <input type="hidden" name="station_id" value="<?= $station['id'] ?>">
                <input type="hidden" id="selectedMonths" name="months" value="1">

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">Chagua Mpango wa Muda:</label>
                    <div class="grid grid-cols-2 gap-2.5">
                        <label class="plan-card active p-3 rounded-2xl border border-amber-500 bg-amber-500/10 cursor-pointer transition" onclick="selectSubPlan(1, <?= (int)$monthlyFee ?>)">
                            <div class="flex justify-between items-center mb-1">
                                <span class="font-extrabold text-xs text-white">Mwezi 1 (Siku 30)</span>
                                <span class="w-3.5 h-3.5 rounded-full border-2 border-amber-400 bg-amber-400 flex items-center justify-center"></span>
                            </div>
                            <div class="text-amber-400 font-mono font-black text-sm">Tsh <?= number_format($monthlyFee, 0) ?></div>
                            <div class="text-[10px] text-slate-400">Kawaida</div>
                        </label>

                        <?php 
                        $p3 = round($monthlyFee * 3 * 0.9333, -2); 
                        $p6 = round($monthlyFee * 6 * 0.8333, -2);
                        $p12 = round($monthlyFee * 12 * 0.7916, -2);
                        ?>
                        <label class="plan-card p-3 rounded-2xl border border-slate-800 bg-slate-950/60 hover:border-slate-700 cursor-pointer transition" onclick="selectSubPlan(3, <?= (int)$p3 ?>)">
                            <div class="flex justify-between items-center mb-1">
                                <span class="font-extrabold text-xs text-white">Miezi 3 (Siku 90)</span>
                                <span class="text-[9px] px-1.5 py-0.2 rounded bg-emerald-500/20 text-emerald-400 font-bold">Okoa Tsh <?= number_format(($monthlyFee*3)-$p3, 0) ?></span>
                            </div>
                            <div class="text-amber-400 font-mono font-black text-sm">Tsh <?= number_format($p3, 0) ?></div>
                            <div class="text-[10px] text-slate-400">Miezi 3 kamili</div>
                        </label>

                        <label class="plan-card p-3 rounded-2xl border border-slate-800 bg-slate-950/60 hover:border-slate-700 cursor-pointer transition" onclick="selectSubPlan(6, <?= (int)$p6 ?>)">
                            <div class="flex justify-between items-center mb-1">
                                <span class="font-extrabold text-xs text-white">Miezi 6 (Nusu Mwaka)</span>
                                <span class="text-[9px] px-1.5 py-0.2 rounded bg-emerald-500/20 text-emerald-400 font-bold">Okoa Tsh <?= number_format(($monthlyFee*6)-$p6, 0) ?></span>
                            </div>
                            <div class="text-amber-400 font-mono font-black text-sm">Tsh <?= number_format($p6, 0) ?></div>
                            <div class="text-[10px] text-slate-400">Siku 180 za huduma</div>
                        </label>

                        <label class="plan-card p-3 rounded-2xl border border-slate-800 bg-slate-950/60 hover:border-slate-700 cursor-pointer transition" onclick="selectSubPlan(12, <?= (int)$p12 ?>)">
                            <div class="flex justify-between items-center mb-1">
                                <span class="font-extrabold text-xs text-white">Mwaka 1 (Siku 365)</span>
                                <span class="text-[9px] px-1.5 py-0.2 rounded bg-purple-500/20 text-purple-400 font-bold">Ofa Kubwa</span>
                            </div>
                            <div class="text-amber-400 font-mono font-black text-sm">Tsh <?= number_format($p12, 0) ?></div>
                            <div class="text-[10px] text-slate-400">Okoa Tsh <?= number_format(($monthlyFee*12)-$p12, 0) ?></div>
                        </label>
                    </div>
                </div>

                <div>
                    <div class="flex justify-between items-center mb-1.5">
                        <label class="text-xs font-bold uppercase tracking-wider text-slate-400">Namba ya Simu ya Kulipia:</label>
                        <span id="subNetBadge" class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-slate-800 text-slate-300">Tanzania Telcos</span>
                    </div>
                    <div class="relative">
                        <input type="tel" id="subPhoneInput" name="phone_number" required value="<?= htmlspecialchars($station['owner_phone']) ?>" 
                            onkeyup="detectSubNetwork(this.value)" placeholder="0755123456" 
                            class="w-full px-4 py-3.5 rounded-2xl bg-slate-950 border border-slate-700 text-white font-mono text-sm focus:outline-none focus:border-amber-500 transition">
                    </div>
                    <p class="text-[11px] text-slate-500 mt-1">Inasaidia: M-Pesa, Tigo Pesa (Mixx), Airtel Money & Halopesa</p>
                </div>

                <div class="pt-2">
                    <button type="submit" id="btnSubSubmit" class="w-full py-4 rounded-2xl bg-gradient-to-r from-amber-500 to-yellow-400 hover:from-amber-400 hover:to-yellow-300 text-slate-950 font-black text-sm shadow-xl shadow-amber-500/20 hover:scale-[1.01] active:scale-[0.99] transition cursor-pointer">
                        Lipa Tsh <?= number_format($monthlyFee, 0) ?> Sasa (USSD Push)
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Grafu ya Chart.js ya Siku 7
        const ctx = document.getElementById('revenueChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($chartLabels) ?>,
                datasets: [{
                    label: 'Mapato (Tsh)',
                    data: <?= json_encode($chartValues) ?>,
                    backgroundColor: 'rgba(245, 158, 11, 0.75)',
                    borderColor: '#f59e0b',
                    borderWidth: 1.5,
                    borderRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
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

        // Kazi ya kumtenga mtumiaji kutoka hewani (Kick User)
        async function kickUser(authRef) {
            if (!confirm('Je, una uhakika unataka kukata muunganisho wa mteja huyu?')) return;
            const fd = new FormData();
            fd.append('action', 'kick_user');
            fd.append('auth_ref', authRef);
            fd.append('station_id', <?= $station['id'] ?>);
            
            const res = await fetch('api_engine.php', { method: 'POST', body: fd });
            const data = await res.json();
            alert(data.message);
            location.reload();
        }

        // KUSIMAMIA SUBSCRIPTION MODAL NA MALIPO
        let currentSubFee = <?= (int)$monthlyFee ?>;

        function openSubscriptionModal() {
            document.getElementById('subModal').classList.remove('hidden');
            detectSubNetwork(document.getElementById('subPhoneInput').value);
        }

        function closeSubscriptionModal() {
            document.getElementById('subModal').classList.add('hidden');
        }

        function selectSubPlan(months, amount) {
            document.getElementById('selectedMonths').value = months;
            currentSubFee = amount;
            
            // Sasisha muonekano wa kadi
            document.querySelectorAll('.plan-card').forEach(el => {
                el.classList.remove('active', 'border-amber-500', 'bg-amber-500/10');
                el.classList.add('border-slate-800', 'bg-slate-950/60');
            });

            event.currentTarget.classList.add('active', 'border-amber-500', 'bg-amber-500/10');
            event.currentTarget.classList.remove('border-slate-800', 'bg-slate-950/60');

            document.getElementById('btnSubSubmit').innerText = `Lipa Tsh ${amount.toLocaleString()} Sasa (USSD Push)`;
        }

        function detectSubNetwork(phone) {
            const badge = document.getElementById('subNetBadge');
            const clean = phone.replace(/[^0-9]/g, '');
            const prefix = clean.substring(0, 3);

            if (['074', '075', '076'].includes(prefix)) {
                badge.innerText = 'Vodacom M-Pesa';
                badge.className = 'text-[10px] font-bold px-2 py-0.5 rounded-full bg-rose-500/20 text-rose-400 border border-rose-500/30';
            } else if (['065', '067', '071', '077'].includes(prefix)) {
                badge.innerText = 'Tigo Pesa (Mixx)';
                badge.className = 'text-[10px] font-bold px-2 py-0.5 rounded-full bg-sky-500/20 text-sky-400 border border-sky-500/30';
            } else if (['068', '069', '078'].includes(prefix)) {
                badge.innerText = 'Airtel Money';
                badge.className = 'text-[10px] font-bold px-2 py-0.5 rounded-full bg-red-500/20 text-red-400 border border-red-500/30';
            } else if (['061', '062'].includes(prefix)) {
                badge.innerText = 'Halopesa';
                badge.className = 'text-[10px] font-bold px-2 py-0.5 rounded-full bg-orange-500/20 text-orange-400 border border-orange-500/30';
            } else {
                badge.innerText = 'Tanzania Telcos';
                badge.className = 'text-[10px] font-bold px-2 py-0.5 rounded-full bg-slate-800 text-slate-300';
            }
        }

        async function submitSubscription(e) {
            e.preventDefault();
            const btn = document.getElementById('btnSubSubmit');
            const alertBox = document.getElementById('subAlert');
            const origText = btn.innerText;

            btn.disabled = true;
            btn.innerHTML = `<span class="inline-block animate-spin mr-2">⟳</span> Inatuma USSD Push kwenye simu...`;

            alertBox.classList.remove('hidden');
            alertBox.className = 'p-3.5 rounded-2xl text-xs font-semibold bg-amber-500/15 border border-amber-500/30 text-amber-300';
            alertBox.innerHTML = 'Ombi la malipo limetumwa! Tafadhali weka nambari yako ya siri (PIN) kwenye simu yako kuthibitisha...';

            const fd = new FormData(document.getElementById('subForm'));

            try {
                const res = await fetch('api_engine.php', { method: 'POST', body: fd });
                const data = await res.json();

                if (data.success) {
                    alertBox.className = 'p-3.5 rounded-2xl text-xs font-semibold bg-emerald-500/15 border border-emerald-500/40 text-emerald-300';
                    alertBox.innerHTML = `✓ ${data.message}`;
                    btn.innerHTML = `✓ Malipo Yamethibitishwa! Inajirefresh...`;
                    setTimeout(() => location.reload(), 2500);
                } else {
                    alertBox.className = 'p-3.5 rounded-2xl text-xs font-semibold bg-rose-500/15 border border-rose-500/40 text-rose-300';
                    alertBox.innerHTML = `Hitilafu: ${data.message}`;
                    btn.disabled = false;
                    btn.innerText = origText;
                }
            } catch (err) {
                alertBox.className = 'p-3.5 rounded-2xl text-xs font-semibold bg-rose-500/15 border border-rose-500/40 text-rose-300';
                alertBox.innerHTML = 'Hitilafu ya mawasiliano na seva. Tafadhali jaribu tena.';
                btn.disabled = false;
                btn.innerText = origText;
            }
        }
    </script>
</body>
</html>
