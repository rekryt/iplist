<?php

/**
 * One-off migration script: seed `replace.cidr4` keys across all portal
 * configs from a list of "intersecting" CIDR zones (i.e. zones that show
 * up in multiple portals' `cidr4` arrays and therefore need narrowing
 * via the `replace` feature — see docs/REPLACE.md / docs/REPLACE.en.md).
 *
 * For every CIDR listed in `cidr4.txt` that appears in a portal's
 * `cidr4` array, this script ensures the portal's `replace.cidr4` has
 * that CIDR as a key with an empty array as its value (if the key is
 * absent). It also materializes the canonical `replace = {cidr4:{}, cidr6:{}}`
 * shape when missing, so the on-disk form matches what the running
 * server would write via `Site::saveConfig`.
 *
 * Idempotent: running it again is a no-op. Never overwrites an existing
 * value array — a key that is already populated with /32 entries from
 * a previous reload survives untouched.
 *
 * After this runs, starting the server with `SYS_REPLACE_ESCALATE_IPS=true`
 * (the default) will let the next `reload` cycle populate those empty
 * value arrays with `/32` entries pulled from each portal's `ip4`.
 *
 * Usage (from the project root):
 *   php docs/intersection/intersection.php
 *   docker compose run --rm app php docs/intersection/intersection.php
 *
 * `cidr4.txt` format: one CIDR per line. Blank lines and `#` comments
 * are skipped. Any CIDR that does not appear in a portal's `cidr4` is
 * silently ignored for that portal.
 */

declare(strict_types=1);

ini_set('memory_limit', '4048M');

// Script lives at <project-root>/docs/intersection/intersection.php
// — climb two levels to find `config/`. The list file sits next to the
// script (same directory), so anyone cloning the repo can edit it in
// place without touching the project root.
$projectRoot = dirname(__DIR__, 2);
$configDir = $projectRoot . '/config';
$listPath = __DIR__ . '/cidr4.txt';

if (!is_dir($configDir)) {
    fwrite(STDERR, "config/ directory not found at {$configDir}\n");
    exit(1);
}
if (!is_file($listPath)) {
    fwrite(STDERR, "cidr4.txt not found at {$listPath}\n");
    exit(1);
}

$intersections = [];
foreach (file($listPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') {
        continue;
    }
    // Flip-map so membership checks below are O(1).
    $intersections[$line] = true;
}

if (!$intersections) {
    fwrite(STDERR, "cidr4.txt is empty — nothing to do\n");
    exit(0);
}

fwrite(STDOUT, sprintf("Loaded %d CIDR(s) from cidr4.txt\n", count($intersections)));

$jsonFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
// Key order that mirrors Site::getConfig(), so the file stays consistent
// with what the server writes after a reload. Any unknown keys are appended
// at the tail to preserve forward-compatibility with future config fields.
$orderedKeys = ['domains', 'dns', 'timeout', 'ip4', 'ip6', 'cidr4', 'cidr6', 'external', 'replace'];

$touched = 0;
$keysAdded = 0;

foreach (new DirectoryIterator($configDir) as $groupDir) {
    if ($groupDir->isDot() || !$groupDir->isDir()) {
        continue;
    }
    foreach (new DirectoryIterator($groupDir->getPathname()) as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'json') {
            continue;
        }
        $path = $file->getPathname();

        $raw = file_get_contents($path);
        $config = json_decode($raw);
        if (!$config instanceof stdClass) {
            fwrite(STDERR, "skip {$path}: top-level JSON is not an object\n");
            continue;
        }

        $cidr4 = $config->cidr4 ?? null;
        if (!is_array($cidr4) || !$cidr4) {
            continue;
        }

        // Which of this portal's cidr4 entries are also in cidr4.txt?
        $hits = [];
        foreach ($cidr4 as $cidr) {
            if (is_string($cidr) && isset($intersections[$cidr])) {
                $hits[] = $cidr;
            }
        }
        if (!$hits) {
            continue;
        }

        // Pull or create the replace block. Guard against admins having put
        // something non-object in there manually — log and skip rather than
        // overwrite silently.
        $replace = $config->replace ?? new stdClass();
        if (!$replace instanceof stdClass) {
            fwrite(STDERR, "skip {$path}: `replace` is present but not an object\n");
            continue;
        }
        $cidr4Map = $replace->cidr4 ?? new stdClass();
        if (!$cidr4Map instanceof stdClass) {
            fwrite(STDERR, "skip {$path}: `replace.cidr4` is present but not an object\n");
            continue;
        }
        $cidr6Map = $replace->cidr6 ?? new stdClass();
        if (!$cidr6Map instanceof stdClass) {
            fwrite(STDERR, "skip {$path}: `replace.cidr6` is present but not an object\n");
            continue;
        }

        $added = 0;
        foreach ($hits as $cidr) {
            if (!isset($cidr4Map->{$cidr})) {
                // Empty array — escalation at next reload will fill it with
                // /32 entries pulled from $site->ip4 via IP4Helper::growReplace.
                $cidr4Map->{$cidr} = [];
                $added++;
            }
        }

        if ($added === 0) {
            continue;
        }

        $replace->cidr4 = $cidr4Map;
        $replace->cidr6 = $cidr6Map;
        $config->replace = $replace;

        // Rebuild the config with the canonical key order. Any extra keys
        // we didn't enumerate are appended at the tail.
        $ordered = new stdClass();
        foreach ($orderedKeys as $key) {
            if (property_exists($config, $key)) {
                $ordered->{$key} = $config->{$key};
            }
        }
        foreach ($config as $key => $value) {
            if (!property_exists($ordered, $key)) {
                $ordered->{$key} = $value;
            }
        }

        file_put_contents($path, json_encode($ordered, $jsonFlags));
        $touched++;
        $keysAdded += $added;
        fwrite(STDOUT, sprintf("  %s: +%d key(s)\n", $path, $added));
    }
}

fwrite(STDOUT, sprintf("Done. Touched %d portal(s), added %d replace.cidr4 key(s) in total.\n", $touched, $keysAdded));
