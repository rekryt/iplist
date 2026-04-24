<?php

namespace OpenCCK\Domain\Helper;

use OpenCCK\Domain\Factory\SiteFactory;
use OpenCCK\Infrastructure\API\App;
use OpenCCK\Infrastructure\Storage\CIDRStorage;
use stdClass;

use function Amp\async;
use function Amp\delay;

class IP4Helper {
    public static function processCIDR(array $ips, $results = []): array {
        $count = count($ips);
        foreach ($ips as $i => $ip) {
            if ($ip === '127.0.0.1') {
                continue;
            }
            async(function () use ($ip, $i, $count, &$results) {
                if (self::isInRange($ip, $results)) {
                    return;
                }

                if (CIDRStorage::getInstance()->has($ip)) {
                    $searchArray = CIDRStorage::getInstance()->get($ip);
                    $results = array_merge($results, $searchArray);

                    App::getLogger()->debug($ip . ' -> ' . json_encode($searchArray), [
                        $i + 1 . '/' . $count,
                        'from cache',
                    ]);
                    return;
                }

                $search = null;
                $result = shell_exec('whois ' . $ip . ' | grep CIDR');
                if ($result) {
                    preg_match('/^CIDR:\s*(.*)$/m', $result, $matches);
                    $search = $matches[1] ?? null;
                }

                if (!$search) {
                    $search = shell_exec(
                        implode(' | ', [
                            'whois -a ' . $ip,
                            'grep inetnum',
                            'head -n 1',
                            "awk '{print $2\"-\"$4}'",
                            'sed "s/-$//"',
                            'xargs ipcalc',
                            "grep -oE '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+/[0-9]+$'",
                        ])
                    );
                }

                if (!$search) {
                    $search = shell_exec(
                        implode(' | ', [
                            'whois -a ' . $ip,
                            'grep IPv4',
                            'grep " - "',
                            'head -n 1',
                            "awk '{print $3\"-\"$5}'",
                            'xargs ipcalc',
                            "grep -oE '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+/[0-9]+$'",
                        ])
                    );
                }

                if ($search) {
                    $search = strtr($search, ["\n" => ' ', ', ' => ' ']);
                    $searchArray = array_filter(
                        explode(' ', strtr($search, '  ', '')),
                        fn(string $cidr) => strlen($cidr) > 0
                    );
                    $searchArray = array_values(
                        array_filter(self::trimCIDRs($searchArray), fn($cidr) => self::isInCIDR($ip, $cidr))
                    );

                    CIDRStorage::getInstance()->set($ip, $searchArray);
                    $results = array_merge($results, $searchArray);

                    App::getLogger()->debug($ip . ' -> ' . json_encode($searchArray), [$i + 1 . '/' . $count, 'found']);
                } else {
                    App::getLogger()->error($ip . ' -> CIDR not found', [$i + 1 . '/' . $count]);
                }
                delay(0.001);
            })->await();
        }

        return self::minimizeSubnets($results);
    }

    public static function trimCIDRs(array $searchArray): array {
        $subnets = [];
        foreach ($searchArray as $search) {
            foreach (explode(' ', $search) as $cidr) {
                if (str_contains($cidr, '/')) {
                    $subnets[] = trim($cidr);
                }
            }
        }
        return $subnets;
    }

    public static function sortSubnets(array $subnets): array {
        usort($subnets, function ($a, $b) {
            return (int) explode('/', $a)[1] - (int) explode('/', $b)[1];
        });
        usort($subnets, function ($a, $b) {
            return ip2long(explode('/', $a)[0]) - ip2long(explode('/', $b)[0]);
        });
        return $subnets;
    }

    public static function minimizeSubnets(array $subnets): array {
        $result = [];
        foreach (self::sortSubnets(array_filter($subnets, fn(string $subnet) => !!$subnet)) as $subnet) {
            $include = true;
            [$ip /*, $mask*/] = explode('/', $subnet);
            $ipLong = ip2long($ip);
            // $maskLong = ~((1 << 32 - (int) $mask) - 1);

            foreach ($result as $resSubnet) {
                [$resIp, $resMask] = explode('/', $resSubnet);
                $resIpLong = ip2long($resIp);
                $resMaskLong = ~((1 << 32 - (int) $resMask) - 1);

                if (($ipLong & $resMaskLong) === ($resIpLong & $resMaskLong)) {
                    $include = false;
                    break;
                }
            }

            if ($include) {
                $result[] = $subnet;
            }
        }

        return $result;
    }

    public static function isInCIDR(string $ip, string $cidr): bool {
        [$subnet, $mask] = explode('/', $cidr);
        if ((ip2long($ip) & ~((1 << 32 - $mask) - 1)) === ip2long($subnet)) {
            return true;
        }
        return false;
    }

    public static function isInRange(string $ip, array $cidrs): bool {
        foreach ($cidrs as $cidr) {
            if (self::isInCIDR($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Reload-time half of the `replace` feature for IPv4.
     *
     * For each key `$cidr` in `$replace->cidr4`, walks `$ips` and appends
     * `"$ip/32"` to the value array of that key whenever the IP falls inside
     * the zone. Each value array is then normalized and minimized, so:
     *   - repeated reloads don't grow the list with duplicates;
     *   - /32 entries inside a narrower admin-provided zone (say /24) are
     *     absorbed by `minimizeSubnets`;
     *   - the on-disk JSON converges to a stable, minimized form.
     *
     * Mutates `$replace` in place. No-op when `$replace->cidr4` is missing
     * or empty.
     *
     * **Must stay synchronous** — no `await`/`delay`/`async` inside. The
     * concurrency invariant is that a parallel HTTP request sees either
     * the fully pre-mutation or fully post-mutation snapshot of `$replace`,
     * never an intermediate state. Introducing a fiber-yield point here
     * breaks that guarantee.
     */
    public static function growReplace(object $replace, array $ips): void {
        if (!isset($replace->cidr4) || !is_object($replace->cidr4)) {
            return;
        }
        $map = $replace->cidr4;
        foreach ($map as $cidr => $values) {
            $values = (array) $values;
            foreach ($ips as $ip) {
                if (self::isInCIDR($ip, $cidr)) {
                    $values[] = $ip . '/32';
                }
            }
            $map->{$cidr} = self::minimizeSubnets(SiteFactory::normalize($values, true));
        }
    }

    /**
     * View-time half of the `replace` feature for IPv4.
     *
     * Returns `$cidrs` with every string that matches a key of
     * `$replace->cidr4` removed, plus the flattened concatenation of every
     * value array. Does NOT run `minimizeSubnets` on the result — the caller
     * typically aggregates across sites first, so a second minimization pass
     * at the aggregation layer is more efficient than doing it per site here.
     *
     * CIDR-key comparison is strict byte-for-byte: admins must write
     * replacement keys in exactly the form in which they appear in `$cidrs`.
     * No trim, no canonicalization. The filter uses `isset` against the
     * hash-cast map so the cost scales as O(|cidrs| + |keys|) rather than
     * the O(|cidrs| × |keys|) of `array_diff`.
     */
    public static function applyReplace(array $cidrs, object $replace): array {
        if (!isset($replace->cidr4) || !is_object($replace->cidr4)) {
            return $cidrs;
        }
        $map = (array) $replace->cidr4;
        if (!$map) {
            return $cidrs;
        }

        $result = [];
        foreach ($cidrs as $cidr) {
            if (!isset($map[$cidr])) {
                $result[] = $cidr;
            }
        }
        foreach ($map as $values) {
            $result = array_merge($result, (array) $values);
        }
        return $result;
    }

    /**
     * преобразование короткой маски IPv4 в полную
     * @param string $mask
     * @return string
     */
    public static function formatShortIpMask(string $mask): string {
        if ($mask == '') {
            $mask = '32';
        }
        $binaryMask = str_repeat('1', (int) $mask) . str_repeat('0', 32 - $mask);
        $octets = [];

        for ($i = 0; $i < 4; $i++) {
            $octets[] = bindec(substr($binaryMask, $i * 8, 8));
        }

        return implode('.', $octets);
    }
}
