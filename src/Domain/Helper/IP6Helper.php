<?php

namespace OpenCCK\Domain\Helper;

use OpenCCK\Domain\Factory\SiteFactory;
use OpenCCK\Infrastructure\API\App;
use OpenCCK\Infrastructure\Storage\CIDRStorage;
use stdClass;

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

        return self::minimizeSubnets($results);
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
                    $subnets[] = $address . '/' . min($prefix, \OpenCCK\getEnv('SYS_IP6_SUBNET_PREFIX_CAP') ?? 64);
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
     * IPv6 counterpart of `IP4Helper::minimizeSubnets`: drops subnets that
     * are strictly contained in another input subnet. Adjacent-but-disjoint
     * ranges are preserved — for adjacent fusion use `aggregateSubnets`.
     *
     * O(N log N) via sort-and-walk over 16-byte [start, end] ranges. Big-
     * endian byte strings from `inet_pton` compare as unsigned integers
     * under `strcmp`, so the sort is cheap. The earlier O(N²) version re-
     * parsed every retained CIDR on each inner iteration and degraded
     * badly (≥ 100 s per 10 k subnets).
     */
    public static function minimizeSubnets(array $subnets): array {
        $parsed = [];
        foreach ($subnets as $subnet) {
            if (!is_string($subnet) || $subnet === '' || !str_contains($subnet, '/')) {
                continue;
            }
            [$ip, $maskRaw] = explode('/', $subnet, 2);
            $start = @inet_pton($ip);
            if ($start === false || strlen($start) !== 16) {
                continue;
            }
            $mask = (int) $maskRaw;
            if ($mask < 0 || $mask > 128) {
                continue;
            }
            $end = $start | self::b_low_ones(128 - $mask);
            $parsed[] = [$start, $end, $subnet];
        }
        if (!$parsed) {
            return [];
        }

        // Sort by start ASC, end DESC. On big-endian byte strings `strcmp`
        // matches unsigned-integer order; `end DESC` ranks broader prefixes
        // first at the same starting address.
        usort($parsed, function (array $a, array $b): int {
            $byStart = strcmp($a[0], $b[0]);
            if ($byStart !== 0) {
                return $byStart;
            }
            return strcmp($b[1], $a[1]);
        });

        $result = [];
        $lastEnd = null;
        foreach ($parsed as [$start, $end, $subnet]) {
            if ($lastEnd === null || strcmp($start, $lastEnd) > 0) {
                $result[] = $subnet;
                $lastEnd = $end;
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
     * IPv6 counterpart of `IP4Helper::aggregateSubnets`. Same algorithm,
     * same contract (merge contiguous ranges, emit the minimum CIDR set,
     * respect the `$parentCidr` cap so the result never equals the parent
     * or anything broader). The 128-bit arithmetic is done on byte-strings
     * produced by `inet_pton` — big-endian representation means `strcmp`
     * on those strings compares as unsigned integers, and the byte-level
     * helpers below (`b_sub`, `b_inc`, `b_add_pow2`, `b_trailing_zeros`,
     * `b_high_bit_pos`, `b_low_ones`) do the rest.
     *
     * Two optional lossy post-steps run after the standard range merge:
     *
     * - `$densityThreshold64 > 0` inflates any `/64` whose covered-address
     *   count reaches the threshold to the full `/64`.
     * - `$densityThreshold32 > 0` does the same at `/32` granularity on
     *   top of the narrow pass (pyramid effect).
     *
     * Each step is skipped when the parent prefix is already at or below
     * its bucket prefix.
     *
     * @param array<int, string> $cidrs
     * @param ?string $parentCidr
     * @param int $densityThreshold64
     * @param int $densityThreshold32
     * @return array<int, string>
     */
    public static function aggregateSubnets(
        array $cidrs,
        ?string $parentCidr = null,
        int $densityThreshold64 = 0,
        int $densityThreshold32 = 0
    ): array {
        // Parent prefix (reused for density skips and final cap).
        $parentPrefix = null;
        if ($parentCidr !== null && str_contains($parentCidr, '/')) {
            [, $pMaskRaw] = explode('/', $parentCidr, 2);
            $parentPrefix = (int) $pMaskRaw;
        }

        $ranges = [];
        foreach ($cidrs as $cidr) {
            if (!is_string($cidr) || !str_contains($cidr, '/')) {
                continue;
            }
            [$ip, $maskRaw] = explode('/', $cidr, 2);
            $start = @inet_pton($ip);
            if ($start === false || strlen($start) !== 16) {
                continue;
            }
            $mask = (int) $maskRaw;
            if ($mask < 0 || $mask > 128) {
                continue;
            }
            // end = start | (low (128-mask) bits all 1). Uses PHP's byte-wise
            // `|` on strings of equal length.
            $end = $start | self::b_low_ones(128 - $mask);
            $ranges[] = [$start, $end];
        }
        if (!$ranges) {
            return [];
        }

        // Sort by start (big-endian bytes → strcmp acts as unsigned compare).
        usort($ranges, fn(array $a, array $b) => strcmp($a[0], $b[0]));

        // Merge adjacent/overlapping.
        $merged = self::mergeSortedRanges($ranges);

        // Narrow-tier collapse at /64.
        if ($densityThreshold64 > 0 && ($parentPrefix === null || $parentPrefix < 64)) {
            $merged = self::collapseDenseBuckets($merged, $densityThreshold64, 64);
        }

        // Wide-tier collapse at /32 (runs after narrow).
        if ($densityThreshold32 > 0 && ($parentPrefix === null || $parentPrefix < 32)) {
            $merged = self::collapseDenseBuckets($merged, $densityThreshold32, 32);
        }

        // Cap from parent prefix.
        $kByCap = 128;
        if ($parentPrefix !== null) {
            if ($parentPrefix >= 0 && $parentPrefix < 128) {
                $kByCap = 128 - ($parentPrefix + 1);
            } elseif ($parentPrefix >= 128) {
                $kByCap = 0;
            }
        }

        $zero16 = str_repeat("\x00", 16);

        $result = [];
        foreach ($merged as [$start, $end]) {
            while (strcmp($start, $end) <= 0) {
                $kByAlign = self::b_trailing_zeros($start);
                // kByLen = floor(log2(end - start + 1))
                $diff = self::b_sub($end, $start); // end - start (≥ 0)
                $count = self::b_inc($diff); // end - start + 1
                if ($count === $zero16) {
                    // Wrapped: the range is the full 2^128 space.
                    $kByLen = 128;
                } else {
                    $kByLen = self::b_high_bit_pos($count);
                }
                $k = min($kByAlign, $kByLen, $kByCap);
                $result[] = inet_ntop($start) . '/' . (128 - $k);
                if ($k >= 128) {
                    break; // Emitted ::/0 or equivalent — stepping past 2^128.
                }
                $prevStart = $start;
                $start = self::b_add_pow2($start, $k);
                if (strcmp($start, $prevStart) <= 0) {
                    break; // Wrap detected (rare: only with /0 range + no cap).
                }
            }
        }

        return $result;
    }

    /**
     * Linearly merge an already-start-sorted list of [startBytes, endBytes]
     * ranges into a disjoint, touching-free list. Mirrors the v4 counterpart
     * but works on 16-byte big-endian strings.
     *
     * @param array<int, array{string,string}> $ranges
     * @return array<int, array{string,string}>
     */
    private static function mergeSortedRanges(array $ranges): array {
        $merged = [$ranges[0]];
        $tail = 0;
        for ($i = 1, $n = count($ranges); $i < $n; $i++) {
            [$s, $e] = $ranges[$i];
            $plusOne = self::b_inc($merged[$tail][1]);
            if (strcmp($s, $plusOne) <= 0) {
                if (strcmp($e, $merged[$tail][1]) > 0) {
                    $merged[$tail][1] = $e;
                }
            } else {
                $merged[] = [$s, $e];
                $tail++;
            }
        }
        return $merged;
    }

    /**
     * Density-based lossy post-aggregation for IPv6, parametrised by the
     * bucket prefix (`$bucketPrefix` must be a multiple of 8 — typically
     * 64 for the narrow tier or 32 for the wide tier).
     *
     * For each bucket (identified by the first `$bucketPrefix / 8` bytes
     * of the address), sums coverage from ranges entirely inside it. If
     * the sum reaches `$threshold`, inflates that bucket to its full span.
     * Ranges that already span ≥ bucket size are pass-through.
     *
     * Coverage is summed as PHP `int` and saturates at `PHP_INT_MAX` on
     * overflow via `rangeLengthSaturated` — a single block big enough to
     * saturate is already larger than any meaningful threshold.
     *
     * @param array<int, array{string,string}> $merged  Disjoint, start-sorted.
     * @param int $threshold
     * @param int $bucketPrefix                          Multiple of 8 in [8, 120].
     * @return array<int, array{string,string}>
     */
    private static function collapseDenseBuckets(array $merged, int $threshold, int $bucketPrefix): array {
        $bucketBytes = intdiv($bucketPrefix, 8); // prefix must be byte-aligned
        $bucketHostFill = str_repeat("\xff", 16 - $bucketBytes);
        $bucketHostZero = str_repeat("\x00", 16 - $bucketBytes);

        // Pass 1: coverage per bucket. A range is "inside one bucket" iff its
        // start and end share the same high $bucketBytes bytes.
        $coverage = [];
        foreach ($merged as [$s, $e]) {
            $sHi = substr($s, 0, $bucketBytes);
            $eHi = substr($e, 0, $bucketBytes);
            if ($sHi !== $eHi) {
                // Range spans ≥ 2 buckets — already bucket-sized or broader.
                continue;
            }
            $existing = $coverage[$sHi] ?? 0;
            if ($existing >= $threshold) {
                continue; // already over the bar, skip further accounting
            }
            $len = self::rangeLengthSaturated(substr($s, $bucketBytes), substr($e, $bucketBytes));
            if ($len >= $threshold - $existing) {
                $coverage[$sHi] = $threshold; // sentinel: reached
            } else {
                $coverage[$sHi] = $existing + $len;
            }
        }

        // Pass 2: expand qualifying buckets.
        $expanded = [];
        foreach ($merged as [$s, $e]) {
            $sHi = substr($s, 0, $bucketBytes);
            $eHi = substr($e, 0, $bucketBytes);
            if ($sHi !== $eHi) {
                $expanded[] = [$s, $e];
                continue;
            }
            if (($coverage[$sHi] ?? 0) >= $threshold) {
                $expanded[] = [$sHi . $bucketHostZero, $sHi . $bucketHostFill];
            } else {
                $expanded[] = [$s, $e];
            }
        }

        usort($expanded, fn(array $a, array $b) => strcmp($a[0], $b[0]));
        return self::mergeSortedRanges($expanded);
    }

    /**
     * Length of the range `[startLow, endLow]` + 1, where both are big-
     * endian unsigned integers of equal byte width (the bucket's "low"
     * portion — 8 bytes for /64 buckets, 12 bytes for /32). Saturates at
     * `PHP_INT_MAX` when the result exceeds 2^63 — a single block that
     * large is guaranteed to clear any meaningful density threshold on
     * its own.
     */
    private static function rangeLengthSaturated(string $startLow, string $endLow): int {
        $width = strlen($startLow);
        // Subtract byte-by-byte with borrow, LSB first.
        $diffBytes = '';
        $borrow = 0;
        for ($i = $width - 1; $i >= 0; $i--) {
            $va = ord($endLow[$i]) - $borrow;
            $vb = ord($startLow[$i]);
            if ($va < $vb) {
                $diffBytes = chr($va + 256 - $vb) . $diffBytes;
                $borrow = 1;
            } else {
                $diffBytes = chr($va - $vb) . $diffBytes;
                $borrow = 0;
            }
        }
        // Any non-zero byte above the low-8 position means the diff does
        // not fit in int64 → saturate.
        if ($width > 8) {
            for ($i = 0, $n = $width - 8; $i < $n; $i++) {
                if ($diffBytes[$i] !== "\x00") {
                    return PHP_INT_MAX;
                }
            }
        }
        // Pad (or slice) to exactly 8 bytes and unpack as big-endian uint64.
        // 'J' in PHP returns a signed int, so values ≥ 2^63 surface as negative.
        $tail = $width >= 8 ? substr($diffBytes, -8) : str_pad($diffBytes, 8, "\x00", STR_PAD_LEFT);
        $diff = unpack('J', $tail)[1];
        if ($diff < 0 || $diff === PHP_INT_MAX) {
            return PHP_INT_MAX;
        }
        return $diff + 1;
    }

    /**
     * 16-byte big-endian unsigned integer with the low $bits bits all set.
     * `b_low_ones(0)` is all zeros; `b_low_ones(128)` is all 0xff.
     */
    private static function b_low_ones(int $bits): string {
        $bits = max(0, min(128, $bits));
        $bytes = str_repeat("\x00", 16);
        $fullBytes = $bits >> 3;
        $partial = $bits & 7;
        for ($i = 0; $i < $fullBytes; $i++) {
            $bytes[15 - $i] = "\xff";
        }
        if ($partial > 0) {
            $bytes[15 - $fullBytes] = chr((1 << $partial) - 1);
        }
        return $bytes;
    }

    /** Increment a 16-byte big-endian unsigned integer modulo 2^128. */
    private static function b_inc(string $a): string {
        for ($i = 15; $i >= 0; $i--) {
            $v = ord($a[$i]);
            if ($v < 255) {
                $a[$i] = chr($v + 1);
                return $a;
            }
            $a[$i] = "\x00";
        }
        return $a; // Wrapped to zero.
    }

    /** a + 2^k modulo 2^128. No-op when k ≥ 128. */
    private static function b_add_pow2(string $a, int $k): string {
        if ($k < 0 || $k >= 128) {
            return $a;
        }
        $byteIdx = 15 - ($k >> 3);
        $carry = 1 << ($k & 7);
        for ($i = $byteIdx; $i >= 0 && $carry > 0; $i--) {
            $v = ord($a[$i]) + $carry;
            $a[$i] = chr($v & 0xff);
            $carry = $v >> 8;
        }
        return $a;
    }

    /** a - b for 16-byte big-endian unsigned integers. Assumes a ≥ b. */
    private static function b_sub(string $a, string $b): string {
        $result = str_repeat("\x00", 16);
        $borrow = 0;
        for ($i = 15; $i >= 0; $i--) {
            $va = ord($a[$i]) - $borrow;
            $vb = ord($b[$i]);
            if ($va < $vb) {
                $result[$i] = chr($va + 256 - $vb);
                $borrow = 1;
            } else {
                $result[$i] = chr($va - $vb);
                $borrow = 0;
            }
        }
        return $result;
    }

    /** Count trailing zero bits of a 16-byte big-endian number. 128 for zero. */
    private static function b_trailing_zeros(string $a): int {
        for ($i = 15; $i >= 0; $i--) {
            $v = ord($a[$i]);
            if ($v !== 0) {
                $t = 0;
                while (($v & 1) === 0) {
                    $v >>= 1;
                    $t++;
                }
                return (15 - $i) * 8 + $t;
            }
        }
        return 128;
    }

    /** Position of highest set bit (0-indexed from LSB). -1 for zero. */
    private static function b_high_bit_pos(string $a): int {
        for ($i = 0; $i < 16; $i++) {
            $v = ord($a[$i]);
            if ($v !== 0) {
                $h = 7;
                while (($v & 0x80) === 0) {
                    $v <<= 1;
                    $h--;
                }
                return (15 - $i) * 8 + $h;
            }
        }
        return -1;
    }

    /**
     * IPv6 counterpart of IP4Helper::growReplace. Uses /128 for host-level
     * escalation, operates on $replace->cidr6, and applies minimizeSubnets
     * (or aggregateSubnets when $aggregate is true) to each value array on
     * every call — see the v4 docblock for the full contract. Mutates
     * $replace in place. Must stay synchronous.
     *
     * Two-tier density collapse inside `aggregateSubnets` is driven by:
     *   - `$densityThreshold64` (`SYS_REPLACE_COLLAPSE_THRESHOLD_IP6_64`) — narrow /64 tier;
     *   - `$densityThreshold32` (`SYS_REPLACE_COLLAPSE_THRESHOLD_IP6_32`) — wide /32 tier.
     * Both are ignored when `$aggregate === false`.
     */
    public static function growReplace(
        object $replace,
        array $ips,
        bool $aggregate = false,
        int $densityThreshold64 = 0,
        int $densityThreshold32 = 0
    ): void {
        if (!isset($replace->cidr6) || !is_object($replace->cidr6)) {
            return;
        }
        $map = $replace->cidr6;
        foreach ($map as $cidr => $values) {
            $values = (array) $values;
            foreach ($ips as $ip) {
                if (self::isInCidr($ip, $cidr)) {
                    $values[] = $ip . '/128';
                }
            }
            $values = SiteFactory::normalize($values, true);
            $map->{$cidr} = $aggregate
                ? self::aggregateSubnets($values, $cidr, $densityThreshold64, $densityThreshold32)
                : self::minimizeSubnets($values);
        }
    }

    /**
     * IPv6 counterpart of IP4Helper::applyReplace. Operates on
     * $replace->cidr6. See the v4 docblock for the contract.
     */
    public static function applyReplace(array $cidrs, object $replace): array {
        if (!isset($replace->cidr6) || !is_object($replace->cidr6)) {
            return $cidrs;
        }
        $map = (array) $replace->cidr6;
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
