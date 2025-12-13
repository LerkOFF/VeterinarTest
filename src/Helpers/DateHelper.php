<?php
declare(strict_types=1);

namespace App\Helpers;

final class DateHelper
{
    /**
     * Принимает "ДД-ММ-ГГГГ", возвращает "YYYY-MM-DD" (для хранения в БД) или null.
     */
    public static function normalizeBirthDateToIso(?string $ddmmyyyy): ?string
    {
        $s = trim((string)$ddmmyyyy);
        if ($s === '') {
            return null;
        }

        if (!preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $s, $m)) {
            throw new \InvalidArgumentException('Дата рождения должна быть в формате ДД-ММ-ГГГГ.');
        }

        $day = (int)$m[1];
        $month = (int)$m[2];
        $year = (int)$m[3];

        if (!checkdate($month, $day, $year)) {
            throw new \InvalidArgumentException('Дата рождения некорректна.');
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * Принимает "YYYY-MM-DD", возвращает "ДД-ММ-ГГГГ" для отображения.
     * Если формат не распознан — возвращает исходную строку.
     */
    public static function formatIsoToDdMmYyyy(?string $iso): string
    {
        $s = trim((string)$iso);
        if ($s === '') {
            return '';
        }

        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
            return $s;
        }

        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }

    /**
     * Принимает "ДД-ММ-ГГГГ" для визита, возвращает тот же формат (мы так и храним визиты),
     * или null если пусто.
     */
    public static function normalizeVisitDate(?string $ddmmyyyy): ?string
    {
        $s = trim((string)$ddmmyyyy);
        if ($s === '') {
            return null;
        }

        if (!preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $s, $m)) {
            throw new \InvalidArgumentException('Дата визита должна быть в формате ДД-ММ-ГГГГ.');
        }

        $day = (int)$m[1];
        $month = (int)$m[2];
        $year = (int)$m[3];

        if (!checkdate($month, $day, $year)) {
            throw new \InvalidArgumentException('Дата визита некорректна.');
        }

        return $s;
    }

    /**
     * Принимает "ЧЧ:ММ", возвращает "ЧЧ:ММ" или null если пусто.
     */
    public static function normalizeVisitTime(?string $hhmm): ?string
    {
        $s = trim((string)$hhmm);
        if ($s === '') {
            return null;
        }

        if (!preg_match('/^(\d{2}):(\d{2})$/', $s, $m)) {
            throw new \InvalidArgumentException('Время визита должно быть в формате ЧЧ:ММ.');
        }

        $h = (int)$m[1];
        $min = (int)$m[2];

        if ($h < 0 || $h > 23 || $min < 0 || $min > 59) {
            throw new \InvalidArgumentException('Время визита некорректно.');
        }

        return $s;
    }
}
