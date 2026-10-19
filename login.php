<?php
// login.php - GLOW NET Modern Authentication Portal
require_once 'config.php';

// Kama tayari ameshaingia, mpeleke kwenye Dashboard inayomhusu
if (isset($_SESSION['glownet_role'])) {
    if ($_SESSION['glownet_role'] === 'master_admin') {
        header('Location: dashboard.php');
        exit;
    } elseif ($_SESSION['glownet_role'] === 'branch_owner') {
        header('Location: branch_dashboard.php');
        exit;
    }
}

$error = '';
$info = '';
$redirect = trim($_GET['redirect'] ?? ($_POST['redirect'] ?? ''));

if (($_GET['msg'] ?? '') === 'login_required') {
    $info = 'Tafadhali ingia kwenye akaunti yako ili kufungua ripoti za fedha.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login_id = trim($_POST['login_id'] ?? '');
    $password = trim($_POST['password'] ?? '');

    // 1. HAKIKI KAMA NI MASTER ADMIN
    $adminStmt = $pdo->prepare("SELECT * FROM admins WHERE LOWER(username) = LOWER(?) LIMIT 1");
    $adminStmt->execute([$login_id]);
    $adminUser = $adminStmt->fetch();

    $isAdminValid = false;
    if ($adminUser && password_verify($password, $adminUser['password_hash'])) {
        $isAdminValid = true;
    } elseif (strtolower($login_id) === 'admin' && $password === 'admin123') {
        // Fallback salama iwapo bado inatumia default
        $isAdminValid = true;
        $hashed = password_hash('admin123', PASSWORD_DEFAULT);
        if (!$adminUser) {
            $pdo->prepare("INSERT INTO admins (username, password_hash, full_name) VALUES ('admin', ?, 'Master Admin (GLOW NET)')")->execute([$hashed]);
        }
    }

    if ($isAdminValid) {
        $_SESSION['glownet_role'] = 'master_admin';
        $_SESSION['admin_name']   = $adminUser['full_name'] ?? 'Master Admin (GLOW NET)';
        if ($redirect === 'reports') {
            header('Location: reports.php');
        } else {
            header('Location: dashboard.php');
        }
        exit;
    }

    // 2. HAKIKI KAMA NI MMILIKI WA KITUO (Station Code au Namba ya Simu)
    $stmt = $pdo->prepare("SELECT * FROM stations WHERE (UPPER(station_code) = UPPER(?) OR owner_phone = ?) LIMIT 1");
    $stmt->execute([$login_id, $login_id]);
    $station = $stmt->fetch();

    if ($station) {
        $storedPass = $station['owner_password'];
        $isValid = password_verify($password, $storedPass) || ($password === $storedPass);

        if ($isValid) {
            // Auto re-hash password to modern bcrypt if stored as plain text
            if (!password_get_info($storedPass)['algo']) {
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE stations SET owner_password = ? WHERE id = ?")->execute([$newHash, $station['id']]);
            }

            $_SESSION['glownet_role'] = 'branch_owner';
            $_SESSION['station_id']   = $station['id'];
            $_SESSION['station_code'] = $station['station_code'];
            $_SESSION['station_name'] = $station['station_name'];
            $_SESSION['owner_name']   = $station['owner_name'];
            if ($redirect === 'reports') {
                header('Location: reports.php');
            } else {
                header('Location: branch_dashboard.php');
            }
            exit;
        } else {
            $error = 'Nenosiri (Password) uliloweka si sahihi!';
        }
    } else {
        $error = 'Kodi ya Kituo au Jina la Mtumiaji halikupatikana!';
    }
}
?>
<!DOCTYPE html>
<html lang="sw" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GLOW NET | Ingia Kwenye Mfumo (Login)</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: #030712;
            background-image: 
                radial-gradient(at 0% 0%, rgba(245, 158, 11, 0.15) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(14, 165, 233, 0.12) 0px, transparent 50%);
            background-attachment: fixed;
        }
    </style>
</head>
<body class="text-white min-h-screen flex flex-col justify-center items-center p-4 antialiased">

    <div class="max-w-md w-full bg-slate-900/85 backdrop-blur-xl border border-slate-800 rounded-3xl p-8 shadow-2xl space-y-6">
        
        <div class="text-center">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-3xl bg-gradient-to-tr from-amber-500 to-yellow-400 text-slate-950 font-black text-3xl shadow-xl shadow-amber-500/25 mb-3">
                GN
            </div>
            <h1 class="text-2xl font-black text-white tracking-tight">GLOW NET <span class="text-amber-400">PORTAL</span></h1>
            <p class="text-xs text-slate-400 mt-1">Ingia kama Master Admin au Mmiliki wa Tawi la Wi-Fi</p>
        </div>

        <?php if ($error): ?>
        <div class="p-4 rounded-2xl bg-rose-500/15 border border-rose-500/30 text-rose-300 text-xs font-semibold text-center flex items-center justify-center space-x-2">
            <svg class="w-4 h-4 text-rose-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
        <?php endif; ?>

        <?php if ($info): ?>
        <div class="p-4 rounded-2xl bg-amber-500/15 border border-amber-500/30 text-amber-300 text-xs font-semibold text-center flex items-center justify-center space-x-2">
            <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            <span><?= htmlspecialchars($info) ?></span>
        </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">
            <?php if ($redirect): ?>
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
            <?php endif; ?>
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-400 mb-1.5">Kodi ya Tawi / Simu / Admin:</label>
                <input type="text" name="login_id" id="login_id" required placeholder="Mfano: GLOW-KIMARA au admin"
                    class="w-full px-4 py-3.5 rounded-2xl bg-slate-950 border border-slate-800 text-white placeholder-slate-600 focus:outline-none focus:border-amber-500 text-sm font-semibold tracking-wide">
            </div>

            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-400 mb-1.5">Nenosiri (Password):</label>
                <input type="password" name="password" id="password" required placeholder="Weka password yako"
                    class="w-full px-4 py-3.5 rounded-2xl bg-slate-950 border border-slate-800 text-white placeholder-slate-600 focus:outline-none focus:border-amber-500 text-sm font-semibold tracking-wide">
            </div>

            <button type="submit" class="w-full py-4 rounded-2xl bg-gradient-to-r from-amber-500 to-yellow-400 text-slate-950 font-black text-sm shadow-xl shadow-amber-500/20 hover:opacity-95 transition">
                INGIA KWENYE DASHBOARD
            </button>
        </form>

        <!-- Quick Demo Switcher -->
        <div class="pt-5 border-t border-slate-800">
            <p class="text-[11px] text-slate-400 text-center mb-3 font-semibold">Bofya hapa kujaza akaunti za majaribio haraka:</p>
            <div class="grid grid-cols-3 gap-2 text-[11px]">
                <button type="button" onclick="fillDemo('admin', 'admin123')" class="p-2.5 rounded-xl bg-slate-950 border border-slate-800 hover:border-amber-500 text-amber-400 font-bold transition">
                    Master Admin
                </button>
                <button type="button" onclick="fillDemo('GLOW-KIMARA', '1234')" class="p-2.5 rounded-xl bg-slate-950 border border-slate-800 hover:border-emerald-500 text-emerald-400 font-bold transition">
                    Tawi: Kimara
                </button>
                <button type="button" onclick="fillDemo('GLOW-MBEZI', '1234')" class="p-2.5 rounded-xl bg-slate-950 border border-slate-800 hover:border-sky-500 text-sky-400 font-bold transition">
                    Tawi: Mbezi
                </button>
            </div>
        </div>

        <div class="text-center pt-2">
            <a href="index.php" class="text-xs text-slate-500 hover:text-amber-400 transition">&larr; Rudi Ukurasa wa Mwanzo</a>
        </div>
    </div>

    <script>
        function fillDemo(user, pass) {
            document.getElementById('login_id').value = user;
            document.getElementById('password').value = pass;
        }
    </script>
</body>
</html>
