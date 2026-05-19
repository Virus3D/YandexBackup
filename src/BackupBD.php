<?php

declare(strict_types=1);

namespace App\Backup;

use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

use function sprintf;

/**
 * Класс для автоматического создания дампа БД.
 *
 * Требования:
 * - PHP 8.4+ (используется typed properties)
 * - Установленные расширения: curl, pdo_mysql (или pdo_pgsql), zip (опционально)
 */
final class BackupBD
{
    private const DATE_FORMAT = 'Y-m-d_H-i-s';

    /**
     * Конмтруктор.
     *
     * @param string                       $backupDir путь куда сохраняются дампы
     * @param array<array<string, string>> $databases Массив подключений:
     *                                     [['driver'=>'mysql','host'=>'...','db'=>'...','user'=>'...','pass'=>'...'], ...]
     * @param bool                         $compress  Использовать gzip для дампа
     */
    public function __construct(
        private readonly string $backupDir,
        private readonly array $databases,
        private readonly bool $compress,
        private readonly LoggerInterface $logger,
    ) {
        if (! is_dir($this->backupDir) || ! is_writable($this->backupDir)) {
            throw new RuntimeException("Temp directory '{$this->backupDir}' is not writable.");
        }
    }// end __construct()

    /**
     * Главный метод: выполняет полный цикл резервного копирования и ротации.
     */
    public function run(): void
    {
        $this->logger->info('=== Dump started at ' . date('c') . ' ===');

        // Дамп и загрузка каждой БД.
        foreach ($this->databases as $index => $dbConfig) {
            try {
                $this->backupSingleDatabase($dbConfig);
            } catch (Throwable $e) {
                $this->logger->error("ERROR processing DB #{$index}: " . $e->getMessage());
                // Продолжаем с остальными БД, не роняя весь скрипт.
            }
        }

        $this->logger->info('=== Dump finished at ' . date('c') . ' ===');
    }// end run()

    /**
     * Бэкап одной БД: дамп → загрузка.
     *
     * @param array<string, string> $config
     */
    private function backupSingleDatabase(array $config): void
    {
        $dbName    = $config['db'];
        $timestamp = date(self::DATE_FORMAT);
        $extension = $this->compress ? '.sql.gz' : '.sql';
        $fileName  = "{$dbName}_{$timestamp}{$extension}";
        $localPath = $this->backupDir . '/' . $fileName;

        $this->logger->info("[DB:{$dbName}] Creating dump...");
        $this->createDump($config, $localPath);

        $this->logger->info("[DB:{$dbName}] Dump created.");
    }// end backupSingleDatabase()

    /**
     * Создание дампа через системные утилиты.
     * Для PostgreSQL замените вызов на pg_dump.
     *
     * @param array<string, string> $config
     */
    private function createDump(array $config, string $outputPath): void
    {
        $driver = $config['driver'] ?? 'mysql';
        $host   = escapeshellarg($config['host']);
        $user   = escapeshellarg($config['user']);
        $pass   = escapeshellarg($config['pass']);
        $db     = escapeshellarg($config['db']);
        $port   = isset($config['port']) ? (int) $config['port'] : 3_306;

         // Создаём временный файл опций MySQL с паролем.
        $cnfFile = tempnam($this->backupDir, 'my_');
        if (!$cnfFile) {
            throw new RuntimeException('Cannot create temporary file for MySQL credentials');
        }

        // Записываем в файл минимальную конфигурацию.
        $cnfContent = "[client]\n";
        $cnfContent .= "host={$host}\n";
        $cnfContent .= "port={$port}\n";
        $cnfContent .= "user={$user}\n";
        $cnfContent .= "password={$pass}\n";
        // Для совместимости с некоторыми версиями mysqldump.
        $cnfContent .= "[mysqldump]\n";
        $cnfContent .= "password={$pass}\n";

        file_put_contents($cnfFile, $cnfContent);
        // Права 0600 — только владелец может читать.
        chmod($cnfFile, 0600);

        try {
            // Формируем команду mysqldump с использованием файла опций.
            $command = sprintf(
                'mysqldump --defaults-extra-file=%s --single-transaction --quick --routines --triggers --no-tablespaces %s',
                escapeshellarg($cnfFile),
                $db
            );

            if ($this->compress) {
                $command .= ' | gzip > ' . escapeshellarg($outputPath);
            } else {
                $command .= ' > ' . escapeshellarg($outputPath);
            }

            $command .= ' 2>&1';

            $output = [];
            $returnCode = 0;
            exec($command, $output, $returnCode);

            if ($returnCode !== 0) {
                $errorMsg = implode("\n", $output);
                throw new RuntimeException("mysqldump failed with code {$returnCode}: {$errorMsg}");
            }

            if (!file_exists($outputPath) || filesize($outputPath) === 0) {
                throw new RuntimeException("Dump file is empty or missing.");
            }
        } finally {
            // Удаляем временный файл с паролем.
            if (file_exists($cnfFile)) {
                unlink($cnfFile);
            }
        }// end try
    }// end createDump()
}// end class
