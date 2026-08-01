<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$pdo = database();
$user = require_user($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $statement = $pdo->prepare(
        "SELECT id, result_text AS latest_result, rewrite_mode,
                'completed' AS status,
                CONCAT('Rewrite ', DATE_FORMAT(created_at, '%d %b %Y')) AS title,
                created_at, created_at AS updated_at
         FROM rewrite_history
         WHERE user_id = :user_id
         ORDER BY created_at DESC
         LIMIT 50",
    );
    $statement->execute(['user_id' => $user['id']]);
    json_response(['items' => $statement->fetchAll()]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Metode tidak didukung.'], 405);
}

require_same_origin();
$data = request_json();
$source = trim((string) ($data['source'] ?? ''));
$result = trim((string) ($data['result'] ?? ''));
$mode = strtolower((string) ($data['mode'] ?? 'balanced'));
$allowedModes = ['balanced', 'skripsi', 'buku', 'email', 'bisnis', 'natural'];
if ($source === '' || $result === '' || mb_strlen($source) > 20000) {
    json_response(['error' => 'Teks tidak valid.'], 422);
}
if (!in_array($mode, $allowedModes, true)) {
    $mode = 'balanced';
}

$id = uuid_v4();
$statement = $pdo->prepare(
    'INSERT INTO rewrite_history
        (id, user_id, source_text, result_text, rewrite_mode, quality_score, fact_score, language_score)
     VALUES
        (:id, :user_id, :source_text, :result_text, :rewrite_mode, :quality_score, :fact_score, :language_score)',
);
$statement->execute([
    'id' => $id,
    'user_id' => $user['id'],
    'source_text' => $source,
    'result_text' => $result,
    'rewrite_mode' => $mode,
    'quality_score' => max(0, min(100, (int) ($data['qualityScore'] ?? 0))),
    'fact_score' => max(0, min(100, (int) ($data['factScore'] ?? 0))),
    'language_score' => max(0, min(100, (int) ($data['languageScore'] ?? 0))),
]);
json_response(['id' => $id, 'ok' => true, 'saved' => true], 201);

