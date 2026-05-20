<?php

declare(strict_types=1);

namespace App\Backup;

/**
 * Вспомогательный класс со статическими утилитами.
 */
class Helper
{
    /**
     * Форматирует размер файла в человекочитаемый вид (например, "1.5 GB").
     *
     * Использует бинарные единицы (1024), но для краткости отображает их как десятичные символы (KB, MB, GB, TB).
     *
     * @param int  $bytes      Размер в байтах
     * @param bool $stringOnly Если true, возвращает строку "значение единица",
     *                          иначе массив ['value' => float, 'unit' => string]
     *
     * @return string|array<string, float|string> Строка или массив с отформатированным размером
     */
    public static function formatFileSize(int $bytes, bool $stringOnly = true): string|array
    {
        $units = [
            'TB' => 1099511627776,
            'GB' => 1073741824,
            'MB' => 1048576,
            'KB' => 1024,
            'B'  => 0,
        ];
        foreach ($units as $unit => $min) {
            if ($bytes >= $min) {
                $value = $min === 0 ? $bytes : round($bytes / $min, 2);
                return $stringOnly ? "{$value} {$unit}" : [
                    'value' => $value,
                    'unit'  => $unit,
                ];
            }
        }
        return $stringOnly ? '0 B' : [
            'value' => 0,
            'unit'  => 'B',
        ];
    }// end formatFileSize()

    /**
     * Генерирует UUID версии 4 (случайный).
     *
     * @return string UUID в формате xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
     */
    public static function generateUuidV4(): string
    {
        $data = random_bytes(16);

        // Устанавливаем версию 4 (биты 12-15).
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        // Устанавливаем вариант RFC 4122 (биты 6-7).
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }// end generateUuidV4()
}// end class
