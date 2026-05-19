<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use App\Backup\BackupBD;
use App\Backup\HttpClient;
use App\Backup\Logger;
use App\Backup\YandexDisk;
use App\Backup\YandexOAuth;
use App\Backup\YandexDiskBackup;
use Dotenv\Dotenv;

// Загрузка .env .
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$dotenv->required(
    [
        'YANDEX_CLIENT_ID',
        'YANDEX_CLIENT_SECRET',
        'YANDEX_TOKEN_FILE',
        'BACKUP_PROJECT',
        'BACKUP_LOCAL_DIR',
        'BACKUP_YANDEX_DIR',
        'BACKUP_DATABASES',
    ]
);

$clientId     = $_ENV['YANDEX_CLIENT_ID'];
$clientSecret = $_ENV['YANDEX_CLIENT_SECRET'];
$tokenFile    = $_ENV['YANDEX_TOKEN_FILE'];

$oauth = new YandexOAuth($clientId, $clientSecret, $tokenFile);
$accessToken = $oauth->getAccessToken();

$project    = $_ENV['BACKUP_PROJECT'];
$localDir   = $_ENV['BACKUP_LOCAL_DIR'];
$yandexDir  = $_ENV['BACKUP_YANDEX_DIR'];
$maxBackups = (int) ($_ENV['BACKUP_MAX_COPIES'] ?? 7);

$databasesJson = $_ENV['BACKUP_DATABASES'];
$databases = json_decode($databasesJson, true, 512, JSON_THROW_ON_ERROR);

$backupCompress = (bool) ($_ENV['BACKUP_COMPRESS'] ?? false);

// Строим зависимости.
$logger = new Logger(__DIR__ . '/yandex.log');

(new BackupBD($localDir, $databases, $backupCompress, $logger))->run();

$http   = new HttpClient($logger);
$http->setAccessToken($accessToken);

$disk   = new YandexDisk($http, $logger);

$backup = new YandexDiskBackup(
    disk: $disk,
    logger: $logger,
    project: $project,
    backupSrcDir: $localDir,
    diskDestResource: $yandexDir,
    maxBackups: $maxBackups
);

$backup->run();
