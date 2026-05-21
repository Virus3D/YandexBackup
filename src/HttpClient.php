<?php

declare(strict_types=1);

namespace App\Backup;

use Psr\Log\LoggerInterface;
use RuntimeException;

class HttpClient
{
    private ?string $token = null;

    // Порог, после которого переключаемся на составную загрузку (байты): 2 GiB.
    private const RESUMABLE_THRESHOLD = 2 * 1024 * 1024 * 1024;
    // Размер одной части при составной загрузке (рекомендация Яндекса — кратно 4 MiB): 32 MiB.
    private const CHUNK_SIZE = 32 * 1024 * 1024;
    // Максимальное время для загрузки одной части (15 минут).
    private const CHUNK_TIMEOUT = 900;
    // Максимальное количество попыток для каждой части.
    private const MAX_RETRIES = 3;

    public function __construct(private readonly LoggerInterface $logger)
    {
    }// end __construct()

    /**
     * Установка токена доступа.
     */
    public function setAccessToken(string $token): void
    {
        $this->token = $token;
    }// end setAccessToken()

    /**
     * Выполнение запроса.
     *
     * @param array<mixed> $options
     *
     * @return array{code: int, result: mixed}
     *
     * @throws RuntimeException
     */
    public function request(string $method, string $url, array $options = []): array
    {
        if (!$this->token) {
            throw new RuntimeException('Access token is not set.');
        }

        $ch = curl_init();

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: OAuth ' . $this->token,
        ];

        curl_setopt_array(
            $ch,
            [
                CURLOPT_URL            => $url,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT        => 30,
            ]
        );

        // Применяем дополнительные опции (для PUT с файлом и т.д.).
        if (!empty($options)) {
            curl_setopt_array($ch, $options);
        }

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);

        if ($curlError) {
            $this->logger->error("cURL error: {$curlError}");
            throw new RuntimeException("cURL error: {$curlError}");
        }

        $decoded = json_decode($response, true);

        return [
            'code'   => $httpCode,
            'result' => $decoded,
        ];
    }// end request()

    /**
     * Удобный метод для GET
     *
     * @param array<mixed> $query
     *
     * @return array{code: int, result: mixed}
     */
    public function get(string $url, array $query = []): array
    {
        if ($query) {
            $url .= '?' . http_build_query($query);
        }
        return $this->request('GET', $url);
    }// end get()

    /**
     * Удобный метод для DELETE
     *
     * @param array<mixed> $query
     *
     * @return array{code: int, result: mixed}
     */
    public function delete(string $url, array $query = []): array
    {
        if ($query) {
            $url .= '?' . http_build_query($query);
        }
        return $this->request('DELETE', $url);
    }// end delete()

    /**
     * Удобный метод для PUT
     *
     * @param array<mixed> $data
     *
     * @return array{code: int, result: mixed}
     */
    public function put(string $url, array $data = []): array
    {
        $options = [];
        if ($data) {
            $options[CURLOPT_POSTFIELDS] = json_encode($data);
        }
        return $this->request('PUT', $url, $options);
    }// end put()

    /**
     * Загрузка локального файла на URL, полученный от Яндекс.Диска.
     * Автоматически выбирает стратегию: простая PUT или составная (resumable).
     *
     * @return array{code: int, result: mixed}
     */
    public function uploadFile(string $uploadUrl, string $localPath): array
    {
        $fileSize = filesize($localPath);

        if ($fileSize <= self::RESUMABLE_THRESHOLD) {
            return $this->simpleUpload($uploadUrl, $localPath, $fileSize);
        }

        $this->logger->info("Файл большой ({$fileSize} байт), используется составная загрузка.");
        return $this->resumableUpload($uploadUrl, $localPath, $fileSize);
    }// end uploadFile()

    /**
     * Простая загрузка файла одним PUT-запросом.
     *
     * @return array{code: int, result: mixed}
     */
    private function simpleUpload(string $uploadUrl, string $localPath, int $fileSize): array
    {
        $fp = fopen($localPath, 'rb');
        if (!$fp) {
            throw new RuntimeException("Cannot open file: {$localPath}");
        }

        // Динамический таймаут: не менее 30 минут, примерно по 512 KB/s.
        $timeout = max(1800, (int) ceil($fileSize / (512 * 1024)));

        $ch = curl_init($uploadUrl);
        curl_setopt_array(
            $ch,
            [
                CURLOPT_PUT            => true,
                CURLOPT_INFILE         => $fp,
                CURLOPT_INFILESIZE     => $fileSize,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: OAuth ' . $this->token,
                    'Content-Type: application/octet-stream',
                ],
                CURLOPT_TIMEOUT        => $timeout,
                // Оптимизация TCP: отключаем алгоритм Нагла, уменьшаем задержку
                CURLOPT_TCP_NODELAY   => true,
                // Буфер для загрузки (по умолчанию 16384) можно увеличить
                CURLOPT_BUFFERSIZE    => 256 * 1024,
            ]
        );

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        fclose($fp);

        if ($curlError) {
            throw new RuntimeException('cURL error: ' . $curlError);
        }

        return [
            'code'   => $httpCode,
            'result' => json_decode($response, true),
        ];
    }// end simpleUpload()

    /**
     * Составная загрузка (resumable) согласно API Яндекс.Диска.
     * Делит файл на части и загружает последовательно с докачкой.
     *
     * @return array{code: int, result: mixed}
     */
    private function resumableUpload(string $uploadUrl, string $localPath, int $fileSize): array
    {
        $fp = fopen($localPath, 'rb');
        if (!$fp) {
            throw new RuntimeException("Cannot open file: {$localPath}");
        }

        $offset = 0;
        $totalChunks = (int) ceil($fileSize / self::CHUNK_SIZE);
        $chunkIndex = 0;

        while ($offset < $fileSize) {
            $chunkSize = min(self::CHUNK_SIZE, $fileSize - $offset);
            fseek($fp, $offset);
            $chunk = fread($fp, $chunkSize);
            if ($chunk === false) {
                fclose($fp);
                throw new RuntimeException("Failed to read chunk at offset {$offset}");
            }

            $endByte = $offset + $chunkSize - 1;
            $rangeHeader = "bytes {$offset}-{$endByte}/{$fileSize}";

            $this->logger->info(
                sprintf(
                    "Загрузка части %d/%d (%s, %.1f МБ)...",
                    ++$chunkIndex,
                    $totalChunks,
                    $rangeHeader,
                    $chunkSize / (1024 * 1024)
                )
            );

            $success = false;
            $retries = 0;

            while (!$success && $retries < self::MAX_RETRIES) {
                if ($retries > 0) {
                    $this->logger->warning("Повторная попытка {$retries}/" . self::MAX_RETRIES);
                    // Перед повтором переоткрываем файл и перемещаем указатель.
                    fseek($fp, $offset);
                    $chunk = fread($fp, $chunkSize);
                }

                $ch2 = curl_init($uploadUrl);
                curl_setopt_array(
                    $ch2,
                    [
                        CURLOPT_CUSTOMREQUEST  => 'PUT',
                        CURLOPT_POSTFIELDS     => $chunk,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_HTTPHEADER     => [
                            'Authorization: OAuth ' . $this->token,
                            'Content-Type: application/octet-stream',
                            'Content-Length: ' . $chunkSize,
                            'Content-Range: ' . $rangeHeader,
                        ],
                        CURLOPT_TIMEOUT        => self::CHUNK_TIMEOUT,
                        CURLOPT_TCP_NODELAY    => true,
                        CURLOPT_BUFFERSIZE     => 256 * 1024,
                    ]
                );

                $response = curl_exec($ch2);
                $httpCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch2);

                if ($curlError) {
                    $this->logger->error("Ошибка cURL: {$curlError}");
                } elseif ($httpCode === 201) {
                    // Файл полностью загружен (последняя часть).
                    fclose($fp);
                    return [
                        'code'   => 201,
                        'result' => json_decode($response, true),
                    ];
                } elseif ($httpCode === 202) {
                    // Часть принята, продолжаем.
                    $success = true;
                } else {
                    $this->logger->error("HTTP {$httpCode}: " . ($response ?: 'empty'));
                }

                $retries++;
            }// end while

            if (!$success) {
                fclose($fp);
                throw new RuntimeException("Не удалось загрузить часть после " . self::MAX_RETRIES . " попыток.");
            }

            $offset += $chunkSize;
        }// end while

        fclose($fp);
        // Если цикл завершился без 201, возвращаем успех.
        return [
            'code'   => 201,
            'result' => null,
        ];
    }// end resumableUpload()
}// end class
