<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$pdo = database();
$user = require_user($pdo);
if (!in_array($user['role'], ['admin', 'owner'], true)) {
    json_response(['error' => 'Akses admin diperlukan.'], 403);
}

$summary = $pdo->query(
    "SELECT COUNT(*) AS transaction_count,
            COALESCE(SUM(gross_amount), 0) AS gross_sales,
            COALESCE(SUM(fee_amount), 0) AS fees,
            COALESCE(SUM(net_amount), 0) AS net_sales
     FROM payments
     WHERE status IN ('paid', 'settlement')",
)->fetch();
$userSummary = $pdo->query(
    "SELECT COUNT(*) AS user_count,
            SUM(CASE
                WHEN plan <> 'free'
                 AND plan_expires_at > UTC_TIMESTAMP()
                THEN 1 ELSE 0
            END) AS active_subscriber_count
     FROM users
     WHERE status = 'active'",
)->fetch();
$pendingSummary = $pdo->query(
    "SELECT COUNT(*) AS pending_payment_count
     FROM payments
     WHERE status = 'pending'",
)->fetch();
$payments = $pdo->query(
    "SELECT id, user_id, provider, provider_reference, plan, gross_amount,
            fee_amount, net_amount, currency, status, paid_at, created_at
     FROM payments
     ORDER BY created_at DESC
     LIMIT 100",
)->fetchAll();
$payouts = $pdo->query(
    "SELECT id, provider_reference, gross_amount, fee_amount, net_amount,
            status, payout_date, created_at
     FROM payouts ORDER BY created_at DESC LIMIT 100",
)->fetchAll();
$reconciliation = $pdo->query(
    "SELECT status, COUNT(*) AS payment_count,
            COALESCE(SUM(gross_amount), 0) AS gross_amount,
            COALESCE(SUM(fee_amount), 0) AS fee_amount,
            COALESCE(SUM(net_amount), 0) AS net_amount
     FROM payments
     GROUP BY status
     ORDER BY payment_count DESC",
)->fetchAll();
$users = $pdo->query(
    "SELECT id, email, role, status, plan, plan_expires_at, created_at
     FROM users
     ORDER BY created_at DESC
     LIMIT 100",
)->fetchAll();

json_response([
    'currency' => 'IDR',
    'payments' => $payments,
    'payouts' => $payouts,
    'reconciliation' => $reconciliation,
    'summary' => array_merge(
        is_array($summary) ? $summary : [],
        is_array($userSummary) ? $userSummary : [],
        is_array($pendingSummary) ? $pendingSummary : [],
    ),
    'users' => $users,
]);
