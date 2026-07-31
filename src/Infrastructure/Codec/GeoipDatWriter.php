<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Codec;

/**
 * Сборка v2ray/xray `geoip.dat` без внешней утилиты.
 *
 * Схема (v2fly/v2ray-core → app/router/routercommon/common.proto):
 *
 *   message CIDR      { bytes ip = 1; uint32 prefix = 2; }
 *   message GeoIP     { string country_code = 1; repeated CIDR cidr = 2; }
 *   message GeoIPList { repeated GeoIP entry = 1; }
 *
 * `ip` — сырые 4 или 16 байт (`inet_pton`), не текст. Данные те же, что уходили
 * в Go-утилиту `v2fly/geoip`, меняется только «чем пишем файл»: пропадают
 * ~409 временных файлов и fork/exec на каждый запрос.
 *
 * Класс — чистая функция: ни I/O, ни Amp, ни синглтонов. Это условие для
 * запуска в процессе-воркере `amphp/parallel` (см. EncodeDatTask).
 */
final class GeoipDatWriter {
    /**
     * @param array<string, array<int, string>> $entries код списка → IP/CIDR
     */
    public static function payload(array $entries): string {
        return EntryPayload::pack($entries);
    }

    /**
     * @param ?callable $yield вызывается после каждого списка — точка для
     *        кооперативной уступки event loop'у на inline-пути
     */
    public static function encode(string $payload, ?callable $yield = null): string {
        $entries = [];

        // Кэш varint-представлений маски: значений всего 129 (0…128), поэтому
        // в горячем цикле на 2+ млн записей varint не считается вообще.
        $prefixField = [];
        for ($prefix = 1; $prefix <= 128; $prefix++) {
            $prefixField[$prefix] = ProtobufWriter::uint(2, $prefix);
        }
        // prefix = 0 — значение по умолчанию, канонический protobuf такое поле
        // опускает, как и Go-маршаллер
        $prefixField[0] = '';

        foreach (EntryPayload::unpack($payload) as $code => $rows) {
            // `.=` вместо массива с implode: у строки с единственной ссылкой
            // append амортизированно O(1), а массив на сотни тысяч фрагментов
            // стоил бы больше памяти, чем сам результат
            $blob = '';
            foreach ($rows as $row) {
                $slash = strpos($row, '/');
                $packed = @inet_pton($slash === false ? $row : substr($row, 0, $slash));
                if ($packed === false) {
                    continue;
                }

                $bits = isset($packed[4]) ? 128 : 32;
                $prefix = $slash === false ? $bits : (int) substr($row, $slash + 1);
                if ($prefix < 0 || $prefix > $bits) {
                    continue;
                }

                // Поле `ip` собирается из готовых байт: тег 0x0a и длина адреса
                // (4 или 16) — константы, считать их varint'ом незачем.
                $cidr = ($bits === 32 ? "\x0a\x04" : "\x0a\x10") . $packed . $prefixField[$prefix];
                // Длина CIDR-сообщения максимум 21 байт, то есть всегда влезает
                // в один байт varint — отсюда chr() вместо общего кодировщика.
                $blob .= "\x12" . chr(strlen($cidr)) . $cidr;
            }

            if ($blob === '') {
                continue;
            }

            $entries[] = ProtobufWriter::bytes(1, ProtobufWriter::bytes(1, $code) . $blob);

            if ($yield !== null) {
                $yield();
            }
        }

        return implode('', $entries);
    }
}
