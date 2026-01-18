<?php

namespace OpenCCK\App\Controller;

use OpenCCK\Domain\Factory\SiteFactory;

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
                $response = array_merge($response, $sitesEntities[$site]->$data ?? []);
            }
        } else {
            foreach ($sitesEntities as $siteEntity) {
                $response = array_merge($response, $siteEntity->$data);
            }
        }
        $response = SiteFactory::normalizeArray($response, in_array($data, ['ipv4', 'ipv6', 'cidr4', 'cidr6']));

        return json_encode(
            array_map(fn(string $item) => ['hostname' => $item, 'ip' => ''], $response),
            JSON_PRETTY_PRINT
        );
    }
}
