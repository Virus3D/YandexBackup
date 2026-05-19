<?php

declare(strict_types=1);

namespace App\Backup;

/**
 * Главный класс автоматического резервного копирования баз данных на Яндекс.Диск с ротацией.
 *
 * Координирует:
 * - проверку доступности диска,
 * - создание целевой папки,
 * - удаление старых бекапов (ротацию),
 * - загрузку свежих дампов из локальной папки на Яндекс.Диск.
 */
class YandexDiskBackup
{
    /**
     * Конструктор.
     *
     * @param YandexDisk $disk              Инициализированный клиент Яндекс.Диска
     * @param Logger     $logger            Логгер для записи хода выполнения и ошибок
     * @param string     $project           Название проекта (используется в логах)
     * @param string     $backupSrcDir      Локальная директория с файлами дампов (например, '/app/backups/')
     * @param string     $diskDestResource  Целевая директория на Яндекс.Диске (например, 'app:/MyProject')
     * @param int        $maxBackups        Максимальное количество хранимых бекапов для каждой БД
     */
    public function __construct(
        private readonly YandexDisk $disk,
        private readonly Logger $logger,
        private readonly string $project,
        private readonly string $backupSrcDir,
        private readonly string $diskDestResource,
        private readonly int $maxBackups = 7,
    ) {
    }// end __construct()

    /**
     * Запускает полный цикл резервного копирования:
     * информация о диске → проверка папки → ротация → загрузка новых файлов.
     */
    public function run(): void
    {
        // Получаем общую информацию о Яндекс.Диске.
        $diskInfo = $this->disk->info();
        if (!$diskInfo) {
            $this->logger->error('Не удалось получить информацию о Диске');
            return;
        }

        // Логируем детали учётной записи.
        $this->logger->info('Логин ' . $diskInfo['user']['display_name'] ?? 'unknown');
        $this->logger->info('Всего ' . Helper::formatFileSize($diskInfo['total_space'] ?? 0));
        $this->logger->info('Использовано ' . Helper::formatFileSize($diskInfo['used_space'] ?? 0));
        $this->logger->info('Начат бекап проекта ' . $this->project);

        // Убедиться, что целевая папка существует (создать при необходимости).
        if (!$this->ensureDirectoryExists()) {
            return;
        }

        // Удалить старые бекапы (ротация).
        $this->rotateOldBackups();

        // Загрузить новые файлы.
        $this->uploadBackups();

        $this->logger->info('Бекап проекта ' . $this->project . ' завершён');
    }// end run()

    /**
     * Проверяет существование директории на Яндекс.Диске и создаёт её при отсутствии.
     *
     * @return bool true, если директория существует или была успешно создана
     */
    protected function ensureDirectoryExists(): bool
    {
        $info = $this->disk->getDirectoryInfo($this->diskDestResource);

        // Код 404 — директория отсутствует.
        if ($info['code'] == 404) {
            $info = $this->disk->createDirectory($this->diskDestResource);
            if ($info['code'] != 201) {
                $this->logger->error('Не удалось создать папку на Диске: ' . $this->diskDestResource);
                return false;
            }
            return true;
        }

        // Коды 200 или 201 — директория существует (или только что создана).
        return in_array($info['code'], [200, 201]);
    }// end ensureDirectoryExists()

    /**
     * Выполняет ротацию старых бекапов на Яндекс.Диске.
     *
     * Файлы группируются по имени базы данных (префикс до даты) и сортируются
     * по имени от новых к старым. Для каждой группы оставляется не более $this->maxBackups файлов.
     * Остальные безвозвратно удаляются.
     */
    private function rotateOldBackups(): void
    {
        // Получаем список всех файлов в целевой папке.
        $dirInfo = $this->disk->getDirectoryInfo($this->diskDestResource);
        if (!isset($dirInfo['result']['_embedded']['items'])) {
            return;
        }

        $items = $dirInfo['result']['_embedded']['items'];

        // Группируем файлы по префиксу (всё до последнего '_', отделяющего дату)
        // Пример имени: garden_2026-05-19_15-30-00.sql.gz → префикс "garden".
        $grouped = [];
        foreach ($items as $item) {
            $name = $item['name'];
            $prefix = preg_replace('/_\d{4}-\d{2}-\d{2}_.*$/', '', $name);
            if ($prefix !== $name) {
                $grouped[$prefix][] = $item;
            }
        }

        foreach ($grouped as $dbPrefix => $files) {
            // Сортируем по дате создания (или по имени, которое содержит дату) от новых к старым.
            usort(
                $files,
                function ($a, $b) {
                    // Обратный порядок.
                    return strcmp($b['name'], $a['name']);
                }
            );

            // Оставляем только последние $this->maxBackups файлов, удаляем остальные.
            $toDelete = array_slice($files, $this->maxBackups);
            foreach ($toDelete as $file) {
                $path = $file['path'];
                $this->logger->info('Удаление старого бекапа: ' . $file['name']);
                if (!$this->disk->delete($path)) {
                    $this->logger->warning('Не удалось удалить: ' . $path);
                }
            }
        }
    }// end rotateOldBackups()

    /**
     * Загружает свежие файлы дампов из локальной папки на Яндекс.Диск.
     *
     * Поддерживаются как сжатые дампы (*.sql.gz), так и несжатые (*.sql).
     * После успешной загрузки локальный файл удаляется.
     */
    private function uploadBackups(): void
    {
        // Ищем файлы дампов по двум маскам.
        $patterns = [
            '*.sql.gz',
            '*.sql',
        ];
        $files = [];
        foreach ($patterns as $pattern) {
            $matched = glob($this->backupSrcDir . $pattern);
            if ($matched) {
                $files = array_merge($files, $matched);
            }
        }

        if (empty($files)) {
            $this->logger->warning('Нет файлов для загрузки в ' . $this->backupSrcDir);
            return;
        }

        // Загружаем каждый найденный файл.
        foreach ($files as $localFile) {
            $baseName = basename($localFile);
            $remotePath = $this->diskDestResource . '/' . $baseName;
            $this->logger->info('Загрузка ' . $baseName);
            if ($this->disk->upload($localFile, $remotePath)) {
                // После успешной загрузки удаляем локальный файл.
                unlink($localFile);
                $this->logger->info('Файл ' . $baseName . ' успешно загружен и удалён локально');
            } else {
                $this->logger->error('Ошибка загрузки ' . $baseName);
            }
        }
    }// end uploadBackups()
}// end class
