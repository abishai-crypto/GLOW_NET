-- GLOW NET V4 DATABASE SCHEMA (Port 3306 / 3307)
CREATE DATABASE IF NOT EXISTS glownet_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE glownet_db;

-- 0. JEDWALI LA MA-ADMIN (ADMINS)
CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) DEFAULT 'Master Admin',
    email VARCHAR(100) DEFAULT NULL,
    role VARCHAR(30) DEFAULT 'master_admin',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Seed default admin (Password: admin123)
INSERT INTO admins (username, password_hash, full_name) 
VALUES ('admin', '$2y$10$ziieA5eg9XAfxyuyKM29MeGnbtRRkSPrOHI48YWsAPnAC2czJPbP.', 'Master Admin (GLOW NET)')
ON DUPLICATE KEY UPDATE username = username;


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

CREATE TABLE IF NOT EXISTS system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(50) UNIQUE NOT NULL,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- =====================================================================
-- SEED DATA (Demo Stations, Bundles, Vouchers & Sample Transactions)
-- =====================================================================

-- 1. Stations (Default password: 1234)
INSERT INTO stations (id, station_code, station_name, owner_name, owner_phone, owner_password, location, hardware_type, router_ip, router_user, router_pass, subscription_status, subscription_expires_at, monthly_fee) 
VALUES
(1, 'GLOW-KIMARA', 'GlowNet Kimara Suka Hub', 'Mussa Juma (Kimara)', '0755111222', '$2y$10$4esc.R9oEG.kAK7hLf48seSgJxp6V6NDECtxbzUMIg3zBBXu8mgum', 'Kimara Suka, DSM', 'mikrotik', '192.168.88.1', 'admin', '1234', 'active', DATE_ADD(NOW(), INTERVAL 30 DAY), 10000.00),
(2, 'GLOW-MBEZI', 'GlowNet Mbezi Stand AP', 'Amina Ally (Mbezi)', '0713333444', '$2y$10$4esc.R9oEG.kAK7hLf48seSgJxp6V6NDECtxbzUMIg3zBBXu8mgum', 'Mbezi Luis, DSM', 'generic_ap', '192.168.1.1', 'admin', '1234', 'active', DATE_ADD(NOW(), INTERVAL 30 DAY), 10000.00),
(3, 'GLOW-SINZA', 'GlowNet Sinza Mori Zone', 'David Mwamba (Sinza)', '0788555666', '$2y$10$4esc.R9oEG.kAK7hLf48seSgJxp6V6NDECtxbzUMIg3zBBXu8mgum', 'Sinza Mori, DSM', 'mikrotik', '192.168.89.1', 'admin', '1234', 'active', DATE_ADD(NOW(), INTERVAL 30 DAY), 10000.00)
ON DUPLICATE KEY UPDATE station_code = station_code;

-- 2. Wi-Fi Bundles
INSERT INTO bundles (id, station_id, bundle_name, price, duration_minutes, speed_limit) 
VALUES
(1, 1, 'Masaa 3 Kasi ya Juu', 300.00, 180, '5M/5M'),
(2, 1, 'Masaa 12 Bila Kikomo', 500.00, 720, '6M/6M'),
(3, 1, 'Siku Nzima (Masaa 24)', 1000.00, 1440, '8M/8M'),
(4, 1, 'Wiki Nzima (Siku 7)', 5000.00, 10080, '10M/10M'),
(5, 1, 'Mwezi Mzima (Siku 30)', 10000.00, 43200, '12M/12M'),
(6, 2, 'Siku Nzima Promo (24h)', 500.00, 1440, '5M/5M'),
(7, 2, 'Wiki Nzima Special', 3500.00, 10080, '7M/7M'),
(8, 2, 'Mwezi Mzima Unlimited', 8000.00, 43200, '10M/10M'),
(9, 3, 'Masaa 12 Sinza Night/Day', 500.00, 720, '6M/6M'),
(10, 3, 'Siku Nzima Sinza Express', 1000.00, 1440, '10M/10M')
ON DUPLICATE KEY UPDATE bundle_name = bundle_name;

-- 3. Demo Vouchers
INSERT INTO vouchers (station_id, bundle_id, batch_no, voucher_code, status) 
VALUES
(1, 1, 'BATCH-01', 'GN100100', 'unused'),
(1, 2, 'BATCH-01', 'GN100200', 'unused'),
(1, 3, 'BATCH-01', 'GN300400', 'unused'),
(1, 4, 'BATCH-01', 'GN500600', 'unused'),
(2, 6, 'BATCH-02', 'GN700800', 'unused'),
(2, 7, 'BATCH-02', 'GN800900', 'unused'),
(3, 9, 'BATCH-03', 'GN900100', 'unused'),
(3, 10, 'BATCH-03', 'GN900200', 'unused')
ON DUPLICATE KEY UPDATE voucher_code = voucher_code;

-- 4. Sample Transactions (For analytics and charts)
INSERT INTO transactions (station_id, bundle_id, reference_no, customer_phone, mac_address, amount, payment_type, payment_network, status, created_at) 
VALUES
(1, 2, 'GN-DEMO-01', '0755111222', '44:65:0E:11:22:33', 500.00, 'mobile_money', 'M-Pesa', 'completed', NOW()),
(1, 3, 'GN-DEMO-02', '0714223344', '58:D0:61:AA:BB:CC', 1000.00, 'mobile_money', 'Tigo Pesa', 'completed', NOW()),
(1, 4, 'GN-DEMO-03', '0788334455', '30:96:FB:DD:EE:FF', 5000.00, 'voucher', NULL, 'completed', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(1, NULL, 'SUB-DEMO-01', '0755111222', NULL, 10000.00, 'branch_subscription', 'M-Pesa', 'completed', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(2, 6, 'GN-DEMO-04', '0766778899', '28:39:26:01:02:03', 500.00, 'mobile_money', 'M-Pesa', 'completed', NOW()),
(2, 7, 'GN-DEMO-05', '0622334455', 'B4:CD:27:04:05:06', 3500.00, 'mobile_money', 'Halopesa', 'completed', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(3, 10, 'GN-DEMO-06', '0688990011', '68:DB:F5:07:08:09', 1000.00, 'mobile_money', 'Airtel Money', 'completed', NOW())
ON DUPLICATE KEY UPDATE reference_no = reference_no;

