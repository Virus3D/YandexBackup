<?php

declare(strict_types=1);

namespace App\Backup;

use RuntimeException;

use const CURLINFO_HTTP_CODE;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_POST;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_RETURNTRANSFER;
use const JSON_ERROR_NONE;
use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_UNICODE;

/**
 * Класс для получения и обновления OAuth-токенов Яндекс.Диска.
 *
 * Реализует поток Authorization Code Grant:
 *   1. Пользователь открывает в браузере URL, сгенерированный getAuthorizationUrl().
 *   2. Разрешает доступ и копирует параметр 'code' из адресной строки.
 *   3. Этот код передаётся в requestAccessTokenByCode() для получения access_token и refresh_token.
 *   4. Токены сохраняются в файл. При необходимости access_token автоматически обновляется через refreshAccessToken().
 *
 * Пример использования:
 * ```php
 * $oauth = new YandexOAuth('client_id', 'client_secret');
 * echo $oauth->getAuthorizationUrl(); // открыть в браузере
 * $code = readline('Введите code: ');
 * $tokens = $oauth->requestAccessTokenByCode($code);
 * echo $tokens['access_token'];
 * ```
 */
final class YandexOAuth
{
    /**
     * URL страницы авторизации
     */
    private const AUTH_URL = 'https://oauth.yandex.ru/authorize';

    /**
     * URL эндпоинта для получения/обновления токенов
     */
    private const TOKEN_URL = 'https://oauth.yandex.ru/token';

    /**
     * Уникальный идентификатор устройства, передаваемый в запросах (необязательный, но рекомендуется)
     */
    private ?string $deviceId = null;

    /**
     * Конструктор.
     *
     * @param string $clientId     Идентификатор приложения (client_id), выданный Яндекс.OAuth
     * @param string $clientSecret Секретный ключ приложения (client_secret)
     * @param string $tokenFile    Путь к файлу, в котором будут сохраняться токены (JSON)
     */
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $tokenFile = 'yandex_token.json',
    ) {
    }// end __construct()

    /**
     * Задаёт идентификатор устройства для более точного трекинга сессий Яндексом.
     *
     * @param string $deviceId Уникальная строка, идентифицирующая устройство/сервер
     */
    public function setDeviceId(string $deviceId): void
    {
        $this->deviceId = $deviceId;
    }// end setDeviceId()

    /**
     * Формирует URL, который необходимо открыть в браузере для получения кода подтверждения.
     *
     * @return string Полный URL для авторизации пользователя
     */
    public function getAuthorizationUrl(): string
    {
        $params = [
            'response_type' => 'code',
            'client_id'     => $this->clientId,
            'force_confirm' => 'yes',
        ];

        // Яндекс рекомендует передавать device_id (даже если он необязателен).
        if (null !== $this->deviceId) {
            $params['device_id'] = $this->deviceId;
        }

        return self::AUTH_URL . '?' . http_build_query($params);
    }// end getAuthorizationUrl()

    /**
     * Обменивает код подтверждения на access_token и refresh_token и сохраняет их в файл.
     *
     * @param string $code Код, полученный после разрешения прав пользователем (параметр code из URL)
     *
     * @return array<mixed> Ассоциативный массив с ключами: access_token, refresh_token, expires_in, token_type, created, ...
     *
     * @throws RuntimeException При ошибке запроса к API или неверном коде
     */
    public function requestAccessTokenByCode(string $code): array
    {
        $data = [
            'grant_type' => 'authorization_code',
            'code'       => $code,
        ];

        if (null !== $this->deviceId) {
            $data['device_id'] = $this->deviceId;
        }

        $tokens = $this->sendTokenRequest($data);
        $this->saveTokens($tokens);

        return $tokens;
    }// end requestAccessTokenByCode()

    /**
     * Обновляет access_token по действующему refresh_token.
     *
     * @param string $refreshToken Текущий refresh_token
     *
     * @return array<mixed> Новый набор токенов (refresh_token может обновиться или остаться прежним)
     *
     * @throws RuntimeException При ошибке обновления (в т.ч. если refresh_token просрочен)
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        $data = [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
        ];

        $tokens = $this->sendTokenRequest($data);
        // Сохраняем всегда, потому что refresh_token мог обновиться.
        $this->saveTokens($tokens);

        return $tokens;
    }// end refreshAccessToken()

    /**
     * Возвращает актуальный access_token из файла токенов, при необходимости автоматически его обновляя.
     *
     * @return string|null Актуальный access_token или null, если нет сохранённых токенов
     *
     * @throws RuntimeException Если токены просрочены и обновить не удалось (файл удаляется)
     */
    public function getAccessToken(): ?string
    {
        $tokens = $this->loadTokens();
        if (null === $tokens) {
            return null;
        }

        // Если до истечения срока действия осталось меньше минуты — обновляем.
        if (time() >= ($tokens['created'] + $tokens['expires_in'] - 60)) {
            if (isset($tokens['refresh_token'])) {
                try {
                    $newTokens = $this->refreshAccessToken($tokens['refresh_token']);

                    return $newTokens['access_token'];
                } catch (RuntimeException $e) {
                    // Удаляем невалидный файл токенов.
                    unlink($this->tokenFile);

                    throw new RuntimeException('Токены устарели, необходимо повторно авторизоваться: ' . $e->getMessage());
                }
            }

            throw new RuntimeException('Токен истёк, а refresh_token отсутствует в сохранённых данных.');
        }

        return $tokens['access_token'];
    }// end getAccessToken()

    /**
     * Отправляет POST-запрос к эндпоинту /token и возвращает разобранный ответ.
     *
     * @param array<mixed> $params Параметры тела запроса (grant_type, code, refresh_token, device_id и т.д.)
     *
     * @return array<mixed> Декодированный ответ API с добавленным полем 'created' (timestamp)
     *
     * @throws RuntimeException При ошибках cURL или неверном ответе API
     */
    private function sendTokenRequest(array $params): array
    {
        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array(
            $ch,
            [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query($params),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
                    'Content-Type: application/x-www-form-urlencoded',
                ],
            ]
        );

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($curlError) {
            throw new RuntimeException('cURL error: ' . $curlError);
        }

        $data = json_decode($response, true);
        if (200 !== $httpCode || isset($data['error'])) {
            $errorDescription = $data['error_description'] ?? $data['error'] ?? 'Unknown error';

            throw new RuntimeException("Token request failed (HTTP {$httpCode}): {$errorDescription}");
        }

        // Добавляем метку времени создания токенов для расчёта TTL.
        $data['created'] = time();

        return $data;
    }// end sendTokenRequest()

    /**
     * Сохраняет токены в JSON-файл.
     *
     * @param array<mixed> $tokens Данные токенов (как получено от API + поле 'created')
     */
    private function saveTokens(array $tokens): void
    {
        file_put_contents(
            $this->tokenFile,
            json_encode($tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }// end saveTokens()

    /**
     * Загружает токены из JSON-файла, если он существует и валиден.
     *
     * @return array<mixed>|null Массив токенов или null, если файла нет или JSON повреждён
     */
    private function loadTokens(): ?array
    {
        if (! file_exists($this->tokenFile)) {
            return null;
        }

        $data = json_decode(file_get_contents($this->tokenFile), true);
        if (JSON_ERROR_NONE !== json_last_error()) {
            return null;
        }

        return $data;
    }// end loadTokens()
}// end class
