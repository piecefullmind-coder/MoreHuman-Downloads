<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

try {
    database()->query('SELECT 1');
    json_response(['database' => 'connected', 'ok' => true]);
} catch (Throwable $error) {
    json_response(['database' => 'unavailable', 'ok' => false], 503);
}

