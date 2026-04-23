<?php

namespace OpenCCK\App\Controller;

use OpenCCK\Domain\Factory\SiteFactory;
use OpenCCK\Domain\Helper\IP4Helper;

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
                $response = array_merge($response, $siteEntity->$data ?? []);
            }
        }

        if ($data === 'cidr4') {
            $response = IP4Helper::minimizeSubnets($response);
        }

        return $this->render(
            SiteFactory::normalizeArray($response, in_array($data, ['ip4', 'ip6', 'cidr4', 'cidr6']))
        );
    }

    /**
     * @param array $response
     * @return string
     */
    protected function render(array $response): string {
        return implode($this::DELIMITER, $response);
    }
}
