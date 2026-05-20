<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use App\Backup\YandexOAuth;
use Dotenv\Dotenv;

// Загрузка .env .
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$dotenv->required(
    [
        'YANDEX_CLIENT_ID',
        'YANDEX_CLIENT_SECRET',
        'YANDEX_TOKEN_FILE',
    ]
);

$clientId     = $_ENV['YANDEX_CLIENT_ID'];
$clientSecret = $_ENV['YANDEX_CLIENT_SECRET'];
$tokenFile    = $_ENV['YANDEX_TOKEN_FILE'];

$deviceId    = $_ENV['YANDEX_DEVICE_ID'] ?? null;

$oauth = new YandexOAuth($clientId, $clientSecret, $tokenFile);

$oauth->setDeviceId($deviceId);

// Получаем ссылку и просим пользователя открыть её.
echo "Откройте ссылку в браузере:\n";
echo $oauth->getAuthorizationUrl() . "\n";
echo "После подтверждения скопируйте код из адресной строки (параметр 'code') и вставьте сюда: ";
$code = mb_trim(fgets(\STDIN));

// Обмениваем код на токены.
$tokens = $oauth->requestAccessTokenByCode($code);
echo "Токены успешно сохранены в yandex_token.json\n";
print_r($tokens);
