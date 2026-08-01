<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Metode tidak didukung.'], 405);
}

require_same_origin();
$pdo = database();
$data = request_json();
$action = (string) ($data['action'] ?? 'request');

if ($action === 'request') {
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(['error' => 'Alamat email tidak valid.'], 422);
    }

    $statement = $pdo->prepare("SELECT id FROM users WHERE email = :email AND status = 'active' LIMIT 1");
    $statement->execute(['email' => $email]);
    $userId = $statement->fetchColumn();
    if (is_string($userId)) {
        $token = bin2hex(random_bytes(32));
        $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = :user_id OR expires_at <= UTC_TIMESTAMP()')
            ->execute(['user_id' => $userId]);
        $pdo->prepare(
            'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
             VALUES (:user_id, :token_hash, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 MINUTE))',
        )->execute([
            'user_id' => $userId,
            'token_hash' => hash('sha256', $token),
        ]);
        $url = 'https://morehuman.site/update-password/?token=' . rawurlencode($token);
        $subject = 'Atur ulang kata sandi More Human';
        $body = "Buka tautan aman berikut dalam 30 menit:\n\n" . $url . "\n\nJika Anda tidak meminta ini, abaikan email ini.";
        $headers = implode("\r\n", [
            'From: More Human <info@morehuman.site>',
            'Reply-To: info@morehuman.site',
            'Content-Type: text/plain; charset=UTF-8',
        ]);
        @mail($email, $subject, $body, $headers);
    }
    json_response(['ok' => true]);
}

if ($action === 'reset') {
    $token = (string) ($data['token'] ?? '');
    $password = (string) ($data['password'] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        json_response(['error' => 'Tautan reset tidak valid.'], 422);
    }
    if (strlen($password) < 10) {
        json_response(['error' => 'Kata sandi minimal 10 karakter.'], 422);
    }
    $statement = $pdo->prepare(
        'SELECT id, user_id FROM password_reset_tokens
         WHERE token_hash = :token_hash AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()
         LIMIT 1 FOR UPDATE',
    );
    $pdo->beginTransaction();
    try {
        $statement->execute(['token_hash' => hash('sha256', $token)]);
        $reset = $statement->fetch();
        if (!is_array($reset)) {
            $pdo->rollBack();
            json_response(['error' => 'Tautan reset sudah tidak berlaku.'], 422);
        }
        $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id')
            ->execute(['id' => $reset['user_id'], 'password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        $pdo->prepare('UPDATE password_reset_tokens SET used_at = UTC_TIMESTAMP() WHERE id = :id')
            ->execute(['id' => $reset['id']]);
        $pdo->prepare('DELETE FROM sessions WHERE user_id = :user_id')
            ->execute(['user_id' => $reset['user_id']]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    json_response(['ok' => true]);
}

json_response(['error' => 'Tindakan tidak didukung.'], 400);
