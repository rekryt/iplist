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

            $response = array_merge($response, [
                '/ip firewall address-list remove [find list="' . $groupName . '"];',
                '/ip firewall address-list',
            ]);
            $items = [];
            foreach ($groupSites as $siteName => $siteEntity) {
                if (count($sites) && !in_array($siteName, $sites)) {
                    continue;
                }
                $items = array_merge($items, $this->generateList($siteEntity, $siteEntity->$data));
            }
            $items = SiteFactory::normalizeArray($items, in_array($data, ['ip4', 'ip6', 'cidr4', 'cidr6']));
            $items[count($items) - 1] = $items[count($items) - 1] . ';';

            $response = array_merge($response, $items, ['']);
        }

        return implode("\n", $response);
    }

    /**
     * @param Site $siteEntity
     * @param array $array
     * @return array
     */
    private function generateList(Site $siteEntity, array $array): array {
        $items = [];
        foreach ($array as $item) {
            $items[] = 'add list=' . $siteEntity->group . ' address=' . $item . ' comment=' . $siteEntity->name;
        }
        return $items;
    }
}
