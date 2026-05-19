<?php

declare(strict_types=1);

namespace App\Backup;

/**
 * Клиент для работы с REST API Яндекс.Диска.
 *
 * Инкапсулирует основные операции: получение информации о диске,
 * навигацию по папкам, создание директорий, загрузку и удаление файлов.
 */
class YandexDisk
{
    /**
     * Базовый URL API Яндекс.Диска
     */
    private const API_BASE = 'https://cloud-api.yandex.net/v1/disk/';

    /**
     * Конструктор.
     *
     * @param HttpClient $http   HTTP-клиент с поддержкой OAuth-токена
     * @param Logger     $logger Логгер для записи ошибок и предупреждений
     */
    public function __construct(
        private readonly HttpClient $http,
        private readonly Logger $logger
    ) {
    }// end __construct()

    /**
     * Устанавливает OAuth-токен для доступа к Яндекс.Диску.
     *
     * @param string $token Токен доступа
     */
    public function setAccessToken(string $token): void
    {
        $this->http->setAccessToken($token);
    }// end setAccessToken()

    /**
     * Возвращает общую информацию о диске (общий объём, использованное место, имя пользователя).
     *
     * @return array<mixed>|null Ассоциативный массив с данными диска или null при ошибке
     */
    public function info(): ?array
    {
        $result = $this->http->get(self::API_BASE);
        if ($result['code'] !== 200) {
            $this->logger->warning('Disk info failed', $result);
            return null;
        }
        return $result['result'];
    }// end info()

    /**
     * Получает содержимое директории на Яндекс.Диске.
     *
     * @param string $path Путь к директории (например, "app:/backups")
     *
     * @return array<mixed> Ответ API, включающий список файлов (ключ 'result')
     */
    public function getDirectoryInfo(string $path): array
    {
        return $this->http->get(
            self::API_BASE . 'resources',
            [
                'path' => $path,
                'sort' => '-name',
            ]
        );
    }// end getDirectoryInfo()

    /**
     * Создаёт директорию на Яндекс.Диске.
     *
     * @param string $path Полный путь новой директории
     *
     * @return array<mixed> Ответ API с HTTP-кодом и телом
     */
    public function createDirectory(string $path): array
    {
        return $this->http->put(self::API_BASE . 'resources', ['path' => $path]);
    }// end createDirectory()

    /**
     * Загружает локальный файл на Яндекс.Диск.
     *
     * Процесс двухшаговый:
     * 1. Получает URL для загрузки (href).
     * 2. Отправляет файл методом PUT на полученный URL.
     *
     * @param string $localPath  Локальный путь к файлу
     * @param string $remotePath Целевой путь на Яндекс.Диске
     *
     * @return bool true при успешной загрузке (код 201 или 202), иначе false
     */
    public function upload(string $localPath, string $remotePath): bool
    {
        // 1. Получить URL для загрузки
        $result = $this->http->get(
            self::API_BASE . 'resources/upload',
            [
                'path'      => $remotePath,
                'overwrite' => 'true',
            ]
        );

        if ($result['code'] !== 200 || empty($result['result']['href'])) {
            $this->logger->error('Failed to get upload URL', $result);
            return false;
        }

        // 2. Загрузить файл
        $uploadResult = $this->http->uploadFile($result['result']['href'], $localPath);
        if (!in_array($uploadResult['code'], [201, 202])) {
            $this->logger->error('File upload failed', $uploadResult);
            return false;
        }

        return true;
    }// end upload()

    /**
     * Удаляет файл или директорию на Яндекс.Диске безвозвратно.
     *
     * @param string $path Путь к удаляемому ресурсу
     *
     * @return bool true, если удаление прошло успешно (код 202 или 204)
     */
    public function delete(string $path): bool
    {
        $result = $this->http->delete(
            self::API_BASE . 'resources',
            [
                'path'        => $path,
                'permanently' => 'true',
            ]
        );

        return in_array($result['code'], [204, 202]);
    }// end delete()
}// end class
