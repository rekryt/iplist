<?php

namespace OpenCCK\App\Controller;

use OpenCCK\Domain\Entity\Site;
use OpenCCK\Domain\Factory\SiteFactory;
use OpenCCK\Domain\Helper\IP4Helper;
use OpenCCK\Domain\Helper\IP6Helper;

class CustomController extends AbstractIPListController {
    /**
     * @return string
     */
    public function getBody(): string {
        $this->setHeaders(['content-type' => 'text/plain']);

        $sites = SiteFactory::normalizeArray($this->request->getQueryParameters()['site'] ?? []);
        $data = $this->request->getQueryParameter('data') ?? '';
        $template = $this->request->getQueryParameter('template') ?? '';
        if ($data == '') {
            return "# Error: The 'data' GET parameter is required in the URL to access this page";
        }
        if ($template == '') {
            return "# Error: The 'template' GET parameter is required in the URL to access this page";
        }

        $response = [];
        $isIpData = in_array($data, ['ip4', 'ip6', 'cidr4', 'cidr6']);
        foreach ($this->getGroups() as $groupName => $groupSites) {
            if (count($sites)) {
                $groupSites = array_filter($groupSites, fn(Site $siteEntity) => in_array($siteEntity->name, $sites));
            }
            if (!count($groupSites)) {
                continue;
            }

            foreach ($groupSites as $siteName => $siteEntity) {
                // Skip per-site minimize for the common no-replace case — cidr4
                // is already minimized at load, cidr6 is kept raw by design.
                $rawRows = match (true) {
                    $data === 'cidr4' && !$this->native && $siteEntity->hasReplace('cidr4')
                        => IP4Helper::minimizeSubnets(
                        IP4Helper::applyReplace($siteEntity->cidr4, $siteEntity->replace)
                    ),
                    $data === 'cidr6' && !$this->native && $siteEntity->hasReplace('cidr6')
                        => IP6Helper::minimizeSubnets(
                        IP6Helper::applyReplace($siteEntity->cidr6, $siteEntity->replace)
                    ),
                    default => $siteEntity->{$data},
                };
                $this->appendRenderedRows(
                    $response,
                    $siteEntity,
                    SiteFactory::normalizeArray($rawRows, $isIpData),
                    $data,
                    $template
                );
            }
        }

        return implode("\n", $response);
    }

    /**
     * @param array<int, string> $out
     */
    private function appendRenderedRows(
        array &$out,
        Site $siteEntity,
        array $dataArray,
        string $data,
        string $template
    ): void {
        foreach ($dataArray as $item) {
            $patterns = [
                'group' => $siteEntity->group,
                'site' => $siteEntity->name,
                'data' => $item,
            ];
            switch ($data) {
                case 'ip4':
                    $patterns['shortmask'] = '32';
                    $patterns['mask'] = IP4Helper::formatShortIpMask($patterns['shortmask']);
                    break;
                case 'ip6':
                    $patterns['shortmask'] = '128';
                    $patterns['mask'] = IP6Helper::formatShortIpMask($patterns['shortmask']);
                    break;
                case 'cidr4':
                    $parts = explode('/', $item);
                    $patterns['shortmask'] = $parts[1];
                    $patterns['mask'] = IP4Helper::formatShortIpMask($patterns['shortmask']);
                    break;
                case 'cidr6':
                    $parts = explode('/', $item);
                    $patterns['shortmask'] = $parts[1];
                    $patterns['mask'] = IP6Helper::formatShortIpMask($patterns['shortmask']);
                    break;
                case 'domains':
                    break;
            }

            $result = $template;
            foreach ($patterns as $key => $value) {
                $result = str_replace('{' . $key . '}', $value, $result);
            }
            $out[] = $result;
        }
    }
}
