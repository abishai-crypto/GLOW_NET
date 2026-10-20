<?php
// config.php - GLOW NET V2 Enhanced Core Configuration (Port 3306 & 3307 Auto-Detect + Auto-Migration)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('Africa/Dar_es_Salaam');

$localConfigFile = __DIR__ . '/config.local.php';
$localConfig = file_exists($localConfigFile) ? include $localConfigFile : [];

$db_host = getenv('DB_HOST') ?: ($localConfig['db']['host'] ?? '127.0.0.1');
$db_name = getenv('DB_NAME') ?: ($localConfig['db']['name'] ?? 'glownet_db');
$db_user = getenv('DB_USER') ?: ($localConfig['db']['user'] ?? 'root');
$db_pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : ($localConfig['db']['pass'] ?? '');
$db_port_custom = getenv('DB_PORT') ?: ($localConfig['db']['port'] ?? null);

$ports_to_try = $db_port_custom ? [(int)$db_port_custom] : [3307, 3306];

$pdo = null;
$connected_port = null;
$last_error = '';

foreach ($ports_to_try as $port) {
    try {
        $dsn = "mysql:host=$db_host;port=$port;charset=utf8mb4";
        if ($db_host !== '127.0.0.1' && $db_host !== 'localhost') {
            $dsn .= ";dbname=$db_name";
        }

        $pdoOptions = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5
        ];

        // Cloud MySQL SSL/TLS transport support (k.m. TiDB Cloud / Aiven)
        if ($db_host !== '127.0.0.1' && $db_host !== 'localhost') {
            if (defined('PDO::MYSQL_ATTR_SSL_CA')) {
                $pdoOptions[PDO::MYSQL_ATTR_SSL_CA] = true;
            }
            if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                $pdoOptions[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
            }
        }

        $tempPdo = new PDO($dsn, $db_user, $db_pass, $pdoOptions);
        $pdo = $tempPdo;
        $connected_port = $port;
        break;
    } catch (PDOException $e) {
        $last_error = $e->getMessage();
    }
}

if (!$pdo) {
    die("<div style='font-family:system-ui,-apple-system,sans-serif;max-width:650px;margin:50px auto;padding:28px;background:#0f172a;color:#f8fafc;border-radius:18px;border:1px solid #ef4444;box-shadow:0 25px 50px -12px rgba(0,0,0,0.5);'>
        <div style='display:flex;align-items:center;gap:12px;margin-bottom:16px;'>
            <div style='width:44px;height:44px;border-radius:12px;background:#ef4444;display:flex;align-items:center;justify-content:center;font-weight:900;'>!</div>
            <h2 style='color:#f87171;margin:0;font-size:20px;'>GLOW NET: MySQL Haijapatikana!</h2>
        </div>
        <p style='color:#cbd5e1;font-size:14px;line-height:1.6;'>Mfumo umeshindwa kuunganisha kwenye database ya MySQL (Host: <b>" . htmlspecialchars($db_host) . "</b>).</p>
        <p style='color:#cbd5e1;font-size:14px;line-height:1.6;'><b>Cha Kufanya:</b> Ikiwa unatumia XAMPP kwenye kompyuta, hakikisha umewasha <b>MySQL</b> kwenye XAMPP Control Panel. Ikiwa unatumia Cloud (Render/VPS), hakikisha vigezo vya <b>DB_HOST, DB_USER, DB_PASS</b> ni sahihi.</p>
        <div style='margin-top:16px;padding:12px;background:#020617;border-radius:10px;font-family:monospace;font-size:12px;color:#94a3b8;border:1px solid #1e293b;word-break:break-all;'>
            Hitilafu: " . htmlspecialchars($last_error) . "
        </div>
    </div>");
}

try {
    try {
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (Throwable $dbCreateEx) {
        // Baadhi ya cloud databases (k.m. TiDB Cloud) zinaruhusu database iliyopo tu
    }
    try {
        $pdo->exec("USE `$db_name`");
    } catch (Throwable $dbUseEx) {}

    // 0. TENGENEZA JEDWALI LA MA-ADMIN (ADMINS)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admins (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            full_name VARCHAR(100) DEFAULT 'Master Admin',
            email VARCHAR(100) DEFAULT NULL,
            role VARCHAR(30) DEFAULT 'master_admin',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
    ");

    $checkAdmin = $pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn();
    if ($checkAdmin == 0) {
        $defaultAdminPass = password_hash('admin123', PASSWORD_DEFAULT);
        $stmtAd = $pdo->prepare("INSERT INTO admins (username, password_hash, full_name) VALUES ('admin', ?, 'Master Admin (GLOW NET)')");
        $stmtAd->execute([$defaultAdminPass]);
    }

    // 1. TENGENEZA JEDWALI LA VITUO (STATIONS)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS stations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            station_code VARCHAR(30) UNIQUE NOT NULL,
            station_name VARCHAR(100) NOT NULL,
            owner_name VARCHAR(100) NOT NULL,
            owner_phone VARCHAR(20) NOT NULL,
            owner_password VARCHAR(255) NOT NULL DEFAULT '1234',
            location VARCHAR(100) NOT NULL,
            hardware_type ENUM('mikrotik', 'generic_ap') DEFAULT 'mikrotik',
            router_ip VARCHAR(100) DEFAULT '192.168.88.1',
            router_port INT DEFAULT 8728,
            router_user VARCHAR(50) DEFAULT 'admin',
            router_pass VARCHAR(100) DEFAULT '',
            ap_controller_url VARCHAR(255) DEFAULT NULL,
            ap_secret_key VARCHAR(100) DEFAULT NULL,
            subscription_status ENUM('active', 'expired', 'suspended') DEFAULT 'active',
            subscription_expires_at DATETIME NOT NULL,
            monthly_fee DECIMAL(10,2) DEFAULT 10000.00,
            last_ping_status ENUM('online', 'offline', 'unknown') DEFAULT 'unknown',
            last_ping_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
    ");

    // 2. TENGENEZA JEDWALI LA VIFURUSHI (BUNDLES)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bundles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            station_id INT NOT NULL,
            bundle_name VARCHAR(60) NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            duration_minutes INT NOT NULL,
            speed_limit VARCHAR(30) DEFAULT '5M/5M',
            data_limit_mb INT DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (station_id) REFERENCES stations(id) ON DELETE CASCADE
        );
    ");

    // 3. TENGENEZA JEDWALI LA VOCHA (VOUCHERS)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vouchers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            station_id INT NOT NULL,
            bundle_id INT NOT NULL,
            batch_no VARCHAR(30) DEFAULT 'INITIAL',
            voucher_code VARCHAR(20) UNIQUE NOT NULL,
            status ENUM('unused', 'used', 'expired') DEFAULT 'unused',
            used_by_mac VARCHAR(30) DEFAULT NULL,
            activated_at DATETIME DEFAULT NULL,
            expires_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (station_id) REFERENCES stations(id) ON DELETE CASCADE,
            FOREIGN KEY (bundle_id) REFERENCES bundles(id) ON DELETE CASCADE
        );
    ");

    // 4. TENGENEZA JEDWALI LA MIAMALA (TRANSACTIONS)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS transactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            station_id INT NOT NULL,
            bundle_id INT NULL DEFAULT NULL,
            reference_no VARCHAR(50) UNIQUE NOT NULL,
            customer_phone VARCHAR(20) DEFAULT NULL,
            mac_address VARCHAR(30) DEFAULT NULL,
            amount DECIMAL(10,2) NOT NULL,
            payment_type VARCHAR(40) NOT NULL DEFAULT 'mobile_money',
            payment_network VARCHAR(30) DEFAULT NULL,
            status ENUM('pending', 'completed', 'failed') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (station_id) REFERENCES stations(id) ON DELETE CASCADE
        );
    ");

    // 5. TENGENEZA JEDWALI LA SESSIONS ZINAZOFANYA KAZI (ACTIVE_SESSIONS)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS active_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            station_id INT NOT NULL,
            bundle_id INT NOT NULL,
            mac_address VARCHAR(30) NOT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            auth_method ENUM('mobile_money', 'voucher') NOT NULL,
            auth_reference VARCHAR(50) NOT NULL,
            speed_limit VARCHAR(30) DEFAULT '5M/5M',
            started_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            status ENUM('online', 'disconnected') DEFAULT 'online',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (station_id) REFERENCES stations(id) ON DELETE CASCADE
        );
    ");

    // 6. TENGENEZA JEDWALI LA MIPANGILIO YA MFUMO (SYSTEM_SETTINGS)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS system_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(50) UNIQUE NOT NULL,
            setting_value TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        );
    ");

    // Weka data za mwanzo kama halipo jedwali la vituo
    $checkStations = $pdo->query("SELECT COUNT(*) FROM stations")->fetchColumn();
    if ($checkStations == 0) {
        $defaultStationPass = password_hash('1234', PASSWORD_DEFAULT);
        $stmtSt = $pdo->prepare("
            INSERT INTO stations (station_code, station_name, owner_name, owner_phone, owner_password, location, hardware_type, router_ip, router_user, router_pass, subscription_status, subscription_expires_at, monthly_fee) VALUES
            ('GLOW-KIMARA', 'GlowNet Kimara Suka Hub', 'Mussa Juma (Kimara)', '0755111222', ?, 'Kimara Suka, DSM', 'mikrotik', '192.168.88.1', 'admin', '1234', 'active', DATE_ADD(NOW(), INTERVAL 30 DAY), 10000.00),
            ('GLOW-MBEZI', 'GlowNet Mbezi Stand AP', 'Amina Ally (Mbezi)', '0713333444', ?, 'Mbezi Luis, DSM', 'generic_ap', '192.168.1.1', 'admin', '1234', 'active', DATE_ADD(NOW(), INTERVAL 30 DAY), 10000.00),
            ('GLOW-SINZA', 'GlowNet Sinza Mori Zone', 'David Mwamba (Sinza)', '0788555666', ?, 'Sinza Mori, DSM', 'mikrotik', '192.168.89.1', 'admin', '1234', 'active', DATE_ADD(NOW(), INTERVAL 30 DAY), 10000.00)
        ");
        $stmtSt->execute([$defaultStationPass, $defaultStationPass, $defaultStationPass]);

        $pdo->exec("
            INSERT INTO bundles (station_id, bundle_name, price, duration_minutes, speed_limit) VALUES
            (1, 'Masaa 3 Kasi ya Juu', 300.00, 180, '5M/5M'),
            (1, 'Masaa 12 Bila Kikomo', 500.00, 720, '6M/6M'),
            (1, 'Siku Nzima (Masaa 24)', 1000.00, 1440, '8M/8M'),
            (1, 'Wiki Nzima (Siku 7)', 5000.00, 10080, '10M/10M'),
            (1, 'Mwezi Mzima (Siku 30)', 10000.00, 43200, '12M/12M'),
            (2, 'Siku Nzima Promo (24h)', 500.00, 1440, '5M/5M'),
            (2, 'Wiki Nzima Special', 3500.00, 10080, '7M/7M'),
            (2, 'Mwezi Mzima Unlimited', 8000.00, 43200, '10M/10M'),
            (3, 'Masaa 12 Sinza Night/Day', 500.00, 720, '6M/6M'),
            (3, 'Siku Nzima Sinza Express', 1000.00, 1440, '10M/10M');
        ");

        $pdo->exec("
            INSERT INTO vouchers (station_id, bundle_id, batch_no, voucher_code, status) VALUES
            (1, 1, 'BATCH-01', 'GN100100', 'unused'),
            (1, 2, 'BATCH-01', 'GN100200', 'unused'),
            (1, 3, 'BATCH-01', 'GN300400', 'unused'),
            (1, 4, 'BATCH-01', 'GN500600', 'unused'),
            (2, 6, 'BATCH-02', 'GN700800', 'unused'),
            (2, 7, 'BATCH-02', 'GN800900', 'unused'),
            (3, 9, 'BATCH-03', 'GN900100', 'unused'),
            (3, 10, 'BATCH-03', 'GN900200', 'unused');
        ");

        // Weka miamala ya majaribio ya leo, wiki hii, na mwezi huu kwa ajili ya takwimu halisi za grafu
        $pdo->exec("
            INSERT INTO transactions (station_id, bundle_id, reference_no, customer_phone, mac_address, amount, payment_type, payment_network, status, created_at) VALUES
            (1, 2, 'GN-DEMO-01', '0755111222', '44:65:0E:11:22:33', 500.00, 'mobile_money', 'M-Pesa', 'completed', NOW()),
            (1, 3, 'GN-DEMO-02', '0714223344', '58:D0:61:AA:BB:CC', 1000.00, 'mobile_money', 'Tigo Pesa', 'completed', NOW()),
            (1, 4, 'GN-DEMO-03', '0788334455', '30:96:FB:DD:EE:FF', 5000.00, 'voucher', NULL, 'completed', DATE_SUB(NOW(), INTERVAL 2 DAY)),
            (1, NULL, 'SUB-DEMO-01', '0755111222', NULL, 10000.00, 'branch_subscription', 'M-Pesa', 'completed', DATE_SUB(NOW(), INTERVAL 5 DAY)),
            (2, 6, 'GN-DEMO-04', '0766778899', '28:39:26:01:02:03', 500.00, 'mobile_money', 'M-Pesa', 'completed', NOW()),
            (2, 7, 'GN-DEMO-05', '0622334455', 'B4:CD:27:04:05:06', 3500.00, 'mobile_money', 'Halopesa', 'completed', DATE_SUB(NOW(), INTERVAL 3 DAY)),
            (3, 10, 'GN-DEMO-06', '0688990011', '68:DB:F5:07:08:09', 1000.00, 'mobile_money', 'Airtel Money', 'completed', NOW());
        ");
    }

    // Auto-Migrate: Hakikisha safu zote zipo hata kama database ilikuwa na schema ya zamani
    $cols = [
        ['stations', 'monthly_fee', "DECIMAL(10,2) DEFAULT 10000.00 AFTER subscription_expires_at"],
        ['stations', 'router_port', "INT DEFAULT 8728 AFTER router_ip"],
        ['stations', 'last_ping_status', "ENUM('online', 'offline', 'unknown') DEFAULT 'unknown' AFTER subscription_expires_at"],
        ['stations', 'last_ping_at', "DATETIME DEFAULT NULL AFTER last_ping_status"],
        ['bundles', 'data_limit_mb', "INT DEFAULT 0 AFTER speed_limit"],
        ['vouchers', 'batch_no', "VARCHAR(30) DEFAULT 'GENERAL' AFTER bundle_id"],
        ['transactions', 'payment_network', "VARCHAR(30) DEFAULT NULL AFTER payment_type"],
        ['active_sessions', 'speed_limit', "VARCHAR(30) DEFAULT '5M/5M' AFTER auth_reference"]
    ];

    foreach ($cols as $c) {
        $tbl = $c[0];
        $col = $c[1];
        $def = $c[2];
        $checkCol = $pdo->query("SHOW COLUMNS FROM `$tbl` LIKE '$col'")->fetch();
        if (!$checkCol) {
            $pdo->exec("ALTER TABLE `$tbl` ADD COLUMN `$col` $def");
        }
    }

    // Hakikisha transactions.bundle_id inaweza kuwa NULL (kwa ajili ya malipo ya ada ya tawi)
    try {
        $pdo->exec("ALTER TABLE `transactions` MODIFY COLUMN `bundle_id` INT NULL DEFAULT NULL");
        $pdo->exec("ALTER TABLE `transactions` MODIFY COLUMN `payment_type` VARCHAR(40) NOT NULL DEFAULT 'mobile_money'");
    } catch (Exception $e) {
        // Ipuuzwe kama tayari ipo sawa
    }

    // 1. Sasisha hali ya vituo vilivyoisha muda wa subscription kuwa 'expired'
    $pdo->exec("UPDATE stations SET subscription_status = 'expired' WHERE subscription_expires_at <= NOW() AND subscription_status = 'active'");

    // 2. Zima kiotomatiki wateja ambao muda wao wa hewani umemalizika AU kituo chao kimeisha subscription
    $pdo->exec("UPDATE active_sessions SET status = 'disconnected' WHERE status = 'online' AND (expires_at <= NOW() OR station_id IN (SELECT id FROM stations WHERE subscription_status != 'active'))");

} catch (PDOException $e) {
    die("Hitilafu ya kusanidi Database: " . $e->getMessage());
}
?>
