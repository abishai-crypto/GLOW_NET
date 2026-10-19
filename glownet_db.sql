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
