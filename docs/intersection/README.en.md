# `intersection.php` — `replace.cidr4` key seeder

[Для русской версии: README.md](README.md)

A one-off PHP script: walks every portal config under
`config/<group>/<portal>.json` and, for every portal whose `cidr4`
contains any zone listed in `cidr4.txt`, adds that zone as a key in
`replace.cidr4` with an empty `[]` value.

Used to bulk-mark portals that share "wide" CIDR zones (e.g.
`172.217.0.0/16` between google and yandex) — for the feature itself
see [../REPLACE.en.md](../REPLACE.en.md).

## What the script actually does

1. Reads `cidr4.txt` — one CIDR per line. Blank lines and lines
   starting with `#` are skipped.
2. Recursively walks `config/*/*.json`.
3. For each portal: if its `cidr4` contains any zone from the list,
   the script adds each such zone as a key in `replace.cidr4` with
   an empty `[]` value (if the key isn't there already).
4. Materialises the canonical `replace: {cidr4: {...}, cidr6: {}}`
   shape when the portal had no `replace` block before.
5. Rewrites the portal JSON in the exact key order the server itself
   uses via `Site::saveConfig`.

**Idempotent.** Running it again is a no-op. Value arrays already
populated by prior `reload` cycles are never overwritten.

## How to run

Run from the project root:

```bash
# Direct
php docs/intersection/intersection.php

# Through docker compose
docker compose run --rm app php docs/intersection/intersection.php
```

The script bumps the memory limit to 4 GB
(`ini_set('memory_limit', '4048M')`) so it can process heavy configs
like Adobe / Amazon with thousands of domains.

## `cidr4.txt` format

One CIDR zone per line, in exactly the form it appears in the
portals' `cidr4` arrays (the comparison is **byte-for-byte**, no
normalisation). Blank lines and `#` comments are skipped:

```
# Akamai
23.32.0.0/11
23.192.0.0/11
104.64.0.0/10

# Google
172.217.0.0/16
216.58.192.0/19
```

## What happens next

Restart the server. On the next `reload` cycle, with
`SYS_REPLACE_ESCALATE_IPS=true` (the default), the empty value arrays
will fill up with `/32` entries pulled from each portal's `ip4`.
If `SYS_REPLACE_AGGREGATE_SUBNETS=true` and the
`SYS_REPLACE_COLLAPSE_THRESHOLD_*` thresholds are set, supernet
aggregation and density collapse will also kick in. Full story in
[../REPLACE.en.md](../REPLACE.en.md).

## Guards against malformed input

-   Config file whose top level is not an `object` — skipped with a
    message on `stderr`.
-   `replace`, `replace.cidr4`, or `replace.cidr6` present but not an
    `object` — skipped with a message. Hand-broken structures are
    flagged, not silently overwritten.
-   Empty or missing `cidr4` on a portal — portal is left alone.
-   A zone from `cidr4.txt` that doesn't match any portal — silently
    ignored.

## When NOT to run

-   If `cidr4.txt` is actively being edited / under a version-control
    review — wait until the list stabilises.
-   If the server is mid-`reload` for a large portal — the script
    could overwrite a config on top of fresh escalation output.
    Stop the service or wait for a quiet moment.
-   Repeatedly "just in case" — the script is idempotent, but file
    `mtime`s shift on every run.
