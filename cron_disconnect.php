<?php
// cron_disconnect.php - Background Worker & Session/Subscription Cleaner
require_once 'config.php';
require_once 'RouterBridge.php';

// 1. Sasisha hali ya vituo vilivyoisha muda wa subscription
$expStationsStmt = $pdo->query("SELECT id, station_code, station_name FROM stations WHERE subscription_expires_at <= NOW() AND subscription_status = 'active'");
$expiredStations = $expStationsStmt->fetchAll();

if (!empty($expiredStations)) {
    $pdo->exec("UPDATE stations SET subscription_status = 'expired' WHERE subscription_expires_at <= NOW() AND subscription_status = 'active'");
}

// 2. Tafuta sessions zilizopitwa na muda au za vituo vilivyoisha
$expSessionsStmt = $pdo->query("
    SELECT a.*, s.hardware_type, s.router_ip, s.router_port, s.router_user, s.router_pass
    FROM active_sessions a
    JOIN stations s ON a.station_id = s.id
    WHERE a.status = 'online' AND (a.expires_at <= NOW() OR s.subscription_status != 'active')
");
$expiredSessions = $expSessionsStmt->fetchAll();

$disconnectedCount = 0;
foreach ($expiredSessions as $sess) {
    $pdo->prepare("UPDATE active_sessions SET status = 'disconnected' WHERE id = ?")->execute([$sess['id']]);
    RouterBridge::disconnectClient($sess, $sess['mac_address']);
    $disconnectedCount++;
}

echo json_encode([
    'status'                 => 'success',
    'timestamp'              => date('Y-m-d H:i:s'),
    'expired_stations_count' => count($expiredStations),
    'disconnected_sessions'  => $disconnectedCount,
    'message'                => "Cron executed: {$disconnectedCount} session(s) disconnected, " . count($expiredStations) . " station(s) expired."
]);
?>
