<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Codec;

use InvalidArgumentException;

/**
 * Сборка sing-box rule-set в source-формате (JSON).
 *
 * Лежит в Codec, а не в Domain: это такой же кодировщик выдачи, как
 * GeositeDatWriter, и его результат используют два контроллера — `singbox`
 * (отдаёт JSON как есть) и `srs` (скармливает его `sing-box rule-set compile`).
 *
 * Версии source-формата и минимальный sing-box:
 *   1 → 1.8.0   базовый набор (всё, что нужно нам: domain*, ip_cidr)
 *   2 → 1.10.0  оптимизация памяти для domain_suffix
 *   3 → 1.11.0  network_type / network_is_expensive / network_is_constrained
 *   4 → 1.13.0  network_interface_address / default_interface_address
 *   5 → 1.14.0  package_name_regex
 *
 * Мы используем только элементы версии 1, поэтому дефолт — 1: такой rule-set
 * читают все sing-box от 1.8, а новые версии старый формат принимают.
 *
 * @see https://sing-box.sagernet.org/configuration/rule-set/source-format/
 * @see https://sing-box.sagernet.org/configuration/rule-set/headless-rule/
 */
final class SingboxRuleSetBuilder {
    public const VERSION_MIN = 1;
    public const VERSION_MAX = 5;
    public const DEFAULT_VERSION = 1;

    public const DOMAIN_TYPE_SUFFIX = 'suffix';
    public const DOMAIN_TYPE_FULL = 'full';
    public const DOMAIN_TYPE_KEYWORD = 'keyword';
    public const DOMAIN_TYPE_REGEX = 'regex';

    public const DATA_TYPES = ['domains', 'ip4', 'cidr4', 'ip6', 'cidr6'];
    public const DOMAIN_TYPES = [
        self::DOMAIN_TYPE_SUFFIX,
        self::DOMAIN_TYPE_FULL,
        self::DOMAIN_TYPE_KEYWORD,
        self::DOMAIN_TYPE_REGEX,
    ];

    /**
     * Одно правило на всю выдачу: в rule-set нет комментариев, поэтому
     * атрибуция по порталам всё равно теряется, а массивы внутри правила
     * sing-box сопоставляет по ИЛИ.
     *
     * @param array<int, string> $rows готовые строки (домены / IP / CIDR)
     * @return array<string, mixed>
     * @throws InvalidArgumentException
     */
    public static function build(
        int $version,
        string $data,
        array $rows,
        string $domainType = self::DOMAIN_TYPE_SUFFIX
    ): array {
        if ($version < self::VERSION_MIN || $version > self::VERSION_MAX) {
            throw new InvalidArgumentException('Unsupported rule-set version: ' . $version);
        }
        if (!in_array($data, self::DATA_TYPES, true)) {
            throw new InvalidArgumentException('Unsupported data type: ' . $data);
        }
        if (!in_array($domainType, self::DOMAIN_TYPES, true)) {
            throw new InvalidArgumentException('Unsupported domain type: ' . $domainType);
        }

        $rule = self::rule($data, array_values($rows), $domainType);

        // Пустое правило sing-box считает невалидным, поэтому при пустой
        // выдаче отдаём пустой список правил, а не список с пустым правилом
        return ['version' => $version, 'rules' => $rule === [] ? [] : [$rule]];
    }

    /**
     * @param array<string, mixed> $ruleSet
     */
    public static function encode(array $ruleSet): string {
        return json_encode(
            $ruleSet,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * @param array<int, string> $rows
     * @return array<string, mixed>
     */
    private static function rule(string $data, array $rows, string $domainType): array {
        if (!count($rows)) {
            return [];
        }

        return match ($data) {
            'domains' => self::domainRule($rows, $domainType),
            'ip4' => ['ip_cidr' => array_map(fn(string $ip) => $ip . '/32', $rows)],
            'ip6' => ['ip_cidr' => array_map(fn(string $ip) => $ip . '/128', $rows)],
            default => ['ip_cidr' => $rows], // cidr4 / cidr6 — уже с маской
        };
    }

    /**
     * @param array<int, string> $domains
     * @return array<string, mixed>
     */
    private static function domainRule(array $domains, string $domainType): array {
        return match ($domainType) {
            self::DOMAIN_TYPE_FULL => ['domain' => $domains],
            self::DOMAIN_TYPE_KEYWORD => ['domain_keyword' => $domains],
            self::DOMAIN_TYPE_REGEX => ['domain_regex' => $domains],
            // `domain_suffix` в sing-box — строковый суффикс (HasSuffix), т.е.
            // "example.com" матчит и "notexample.com". Поэтому канонический
            // приём конвертеров: точное совпадение через `domain` плюс
            // `domain_suffix` с ведущей точкой — вместе это даёт границу метки.
            default => [
                'domain' => $domains,
                'domain_suffix' => array_map(fn(string $domain) => '.' . ltrim($domain, '.'), $domains),
            ],
        };
    }
}
