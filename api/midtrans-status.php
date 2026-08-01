<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Metode tidak didukung.'], 405);
}

$configPath = __DIR__ . '/midtrans.php';
$enabled = false;
if (is_file($configPath)) {
    @chmod($configPath, 0600);
    $midtrans = require $configPath;
    $enabled = (bool) ($midtrans['enabled'] ?? false);
}

json_response([
    'enabled' => $enabled,
    'message' => $enabled
        ? 'Pembayaran tersedia.'
        : 'Pembayaran menunggu persetujuan Midtrans.',
]);
