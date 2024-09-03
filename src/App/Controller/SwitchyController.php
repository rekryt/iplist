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
        if (count($sites)) {
            foreach ($sites as $site) {
                $domains = array_merge(
                    $domains,
                    array_map($this->wildcardFormat(...), $this->getSites()[$site]->$data)
                );
            }
        } else {
            foreach ($this->getSites() as $siteEntity) {
                $domains = array_merge($domains, array_map($this->wildcardFormat(...), $siteEntity->$data));
            }
        }

        $response = array_merge($response, SiteFactory::normalizeArray($domains));
        $response = array_merge($response, ['', '#END']);

        return implode("\n", $response);
    }

    /**
     * @param string $domain
     * @return string
     */
    private function wildcardFormat(string $domain): string {
        return '*' . $domain . '/*';
    }
}
