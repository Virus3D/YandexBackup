<?php

declare(strict_types=1);

namespace App\Backup;

use Psr\Log\LoggerInterface;
use RuntimeException;

class HttpClient
{
    private ?string $token = null;

    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
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
     * Загрузка файла (специальный случай, как у вас type_file)
     *
     * @return array{code: int, result: mixed}
     */
    public function uploadFile(string $uploadUrl, string $localPath): array
    {
        $fp = fopen($localPath, 'rb');
        if (!$fp) {
            throw new RuntimeException("Cannot open file: {$localPath}");
        }

        $options = [
            CURLOPT_PUT        => true,
            CURLOPT_INFILE     => $fp,
            CURLOPT_INFILESIZE => filesize($localPath),
            CURLOPT_HTTPHEADER => [
                'Authorization: OAuth ' . $this->token,
                'Content-Type: application/octet-stream',
            ],
            CURLOPT_TIMEOUT    => 600,
        ];

        $result = $this->request('PUT', $uploadUrl, $options);
        fclose($fp);
        return $result;
    }// end uploadFile()
}// end class
