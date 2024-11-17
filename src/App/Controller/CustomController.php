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
        foreach ($this->getGroups() as $groupName => $groupSites) {
            if (count($sites)) {
                $groupSites = array_filter($groupSites, fn(Site $siteEntity) => in_array($siteEntity->name, $sites));
            }
            if (!count($groupSites)) {
                continue;
            }

            $items = [];
            foreach ($groupSites as $siteName => $siteEntity) {
                $items = array_merge(
                    $items,
                    $this->generateList(
                        $siteEntity,
                        SiteFactory::normalizeArray(
                            $siteEntity->{$data},
                            in_array($data, ['ip4', 'ip6', 'cidr4', 'cidr6'])
                        ),
                        $data,
                        $template
                    )
                );
            }

            $response = array_merge($response, $items);
        }

        return implode("\n", $response);
    }

    /**
     * @param Site $siteEntity
     * @param array $dataArray
     * @param string $data
     * @param string $template
     * @return array
     */
    private function generateList(Site $siteEntity, array $dataArray, string $data, string $template): array {
        $items = [];
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
            $items[] = $result;
        }
        return $items;
    }
}
