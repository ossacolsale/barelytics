<?php
declare(strict_types=1);

if (PHP_VERSION_ID < 80100 || PHP_VERSION_ID > 80599) { exit(1); }

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/src/Barelytics.php';

use function Barelytics\connectDatabase;
use function Barelytics\cleanRetentionBatch;
use function Barelytics\schemaVersion;

try {
    $db = connectDatabase();
    if (schemaVersion($db) !== \Barelytics\CURRENT_SCHEMA_VERSION) exit(1);
    cleanRetentionBatch($db);
} catch (Throwable $ignored) {
    exit(1);
}
