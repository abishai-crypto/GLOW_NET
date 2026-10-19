<?php
// print_vouchers.php - GLOW NET Professional Voucher Printing Template (A4 Cards & POS 58mm/80mm Thermal Receipt)
require_once 'config.php';

// Hakikisha mtumiaji ameingia kama admin au branch owner
if (!isset($_SESSION['glownet_role'])) {
    header('Location: login.php');
    exit;
}

$station_id = (int)($_GET['station_id'] ?? ($_SESSION['station_id'] ?? 0));
$print_type = $_GET['type'] ?? 'cards'; // 'cards' au 'pos'
$batch_no   = $_GET['batch_no'] ?? '';

// Kama ni branch owner, hakikisha anaona kituo chake pekee
if ($_SESSION['glownet_role'] === 'branch_owner') {
    $station_id = (int)$_SESSION['station_id'];
}

$stStmt = $pdo->prepare("SELECT * FROM stations WHERE id = ?");
$stStmt->execute([$station_id]);
$station = $stStmt->fetch();

if (!$station) {
    die("Kituo hakikupatikana.");
}

$sql = "
    SELECT v.*, b.bundle_name, b.price, b.duration_minutes, b.speed_limit
    FROM vouchers v
    JOIN bundles b ON v.bundle_id = b.id
    WHERE v.station_id = ? AND v.status = 'unused'
";
$params = [$station_id];

if (!empty($batch_no)) {
    $sql .= " AND v.batch_no = ?";
    $params[] = $batch_no;
}

$sql .= " ORDER BY v.id DESC LIMIT 60";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$vouchers = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="sw">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Vocha - <?= htmlspecialchars($station['station_name']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&family=Space+Mono:wght@700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .font-code { font-family: 'Space Mono', monospace; }
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; color: black !important; padding: 0 !important; }
            .print-card { break-inside: avoid; border-color: #cbd5e1 !important; }
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-900 p-6">

    <!-- Top Action Toolbar -->
    <div class="no-print max-w-5xl mx-auto mb-6 p-4 rounded-2xl bg-white shadow-md flex flex-wrap justify-between items-center gap-3">
        <div>
            <h1 class="text-lg font-black text-slate-900">Uchapishaji wa Vocha: <?= htmlspecialchars($station['station_name']) ?></h1>
            <p class="text-xs text-slate-500">Zinazopatikana za kuchapisha: <b><?= count($vouchers) ?></b></p>
        </div>
        <div class="flex items-center space-x-2">
            <a href="?station_id=<?= $station['id'] ?>&type=cards" class="px-3.5 py-2 rounded-xl text-xs font-bold <?= $print_type === 'cards' ? 'bg-amber-500 text-slate-950' : 'bg-slate-100 text-slate-700' ?>">
                Mpangilio wa A4 (Kadi)
            </a>
            <a href="?station_id=<?= $station['id'] ?>&type=pos" class="px-3.5 py-2 rounded-xl text-xs font-bold <?= $print_type === 'pos' ? 'bg-amber-500 text-slate-950' : 'bg-slate-100 text-slate-700' ?>">
                Mashine ya Risiti (POS 58mm/80mm)
            </a>
            <button onclick="window.print()" class="px-5 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 text-white text-xs font-black shadow-lg transition">
                🖨️ Chapisha (Print) Sasa
            </button>
        </div>
    </div>

    <?php if ($print_type === 'pos'): ?>
    <!-- MPANGILIO WA RISITI YA THERMAL (POS 58mm/80mm) -->
    <div class="max-w-xs mx-auto space-y-6">
        <?php foreach ($vouchers as $v): 
            $portalUrl = "http://" . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1') . "/glownet/portal.php?station=" . urlencode($station['station_code']) . "&voucher=" . urlencode($v['voucher_code']);
            $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=" . urlencode($v['voucher_code']);
        ?>
        <div class="print-card bg-white p-4 border border-dashed border-slate-400 rounded-lg text-center space-y-2">
            <div class="font-black text-sm uppercase tracking-wide"><?= htmlspecialchars($station['station_name']) ?></div>
            <div class="text-[11px] text-slate-500">Kifurushi cha Wi-Fi Hotspot</div>
            <div class="border-t border-b border-dashed border-slate-300 py-1.5 my-2">
                <div class="text-xs font-bold text-slate-800"><?= htmlspecialchars($v['bundle_name']) ?></div>
                <div class="text-base font-black text-slate-950 mt-0.5">Tsh <?= number_format($v['price'], 0) ?></div>
            </div>
            
            <div class="py-1">
                <div class="text-[10px] uppercase tracking-wider text-slate-400">PIN ya Vocha:</div>
                <div class="font-code text-xl font-black text-slate-950 tracking-widest my-1"><?= htmlspecialchars($v['voucher_code']) ?></div>
            </div>

            <div class="flex justify-center my-2">
                <img src="<?= $qrUrl ?>" alt="QR" class="w-20 h-20 border p-1 rounded">
            </div>

            <div class="text-[10px] text-slate-500 leading-tight">
                1. Unganisha Wi-Fi: <b>GLOW NET</b><br>
                2. Fungua browser weka PIN au scan QR.<br>
                Asante kwa kutumia mtandao wetu!
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php else: ?>
    <!-- MPANGILIO WA KADI ZA A4 (GRID 4x3) -->
    <div class="max-w-5xl mx-auto">
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
            <?php foreach ($vouchers as $v): 
                $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=90x90&data=" . urlencode($v['voucher_code']);
            ?>
            <div class="print-card bg-white border-2 border-slate-300 rounded-2xl p-4 shadow-sm flex flex-col justify-between relative overflow-hidden">
                <!-- Top Brand -->
                <div class="flex justify-between items-start mb-2">
                    <div>
                        <span class="inline-block px-2 py-0.5 rounded-md bg-amber-500 text-slate-950 text-[10px] font-black uppercase">GLOW NET</span>
                        <div class="text-[11px] font-bold text-slate-700 mt-1 truncate max-w-[140px]"><?= htmlspecialchars($station['station_name']) ?></div>
                    </div>
                    <div class="text-right">
                        <div class="text-xs font-black text-slate-950">Tsh <?= number_format($v['price'], 0) ?></div>
                        <div class="text-[9px] text-slate-500"><?= round($v['duration_minutes']/60, 1) ?>h</div>
                    </div>
                </div>

                <!-- Middle Voucher PIN & QR -->
                <div class="my-2 p-2.5 rounded-xl bg-slate-50 border border-slate-200 flex items-center justify-between">
                    <div>
                        <div class="text-[9px] font-bold uppercase text-slate-400">PIN ya Vocha:</div>
                        <div class="font-code text-base font-black text-slate-950 tracking-wider"><?= htmlspecialchars($v['voucher_code']) ?></div>
                        <div class="text-[10px] font-bold text-amber-600 truncate"><?= htmlspecialchars($v['bundle_name']) ?></div>
                    </div>
                    <img src="<?= $qrUrl ?>" alt="QR" class="w-14 h-14 rounded border border-slate-200 p-0.5">
                </div>

                <!-- Footer Instructions -->
                <div class="text-[9px] text-slate-400 text-center border-t border-slate-100 pt-1.5 flex justify-between">
                    <span>Wi-Fi: <b>GLOW NET</b></span>
                    <span>Kasi: <b><?= htmlspecialchars($v['speed_limit']) ?></b></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</body>
</html>
