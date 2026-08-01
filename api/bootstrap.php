<?php
declare(strict_types=1);

const MOREHUMAN_SESSION_COOKIE = 'mh_session';

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function require_same_origin(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') {
        return;
    }

    $host = strtolower((string) parse_url($origin, PHP_URL_HOST));
    if (!in_array($host, ['morehuman.site', 'www.morehuman.site'], true)) {
        json_response(['error' => 'Permintaan ditolak.'], 403);
    }
}

function request_json(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    if (!is_array($data)) {
        json_response(['error' => 'Format permintaan tidak valid.'], 400);
    }
    return $data;
}

function database(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $configPath = __DIR__ . '/config.php';
    if (!is_file($configPath)) {
        json_response(['error' => 'Database belum dikonfigurasi.'], 503);
    }

    $config = require $configPath;
    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['port'] ?? 3306,
            $config['database'],
        ),
        $config['username'],
        $config['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    );
    ensure_schema($pdo);
    return $pdo;
}

function ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS users (
            id CHAR(36) PRIMARY KEY,
            email VARCHAR(255) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('user','admin','owner') NOT NULL DEFAULT 'user',
            status ENUM('active','disabled') NOT NULL DEFAULT 'active',
            plan ENUM('free','basic','pro','unlimited') NOT NULL DEFAULT 'free',
            plan_expires_at TIMESTAMP NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    );
    $columnCheck = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = 'users'
           AND column_name = :column_name",
    );
    $columnCheck->execute(['column_name' => 'plan']);
    if ((int) $columnCheck->fetchColumn() === 0) {
        $pdo->exec(
            "ALTER TABLE users
             ADD COLUMN plan ENUM('free','basic','pro','unlimited') NOT NULL DEFAULT 'free'",
        );
    }
    $columnCheck->execute(['column_name' => 'plan_expires_at']);
    if ((int) $columnCheck->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE users ADD COLUMN plan_expires_at TIMESTAMP NULL');
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS sessions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id CHAR(36) NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            expires_at TIMESTAMP NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX sessions_user_id (user_id),
            INDEX sessions_expires_at (expires_at),
            CONSTRAINT sessions_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS password_reset_tokens (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id CHAR(36) NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            expires_at TIMESTAMP NOT NULL,
            used_at TIMESTAMP NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX password_reset_user (user_id),
            INDEX password_reset_expiry (expires_at),
            CONSTRAINT password_reset_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS rewrite_history (
            id CHAR(36) PRIMARY KEY,
            user_id CHAR(36) NOT NULL,
            source_text MEDIUMTEXT NOT NULL,
            result_text MEDIUMTEXT NOT NULL,
            rewrite_mode VARCHAR(32) NOT NULL,
            quality_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
            fact_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
            language_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX rewrites_user_created (user_id, created_at),
            CONSTRAINT rewrites_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS payments (
            id CHAR(36) PRIMARY KEY,
            user_id CHAR(36) NULL,
            provider VARCHAR(32) NOT NULL,
            provider_reference VARCHAR(128) NULL UNIQUE,
            plan VARCHAR(32) NULL,
            gross_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            fee_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            net_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            currency CHAR(3) NOT NULL DEFAULT 'IDR',
            status VARCHAR(32) NOT NULL,
            paid_at TIMESTAMP NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX payments_status_created (status, created_at),
            CONSTRAINT payments_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    );
    $paymentPlanColumn = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = 'payments'
           AND column_name = 'plan'",
    );
    $paymentPlanColumn->execute();
    if ((int) $paymentPlanColumn->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE payments ADD COLUMN plan VARCHAR(32) NULL AFTER provider_reference');
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS payouts (
            id CHAR(36) PRIMARY KEY,
            provider_reference VARCHAR(128) NULL UNIQUE,
            gross_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            fee_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            net_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            status VARCHAR(32) NOT NULL,
            payout_date DATE NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    );

    $ready = true;
}

function uuid_v4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

function create_session(PDO $pdo, string $userId): void
{
    $token = bin2hex(random_bytes(32));
    $statement = $pdo->prepare(
        'INSERT INTO sessions (user_id, token_hash, expires_at)
         VALUES (:user_id, :token_hash, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 DAY))',
    );
    $statement->execute([
        'user_id' => $userId,
        'token_hash' => hash('sha256', $token),
    ]);

    setcookie(MOREHUMAN_SESSION_COOKIE, $token, [
        'expires' => time() + 60 * 60 * 24 * 30,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function current_user(PDO $pdo): ?array
{
    $token = $_COOKIE[MOREHUMAN_SESSION_COOKIE] ?? '';
    if (!is_string($token) || strlen($token) !== 64) {
        return null;
    }

    $statement = $pdo->prepare(
        "SELECT u.id, u.email, u.role, u.plan, u.plan_expires_at
         FROM sessions s
         JOIN users u ON u.id = s.user_id
         WHERE s.token_hash = :token_hash
           AND s.expires_at > UTC_TIMESTAMP()
           AND u.status = 'active'
         LIMIT 1",
    );
    $statement->execute(['token_hash' => hash('sha256', $token)]);
    $user = $statement->fetch();
    return is_array($user) ? $user : null;
}

function require_user(PDO $pdo): array
{
    $user = current_user($pdo);
    if ($user === null) {
        json_response(['error' => 'Silakan masuk terlebih dahulu.'], 401);
    }
    return $user;
}
