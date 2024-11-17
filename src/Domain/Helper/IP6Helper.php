<?php

namespace OpenCCK\Domain\Helper;

use OpenCCK\Infrastructure\API\App;
use OpenCCK\Infrastructure\Storage\CIDRStorage;

use function Amp\async;
use function Amp\delay;

class IP6Helper {
    public static function processCIDR(array $ips, $results = []): array {
        $count = count($ips);
        foreach ($ips as $i => $ip) {
            if ($ip === '::1') {
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

                $search = shell_exec(
                    implode(' | ', [
                        'whois ' . $ip,
                        'grep CIDR',
                        'grep -v "/0"',
                        'head -n 1',
                        "awk '{print $2}'",
                        "grep -oE '^([0-9a-fA-F]{1,4}:){1,7}(:|[0-9a-fA-F]{1,4})(:[0-9a-fA-F]{1,4}){0,6}/[0-9]+$'",
                    ])
                );

                if (!$search) {
                    $search = shell_exec(
                        implode(' | ', [
                            'whois -a ' . $ip,
                            'grep inet6num',
                            'grep -v "/0"',
                            'head -n 1',
                            "awk '{print $2}'",
                        ])
                    );
                }

                if (!$search) {
                    $search = shell_exec(
                        implode(' | ', [
                            'whois -a ' . $ip,
                            'grep route6',
                            'grep -v "/0"',
                            'head -n 1',
                            "awk '{print $2}'",
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

        //return self::minimizeSubnets($results);
        return $results;
    }

    /**
     * @param array $searchArray
     * @return array
     */
    public static function trimCIDRs(array $searchArray): array {
        $subnets = [];
        foreach ($searchArray as $search) {
            foreach (explode(' ', $search) as $cidr) {
                if (str_contains($cidr, '/')) {
                    [$address, $prefix] = explode('/', trim($cidr));
                    $subnets[] = $address . '/' . min($prefix, 64);
                }
            }
        }
        return $subnets;
    }

    /**
     * @param array $subnets
     * @return array
     */
    public static function sortSubnets(array $subnets): array {
        usort($subnets, function ($a, $b) {
            return (int) explode('/', $a)[1] - (int) explode('/', $b)[1];
        });

        usort($subnets, function ($a, $b) {
            $ipA = inet_pton(explode('/', $a)[0]);
            $ipB = inet_pton(explode('/', $b)[0]);

            return strcmp($ipA, $ipB);
        });

        return $subnets;
    }

    /**
     * @param array $subnets
     * @return array
     */
    public static function minimizeSubnets(array $subnets): array {
        $result = [];
        foreach (self::sortSubnets(array_filter($subnets, fn(string $subnet) => !!$subnet)) as $subnet) {
            if (!$subnet) {
                continue;
            }
            [$address, $prefix] = explode('/', $subnet);

            $addressNum = inet_pton($address);
            $addressNum = unpack('J', $addressNum)[1];

            $isUnique = true;
            foreach ($result as $existingCidr) {
                [$existingAddress, $existingPrefix] = explode('/', $existingCidr);
                $existingAddressNum = inet_pton($existingAddress);
                $existingAddressNum = unpack('J', $existingAddressNum)[1];

                $mask = (1 << 128) - (1 << 128 - $prefix);
                $existingMask = (1 << 128) - (1 << 128 - $existingPrefix);
                if (($addressNum & $mask) === ($existingAddressNum & $existingMask)) {
                    $isUnique = false;
                    break;
                }
            }

            if ($isUnique) {
                $result[] = $subnet;
            }
        }

        return $result;
    }

    public static function isInCidr(string $ip, string $cidr): bool {
        $ip = inet_pton($ip);

        [$subnet, $mask] = explode('/', $cidr);
        $subnet = inet_pton($subnet);

        $mask = intval($mask);
        $binaryMask = str_repeat('f', $mask >> 2);
        switch ($mask % 4) {
            case 1:
                $binaryMask .= '8';
                break;
            case 2:
                $binaryMask .= 'c';
                break;
            case 3:
                $binaryMask .= 'e';
                break;
        }
        $binaryMask = str_pad($binaryMask, 32, '0');
        $mask = pack('H*', $binaryMask);

        if (($ip & $mask) === ($subnet & $mask)) {
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
     * преобразование короткой маски IPv6 в полную
     * @param string $mask
     * @return string
     */
    public static function formatShortIpMask(string $mask): string {
        if ($mask === '') {
            $mask = '128';
        }

        $binaryMask = str_repeat('1', (int) $mask) . str_repeat('0', 128 - $mask);
        $hextets = [];

        for ($i = 0; $i < 8; $i++) {
            $hextets[] = dechex(bindec(substr($binaryMask, $i * 16, 16)));
        }

        return implode(':', $hextets);
    }
}
