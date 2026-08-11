# Overlapping CIDR zone replacement

[Для русской версии: REPLACE.md](REPLACE.md)

Some CIDR zones are shared between multiple portals (for instance,
`172.217.0.0/16` belongs to both `google` and `yandex` — whois
cannot split them automatically). To avoid routing unrelated
traffic when serving one portal's list, you can
declare targeted substitutions in the portal's config.

## Syntax

```json
{
    ...
    "replace": {
        "cidr4": {
            "172.217.0.0/16": ["172.217.17.206/32", "172.217.17.207/32", "172.217.18.0/24"]
        },
        "cidr6": {
            "2001::/32": ["2001:4860::/32"]
        }
    }
}
```

![Image](https://github.com/user-attachments/assets/6d04bd96-fb47-4da9-ae16-f2ba71cb49cc)

`replace.cidr4` / `replace.cidr6` keys are CIDR zones that should be
**removed** from this portal's `cidr4`/`cidr6` output. Values are
arrays of CIDR zones or masked IP addresses that are **substituted
in place of the key zone**.

An invalid structure (value is not an `object` / not an `array` /
not a string) triggers a load-time error and halts the server —
typos shouldn't fail silently.

## Two phases

### Reload-time (during portal data refresh)

At the end of each `reload` cycle, when `SYS_REPLACE_ESCALATE_IPS=true`
(default `true`), the service walks the keys of `replace.cidr4` and
`replace.cidr6` and **appends** to each value array every `ip4`/`ip6`
entry of the portal that falls inside the key zone, masked as `/32`
(or `/128` for v6). Each value array is then run through
`minimizeSubnets` — entries absorbed by a narrower admin-provided
zone disappear, and a repeated `reload` never grows duplicates. The
result is persisted to the portal's JSON config.

Value arrays therefore grow over time as DNS resolution turns up new
portal IPs inside key zones.

### View-time (when answering HTTP clients)

Every output format **except `json`** strips the `replace` keys from
`cidr4`/`cidr6` and substitutes the value arrays instead. After
cross-site aggregation the result is passed through `minimizeSubnets`
so the response contains no duplicates.

`json` deliberately returns the raw portal state along with the
`replace` block — clients decide how to interpret it.

## `SYS_REPLACE_AGGREGATE_SUBNETS` — lossless aggregation

Default `false`.

When `SYS_REPLACE_AGGREGATE_SUBNETS=true`, value arrays go through
**supernet aggregation** instead of plain `minimizeSubnets`:

-   256 consecutive `/32` entries collapse into a single `/24`;
-   two adjacent `/24` blocks fuse into a `/23`;
-   and so on.

Aggregation is capped by the parent key's prefix — the emitted
blocks are always strictly narrower than the key itself, so the
result can never be the pointless `"1.0.0.0/8": ["1.0.0.0/8"]`.

This is **lossless**: the covered addresses stay the same, only
their representation changes.

Worth enabling for portals with thousands of IPs (Adobe, Google,
etc.) where unaggregated value arrays would balloon into thousands
of `/32` lines.

## `SYS_REPLACE_COLLAPSE_THRESHOLD_*` — lossy density collapse

Four parameters, all default to `0` (disabled):

| ENV                                     | Tier      | Bucket |
| --------------------------------------- | --------- | ------ |
| `SYS_REPLACE_COLLAPSE_THRESHOLD_IP4_24` | v4 narrow | `/24`  |
| `SYS_REPLACE_COLLAPSE_THRESHOLD_IP4_16` | v4 wide   | `/16`  |
| `SYS_REPLACE_COLLAPSE_THRESHOLD_IP6_64` | v6 narrow | `/64`  |
| `SYS_REPLACE_COLLAPSE_THRESHOLD_IP6_32` | v6 wide   | `/32`  |

Each value is the **minimum address count** in a bucket required to
trigger collapse. For `_IP4_24`: if a `/24` has ≥ N covered addresses,
the whole `/24` is claimed as belonging to the portal. Same pattern
for `/16`, `/64`, `/32`.

Tiers run **pyramid-style**:

1. The **narrow** tier (`/24` or `/64`) runs first, inflating dense
   buckets to their full size.
2. The **wider** tier (`/16` or `/32`) runs next, counting coverage
   over the post-narrow output, so tightly-packed narrow-tier
   expansions feed into the wider accounting.

Each tier is **skipped** when the parent key's prefix is already
at or below its bucket — there's no room for a bucket of equal or
larger size inside a narrower parent.

**This is `lossy`**: unrelated IPs sharing the same bucket
(typically CDN / datacenter neighbours) get routed along with the
portal. Thresholds are the accuracy-vs-size trade-off knob.

Only active when `SYS_REPLACE_AGGREGATE_SUBNETS=true`.

## Bulk-marking portals

If you already have a list of "wide" CIDR zones that show up in
several portals, a single script can seed empty keys in
`replace.cidr4` across every affected portal at once — from there,
`reload` with escalation enabled will fill the value arrays on its
own.

The tool and its usage instructions live in
[intersection/README.en.md](intersection/README.en.md).
