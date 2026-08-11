<?php

namespace OpenCCK\App\Controller;

use OpenCCK\Domain\Factory\SiteFactory;
use OpenCCK\Domain\Helper\IP4Helper;

class PacController extends AbstractIPListController {
    /**
     * @return string
     */
    public function getBody(): string {
        $this->setHeaders(['content-type' => 'text/javascript']);

        $sites = SiteFactory::normalizeArray($this->request->getQueryParameters()['site'] ?? []);
        $data = $this->request->getQueryParameter('data') ?? '';
        $template = $this->request->getQueryParameter('template') ?? 'PROXY 127.0.0.1:2080; DIRECT';
        if (!in_array($data, ['domains', 'cidr4'])) {
            return "# Error: The 'data' GET parameter is must be 'domains' or 'cidr4'";
        }

        $items = [];
        $sitesEntities = $this->getSites();
        $isCidr4 = $data === 'cidr4';
        if (count($sites)) {
            foreach ($sites as $site) {
                $entity = $sitesEntities[$site] ?? null;
                if ($entity === null) {
                    continue;
                }
                $rows = $isCidr4 ? $this->resolvedCidr($entity, 'cidr4') : $entity->$data;
                foreach ($rows as $row) {
                    $items[] = $row;
                }
            }
        } else {
            foreach ($sitesEntities as $siteEntity) {
                $rows = $isCidr4 ? $this->resolvedCidr($siteEntity, 'cidr4') : $siteEntity->$data;
                foreach ($rows as $row) {
                    $items[] = $row;
                }
            }
        }

        if ($data === 'cidr4') {
            $items = IP4Helper::minimizeSubnets($items);
        }

        $response = ['const items = ['];
        $response[] = implode(
            ",\n",
            $data == 'cidr4'
                ? array_map(function (string $item) {
                    $parts = explode('/', $item);
                    $mask = IP4Helper::formatShortIpMask($parts[1] ?? '');
                    return '["' . $parts[0] . '","' . $mask . '"]';
                }, SiteFactory::normalizeArray($items, true))
                : array_map(fn(string $item) => '"' . $item . '"', SiteFactory::normalizeArray($items))
        );
        $response[] = '];';

        if ($data == 'cidr4') {
            $response = array_merge($response, [
                'function FindProxyForURL(url, host) {',
                '    for (cidr of items) {',
                '        if (isInNet(host, cidr[0], cidr[1])) {',
                '            return "' . $template . '";',
                '        }',
                '    }',
                '',
                '    return "DIRECT";',
                '}',
            ]);
        } else {
            $response = array_merge($response, [
                'function FindProxyForURL(url, host) {',
                '    for (domain of items) {',
                '        if (host === domain || shExpMatch(host, "*." + domain)) {',
                '            return "' . $template . '";',
                '        }',
                '    }',
                '',
                '    return "DIRECT";',
                '}',
            ]);
        }

        return implode("\n", $response);
    }
}
