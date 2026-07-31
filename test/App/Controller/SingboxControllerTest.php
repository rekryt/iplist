<?php

declare(strict_types=1);

namespace OpenCCK\App\Controller;

use OpenCCK\AsyncTest;

/**
 * HTTP-контракт `?format=singbox` — sing-box rule-set в source-формате.
 */
final class SingboxControllerTest extends AsyncTest {
    /**
     * @param array<string, string|int|array<int, string|int>> $query
     * @return array<string, mixed>
     */
    private function ruleSet(array $query): array {
        $body = $this->body($this->get('/', array_merge(['format' => 'singbox'], $query)));
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<string, string|int|array<int, string|int>> $query
     * @return array<string, mixed>
     */
    private function firstRule(array $query): array {
        $ruleSet = $this->ruleSet($query);
        self::assertArrayHasKey('rules', $ruleSet);
        self::assertCount(1, $ruleSet['rules']);

        return $ruleSet['rules'][0];
    }

    public function testMissingDataReturnsError(): void {
        self::assertStringContainsString(
            "'data' GET parameter is required",
            $this->body($this->get('/', ['format' => 'singbox']))
        );
    }

    public function testInvalidDataReturnsError(): void {
        self::assertStringContainsString(
            "'data' GET parameter must be",
            $this->body($this->get('/', ['format' => 'singbox', 'data' => 'nope']))
        );
    }

    public function testInvalidVersionReturnsError(): void {
        foreach (['0', '6', 'abc', '-1'] as $version) {
            self::assertStringContainsString(
                "'version' GET parameter must be an integer",
                $this->body($this->get('/', ['format' => 'singbox', 'data' => 'cidr4', 'version' => $version])),
                'version=' . $version
            );
        }
    }

    public function testInvalidDomainTypeReturnsError(): void {
        self::assertStringContainsString(
            "'domaintype' GET parameter must be",
            $this->body($this->get('/', ['format' => 'singbox', 'data' => 'domains', 'domaintype' => 'nope']))
        );
    }

    public function testHeaders(): void {
        $response = $this->get('/', ['format' => 'singbox', 'data' => 'cidr4']);
        $body = $this->body($response);

        self::assertSame(200, $response->getStatus());
        self::assertSame('application/json; charset=utf-8', $response->getHeader('content-type'));
        // Источник читается в браузере и забирается sing-box'ом по ссылке,
        // поэтому по умолчанию никакого attachment быть не должно
        self::assertNull($response->getHeader('content-disposition'));
        self::assertSame((string) strlen($body), $response->getHeader('content-length'));
    }

    public function testFileSaveTurnsResponseIntoAttachment(): void {
        $response = $this->get('/', ['format' => 'singbox', 'data' => 'cidr4', 'filesave' => '1']);

        self::assertSame(200, $response->getStatus());
        self::assertSame('application/json; charset=utf-8', $response->getHeader('content-type'));
        self::assertStringContainsString('attachment', $response->getHeader('content-disposition') ?? '');
        self::assertStringContainsString('.json', $response->getHeader('content-disposition') ?? '');
    }

    public function testVersionDefaultsToOneAndIsOverridable(): void {
        self::assertSame(1, $this->ruleSet(['data' => 'cidr4'])['version']);
        self::assertSame(3, $this->ruleSet(['data' => 'cidr4', 'version' => 3])['version']);
    }

    public function testDomainsSuffixEmitsExactAndDottedSuffix(): void {
        $rule = $this->firstRule(['data' => 'domains', 'site' => 'game-a']);

        self::assertContains('game-a.com', $rule['domain']);
        // Ведущая точка обязательна: `domain_suffix` в sing-box — строковый
        // суффикс, без точки он матчил бы и notgame-a.com
        self::assertContains('.game-a.com', $rule['domain_suffix']);
        self::assertCount(count($rule['domain']), $rule['domain_suffix']);
        self::assertArrayNotHasKey('ip_cidr', $rule);
    }

    public function testDomainsFullEmitsOnlyDomain(): void {
        $rule = $this->firstRule(['data' => 'domains', 'site' => 'game-a', 'domaintype' => 'full']);

        self::assertContains('game-a.com', $rule['domain']);
        self::assertArrayNotHasKey('domain_suffix', $rule);
    }

    public function testDomainsKeywordAndRegex(): void {
        $keyword = $this->firstRule(['data' => 'domains', 'site' => 'game-a', 'domaintype' => 'keyword']);
        self::assertContains('game-a.com', $keyword['domain_keyword']);
        self::assertArrayNotHasKey('domain', $keyword);

        $regex = $this->firstRule(['data' => 'domains', 'site' => 'game-a', 'domaintype' => 'regex']);
        self::assertContains('game-a.com', $regex['domain_regex']);
        self::assertArrayNotHasKey('domain', $regex);
    }

    public function testIp4AndIp6GetHostMasks(): void {
        $ip4 = $this->firstRule(['data' => 'ip4']);
        self::assertNotEmpty($ip4['ip_cidr']);
        foreach ($ip4['ip_cidr'] as $entry) {
            self::assertStringEndsWith('/32', $entry);
        }

        $ip6 = $this->firstRule(['data' => 'ip6']);
        self::assertNotEmpty($ip6['ip_cidr']);
        foreach ($ip6['ip_cidr'] as $entry) {
            self::assertStringEndsWith('/128', $entry);
        }
    }

    public function testCidrRowsPassThroughUnchanged(): void {
        $rule = $this->firstRule(['data' => 'cidr4', 'site' => 'game-a']);

        self::assertNotEmpty($rule['ip_cidr']);
        foreach ($rule['ip_cidr'] as $entry) {
            self::assertMatchesRegularExpression('#^\d+\.\d+\.\d+\.\d+/\d+$#', $entry);
        }
    }

    /**
     * Замена CIDR должна применяться так же, как в text/mikrotik: родительская
     * зона исчезает, вместо неё появляются значения из `replace`.
     */
    public function testReplaceIsAppliedForCidr4(): void {
        $rule = $this->firstRule(['data' => 'cidr4', 'site' => 'mock-google']);

        self::assertNotContains('172.217.0.0/16', $rule['ip_cidr']);
        self::assertContains('172.217.17.206/32', $rule['ip_cidr']);
        self::assertContains('172.217.17.207/32', $rule['ip_cidr']);
    }

    public function testReplaceIsAppliedForCidr6(): void {
        $rule = $this->firstRule(['data' => 'cidr6', 'site' => 'mock-google']);

        self::assertNotContains('2001:db8::/32', $rule['ip_cidr']);
        self::assertContains('2001:db8::1/128', $rule['ip_cidr']);
    }

    public function testNativeReturnsRawCidr(): void {
        $rule = $this->firstRule(['data' => 'cidr4', 'site' => 'mock-google', 'native' => 1]);

        self::assertContains('172.217.0.0/16', $rule['ip_cidr']);
        self::assertNotContains('172.217.17.206/32', $rule['ip_cidr']);
    }

    /**
     * Страховка от обхода `resolvedCidr()`: `exclude[cidr4]` обязан отсекать и
     * те CIDR, которые появляются только после подстановки `replace`
     * (зеркало ReplaceFlowTest::testExcludeCidr4DropsSubstitutedValueAfterReplace).
     */
    public function testExcludeCidr4DropsSubstitutedValue(): void {
        $rule = $this->firstRule([
            'data' => 'cidr4',
            'site' => 'mock-google',
            'exclude[cidr4]' => '172.217.17.206/32',
        ]);

        self::assertNotContains('172.217.17.206/32', $rule['ip_cidr']);
        self::assertContains('172.217.17.207/32', $rule['ip_cidr']);
    }

    public function testGroupFilterAndExcludeSite(): void {
        $games = $this->firstRule(['data' => 'domains', 'group' => 'games']);
        self::assertContains('game-a.com', $games['domain']);
        self::assertContains('game-b.com', $games['domain']);

        $withoutB = $this->firstRule(['data' => 'domains', 'group' => 'games', 'exclude[site]' => 'game-b']);
        self::assertContains('game-a.com', $withoutB['domain']);
        self::assertNotContains('game-b.com', $withoutB['domain']);
    }

    public function testWildcardCollapsesDomains(): void {
        $rule = $this->firstRule(['data' => 'domains', 'site' => 'game-a', 'wildcard' => 1]);

        self::assertContains('game-a.com', $rule['domain']);
        self::assertNotContains('www.game-a.com', $rule['domain']);
        self::assertContains('.game-a.co.uk', $rule['domain_suffix']);
    }

    /**
     * Пустое правило sing-box считает невалидным, поэтому при пустой выдаче
     * должен быть пустой список правил, а не список с пустым правилом.
     */
    public function testEmptyResultProducesEmptyRules(): void {
        $ruleSet = $this->ruleSet(['data' => 'domains', 'site' => 'does-not-exist']);

        self::assertSame(1, $ruleSet['version']);
        self::assertSame([], $ruleSet['rules']);
    }

    public function testRuleArraysAreJsonListsNotObjects(): void {
        // exclude по первому домену оставляет "дырку" в ключах после фильтрации;
        // если её не переиндексировать, json_encode отдаст объект вместо массива,
        // и sing-box такой rule-set не примет
        $body = $this->body(
            $this->get('/', [
                'format' => 'singbox',
                'data' => 'domains',
                'site' => 'game-a',
                'exclude[domain]' => 'api.game-a.com',
            ])
        );

        self::assertStringContainsString('"domain": [', $body);
        self::assertStringNotContainsString('"domain": {', $body);
    }
}
