<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/event_lifecycle.php';

synchronizeEventLifecycle($pdo);
fwrite(STDOUT, "Event lifecycle synchronization completed.\n");
