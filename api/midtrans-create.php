<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Metode tidak didukung.'], 405);
}

require_same_origin();
$pdo = database();
$user = require_user($pdo);
$data = request_json();
$plan = strtolower((string) ($data['plan'] ?? ''));
$plans = [
    'basic' => ['amount' => 15000, 'name' => 'More Human Basic - 30 hari'],
    'pro' => ['amount' => 30000, 'name' => 'More Human Pro - 30 hari'],
    'unlimited' => ['amount' => 50000, 'name' => 'More Human Unlimited - 30 hari'],
];
if (!isset($plans[$plan])) {
    json_response(['error' => 'Paket tidak valid.'], 422);
}

$configPath = __DIR__ . '/midtrans.php';
if (!is_file($configPath)) {
    json_response(['error' => 'Pembayaran belum dikonfigurasi.'], 503);
}
@chmod($configPath, 0600);
$midtrans = require $configPath;
if (!(bool) ($midtrans['enabled'] ?? false)) {
    json_response([
        'error' => 'Pembayaran menunggu persetujuan Midtrans.',
    ], 503);
}
$orderId = 'MH-' . gmdate('Ymd') . '-' . str_replace('-', '', uuid_v4());
$paymentId = uuid_v4();
$amount = $plans[$plan]['amount'];

$insert = $pdo->prepare(
    "INSERT INTO payments
        (id, user_id, provider, provider_reference, plan, gross_amount, net_amount, currency, status)
     VALUES
        (:id, :user_id, 'midtrans', :provider_reference, :plan, :gross_amount, :net_amount, 'IDR', 'pending')",
);
$insert->execute([
    'id' => $paymentId,
    'user_id' => $user['id'],
    'provider_reference' => $orderId,
    'plan' => $plan,
    'gross_amount' => $amount,
    'net_amount' => $amount,
]);

$payload = [
    'transaction_details' => [
        'order_id' => $orderId,
        'gross_amount' => $amount,
    ],
    'item_details' => [[
        'id' => $plan,
        'price' => $amount,
        'quantity' => 1,
        'name' => $plans[$plan]['name'],
    ]],
    'customer_details' => ['email' => $user['email']],
    'callbacks' => [
        'finish' => 'https://morehuman.site/app/?payment=finish',
        'error' => 'https://morehuman.site/app/?payment=error',
    ],
    'expiry' => ['duration' => 24, 'unit' => 'hour'],
];

$request = curl_init('https://app.midtrans.com/snap/v1/transactions');
curl_setopt_array($request, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'Authorization: Basic ' . base64_encode($midtrans['server_key'] . ':'),
        'Content-Type: application/json',
    ],
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 20,
]);
$responseBody = curl_exec($request);
$status = (int) curl_getinfo($request, CURLINFO_HTTP_CODE);
$transportError = curl_error($request);
curl_close($request);

$response = json_decode(is_string($responseBody) ? $responseBody : '{}', true);
if (
    $transportError !== '' ||
    $status !== 201 ||
    !is_array($response) ||
    !isset($response['redirect_url'])
) {
    $failed = $pdo->prepare(
        "UPDATE payments SET status = 'creation_failed' WHERE id = :id",
    );
    $failed->execute(['id' => $paymentId]);
    json_response([
        'error' => $status === 401
            ? 'Akun Midtrans belum diizinkan bertransaksi.'
            : 'Halaman pembayaran belum dapat dibuat.',
    ], 502);
}

json_response([
    'orderId' => $orderId,
    'redirectUrl' => $response['redirect_url'],
], 201);
