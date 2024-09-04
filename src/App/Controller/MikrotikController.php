<?php

namespace OpenCCK\App\Controller;

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
        if (count($sites)) {
            foreach ($sites as $site) {
                $response = array_merge($response, $this->generateList($site, $this->getSites()[$site]->$data));
            }
        } else {
            foreach ($this->getSites() as $siteEntity) {
                $response = array_merge($response, $this->generateList($siteEntity->name, $siteEntity->$data));
            }
        }

        return implode(
            "\n",
            array_merge(
                ['/ip firewall address-list'],
                SiteFactory::normalizeArray($response, in_array($data, ['ipv4', 'ipv6', 'cidr4', 'cidr6']))
            )
        );
    }

    /**
     * @param string $site
     * @param array $array
     * @return array
     */
    private function generateList(string $site, array $array): array {
        $response = [];
        $listName = str_replace(' ', '', $site);
        foreach ($array as $item) {
            $response[] = 'add list=' . $listName . ' address=' . $item . ' comment=' . $listName;
        }
        return $response;
    }
}
