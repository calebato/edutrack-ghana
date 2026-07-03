<?php
require_once __DIR__ . '/ml.php';
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
registerActiveMLModel();
echo "Active ML model registered.\n";
