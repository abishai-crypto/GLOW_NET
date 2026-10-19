<?php
// download_system_zip.php - Inazalisha na kupakua faili la GLOW_NET_V4_SYSTEM.zip
$zipFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'GLOW_NET_V4_SYSTEM.zip';
if (file_exists($zipFile)) {
    @unlink($zipFile);
}

$zip = new ZipArchive();
if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
    $files = [
        'index.php',
        'config.php',
        'login.php',
        'branch_dashboard.php',
        'api_engine.php',
        'dashboard.php',
        'portal.php',
        'PaymentGateway.php',
        'RouterBridge.php',
        'payment_webhook.php',
        'cron_disconnect.php',
        'print_vouchers.php',
        'reports.php',
        'glownet_db.sql',
        'logout.php',
        'mikrotik_login.html',
        'MAELEKEZO_YA_KUWASHA.txt'
    ];

    foreach ($files as $file) {
        $filePath = __DIR__ . DIRECTORY_SEPARATOR . $file;
        if (file_exists($filePath)) {
            $zip->addFile($filePath, "glownet/" . $file);
        }
    }
    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="GLOW_NET_V4_SYSTEM.zip"');
    header('Content-Length: ' . filesize($zipFile));
    header('Pragma: no-cache');
    header('Expires: 0');
    readfile($zipFile);
    @unlink($zipFile);
    exit;
} else {
    die("Hitilafu ya kutengeneza faili la ZIP kwenye seva.");
}
?>
