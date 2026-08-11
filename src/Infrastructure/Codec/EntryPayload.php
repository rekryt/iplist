<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Codec;

/**
 * Плоское представление карты «код списка → значения» для передачи в воркер.
 *
 * Зачем плоская строка, а не вложенные массивы: аргументы Task сериализуются
 * в родительском процессе, и стоимость `serialize()` растёт с числом элементов
 * массива. Одна большая строка обходится почти бесплатно (замер на полном
 * каталоге: 9.1 МБ payload сериализуется за ~5 мс против ~870 мс самого
 * кодирования), а `explode` уже выполняется в воркере.
 *
 * Здесь же собраны инварианты, общие для geosite.dat и geoip.dat: код списка
 * в верхнем регистре, повторные вхождения одного кода объединяются, значения
 * дедуплицируются и сортируются, порядок списков детерминирован.
 */
final class EntryPayload {
    private const ENTRY_SEPARATOR = "\x1e";
    private const LINE_SEPARATOR = "\n";

    /**
     * @param array<string, array<int, string>> $entries
     */
    public static function pack(array $entries): string {
        $blocks = [];
        foreach ($entries as $code => $values) {
            if (!count($values)) {
                continue;
            }
            $blocks[] = $code . self::LINE_SEPARATOR . implode(self::LINE_SEPARATOR, $values);
        }

        return implode(self::ENTRY_SEPARATOR, $blocks);
    }

    /**
     * @return array<string, array<int, string>> код в UPPERCASE → уникальные отсортированные значения
     */
    public static function unpack(string $payload): array {
        if ($payload === '') {
            return [];
        }

        $lists = [];
        foreach (explode(self::ENTRY_SEPARATOR, $payload) as $block) {
            if ($block === '') {
                continue;
            }
            $lines = explode(self::LINE_SEPARATOR, $block);
            // v2ray/xray при поиске делает strings.ToUpper(code) и сравнивает
            // строго, поэтому код списка обязан лежать в dat в верхнем регистре
            $code = strtoupper((string) array_shift($lines));
            if ($code === '') {
                continue;
            }
            $lists[$code] = isset($lists[$code]) ? array_merge($lists[$code], $lines) : $lines;
        }

        // Детерминированный порядок: одинаковый запрос — одинаковые байты,
        // это и кэшируемость, и возможность сравнивать выдачу в тестах
        ksort($lists);

        foreach ($lists as $code => $values) {
            // Сортировка + линейный проход вместо array_unique + array_filter:
            // после сортировки дубликаты стоят рядом, поэтому дедуп и отбрасывание
            // пустых строк делаются одним циклом без коллбэков. На полном каталоге
            // (`data=ip4` — 2.35 млн записей) это разница в разы: array_unique сам
            // внутри сортирует, а array_filter добавляет вызов коллбэка на элемент.
            sort($values, SORT_STRING);

            $unique = [];
            $previous = null;
            foreach ($values as $value) {
                if ($value === '' || $value === $previous) {
                    continue;
                }
                $unique[] = $value;
                $previous = $value;
            }

            if ($unique === []) {
                unset($lists[$code]);
                continue;
            }
            $lists[$code] = $unique;
        }

        return $lists;
    }
}
