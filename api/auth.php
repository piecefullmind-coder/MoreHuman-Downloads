<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$pdo = database();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = current_user($pdo);
    json_response(['authenticated' => $user !== null, 'user' => $user]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Metode tidak didukung.'], 405);
}

require_same_origin();
$data = request_json();
$action = (string) ($data['action'] ?? '');

if ($action === 'logout') {
    $token = $_COOKIE[MOREHUMAN_SESSION_COOKIE] ?? '';
    if (is_string($token) && strlen($token) === 64) {
        $statement = $pdo->prepare('DELETE FROM sessions WHERE token_hash = :token_hash');
        $statement->execute(['token_hash' => hash('sha256', $token)]);
    }
    setcookie(MOREHUMAN_SESSION_COOKIE, '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    json_response(['ok' => true]);
}

$email = strtolower(trim((string) ($data['email'] ?? '')));
$password = (string) ($data['password'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['error' => 'Alamat email tidak valid.'], 422);
}

if ($action === 'signup') {
    if (strlen($password) < 10) {
        json_response(['error' => 'Kata sandi minimal 10 karakter.'], 422);
    }
    $exists = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $exists->execute(['email' => $email]);
    if ($exists->fetch()) {
        json_response(['error' => 'Email sudah terdaftar. Silakan masuk.'], 409);
    }

    $userId = uuid_v4();
    $statement = $pdo->prepare(
        'INSERT INTO users (id, email, password_hash) VALUES (:id, :email, :password_hash)',
    );
    $statement->execute([
        'id' => $userId,
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);
    create_session($pdo, $userId);
    json_response([
        'ok' => true,
        'user' => [
            'email' => $email,
            'id' => $userId,
            'role' => 'user',
            'plan' => 'free',
            'plan_expires_at' => null,
        ],
    ], 201);
}

if ($action === 'login') {
    $statement = $pdo->prepare(
        "SELECT id, email, password_hash, role, plan, plan_expires_at
         FROM users WHERE email = :email AND status = 'active' LIMIT 1",
    );
    $statement->execute(['email' => $email]);
    $user = $statement->fetch();
    if (!is_array($user) || !password_verify($password, $user['password_hash'])) {
        usleep(250000);
        json_response(['error' => 'Email atau kata sandi salah.'], 401);
    }
    create_session($pdo, $user['id']);
    unset($user['password_hash']);
    json_response(['ok' => true, 'user' => $user]);
}

json_response(['error' => 'Tindakan tidak didukung.'], 400);
