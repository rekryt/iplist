<?php

namespace OpenCCK\App\Controller;

use OpenCCK\Domain\Entity\Site;
use OpenCCK\Domain\Factory\SiteFactory;
use OpenCCK\Domain\Helper\IP4Helper;
use OpenCCK\Domain\Helper\IP6Helper;

class MikrotikController extends AbstractIPListController {
    /**
     * @return string
     */
    public function getBody(): string {
        $this->setHeaders(['content-type' => 'text/plain']);

        $sites = SiteFactory::normalizeArray($this->request->getQueryParameters()['site'] ?? []);
        $data = $this->request->getQueryParameter('data') ?? '';
        $append = $this->request->getQueryParameter('append') ?? '';
        $template = $this->request->getQueryParameter('template') ?? '{group}_{data}';
        if ($data == '') {
            return "# Error: The 'data' GET parameter is required in the URL to access this page";
        }
        $appendStr = $append ? ' ' . $append : '';

        $lists = [];
        foreach ($this->getGroups() as $groupName => $groupSites) {
            if (count($sites)) {
                $groupSites = array_filter($groupSites, fn(Site $siteEntity) => in_array($siteEntity->name, $sites));
            }
            if (!count($groupSites)) {
                continue;
            }

            $listName = str_replace(['{group}', '{data}'], [$groupName, $data], $template);

            // Flip-map dedup — $seen is reset per group so the "one add per IP
            // per list" rule still holds across sites in the same group, without
            // the O(N²) in_array scan. For cidr4/cidr6 the rows come from
            // `resolvedCidr` (replace substitution applied) and are minimized
            // PER SITE, not cross-site: otherwise one site's /16 would absorb
            // another site's /32 and the corresponding `comment=<site>` line
            // would vanish — the atribution is worth the occasional overlap.
            $items = [];
            $seen = [];
            foreach ($groupSites as $siteName => $siteEntity) {
                if (count($sites) && !in_array($siteName, $sites)) {
                    continue;
                }
                $rows = $this->siteRows($siteEntity, $data);
                foreach ($rows as $row) {
                    if (isset($seen[$row])) {
                        continue;
                    }
                    $seen[$row] = true;
                    $items[] =
                        'add list=' . $listName . ' address=' . $row . ' comment=' . $siteEntity->name . $appendStr;
                }
            }

            // Skip empty list blocks entirely — the trailing-";" line below
            // assumes at least one entry. Appending to index -1 of an empty
            // array raises "Undefined array key -1" and surfaces as HTTP 500
            // once the warning is promoted to a throwable.
            if (!$items) {
                continue;
            }
            $items[array_key_last($items)] .= ';';

            if (!isset($lists[$listName])) {
                $lists[$listName] = [];
            }
            foreach ($items as $item) {
                $lists[$listName][] = $item;
            }
        }

        $response = [];
        foreach ($lists as $listName => $items) {
            $response[] = '/ip firewall address-list remove [find list="' . $listName . '"];';
            $response[] = ':delay 5s';
            $response[] = '';
            $response[] = '/ip firewall address-list';
            foreach ($items as $item) {
                $response[] = $item;
            }
            $response[] = '';
            $response[] = '';
        }

        return implode("\n", $response);
    }

    /**
     * Per-site row source. For cidr4/cidr6 we only pay for `applyReplace` +
     * per-site `minimizeSubnets` when the site actually declares a `replace`
     * block — otherwise the raw property is returned as-is. Skipping the
     * O(N²) minimize pass here is the whole point of the `hasReplace` probe:
     * both `$site->cidr4` and `$site->cidr6` are already minimized at load
     * by `SiteFactory::create` and kept minimized across `reload`/
     * `reloadExternal`, so returning them directly is safe.
     *
     * @return array<int, string>
     */
    private function siteRows(Site $site, string $data): array {
        if ($data === 'cidr4') {
            return $site->hasReplace('cidr4') && !$this->native
                ? IP4Helper::minimizeSubnets($this->resolvedCidr($site, 'cidr4'))
                : $this->resolvedCidr($site, 'cidr4');
        }
        if ($data === 'cidr6') {
            return $site->hasReplace('cidr6') && !$this->native
                ? IP6Helper::minimizeSubnets($this->resolvedCidr($site, 'cidr6'))
                : $this->resolvedCidr($site, 'cidr6');
        }
        return $site->$data ?? [];
    }
}
