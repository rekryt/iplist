<?php

namespace OpenCCK\App\Controller;

use OpenCCK\Domain\Factory\SiteFactory;

class SwitchyController extends AbstractIPListController {
    /**
     * @return string
     */
    public function getBody(): string {
        $this->setHeaders(['content-type' => 'text/plain']);

        $sites = SiteFactory::normalizeArray($this->request->getQueryParameters()['site'] ?? []);
        $data = $this->request->getQueryParameter('data') ?? '';
        if ($data != 'domains') {
            return "# Error: The 'data' GET parameter is must be 'domains'";
        }

        $response = [
            '; Switchy - RuleList',
            '; Date: ' . date('Y-m-d'),
            '; URL: ' .
            $this->getBaseURL() .
            '/?format=switchy&data=domains' .
            (count($sites)
                ? '&' . implode('&', array_map(fn(string $site) => 'site=' . urlencode($site), $sites))
                : ''),
            '',
            '#BEGIN',
            '',
            '[Wildcard]',
        ];

        $domains = [];
        $sitesEntities = $this->getSites();
        if (count($sites)) {
            foreach ($sites as $site) {
                foreach ($sitesEntities[$site]->$data as $row) {
                    $domains[] = '*' . $row . '/*';
                }
            }
        } else {
            foreach ($sitesEntities as $siteEntity) {
                foreach ($siteEntity->$data as $row) {
                    $domains[] = '*' . $row . '/*';
                }
            }
        }

        foreach (SiteFactory::normalizeArray($domains) as $row) {
            $response[] = $row;
        }
        $response[] = '';
        $response[] = '#END';

        return implode("\n", $response);
    }
}
