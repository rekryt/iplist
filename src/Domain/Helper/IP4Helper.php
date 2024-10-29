<?php

namespace OpenCCK\Domain\Helper;

use OpenCCK\Infrastructure\API\App;
use OpenCCK\Infrastructure\Storage\CIDRStorage;

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
                    $results = array_merge($results, self::trimCIDRs($searchArray));

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
                    CIDRStorage::getInstance()->set($ip, $searchArray);
                    $results = array_merge($results, self::trimCIDRs($searchArray));

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

    public static function isInRange(string $ip, array $cidrs): bool {
        foreach ($cidrs as $cidr) {
            [$subnet, $mask] = explode('/', $cidr);
            if ((ip2long($ip) & ~((1 << 32 - $mask) - 1)) === ip2long($subnet)) {
                return true;
            }
        }
        return false;
    }
}
