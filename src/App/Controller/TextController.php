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
            return "# Error: The 'data' GET parameter is required in the URL to access this page";
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

        return $this->render(
            SiteFactory::normalizeArray($response, in_array($data, ['ipv4', 'ipv6', 'cidr4', 'cidr6']))
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
