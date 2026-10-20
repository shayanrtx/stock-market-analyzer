<?php

declare(strict_types=1);

namespace Sabadgardan\Market;

/**
 * Tolerant access to API records: case-insensitive keys, alternative names, and
 * unwrapping of tse.ir's ``{"value": x, "state": y}`` cells.
 */
final class Field
{
    public static function get(mixed $record, string|array $names, mixed $default = null): mixed
    {
        if (!is_array($record)) {
            return $default;
        }
        $lower = array_change_key_case($record, CASE_LOWER);
        foreach ((array) $names as $name) {
            $key = strtolower($name);
            if (!array_key_exists($key, $lower)) {
                continue;
            }
            $value = $lower[$key];
            if (is_array($value) && array_key_exists('value', $value)) {
                $value = $value['value'];
            }
            if ($value !== null) {
                return $value;
            }
        }
        return $default;
    }

    public static function float(mixed $record, string|array $names, ?float $default = null): ?float
    {
        $v = self::get($record, $names);
        return is_numeric($v) ? (float) $v : $default;
    }

    /** @return list<mixed> */
    public static function list(mixed $data, string $key): array
    {
        $rows = is_array($data) && !array_is_list($data) ? self::get($data, $key, []) : $data;
        return is_array($rows) && array_is_list($rows) ? $rows : [];
    }
}
