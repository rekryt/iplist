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

    /**
     * Drop every subnet that is fully contained in another subnet in the same
     * input. Adjacent-but-disjoint subnets are preserved verbatim (unlike
     * `aggregateSubnets`, which fuses them into broader blocks).
     *
     * O(N log N) via sort-and-walk:
     *   1. Parse each CIDR once into a [start, end, subnet] triple.
     *   2. Sort by start ASC, end DESC — at the same starting address the
     *      broader prefix (larger end) wins the tie-break.
     *   3. Walk in order, keeping a running `$lastEnd`. A subnet is retained
     *      only when its start exceeds the last retained subnet's end;
     *      otherwise it is contained inside that retained subnet and
     *      dropped. This works because after the sort, any containing
     *      subnet always appears before the ones it contains.
     *
     * The previous implementation ran an O(N) containment scan per element
     * (O(N²) total) and re-parsed every kept CIDR on each inner iteration —
     * at N = 10k that alone burned ~100 s per request.
     */
    public static function minimizeSubnets(array $subnets): array {
        $parsed = [];
        foreach ($subnets as $subnet) {
            if (!is_string($subnet) || $subnet === '' || !str_contains($subnet, '/')) {
                continue;
            }
            [$ip, $maskRaw] = explode('/', $subnet, 2);
            $ipLong = ip2long($ip);
            if ($ipLong === false) {
                continue;
            }
            $mask = (int) $maskRaw;
            if ($mask < 0 || $mask > 32) {
                continue;
            }
            $end = $ipLong + (1 << (32 - $mask)) - 1;
            $parsed[] = [$ipLong, $end, $subnet];
        }
        if (!$parsed) {
            return [];
        }

        // Sort by start ASC, then by end DESC (broader prefix first at same start).
        usort($parsed, fn(array $a, array $b) => $a[0] === $b[0] ? $b[1] <=> $a[1] : $a[0] <=> $b[0]);

        $result = [];
        $lastEnd = PHP_INT_MIN;
        foreach ($parsed as [$start, $end, $subnet]) {
            if ($start > $lastEnd) {
                $result[] = $subnet;
                $lastEnd = $end;
            }
            // else: $start ≤ $lastEnd ⇒ this subnet is contained in the
            // previously retained one (CIDR blocks can only overlap by
            // containment, never partially).
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
     * Aggregate (supernet) a CIDR list into the minimum set of blocks that
     * exactly covers the union of its address ranges.
     *
     * Unlike `minimizeSubnets` (which only drops subnets contained in
     * broader ones already present in the input), `aggregateSubnets`
     * FUSES contiguous and overlapping ranges. A run of 256 adjacent
     * `/32` entries collapses into a single `/24`; two halves of the
     * same `/23` merge into the `/23`.
     *
     * When `$parentCidr` is provided, the output is capped so that no
     * emitted block has a prefix `<= parentPrefix`. This prevents the
     * value array of `$replace->cidr4[$parentCidr]` from aggregating
     * all the way up to the parent itself (which would produce the
     * pointless `"1.0.0.0/8": ["1.0.0.0/8"]`). Pass `null` for the
     * cap to aggregate without restriction.
     *
     * Two optional lossy post-steps run after lossless aggregation:
     *
     * - `$densityThreshold24 > 0` inflates any `/24` whose covered-address
     *   count reaches the threshold to the full `/24`.
     * - `$densityThreshold16 > 0` does the same at `/16` granularity,
     *   using whatever the previous step produced (so tightly-clustered
     *   `/24`s inflated by the narrow pass feed into the wider pass —
     *   "pyramid" effect).
     *
     * Each step is skipped when the parent prefix is already at or below
     * its bucket prefix (no room to inflate). Intended for huge sparse
     * portals (Adobe, Google) where thousands of isolated `/32` entries
     * dominate the value array — the operator accepts some false
     * positives in exchange for a far smaller list.
     *
     * @param array<int, string> $cidrs         CIDR strings. Malformed entries are skipped.
     * @param ?string $parentCidr               If set, output prefixes are strictly greater (narrower) than the parent's prefix.
     * @param int $densityThreshold24           Narrow-tier collapse threshold at /24 granularity.
     * @param int $densityThreshold16           Wide-tier collapse threshold at /16 granularity.
     * @return array<int, string>               Output sorted by starting address (ascending).
     */
    public static function aggregateSubnets(
        array $cidrs,
        ?string $parentCidr = null,
        int $densityThreshold24 = 0,
        int $densityThreshold16 = 0
    ): array {
        // Parent prefix (used for both density skips and final cap).
        $parentPrefix = null;
        if ($parentCidr !== null && str_contains($parentCidr, '/')) {
            [, $pMaskRaw] = explode('/', $parentCidr, 2);
            $parentPrefix = (int) $pMaskRaw;
        }

        // 1. Parse CIDRs into [start, end] integer ranges.
        $ranges = [];
        foreach ($cidrs as $cidr) {
            if (!is_string($cidr) || !str_contains($cidr, '/')) {
                continue;
            }
            [$ip, $maskRaw] = explode('/', $cidr, 2);
            $start = ip2long($ip);
            if ($start === false) {
                continue;
            }
            $mask = (int) $maskRaw;
            if ($mask < 0 || $mask > 32) {
                continue;
            }
            $end = $start + (1 << (32 - $mask)) - 1;
            $ranges[] = [$start, $end];
        }
        if (!$ranges) {
            return [];
        }

        // 2. Sort by start.
        usort($ranges, fn(array $a, array $b) => $a[0] <=> $b[0]);

        // 3. Merge overlapping or adjacent ranges ([a..b] and [b+1..c] → [a..c]).
        $merged = self::mergeSortedRanges($ranges);

        // 3b. Narrow-tier density collapse at /24. Skipped when
        // parentPrefix ≥ 24 (no room left for /24 buckets inside the parent).
        if ($densityThreshold24 > 0 && ($parentPrefix === null || $parentPrefix < 24)) {
            $merged = self::collapseDenseBuckets($merged, $densityThreshold24, 24);
        }

        // 3c. Wide-tier density collapse at /16. Runs on top of the narrow
        // output, so tightly-packed /24s feed into the /16 accounting.
        if ($densityThreshold16 > 0 && ($parentPrefix === null || $parentPrefix < 16)) {
            $merged = self::collapseDenseBuckets($merged, $densityThreshold16, 16);
        }

        // 4. Compute max block size (in host-bit count `k`) allowed by the
        // parent cap. `kByCap = 32` means "no cap" (aggregate freely up to /0).
        $kByCap = 32;
        if ($parentPrefix !== null) {
            if ($parentPrefix >= 0 && $parentPrefix < 32) {
                // Output prefix must be strictly greater than $parentPrefix,
                // i.e., block size ≤ 2^(32 - parentPrefix - 1).
                $kByCap = 32 - ($parentPrefix + 1);
            } elseif ($parentPrefix >= 32) {
                // /32 parent — the only valid output is /32 entries themselves.
                $kByCap = 0;
            }
        }

        // 5. Emit the minimum CIDR set covering each merged range.
        $result = [];
        foreach ($merged as [$start, $end]) {
            while ($start <= $end) {
                $kByAlign = $start === 0 ? 32 : self::intTrailingZeros($start);
                $kByLen = self::intLog2Floor($end - $start + 1);
                $k = min($kByAlign, $kByLen, $kByCap);
                $result[] = long2ip($start) . '/' . (32 - $k);
                if ($k >= 32) {
                    break; // Would step past 2^32 and wrap; whole space emitted.
                }
                $start += 1 << $k;
            }
        }

        return $result;
    }

    /**
     * Linearly merge an already-start-sorted list of [start, end] ranges,
     * fusing overlapping or touching ones (s ≤ prev.end + 1).
     *
     * @param array<int, array{int,int}> $ranges
     * @return array<int, array{int,int}>
     */
    private static function mergeSortedRanges(array $ranges): array {
        $merged = [$ranges[0]];
        $tail = 0;
        for ($i = 1, $n = count($ranges); $i < $n; $i++) {
            [$s, $e] = $ranges[$i];
            if ($s <= $merged[$tail][1] + 1) {
                if ($e > $merged[$tail][1]) {
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
     * Density-based lossy post-aggregation for IPv4, parametrised by the
     * bucket prefix. Typical calls use `$bucketPrefix = 24` (narrow tier)
     * or `16` (wide tier); any 0..31 value works as long as each bucket
     * has at least one address of room.
     *
     * For each bucket at the given prefix, sums the address count covered
     * by ranges that fall inside it. If the sum reaches `$threshold`, the
     * whole bucket is claimed (the bucket's start/end pair replaces all
     * inner ranges). The result is re-sorted and re-merged because
     * inflated buckets may touch pre-existing adjacent ranges.
     *
     * Ranges ≥ bucket size in length are pass-through — they already
     * span at least one full bucket and don't participate in density
     * accounting.
     *
     * @param array<int, array{int,int}> $merged  Disjoint, start-sorted ranges.
     * @param int $threshold                       Minimum address count to trigger a bucket expansion.
     * @param int $bucketPrefix                    Bucket granularity (24 or 16 in practice).
     * @return array<int, array{int,int}>
     */
    private static function collapseDenseBuckets(array $merged, int $threshold, int $bucketPrefix): array {
        $bucketSize = 1 << (32 - $bucketPrefix);
        $bucketMask = ~($bucketSize - 1);

        // Pass 1: coverage per bucket. Only ranges smaller than bucket size
        // contribute — any range ≥ bucket already spans at least one full
        // bucket and is kept verbatim below.
        $coverage = [];
        foreach ($merged as [$s, $e]) {
            $len = $e - $s + 1;
            if ($len >= $bucketSize) {
                continue;
            }
            // A CIDR-aligned block smaller than the bucket lives entirely within one bucket.
            $bucket = $s & $bucketMask;
            $coverage[$bucket] = ($coverage[$bucket] ?? 0) + $len;
        }

        // Pass 2: expand qualifying buckets.
        $expanded = [];
        foreach ($merged as [$s, $e]) {
            $len = $e - $s + 1;
            if ($len >= $bucketSize) {
                $expanded[] = [$s, $e];
                continue;
            }
            $bucket = $s & $bucketMask;
            if (($coverage[$bucket] ?? 0) >= $threshold) {
                // Claim the whole bucket. Multiple sub-ranges from the same
                // bucket produce identical [bucket, bucket+size-1] entries;
                // the merge below dedupes.
                $expanded[] = [$bucket, $bucket + $bucketSize - 1];
            } else {
                $expanded[] = [$s, $e];
            }
        }

        // Re-sort + merge — inflated buckets may now overlap each other or
        // fuse with adjacent pre-existing ranges.
        usort($expanded, fn(array $a, array $b) => $a[0] <=> $b[0]);
        return self::mergeSortedRanges($expanded);
    }

    private static function intTrailingZeros(int $n): int {
        // Count trailing zero bits of a 32-bit-ish unsigned value. For 0 we
        // return 32 — it means "aligned to any block size", since in the
        // aggregation loop kByLen further bounds the emitted block size.
        if ($n === 0) {
            return 32;
        }
        $r = 0;
        while (($n & 1) === 0) {
            $n >>= 1;
            $r++;
        }
        return $r;
    }

    private static function intLog2Floor(int $n): int {
        // floor(log2($n)). Returns -1 for $n <= 0 (callers don't pass that).
        if ($n <= 0) {
            return -1;
        }
        $r = 0;
        while ($n > 1) {
            $n >>= 1;
            $r++;
        }
        return $r;
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
     *
     * When `$aggregate` is true (driven by `SYS_REPLACE_AGGREGATE_SUBNETS`),
     * each value array is run through `aggregateSubnets` instead of
     * `minimizeSubnets`. Aggregation is a strict superset: it drops
     * subsumed subnets AND fuses contiguous blocks into broader ones
     * (e.g. 256 consecutive /32 entries collapse to a single /24). The
     * current parent key is passed as the cap so aggregation never
     * produces the parent zone itself or anything broader — that would
     * round-trip `"1.0.0.0/8": ["1.0.0.0/8"]` and defeat the feature.
     *
     * Two-tier density collapse inside `aggregateSubnets` is driven by:
     *   - `$densityThreshold24` (`SYS_REPLACE_COLLAPSE_THRESHOLD_IP4_24`) — narrow /24 tier;
     *   - `$densityThreshold16` (`SYS_REPLACE_COLLAPSE_THRESHOLD_IP4_16`) — wide /16 tier.
     * Both are ignored when `$aggregate === false`.
     */
    public static function growReplace(
        object $replace,
        array $ips,
        bool $aggregate = false,
        int $densityThreshold24 = 0,
        int $densityThreshold16 = 0
    ): void {
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
            $values = SiteFactory::normalize($values, true);
            $map->{$cidr} = $aggregate
                ? self::aggregateSubnets($values, $cidr, $densityThreshold24, $densityThreshold16)
                : self::minimizeSubnets($values);
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
