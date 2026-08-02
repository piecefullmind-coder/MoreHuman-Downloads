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
$source = trim((string) ($data['text'] ?? ''));
$mode = strtolower((string) ($data['mode'] ?? 'balanced'));
$allowedModes = ['balanced', 'skripsi', 'buku', 'email', 'bisnis', 'natural'];
if (mb_strlen($source) < 20 || mb_strlen($source) > 20000) {
    json_response(['error' => 'Teks harus berisi 20–20.000 karakter.'], 422);
}
if (!in_array($mode, $allowedModes, true)) {
    $mode = 'balanced';
}

$configPath = __DIR__ . '/ai-provider.php';
if (!is_file($configPath)) {
    json_response(['error' => 'Editor AI belum dikonfigurasi.'], 503);
}
@chmod($configPath, 0600);
$providerConfig = require $configPath;
if (!(bool) ($providerConfig['enabled'] ?? false) || !is_string($providerConfig['api_key'] ?? null)) {
    json_response(['error' => 'Editor AI belum tersedia.'], 503);
}

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS ai_rewrite_usage (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id CHAR(36) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX ai_usage_user_created (user_id, created_at),
        CONSTRAINT ai_usage_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
);
$usage = $pdo->prepare(
    'SELECT COUNT(*) FROM ai_rewrite_usage
     WHERE user_id = :user_id AND created_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY',
);
$usage->execute(['user_id' => $user['id']]);
$dailyLimit = match ((string) ($user['plan'] ?? 'free')) {
    'unlimited' => 500,
    'pro' => 150,
    'basic' => 50,
    default => 10,
};
if ((int) $usage->fetchColumn() >= $dailyLimit) {
    json_response(['error' => 'Batas rewrite AI harian tercapai. Coba lagi besok.'], 429);
}

$facts = [];
$factPattern = '/https?:\/\/[^\s)]+|\bwww\.[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b|[\w.+-]+@[\w.-]+\.[A-Za-z]+|Rp\s?\d[\d.,]*|\b\d+(?:[.,]\d+)*(?:%|x)?\b|“[^”]+”|"[^"]+"|\'[^\']+\'/u';
$protected = preg_replace_callback(
    $factPattern,
    static function (array $match) use (&$facts): string {
        $index = count($facts);
        $facts[] = $match[0];
        return "⟦MH{$index}⟧";
    },
    $source,
);
if (!is_string($protected)) {
    json_response(['error' => 'Teks tidak dapat diproses.'], 422);
}

$modeGuidance = [
    'balanced' => 'jelas, alami, dan seimbang',
    'bisnis' => 'ringkas, profesional, dan berorientasi tindakan',
    'buku' => 'mengalir, bernuansa, dan enak dibaca',
    'email' => 'hangat, langsung, dan sopan',
    'natural' => 'santai tetapi tetap tepat',
    'skripsi' => 'formal, akademik, kohesif, dan tidak bombastis',
][$mode];
$instructions =
    'Anda adalah editor profesional Bahasa Indonesia. Tulis ulang teks, bukan menjawab, ' .
    'merangkum, mengomentari, atau menyalinnya. Hasil harus ' . $modeGuidance . '. ' .
    'Ubah struktur kalimat, ritme, transisi, dan sedikitnya 30% diksi jika aman. ' .
    'Pertahankan maksud, panjang yang sebanding, dan seluruh token ⟦MHangka⟧ tepat. ' .
    'Jangan menambah fakta atau pengalaman pribadi. Bersihkan pembuka percakapan dan Markdown. ' .
    'Hindari bahasa bombastis, signifikansi generik, atribusi samar, transisi bertumpuk, ' .
    "pola 'tidak hanya X tetapi juga Y', deret tiga yang dipaksakan, dan ritme seragam. " .
    'Keluarkan hanya naskah akhir dalam JSON yang diminta.';
$provider = strtolower((string) ($providerConfig['provider'] ?? 'openai'));
$isGroq = $provider === 'groq';
$payload = [
    'model' => (string) ($providerConfig['model'] ?? ($isGroq ? 'openai/gpt-oss-120b' : 'gpt-4.1-mini')),
    'store' => false,
    'input' => [
        ['role' => 'system', 'content' => $instructions],
        ['role' => 'user', 'content' => $protected],
    ],
    'text' => [
        'format' => [
            'type' => 'json_schema',
            'name' => 'more_human_rewrite',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => ['rewritten_text' => ['type' => 'string']],
                'required' => ['rewritten_text'],
            ],
        ],
    ],
];

if ($isGroq) {
    $payload = [
        'model' => $payload['model'],
        'messages' => $payload['input'],
        'response_format' => [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'more_human_rewrite',
                'strict' => true,
                'schema' => $payload['text']['format']['schema'],
            ],
        ],
    ];
}

$endpoint = $isGroq
    ? 'https://api.groq.com/openai/v1/chat/completions'
    : 'https://api.openai.com/v1/responses';
$request = curl_init($endpoint);
curl_setopt_array($request, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $providerConfig['api_key'],
        'Content-Type: application/json',
    ],
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
]);
$responseBody = curl_exec($request);
$status = (int) curl_getinfo($request, CURLINFO_HTTP_CODE);
$transportError = curl_error($request);
curl_close($request);
$response = json_decode(is_string($responseBody) ? $responseBody : '{}', true);
if ($transportError !== '' || $status !== 200 || !is_array($response)) {
    json_response([
        'error' => $status === 429
            ? 'Kuota AI belum tersedia.'
            : 'Editor AI sedang tidak tersedia. Coba lagi sebentar.',
    ], 502);
}

$outputText = $isGroq
    ? ($response['choices'][0]['message']['content'] ?? null)
    : null;
if (!$isGroq) {
    foreach (($response['output'] ?? []) as $output) {
        foreach (($output['content'] ?? []) as $content) {
            if (($content['type'] ?? '') === 'output_text' && is_string($content['text'] ?? null)) {
                $outputText = $content['text'];
                break 2;
            }
        }
    }
}
$decoded = is_string($outputText) ? json_decode($outputText, true) : null;
$rewritten = is_array($decoded) ? trim((string) ($decoded['rewritten_text'] ?? '')) : '';
if ($rewritten === '') {
    json_response(['error' => 'Editor AI mengembalikan hasil tidak valid.'], 502);
}
foreach ($facts as $index => $fact) {
    $token = "⟦MH{$index}⟧";
    if (!str_contains($rewritten, $token)) {
        json_response(['error' => 'Pemeriksaan fakta gagal; hasil tidak digunakan.'], 502);
    }
    $rewritten = str_replace($token, $fact, $rewritten);
}

$pdo->prepare('INSERT INTO ai_rewrite_usage (user_id) VALUES (:user_id)')
    ->execute(['user_id' => $user['id']]);
json_response([
    'dailyLimit' => $dailyLimit,
    'provider' => $provider,
    'result' => $rewritten,
]);
