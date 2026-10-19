<?php
// api_engine.php - GLOW NET Central API Engine
header('Content-Type: application/json');
require_once 'config.php';
require_once 'RouterBridge.php';
require_once 'PaymentGateway.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

/**
 * Wezesha kifurushi kilicholipwa na uunganishe mtumiaji kwenye Router
 */
function activatePaidBundle($pdo, $transaction) {
    if ($transaction['status'] === 'completed') {
        $s = $pdo->prepare("SELECT * FROM active_sessions WHERE auth_reference = ? LIMIT 1");
        $s->execute([$transaction['reference_no']]);
        $existing = $s->fetch();
        if ($existing) {
            $bundle = $pdo->query("SELECT bundle_name FROM bundles WHERE id = " . (int)$existing['bundle_id'])->fetch();
            return [
                'bundle_name'       => $bundle['bundle_name'] ?? 'Kifurushi cha Wi-Fi',
                'speed_limit'       => $existing['speed_limit'],
                'expires_at'        => $existing['expires_at'],
                'remaining_seconds' => max(0, strtotime($existing['expires_at']) - time())
            ];
        }
    }

    $stmt = $pdo->prepare("
        SELECT b.*, s.hardware_type, s.router_ip, s.router_port, s.router_user, s.router_pass, s.ap_controller_url, s.ap_secret_key, s.station_code
        FROM bundles b
        JOIN stations s ON b.station_id = s.id
        WHERE b.id = ? AND s.id = ?
    ");
    $stmt->execute([$transaction['bundle_id'], $transaction['station_id']]);
    $bundle = $stmt->fetch();
    if (!$bundle) return false;

    $duration   = (int)$bundle['duration_minutes'];
    $now        = date('Y-m-d H:i:s');
    $expires_at = date('Y-m-d H:i:s', strtotime("+$duration minutes"));
    $ref        = $transaction['reference_no'];
    $mac        = $transaction['mac_address'];
    $ip         = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE transactions SET status = 'completed' WHERE id = ?")->execute([$transaction['id']]);

        // Disconnect sessions zozote za zamani za MAC hii
        $pdo->prepare("UPDATE active_sessions SET status = 'disconnected' WHERE mac_address = ? AND status = 'online'")->execute([$mac]);

        $sStmt = $pdo->prepare("
            INSERT INTO active_sessions (station_id, bundle_id, mac_address, ip_address, auth_method, auth_reference, speed_limit, started_at, expires_at, status)
            VALUES (?, ?, ?, ?, 'mobile_money', ?, ?, ?, ?, 'online')
        ");
        $sStmt->execute([$transaction['station_id'], $transaction['bundle_id'], $mac, $ip, $ref, $bundle['speed_limit'], $now, $expires_at]);

        // Wasiliana na router kumruhusu mtumiaji
        RouterBridge::authorizeClient($bundle, $mac, $ip, $duration, $bundle['speed_limit'], $ref);

        $pdo->commit();

        return [
            'bundle_name'       => $bundle['bundle_name'],
            'speed_limit'       => $bundle['speed_limit'],
            'expires_at'        => $expires_at,
            'remaining_seconds' => max(0, strtotime($expires_at) - time())
        ];
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("[activatePaidBundle Error] " . $e->getMessage());
        return false;
    }
}

// 1. TAMBUA MTANDAO WA SIMU WA MTUMIAJI PAPO KWA PAPO
if ($action === 'detect_network') {
    $phone = trim($_GET['phone'] ?? $_POST['phone'] ?? '');
    $net = PaymentGateway::detectNetworkProvider($phone);
    echo json_encode(['success' => true, 'network' => $net]);
    exit;
}

// 2. ANZISHA MALIPO YA SIMU (MOBILE MONEY PUSH)
if ($action === 'pay_bundle') {
    $station_id   = (int)($_POST['station_id'] ?? 0);
    $bundle_id    = (int)($_POST['bundle_id'] ?? 0);
    $phone_number = PaymentGateway::normalizePhone($_POST['phone_number'] ?? '');
    $mac_address  = strtoupper(trim($_POST['mac_address'] ?? '44:65:0E:11:22:33'));

    if (strlen($phone_number) < 10) {
        echo json_encode(['success' => false, 'message' => 'Weka namba sahihi ya simu ya Kitanzania (Mfano: 0755123456).']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM bundles WHERE id = ? AND station_id = ? AND is_active = 1");
    $stmt->execute([$bundle_id, $station_id]);
    $bundle = $stmt->fetch();
    if (!$bundle) {
        echo json_encode(['success' => false, 'message' => 'Kifurushi ulichochagua hakikupatikana au hakipo hewani.']);
        exit;
    }

    $netInfo = PaymentGateway::detectNetworkProvider($phone_number);
    $ref = 'GN-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));

    $pdo->prepare("
        INSERT INTO transactions (station_id, bundle_id, reference_no, customer_phone, mac_address, amount, payment_type, payment_network, status) 
        VALUES (?, ?, ?, ?, ?, ?, 'mobile_money', ?, 'pending')
    ")->execute([$station_id, $bundle_id, $ref, $phone_number, $mac_address, $bundle['price'], $netInfo['name']]);

    $pushResult = PaymentGateway::initiateUSSDPush($ref, $phone_number, $bundle['price']);

    echo json_encode([
        'success'      => true,
        'pending_push' => true,
        'reference_no' => $ref,
        'network'      => $netInfo,
        'message'      => $pushResult['message']
    ]);
    exit;
}

// 3. HAKIKI HALI YA MALIPO YA SIMU (POLLING / CONFIRMATION)
if ($action === 'check_payment_status') {
    $ref = trim($_GET['reference_no'] ?? '');
    $stmt = $pdo->prepare("SELECT * FROM transactions WHERE reference_no = ?");
    $stmt->execute([$ref]);
    $tx = $stmt->fetch();

    if (!$tx) {
        echo json_encode(['status' => 'failed', 'message' => 'Muamala haukupatikana.']);
        exit;
    }

    // Thibitisha na kuunganisha mteja
    $sessionInfo = activatePaidBundle($pdo, $tx);
    if ($sessionInfo) {
        echo json_encode([
            'status'       => 'completed',
            'auth_ref'     => $tx['reference_no'],
            'session_info' => $sessionInfo,
            'message'      => 'Malipo yamethibitishwa kikamilifu! Umeunganishwa kwenye GLOW NET Wi-Fi.'
        ]);
    } else {
        echo json_encode([
            'status'  => 'pending',
            'message' => 'Inasubiri uthibitisho wa PIN kwenye simu yako...'
        ]);
    }
    exit;
}

// 4. TUMIA VOCHA YA KIMWILI (REDEEM VOUCHER CODE)
if ($action === 'redeem_voucher') {
    $station_id   = (int)($_POST['station_id'] ?? 0);
    $voucher_code = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $_POST['voucher_code'] ?? ''));
    $mac_address  = strtoupper(trim($_POST['mac_address'] ?? '44:65:0E:11:22:33'));
    $ip_address   = trim($_POST['ip_address'] ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));

    $stmt = $pdo->prepare("
        SELECT v.*, b.bundle_name, b.price, b.duration_minutes, b.speed_limit, s.hardware_type, s.router_ip, s.router_port, s.router_user, s.router_pass
        FROM vouchers v
        JOIN bundles b ON v.bundle_id = b.id
        JOIN stations s ON v.station_id = s.id
        WHERE v.voucher_code = ? AND v.station_id = ?
    ");
    $stmt->execute([$voucher_code, $station_id]);
    $voucher = $stmt->fetch();

    if (!$voucher) {
        echo json_encode(['success' => false, 'message' => 'Vocha hii haipo au haitumiwi kwenye kituo hiki!']);
        exit;
    }

    if ($voucher['status'] !== 'unused') {
        echo json_encode(['success' => false, 'message' => 'Vocha hii imekwisha tumika tayari tarehe: ' . $voucher['activated_at']]);
        exit;
    }

    $duration   = (int)$voucher['duration_minutes'];
    $now        = date('Y-m-d H:i:s');
    $expires_at = date('Y-m-d H:i:s', strtotime("+$duration minutes"));

    $pdo->beginTransaction();
    try {
        // Badili vocha iwe 'used'
        $pdo->prepare("UPDATE vouchers SET status = 'used', used_by_mac = ?, activated_at = ?, expires_at = ? WHERE id = ?")
            ->execute([$mac_address, $now, $expires_at, $voucher['id']]);

        // Rekodi transaction
        $ref = 'VCH-' . $voucher_code;
        $pdo->prepare("INSERT INTO transactions (station_id, bundle_id, reference_no, mac_address, amount, payment_type, payment_network, status) VALUES (?, ?, ?, ?, ?, 'voucher', 'Cash Voucher', 'completed')")
            ->execute([$station_id, $voucher['bundle_id'], $ref, $mac_address, $voucher['price']]);

        // Kata sessions za zamani za MAC hii
        $pdo->prepare("UPDATE active_sessions SET status = 'disconnected' WHERE mac_address = ? AND status = 'online'")->execute([$mac_address]);

        // Sajili session mpya
        $pdo->prepare("INSERT INTO active_sessions (station_id, bundle_id, mac_address, ip_address, auth_method, auth_reference, speed_limit, started_at, expires_at, status) VALUES (?, ?, ?, ?, 'voucher', ?, ?, ?, ?, 'online')")
            ->execute([$station_id, $voucher['bundle_id'], $mac_address, $ip_address, $voucher_code, $voucher['speed_limit'], $now, $expires_at]);

        // Ruhusu kwenye router
        RouterBridge::authorizeClient($voucher, $mac_address, $ip_address, $duration, $voucher['speed_limit'], $voucher_code);

        $pdo->commit();

        echo json_encode([
            'success'      => true,
            'auth_ref'     => $voucher_code,
            'session_info' => [
                'bundle_name'       => $voucher['bundle_name'],
                'speed_limit'       => $voucher['speed_limit'],
                'expires_at'        => $expires_at,
                'remaining_seconds' => max(0, strtotime($expires_at) - time())
            ],
            'message' => "Vocha imekubaliwa kikamilifu! Kifurushi chako: {$voucher['bundle_name']}."
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Hitilafu ya mfumo: ' . $e->getMessage()]);
    }
    exit;
}

// 5. KATA / ONDOA MTUMIAJI ALIYEPITA MUDA AU KUTOKA KWA HIARI (DISCONNECT / KICK)
if ($action === 'disconnect_session' || $action === 'kick_user') {
    $auth_ref   = trim($_POST['auth_ref'] ?? '');
    $station_id = (int)($_POST['station_id'] ?? 0);

    $sStmt = $pdo->prepare("SELECT a.*, s.hardware_type, s.router_ip, s.router_port, s.router_user, s.router_pass FROM active_sessions a JOIN stations s ON a.station_id = s.id WHERE a.auth_reference = ? LIMIT 1");
    $sStmt->execute([$auth_ref]);
    $session = $sStmt->fetch();

    if ($session) {
        $pdo->prepare("UPDATE active_sessions SET status = 'disconnected' WHERE id = ?")->execute([$session['id']]);
        RouterBridge::disconnectClient($session, $session['mac_address']);
        echo json_encode(['success' => true, 'message' => 'Mtumiaji ametenganishwa na mtandao kikamilifu!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Session haikupatikana.']);
    }
    exit;
}

// 6. PING ROUTER KUONA KAMA IPO HEWANI (TEST ROUTER PING)
if ($action === 'test_router_ping') {
    $station_id = (int)($_GET['station_id'] ?? $_POST['station_id'] ?? 0);
    $st = $pdo->prepare("SELECT * FROM stations WHERE id = ?");
    $st->execute([$station_id]);
    $station = $st->fetch();

    if (!$station) {
        echo json_encode(['success' => false, 'message' => 'Kituo hakikupatikana.']);
        exit;
    }

    $online = RouterBridge::pingRouter($station['router_ip'], (int)($station['router_port'] ?? 8728), 1.5);
    $status = $online ? 'online' : 'offline';

    $pdo->prepare("UPDATE stations SET last_ping_status = ?, last_ping_at = NOW() WHERE id = ?")->execute([$status, $station_id]);

    echo json_encode([
        'success'   => true,
        'status'    => $status,
        'router_ip' => $station['router_ip'],
        'port'      => $station['router_port'] ?? 8728,
        'message'   => $online ? 'Router ipo HEWANI (Online)' : 'Router haipatikani kwa sasa (Offline)'
    ]);
    exit;
}

// 7. LIPIA ADA YA MWEZI YA TAWI (BRANCH SUBSCRIPTION PAYMENT)
if ($action === 'pay_branch_subscription') {
    $station_id   = (int)($_POST['station_id'] ?? 0);
    $phone_number = PaymentGateway::normalizePhone($_POST['phone_number'] ?? '');
    $months       = max(1, min(12, (int)($_POST['months'] ?? 1)));

    $stStmt = $pdo->prepare("SELECT * FROM stations WHERE id = ?");
    $stStmt->execute([$station_id]);
    $station = $stStmt->fetch();

    if (!$station) {
        echo json_encode(['success' => false, 'message' => 'Kituo hakikupatikana kwenye mfumo.']);
        exit;
    }

    if (strlen($phone_number) < 10) {
        echo json_encode(['success' => false, 'message' => 'Weka namba sahihi ya simu ya kulipia (Mfano: 0755123456).']);
        exit;
    }

    $baseMonthly = (float)($station['monthly_fee'] ?? 10000.00);
    if ($baseMonthly <= 0) $baseMonthly = 10000.00;

    // Hesabu ya punguzo la miezi mingi
    if ($months === 1) {
        $amount = $baseMonthly;
    } elseif ($months === 3) {
        $amount = round($baseMonthly * 3 * 0.9333, -2); // Mfano: 28,000 badala ya 30,000
    } elseif ($months === 6) {
        $amount = round($baseMonthly * 6 * 0.8333, -2); // Mfano: 50,000 badala ya 60,000
    } elseif ($months === 12) {
        $amount = round($baseMonthly * 12 * 0.7916, -2); // Mfano: 95,000 badala ya 120,000
    } else {
        $amount = $baseMonthly * $months;
    }

    $netInfo = PaymentGateway::detectNetworkProvider($phone_number);
    $ref     = 'SUB-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));

    // 1. Rekodi muamala wa subscription
    $tStmt = $pdo->prepare("
        INSERT INTO transactions (station_id, bundle_id, reference_no, customer_phone, mac_address, amount, payment_type, payment_network, status, created_at)
        VALUES (?, NULL, ?, ?, NULL, ?, 'branch_subscription', ?, 'completed', NOW())
    ");
    $tStmt->execute([$station_id, $ref, $phone_number, $amount, $netInfo['name']]);

    // 2. Hesabu tarehe mpya ya kuisha (kama haijaisha, ongeza kwenye tarehe ya sasa; kama imekwisha, anza leo)
    $currentExpiry = $station['subscription_expires_at'];
    $baseTimestamp = (strtotime($currentExpiry) > time()) ? strtotime($currentExpiry) : time();
    $daysToAdd     = $months * 30;
    $newExpiry     = date('Y-m-d H:i:s', strtotime("+$daysToAdd days", $baseTimestamp));

    // 3. Sasisha kituo
    $uStmt = $pdo->prepare("UPDATE stations SET subscription_status = 'active', subscription_expires_at = ? WHERE id = ?");
    $uStmt->execute([$newExpiry, $station_id]);

    $pushResult = PaymentGateway::initiateUSSDPush($ref, $phone_number, $amount);

    echo json_encode([
        'success'      => true,
        'reference_no' => $ref,
        'network'      => $netInfo,
        'amount'       => $amount,
        'months'       => $months,
        'days_added'   => $daysToAdd,
        'new_expiry'   => $newExpiry,
        'message'      => "Malipo ya Tsh " . number_format($amount, 0) . " yamethibitishwa! Subscription ya kituo imerefushwa kwa siku {$daysToAdd} hadi " . date('d M Y, H:i', strtotime($newExpiry)) . "."
    ]);
    exit;
}

// 8. MASTER ADMIN - USIMAMIZI WA HARAKA WA SUBSCRIPTION (MANAGE SUBSCRIPTION)
if ($action === 'admin_manage_subscription') {
    $station_id = (int)($_POST['station_id'] ?? 0);
    $sub_action = $_POST['sub_action'] ?? 'add_days'; // 'add_days', 'set_status', 'set_fee'

    $stStmt = $pdo->prepare("SELECT * FROM stations WHERE id = ?");
    $stStmt->execute([$station_id]);
    $station = $stStmt->fetch();

    if (!$station) {
        echo json_encode(['success' => false, 'message' => 'Kituo hakikupatikana.']);
        exit;
    }

    if ($sub_action === 'add_days') {
        $days = (int)($_POST['days'] ?? 30);
        $currentExpiry = $station['subscription_expires_at'];
        $baseTimestamp = (strtotime($currentExpiry) > time()) ? strtotime($currentExpiry) : time();
        $newExpiry     = date('Y-m-d H:i:s', strtotime("+$days days", $baseTimestamp));

        $pdo->prepare("UPDATE stations SET subscription_status = 'active', subscription_expires_at = ? WHERE id = ?")
            ->execute([$newExpiry, $station_id]);

        // Rekodi kama muamala uliotolewa na admin (Free extension / Admin Grant)
        $ref = 'ADM-SUB-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 6));
        $pdo->prepare("
            INSERT INTO transactions (station_id, bundle_id, reference_no, customer_phone, amount, payment_type, payment_network, status, created_at)
            VALUES (?, NULL, ?, '0000000000', 0.00, 'branch_subscription', 'Admin Grant', 'completed', NOW())
        ")->execute([$station_id, $ref]);

        echo json_encode([
            'success'    => true,
            'new_expiry' => $newExpiry,
            'message'    => "Siku {$days} zimeongezwa kwa kituo cha {$station['station_name']}! Tarehe mpya: " . date('d M Y, H:i', strtotime($newExpiry))
        ]);
        exit;
    }

    if ($sub_action === 'set_status') {
        $status = $_POST['status'] ?? 'active';
        if (!in_array($status, ['active', 'expired', 'suspended'])) {
            $status = 'active';
        }
        $pdo->prepare("UPDATE stations SET subscription_status = ? WHERE id = ?")->execute([$status, $station_id]);
        
        if ($status !== 'active') {
            // Disconnect sessions za kituo hiki mara moja
            $pdo->prepare("UPDATE active_sessions SET status = 'disconnected' WHERE station_id = ? AND status = 'online'")->execute([$station_id]);
        }

        echo json_encode([
            'success' => true,
            'status'  => $status,
            'message' => "Hali ya kituo cha {$station['station_name']} imebadilishwa kuwa: " . strtoupper($status)
        ]);
        exit;
    }

    if ($sub_action === 'set_fee') {
        $fee = (float)($_POST['monthly_fee'] ?? 10000.00);
        $pdo->prepare("UPDATE stations SET monthly_fee = ? WHERE id = ?")->execute([$fee, $station_id]);
        echo json_encode([
            'success' => true,
            'fee'     => $fee,
            'message' => "Ada ya mwezi ya {$station['station_name']} imesasishwa kuwa Tsh " . number_format($fee, 0)
        ]);
        exit;
    }
}

// Kama action haikueleweka
echo json_encode(['success' => false, 'message' => 'Unknown action requested.']);
?>
