<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Metode tidak didukung.'], 405);
}

$configPath = __DIR__ . '/midtrans.php';
if (!is_file($configPath)) {
    json_response(['error' => 'Pembayaran belum dikonfigurasi.'], 503);
}
@chmod($configPath, 0600);
$midtrans = require $configPath;
$data = request_json();
$orderId = (string) ($data['order_id'] ?? '');
$statusCode = (string) ($data['status_code'] ?? '');
$grossAmount = (string) ($data['gross_amount'] ?? '');
$signature = (string) ($data['signature_key'] ?? '');
$expected = hash(
    'sha512',
    $orderId . $statusCode . $grossAmount . $midtrans['server_key'],
);
if ($orderId === '' || $signature === '' || !hash_equals($expected, $signature)) {
    json_response(['error' => 'Tanda tangan tidak valid.'], 403);
}

$statusRequest = curl_init(
    'https://api.midtrans.com/v2/' . rawurlencode($orderId) . '/status',
);
curl_setopt_array($statusRequest, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'Authorization: Basic ' . base64_encode($midtrans['server_key'] . ':'),
    ],
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
]);
$statusBody = curl_exec($statusRequest);
$statusHttpCode = (int) curl_getinfo($statusRequest, CURLINFO_HTTP_CODE);
curl_close($statusRequest);
$providerStatus = json_decode(
    is_string($statusBody) ? $statusBody : '{}',
    true,
);
$verifiedTransactionStatus = is_array($providerStatus)
    ? strtolower((string) ($providerStatus['transaction_status'] ?? ''))
    : '';
$verifiedFraudStatus = is_array($providerStatus)
    ? strtolower((string) ($providerStatus['fraud_status'] ?? 'accept'))
    : '';
$verifiedAmount = is_array($providerStatus)
    ? (float) ($providerStatus['gross_amount'] ?? -1)
    : -1;
$verifiedOrderId = is_array($providerStatus)
    ? (string) ($providerStatus['order_id'] ?? '')
    : '';
if (
    $statusHttpCode !== 200 ||
    $verifiedOrderId !== $orderId ||
    abs($verifiedAmount - (float) $grossAmount) > 0.001
) {
    json_response(['error' => 'Status pembayaran belum dapat diverifikasi.'], 503);
}
$mappedStatus = match ($verifiedTransactionStatus) {
    'settlement' => 'paid',
    'capture' => $verifiedFraudStatus === 'accept' ? 'paid' : 'challenge',
    'pending' => 'pending',
    'refund', 'partial_refund' => 'refunded',
    'deny', 'cancel', 'expire', 'failure' => $verifiedTransactionStatus,
    default => 'unknown',
};

$pdo = database();
$pdo->beginTransaction();
try {
    $paymentStatement = $pdo->prepare(
        "SELECT id, user_id, status, plan, gross_amount
         FROM payments
         WHERE provider = 'midtrans'
           AND provider_reference = :provider_reference
           AND gross_amount = :gross_amount
         LIMIT 1
         FOR UPDATE",
    );
    $paymentStatement->execute([
        'provider_reference' => $orderId,
        'gross_amount' => $grossAmount,
    ]);
    $payment = $paymentStatement->fetch();
    if (!is_array($payment)) {
        $pdo->rollBack();
        json_response(['error' => 'Pembayaran tidak ditemukan.'], 404);
    }

    $wasPaid = $payment['status'] === 'paid';
    $update = $pdo->prepare(
        "UPDATE payments
         SET status = :status,
             paid_at = CASE WHEN :paid_status = 'paid' THEN COALESCE(paid_at, UTC_TIMESTAMP()) ELSE paid_at END
         WHERE id = :id",
    );
    $update->execute([
        'status' => $mappedStatus,
        'paid_status' => $mappedStatus,
        'id' => $payment['id'],
    ]);

    if ($mappedStatus === 'paid' && !$wasPaid && is_string($payment['user_id'])) {
        $plan = in_array(
            $payment['plan'],
            ['basic', 'pro', 'unlimited'],
            true,
        ) ? $payment['plan'] : null;
        if ($plan !== null) {
            $entitlement = $pdo->prepare(
                "UPDATE users
                 SET plan = :plan,
                     plan_expires_at = DATE_ADD(
                         GREATEST(COALESCE(plan_expires_at, UTC_TIMESTAMP()), UTC_TIMESTAMP()),
                         INTERVAL 30 DAY
                     )
                 WHERE id = :user_id",
            );
            $entitlement->execute([
                'plan' => $plan,
                'user_id' => $payment['user_id'],
            ]);
        }
    }
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $error;
}

json_response(['ok' => true]);
