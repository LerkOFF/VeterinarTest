<?php
declare(strict_types=1);

namespace App\Helpers;

final class BackHelper
{
    /**
     * Разрешаем back только на внутренние пути "/...".
     * Защищает от подстановки внешних URL и схем.
     */
    public static function sanitizeBack(?string $back): ?string
    {
        $b = trim((string)$back);
        if ($b === '') {
            return null;
        }

        // Только относительный путь от корня сайта
        if (!str_starts_with($b, '/')) {
            return null;
        }

        // Запрещаем //example.com (scheme-relative)
        if (str_starts_with($b, '//')) {
            return null;
        }

        return $b;
    }
}
