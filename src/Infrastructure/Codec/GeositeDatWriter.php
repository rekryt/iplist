<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Codec;

use InvalidArgumentException;

/**
 * Сборка v2ray/xray `geosite.dat` (domain-list) без внешних утилит.
 *
 * Схема (v2fly/v2ray-core → app/router/routercommon/common.proto):
 *
 *   message Domain      { Type type = 1; string value = 2; }
 *   enum   Type         { Plain = 0; Regex = 1; RootDomain = 2; Full = 3; }
 *   message GeoSite     { string country_code = 1; repeated Domain domain = 2; }
 *   message GeoSiteList { repeated GeoSite entry = 1; }
 *
 * Почему не внешний генератор: `v2fly/geoip` домены не умеет вообще, а
 * `v2fly/domain-list-community` валидирует имя списка как `[A-Z0-9!-]` —
 * точки и `@` запрещены, а такие имена у 406 из 409 наших порталов.
 *
 * Класс — чистая функция: ни I/O, ни Amp, ни синглтонов. Это условие для
 * запуска в процессе-воркере `amphp/parallel` (см. EncodeDatTask).
 */
final class GeositeDatWriter {
    /** Подстрока (в конфиге v2ray — просто `example`) */
    public const TYPE_PLAIN = 0;
    /** Регулярное выражение (`regexp:…`) */
    public const TYPE_REGEX = 1;
    /** Домен и все его поддомены (`domain:example.com`) */
    public const TYPE_ROOT_DOMAIN = 2;
    /** Точное совпадение (`full:example.com`) */
    public const TYPE_FULL = 3;

    /**
     * @param array<string, array<int, string>> $entries
     */
    public static function payload(array $entries): string {
        return EntryPayload::pack($entries);
    }

    /**
     * @param string $payload результат payload()
     * @param int $domainType одна из TYPE_* констант
     * @param ?callable $yield вызывается после каждого списка — точка для
     *        кооперативной уступки event loop'у на inline-пути. В воркере
     *        всегда null: там уступать некому и нечему.
     * @throws InvalidArgumentException
     */
    public static function encode(string $payload, int $domainType, ?callable $yield = null): string {
        if (
            !in_array($domainType, [self::TYPE_PLAIN, self::TYPE_REGEX, self::TYPE_ROOT_DOMAIN, self::TYPE_FULL], true)
        ) {
            throw new InvalidArgumentException('Unknown geosite domain type: ' . $domainType);
        }
        // Поле `type` одинаково для всей выдачи, поэтому считается один раз.
        // Plain = 0 — значение по умолчанию, канонический protobuf такое поле
        // опускает; так же поступает и Go-маршаллер, чью выдачу мы сравниваем
        // с эталонным dlc.dat.
        $typeField = $domainType !== self::TYPE_PLAIN ? ProtobufWriter::uint(1, $domainType) : '';

        $entries = [];
        foreach (EntryPayload::unpack($payload) as $code => $domains) {
            // `.=` вместо массива с implode: у строки с единственной ссылкой
            // append амортизированно O(1), а массив на 300k+ фрагментов
            // (полный каталог) стоил бы больше памяти, чем сам результат
            $domainsBlob = '';
            foreach ($domains as $domain) {
                // Длины почти всегда < 128 и влезают в один байт varint —
                // на горячем цикле это дешевле общего кодировщика. Домены
                // длиннее 127 байт бывают, поэтому ветка с varint сохранена.
                $length = strlen($domain);
                $message =
                    $typeField . "\x12" . ($length < 128 ? chr($length) : ProtobufWriter::varint($length)) . $domain;

                $length = strlen($message);
                $domainsBlob .= "\x12" . ($length < 128 ? chr($length) : ProtobufWriter::varint($length)) . $message;
            }

            $entries[] = ProtobufWriter::bytes(1, ProtobufWriter::bytes(1, $code) . $domainsBlob);

            if ($yield !== null) {
                $yield();
            }
        }

        return implode('', $entries);
    }
}
