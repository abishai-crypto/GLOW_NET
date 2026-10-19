<?php
// reports.php - GLOW NET V4 Centralized Financial Reports & Analytics
require_once 'config.php';

// Ruhusu Master Admin na Branch Owner
if (!isset($_SESSION['glownet_role'])) {
    header('Location: login.php?msg=login_required&redirect=reports');
    exit;
}

$isMasterAdmin = ($_SESSION['glownet_role'] === 'master_admin');
$branchStationId = $isMasterAdmin ? null : (int)($_SESSION['station_id'] ?? 0);

// Filters
$filterPeriod  = $_GET['period'] ?? 'mwezi'; // 'leo', 'wiki', 'mwezi', 'custom', 'yote'
$filterStation = $isMasterAdmin ? (isset($_GET['station_id']) && $_GET['station_id'] !== 'all' ? (int)$_GET['station_id'] : 'all') : $branchStationId;
$fromDate      = $_GET['from_date'] ?? date('Y-m-01');
$toDate        = $_GET['to_date'] ?? date('Y-m-d');

// Unda WHERE clause ya tarehe na kituo
$where = ["t.status = 'completed'"];
$params = [];

if ($filterStation !== 'all') {
    $where[] = "t.station_id = ?";
    $params[] = (int)$filterStation;
}

if ($filterPeriod === 'leo') {
    $where[] = "DATE(t.created_at) = CURDATE()";
    $periodLabel = "Leo (" . date('d M Y') . ")";
} elseif ($filterPeriod === 'wiki') {
    $where[] = "YEARWEEK(t.created_at, 1) = YEARWEEK(CURDATE(), 1)";
    $periodLabel = "Wiki Hii";
} elseif ($filterPeriod === 'mwezi') {
    $where[] = "YEAR(t.created_at) = YEAR(CURDATE()) AND MONTH(t.created_at) = MONTH(CURDATE())";
    $periodLabel = "Mwezi Huu (" . date('M Y') . ")";
} elseif ($filterPeriod === 'custom') {
    $where[] = "DATE(t.created_at) BETWEEN ? AND ?";
    $params[] = $fromDate;
    $params[] = $toDate;
    $periodLabel = "Kuanzia " . date('d/m/Y', strtotime($fromDate)) . " Hadi " . date('d/m/Y', strtotime($toDate));
} else {
    $periodLabel = "Muda Wote (All Time)";
}

$whereSql = implode(' AND ', $where);

// 1. JUMLA ZA MAPATO KWA FILTER HII
$summaryStmt = $pdo->prepare("
    SELECT 
        COUNT(t.id) AS total_count,
        COALESCE(SUM(t.amount), 0) AS total_amount,
        COALESCE(SUM(CASE WHEN t.payment_type = 'mobile_money' THEN t.amount ELSE 0 END), 0) AS mobile_total,
        COALESCE(SUM(CASE WHEN t.payment_type = 'voucher' THEN t.amount ELSE 0 END), 0) AS voucher_total,
        COALESCE(SUM(CASE WHEN t.payment_type = 'branch_subscription' THEN t.amount ELSE 0 END), 0) AS sub_total
    FROM transactions t
    WHERE $whereSql
");
$summaryStmt->execute($params);
$sum = $summaryStmt->fetch();

// 2. MGAWANYIKO KWA MITANDAO YA SIMU & VOCHA (TELCO BREAKDOWN)
$telcoStmt = $pdo->prepare("
    SELECT 
        COALESCE(t.payment_network, CASE WHEN t.payment_type = 'voucher' THEN 'Cash Voucher' ELSE 'Mobile Money' END) AS network_name,
        COUNT(t.id) AS tx_count,
        SUM(t.amount) AS net_amount
    FROM transactions t
    WHERE $whereSql
    GROUP BY network_name
    ORDER BY net_amount DESC
");
$telcoStmt->execute($params);
$telcoBreakdown = $telcoStmt->fetchAll();

// 3. RIPOTI KWA KILA TAWI KULINGANA NA FILTER
$branchStmt = $pdo->prepare("
    SELECT 
        s.id, s.station_code, s.station_name, s.location, s.owner_name, s.owner_phone,
        COUNT(t.id) AS branch_tx_count,
        COALESCE(SUM(CASE WHEN t.payment_type = 'mobile_money' THEN t.amount ELSE 0 END), 0) AS mobile_revenue,
        COALESCE(SUM(CASE WHEN t.payment_type = 'voucher' THEN t.amount ELSE 0 END), 0) AS voucher_revenue,
        COALESCE(SUM(CASE WHEN t.payment_type = 'branch_subscription' THEN t.amount ELSE 0 END), 0) AS sub_revenue,
        COALESCE(SUM(t.amount), 0) AS total_revenue
    FROM stations s
    LEFT JOIN transactions t ON s.id = t.station_id AND $whereSql
    " . ($filterStation !== 'all' ? "WHERE s.id = " . (int)$filterStation : "") . "
    GROUP BY s.id
    ORDER BY total_revenue DESC
");
$branchStmt->execute($params);
$branchReportRows = $branchStmt->fetchAll();

// 4. ORODHA YA MIAMALA YA HIVI KARIBUNI
$txListStmt = $pdo->prepare("
    SELECT t.*, s.station_name, s.station_code, COALESCE(b.bundle_name, 'Ada ya Kituo (Subscription)') AS bundle_name
    FROM transactions t
    JOIN stations s ON t.station_id = s.id
    LEFT JOIN bundles b ON t.bundle_id = b.id
    WHERE $whereSql
    ORDER BY t.id DESC LIMIT 100
");
$txListStmt->execute($params);
$recentTxs = $txListStmt->fetchAll();

// Orodha ya vituo vyote kwa ajili ya dropdown
$allStationsList = $pdo->query("SELECT id, station_code, station_name FROM stations ORDER BY station_name ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="sw" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GLOW NET V4 | Ripoti za Kina za Mapato</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #030712; }
        @media print {
            body { background: #ffffff !important; color: #000000 !important; }
            .print-hide, nav, button, form { display: none !important; }
            .print-card { border: 1px solid #cbd5e1 !important; background: #ffffff !important; color: #000000 !important; box-shadow: none !important; }
            table { width: 100% !important; border-collapse: collapse !important; }
            th, td { border: 1px solid #e2e8f0 !important; color: #0f172a !important; padding: 8px 12px !important; }
            .print-header { display: block !important; }
        }
    </style>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen font-sans antialiased">

    <!-- Top Navigation -->
    <nav class="bg-slate-900/90 backdrop-blur-md border-b border-slate-800 px-6 py-4 sticky top-0 z-50 flex flex-wrap justify-between items-center print-hide">
        <div class="flex items-center space-x-3.5">
            <div class="w-11 h-11 rounded-2xl bg-gradient-to-tr from-amber-500 to-yellow-400 text-slate-950 font-black flex items-center justify-center text-xl shadow-lg shadow-amber-500/20">
                GN
            </div>
            <div>
                <h1 class="text-base sm:text-lg font-black text-amber-400">GLOW NET V4 | RIPOTI ZA KINA ZA MAPATO</h1>
                <p class="text-xs text-slate-400">
                    Mtumiaji: <b><?= $isMasterAdmin ? 'Master Admin' : htmlspecialchars($_SESSION['station_name']) ?></b> • 
                    Kipindi: <span class="text-slate-200 font-bold"><?= htmlspecialchars($periodLabel) ?></span>
                </p>
            </div>
        </div>

        <div class="flex items-center space-x-2 mt-3 sm:mt-0">
            <button onclick="window.print()" class="px-4 py-2 rounded-xl bg-gradient-to-r from-amber-500 to-yellow-400 hover:from-amber-400 text-slate-950 font-black text-xs transition flex items-center space-x-2 shadow-md cursor-pointer">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
                <span>Chapisha Ripoti (Print / PDF)</span>
            </button>
            <?php if ($isMasterAdmin): ?>
                <a href="dashboard.php" class="px-3.5 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-bold transition">← Dashboard Kuu</a>
            <?php else: ?>
                <a href="branch_dashboard.php" class="px-3.5 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-bold transition">← Dashboard ya Tawi</a>
            <?php endif; ?>
            <a href="logout.php" class="px-3 py-2 rounded-xl bg-rose-500/15 hover:bg-rose-500/25 border border-rose-500/30 text-rose-300 text-xs font-bold transition">Toka</a>
        </div>
    </nav>

    <!-- Print Header Only for Printable Documents -->
    <div class="hidden print-header p-6 border-b border-slate-300 mb-6">
        <h1 class="text-2xl font-black text-slate-950">GLOW NET HOTSPOT - RIPOTI RASMI YA MAPATO YA KIFEDHA</h1>
        <p class="text-sm text-slate-600">Tarehe ya Kuchapisha: <?= date('d M Y, H:i') ?> | Kipindi: <?= htmlspecialchars($periodLabel) ?></p>
    </div>

    <div class="max-w-7xl mx-auto p-4 sm:p-6 space-y-6">

        <!-- FILTER TOOLBAR (PRINT-HIDE) -->
        <div class="bg-slate-900/80 border border-slate-800 rounded-3xl p-5 print-hide">
            <form method="GET" class="flex flex-wrap items-end gap-3 text-xs">
                
                <div>
                    <label class="block font-bold text-slate-400 mb-1">Chagua Kipindi:</label>
                    <select name="period" id="periodSelect" onchange="toggleCustomDates(this.value)" class="p-2.5 rounded-xl bg-slate-950 border border-slate-800 text-white font-semibold">
                        <option value="mwezi" <?= $filterPeriod === 'mwezi' ? 'selected' : '' ?>>Mwezi Huu (<?= date('M Y') ?>)</option>
                        <option value="leo" <?= $filterPeriod === 'leo' ? 'selected' : '' ?>>Leo Tu (<?= date('d M') ?>)</option>
                        <option value="wiki" <?= $filterPeriod === 'wiki' ? 'selected' : '' ?>>Wiki Hii</option>
                        <option value="yote" <?= $filterPeriod === 'yote' ? 'selected' : '' ?>>Muda Wote (All Time)</option>
                        <option value="custom" <?= $filterPeriod === 'custom' ? 'selected' : '' ?>>Tarehe Maalum (Custom)</option>
                    </select>
                </div>

                <div id="customDateRange" class="<?= $filterPeriod === 'custom' ? 'flex' : 'hidden' ?> items-center space-x-2">
                    <div>
                        <label class="block font-bold text-slate-400 mb-1">Kuanzia:</label>
                        <input type="date" name="from_date" value="<?= htmlspecialchars($fromDate) ?>" class="p-2.5 rounded-xl bg-slate-950 border border-slate-800 text-white font-mono">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-400 mb-1">Hadi:</label>
                        <input type="date" name="to_date" value="<?= htmlspecialchars($toDate) ?>" class="p-2.5 rounded-xl bg-slate-950 border border-slate-800 text-white font-mono">
                    </div>
                </div>

                <?php if ($isMasterAdmin): ?>
                <div>
                    <label class="block font-bold text-slate-400 mb-1">Tawi / Kituo:</label>
                    <select name="station_id" class="p-2.5 rounded-xl bg-slate-950 border border-slate-800 text-white font-semibold">
                        <option value="all">Matawi Yote (Mfumo Mzima)</option>
                        <?php foreach ($allStationsList as $st): ?>
                            <option value="<?= $st['id'] ?>" <?= (string)$filterStation === (string)$st['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($st['station_name']) ?> (<?= htmlspecialchars($st['station_code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div>
                    <button type="submit" class="px-5 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-400 text-slate-950 font-black transition cursor-pointer">
                        Chuja Ripoti
                    </button>
                </div>
            </form>
        </div>

        <!-- MUHTASARI WA JUMLA (METRICS SUMMARY CARDS) -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-slate-900/80 border border-slate-800 p-5 rounded-3xl print-card">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Jumla Kuu ya Mapato</p>
                <h2 class="text-3xl font-black text-amber-400 mt-2">Tsh <?= number_format($sum['total_amount'], 0) ?></h2>
                <p class="text-[11px] text-slate-500 mt-1"><?= htmlspecialchars($periodLabel) ?></p>
            </div>

            <div class="bg-slate-900/80 border border-slate-800 p-5 rounded-3xl print-card">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Mapato ya Simu (Mobile Money)</p>
                <h2 class="text-3xl font-black text-emerald-400 mt-2">Tsh <?= number_format($sum['mobile_total'], 0) ?></h2>
                <p class="text-[11px] text-slate-500 mt-1">M-Pesa, Tigo, Airtel, Halopesa</p>
            </div>

            <div class="bg-slate-900/80 border border-slate-800 p-5 rounded-3xl print-card">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Mapato ya Vocha (Cash)</p>
                <h2 class="text-3xl font-black text-sky-400 mt-2">Tsh <?= number_format($sum['voucher_total'], 0) ?></h2>
                <p class="text-[11px] text-slate-500 mt-1">Vocha zilizouzwa taslimu</p>
            </div>

            <div class="bg-slate-900/80 border border-slate-800 p-5 rounded-3xl print-card">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Jumla ya Miamala</p>
                <h2 class="text-3xl font-black text-purple-400 mt-2"><?= number_format($sum['total_count']) ?></h2>
                <p class="text-[11px] text-slate-500 mt-1">Miamala iliyokamilika</p>
            </div>
        </div>

        <!-- MGAWANYO KWA MITANDAO (TELCO BREAKDOWN CARDS) -->
        <div class="bg-slate-900/80 border border-slate-800 p-5 rounded-3xl print-card">
            <h3 class="font-extrabold text-white text-base mb-3">Mgawanyo wa Mapato kwa Njia za Malipo:</h3>
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
                <?php foreach ($telcoBreakdown as $tb): 
                    $pct = $sum['total_amount'] > 0 ? round(($tb['net_amount'] / $sum['total_amount']) * 100, 1) : 0;
                ?>
                <div class="p-3.5 rounded-2xl bg-slate-950 border border-slate-800">
                    <div class="text-xs text-slate-400 font-bold"><?= htmlspecialchars($tb['network_name']) ?></div>
                    <div class="text-lg font-black text-white mt-1">Tsh <?= number_format($tb['net_amount'], 0) ?></div>
                    <div class="flex justify-between items-center text-[10px] text-slate-500 mt-1">
                        <span><?= $tb['tx_count'] ?> miamala</span>
                        <span class="text-amber-400 font-bold"><?= $pct ?>%</span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- JEDWALI LA RIPOTI KWA KILA TAWI -->
        <div class="bg-slate-900/80 border border-slate-800 rounded-3xl overflow-hidden print-card">
            <div class="p-5 border-b border-slate-800">
                <h3 class="text-base font-extrabold text-white">Ripoti ya Mwenendo wa Kila Tawi (Branch Financial Breakdown)</h3>
                <p class="text-xs text-slate-400">Uchanganuzi wa miamala na mapato kwa kila kituo katika kipindi ulichochagua.</p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs sm:text-sm">
                    <thead class="bg-slate-950 text-slate-400 uppercase text-xs font-mono">
                        <tr>
                            <th class="p-4">Tawi / Kituo</th>
                            <th class="p-4">Mmiliki & Simu</th>
                            <th class="p-4">Miamala</th>
                            <th class="p-4">Mapato ya Simu</th>
                            <th class="p-4">Mapato ya Vocha</th>
                            <?php if ($isMasterAdmin): ?><th class="p-4">Ada za Subscription</th><?php endif; ?>
                            <th class="p-4 text-right">Jumla Kuu ya Tawi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        <?php if (empty($branchReportRows)): ?>
                        <tr><td colspan="7" class="p-6 text-center text-slate-500">Hakuna miamala iliyopatikana kwa vigezo hivi.</td></tr>
                        <?php endif; ?>

                        <?php foreach ($branchReportRows as $bRow): ?>
                        <tr class="hover:bg-slate-800/30 transition">
                            <td class="p-4">
                                <div class="font-bold text-white text-sm"><?= htmlspecialchars($bRow['station_name']) ?></div>
                                <div class="text-xs text-slate-400"><?= htmlspecialchars($bRow['location']) ?> (<span class="font-mono text-amber-400"><?= htmlspecialchars($bRow['station_code']) ?></span>)</div>
                            </td>
                            <td class="p-4 text-xs">
                                <div class="font-semibold text-slate-200"><?= htmlspecialchars($bRow['owner_name']) ?></div>
                                <div class="text-slate-400 font-mono text-[11px]"><?= htmlspecialchars($bRow['owner_phone']) ?></div>
                            </td>
                            <td class="p-4 font-mono font-bold text-slate-300">
                                <?= number_format($bRow['branch_tx_count']) ?> miamala
                            </td>
                            <td class="p-4 font-black text-emerald-400 font-mono">
                                Tsh <?= number_format($bRow['mobile_revenue'], 0) ?>
                            </td>
                            <td class="p-4 font-black text-sky-400 font-mono">
                                Tsh <?= number_format($bRow['voucher_revenue'], 0) ?>
                            </td>
                            <?php if ($isMasterAdmin): ?>
                            <td class="p-4 font-black text-purple-400 font-mono">
                                Tsh <?= number_format($bRow['sub_revenue'], 0) ?>
                            </td>
                            <?php endif; ?>
                            <td class="p-4 text-right font-black text-amber-400 font-mono text-base">
                                Tsh <?= number_format($bRow['total_revenue'], 0) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ORODHA YA MIAMALA YA KINA (RECENT TRANSACTIONS IN PERIOD) -->
        <div class="bg-slate-900/80 border border-slate-800 rounded-3xl overflow-hidden print-card">
            <div class="p-5 border-b border-slate-800 flex justify-between items-center">
                <div>
                    <h3 class="text-base font-extrabold text-white">Miamala Iliyofanyika Kwenye Kipindi Hiki (Hadi Miamala 100)</h3>
                    <p class="text-xs text-slate-400">Orodha ya kina ya kila muamala, namba ya simu, na nambari ya kumbukumbu.</p>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-slate-950 text-slate-400 uppercase font-mono">
                        <tr>
                            <th class="p-3">Reference No</th>
                            <th class="p-3">Tawi</th>
                            <th class="p-3">Kifurushi / Huduma</th>
                            <th class="p-3">Simu / Mtumiaji</th>
                            <th class="p-3">Njia ya Malipo</th>
                            <th class="p-3">Kiasi</th>
                            <th class="p-3">Tarehe na Saa</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        <?php if (empty($recentTxs)): ?>
                        <tr><td colspan="7" class="p-6 text-center text-slate-500">Hakuna muamala katika kipindi hiki.</td></tr>
                        <?php endif; ?>

                        <?php foreach ($recentTxs as $tx): ?>
                        <tr>
                            <td class="p-3 font-mono font-bold text-amber-400"><?= htmlspecialchars($tx['reference_no']) ?></td>
                            <td class="p-3 font-semibold text-white"><?= htmlspecialchars($tx['station_name']) ?></td>
                            <td class="p-3 text-slate-200"><?= htmlspecialchars($tx['bundle_name']) ?></td>
                            <td class="p-3 font-mono text-slate-300"><?= htmlspecialchars($tx['customer_phone'] ?: '-') ?></td>
                            <td class="p-3">
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-800 text-slate-300 border border-slate-700">
                                    <?= htmlspecialchars($tx['payment_network'] ?: ($tx['payment_type'] === 'voucher' ? 'Vocha' : 'Simu')) ?>
                                </span>
                            </td>
                            <td class="p-3 font-black text-emerald-400 text-sm">Tsh <?= number_format($tx['amount'], 0) ?></td>
                            <td class="p-3 font-mono text-slate-400 text-[11px]"><?= htmlspecialchars($tx['created_at']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <script>
        function toggleCustomDates(val) {
            const range = document.getElementById('customDateRange');
            if (val === 'custom') {
                range.classList.remove('hidden');
                range.classList.add('flex');
            } else {
                range.classList.add('hidden');
                range.classList.remove('flex');
            }
        }
    </script>
</body>
</html>
