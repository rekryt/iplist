<?php

namespace OpenCCK\App\Controller;

use OpenCCK\Domain\Factory\SiteFactory;
use OpenCCK\Domain\Helper\IP4Helper;

/**
 * @see https://help.keenetic.com/hc/ru/articles/213966749-%D0%94%D0%BE%D0%B1%D0%B0%D0%B2%D0%BB%D0%B5%D0%BD%D0%B8%D0%B5-%D1%81%D1%82%D0%B0%D1%82%D0%B8%D1%87%D0%B5%D1%81%D0%BA%D0%B8%D1%85-%D0%BC%D0%B0%D1%80%D1%88%D1%80%D1%83%D1%82%D0%BE%D0%B2-%D0%B8%D0%B7-%D1%84%D0%B0%D0%B9%D0%BB%D0%B0-bat-%D0%B2-%D0%B8%D0%BD%D1%82%D0%B5%D1%80%D0%BD%D0%B5%D1%82-%D1%86%D0%B5%D0%BD%D1%82%D1%80-%D0%B4%D0%BB%D1%8F-%D0%B2%D0%B5%D1%80%D1%81%D0%B8%D0%B9-NDMS-2-11-%D0%B8-%D0%B1%D0%BE%D0%BB%D0%B5%D0%B5-%D1%80%D0%B0%D0%BD%D0%BD%D0%B8%D1%85
 */
class BatController extends AbstractIPListController {
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
        if (!in_array($data, ['ip4', 'cidr4'])) {
            return "# Error: The 'data' GET parameter must be 'ip4' or 'cidr4'";
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
        $response = SiteFactory::normalizeArray($response, true);

        return implode(
            "\n",
            array_map(function (string $item) {
                $parts = explode('/', $item);
                $mask = IP4Helper::formatShortIpMask($parts[1] ?? '');
                return 'route add ' . $parts[0] . ' mask ' . $mask . ' 0.0.0.0';
            }, $response)
        );
    }
}
