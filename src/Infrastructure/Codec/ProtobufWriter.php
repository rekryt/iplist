<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Codec;

use InvalidArgumentException;

/**
 * Минимальный энкодер protobuf — ровно столько, сколько нужно для v2ray/xray
 * `geosite.dat` и `geoip.dat`: varint, length-delimited строки/байты/сообщения.
 *
 * Своя реализация, а не библиотека: нужных типов wire format всего два,
 * зависимости (`google/protobuf` + генерация классов из .proto) тут были бы
 * несоизмеримо дороже, чем ~40 строк кода.
 *
 * @see https://protobuf.dev/programming-guides/encoding/
 */
final class ProtobufWriter {
    public const WIRE_VARINT = 0;
    public const WIRE_LENGTH_DELIMITED = 2;

    /**
     * Base-128 varint. Отрицательные числа не поддерживаются осознанно:
     * в наших сообщениях их нет, а zigzag/десятибайтовое представление
     * только добавило бы веток в горячий цикл.
     *
     * @throws InvalidArgumentException
     */
    public static function varint(int $value): string {
        if ($value < 0) {
            throw new InvalidArgumentException('Negative varint is not supported: ' . $value);
        }

        $out = '';
        do {
            $byte = $value & 0x7f;
            $value >>= 7;
            $out .= chr($value !== 0 ? $byte | 0x80 : $byte);
        } while ($value !== 0);

        return $out;
    }

    /**
     * Поле-число (enum, uint32, bool — все они на проводе varint).
     */
    public static function uint(int $field, int $value): string {
        return self::varint(($field << 3) | self::WIRE_VARINT) . self::varint($value);
    }

    /**
     * Поле переменной длины. На проводе `string`, `bytes` и вложенное
     * сообщение кодируются одинаково, поэтому метод один.
     */
    public static function bytes(int $field, string $value): string {
        return self::varint(($field << 3) | self::WIRE_LENGTH_DELIMITED) . self::varint(strlen($value)) . $value;
    }
}
