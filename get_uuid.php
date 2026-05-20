<?php

declare(strict_types=1);

use App\Backup\Helper;

require_once __DIR__ . '/vendor/autoload.php';

$deviceId = Helper::generateUuidV4();
echo "UUID: \n{$deviceId}\n";
