<?php

namespace OpenCCK\App\Controller;

use OpenCCK\Domain\Entity\Site;
use OpenCCK\Domain\Factory\SiteFactory;

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
            // the O(N²) in_array scan.
            $items = [];
            $seen = [];
            foreach ($groupSites as $siteName => $siteEntity) {
                if (count($sites) && !in_array($siteName, $sites)) {
                    continue;
                }
                foreach ($siteEntity->$data as $row) {
                    if (isset($seen[$row])) {
                        continue;
                    }
                    $seen[$row] = true;
                    $items[] = 'add list=' . $listName . ' address=' . $row . ' comment=' . $siteEntity->name . $appendStr;
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
}
