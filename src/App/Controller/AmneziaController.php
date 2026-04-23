<?php

namespace OpenCCK\App\Controller;

use OpenCCK\Domain\Factory\SiteFactory;
use OpenCCK\Domain\Helper\IP4Helper;

class AmneziaController extends AbstractIPListController {
    /**
     * @return string
     */
    public function getBody(): string {
        $this->setHeaders(['content-type' => 'application/json']);

        $sites = SiteFactory::normalizeArray($this->request->getQueryParameters()['site'] ?? []);
        $data = $this->request->getQueryParameter('data') ?? '';
        if ($data == '') {
            return "# Error: The 'data' GET parameter is required in the URL to access this page";
        }

        $response = [];
        $sitesEntities = $this->getSites();
        if (count($sites)) {
            foreach ($sites as $site) {
                foreach ($sitesEntities[$site]->$data ?? [] as $row) {
                    $response[] = $row;
                }
            }
        } else {
            foreach ($sitesEntities as $siteEntity) {
                foreach ($siteEntity->$data as $row) {
                    $response[] = $row;
                }
            }
        }
        $response = SiteFactory::normalizeArray($response, in_array($data, ['ip4', 'ip6', 'cidr4', 'cidr6']));

        if ($data === 'cidr4') {
            $response = IP4Helper::minimizeSubnets($response);
        }

        return json_encode(
            array_map(fn(string $item) => ['hostname' => $item, 'ip' => ''], $response),
            JSON_PRETTY_PRINT
        );
    }
}
