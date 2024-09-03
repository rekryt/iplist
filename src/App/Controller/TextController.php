<?php

namespace OpenCCK\App\Controller;

use OpenCCK\Domain\Factory\SiteFactory;

class TextController extends AbstractIPListController {
    const DELIMITER = "\n";
    /**
     * @return string
     */
    public function getBody(): string {
        $this->setHeaders(['content-type' => 'text/plain']);

        $sites = SiteFactory::normalizeArray($this->request->getQueryParameters()['site'] ?? []);
        $data = $this->request->getQueryParameter('data') ?? '';
        if ($data == '') {
            return "# Error: The 'data' GET parameter is required in the URL to access this page, but it cannot have the value 'All'";
        }

        $response = [];
        if (count($sites)) {
            foreach ($sites as $site) {
                $response = array_merge($response, $this->getSites()[$site]->$data);
            }
        } else {
            foreach ($this->getSites() as $siteEntity) {
                $response = array_merge($response, $siteEntity->$data);
            }
        }

        return implode(
            $this::DELIMITER,
            SiteFactory::normalizeArray($response, in_array($data, ['ipv4', 'ipv6', 'cidr4', 'cidr6']))
        );
    }
}
