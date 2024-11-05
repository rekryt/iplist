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
        if ($data == '') {
            return "# Error: The 'data' GET parameter is required in the URL to access this page";
        }

        $response = [];
        foreach ($this->getGroups() as $groupName => $groupSites) {
            if (count($sites)) {
                $groupSites = array_filter($groupSites, fn(Site $siteEntity) => in_array($siteEntity->name, $sites));
            }
            if (!count($groupSites)) {
                continue;
            }

            $listName = $groupName . '_' . $data;
            $response = array_merge($response, [
                '/ip firewall address-list remove [find list="' . $listName . '"];',
                ':delay 5s',
                '',
                '/ip firewall address-list',
            ]);
            $items = [];
            $entries = [];
            foreach ($groupSites as $siteName => $siteEntity) {
                if (count($sites) && !in_array($siteName, $sites)) {
                    continue;
                }
                $filteredItems = array_filter($siteEntity->$data, fn(string $row) => !in_array($row, $entries));
                $items = array_merge(
                    $items,
                    $this->generateList($siteEntity, $listName, $filteredItems, $append ? ' ' . $append : '')
                );
                $entries = array_merge($entries, $filteredItems);
            }
            $items = SiteFactory::normalizeArray($items, in_array($data, ['ip4', 'ip6', 'cidr4', 'cidr6']));
            $items[count($items) - 1] = $items[count($items) - 1] . ';';

            $response = array_merge($response, $items, ['', '']);
        }

        return implode("\n", $response);
    }

    /**
     * @param Site $siteEntity
     * @param string $listName
     * @param array $array
     * @param string $append
     * @return array
     */
    private function generateList(Site $siteEntity, string $listName, array $array, string $append = ''): array {
        $items = [];
        foreach ($array as $item) {
            $items[] = 'add list=' . $listName . ' address=' . $item . ' comment=' . $siteEntity->name . $append;
        }
        return $items;
    }
}
