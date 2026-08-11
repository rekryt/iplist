<?php

namespace OpenCCK\App\Controller;

use OpenCCK\Domain\Entity\Site;
use OpenCCK\Domain\Factory\SiteFactory;
use OpenCCK\Domain\Helper\IP4Helper;
use OpenCCK\Domain\Helper\IP6Helper;

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

        $isCidrField = $data === 'cidr4' || $data === 'cidr6';
        $response = [];
        $sitesEntities = $this->getSites();
        if (count($sites)) {
            foreach ($sites as $site) {
                $entity = $sitesEntities[$site] ?? null;
                if ($entity === null) {
                    continue;
                }
                foreach ($this->rowsFor($entity, $data, $isCidrField) as $row) {
                    $response[] = $row;
                }
            }
        } else {
            foreach ($sitesEntities as $siteEntity) {
                foreach ($this->rowsFor($siteEntity, $data, $isCidrField) as $row) {
                    $response[] = $row;
                }
            }
        }
        $response = SiteFactory::normalizeArray($response, in_array($data, ['ip4', 'ip6', 'cidr4', 'cidr6']));

        if ($data === 'cidr4') {
            $response = IP4Helper::minimizeSubnets($response);
        } elseif ($data === 'cidr6') {
            $response = IP6Helper::minimizeSubnets($response);
        }

        return json_encode(
            array_map(fn(string $item) => ['hostname' => $item, 'ip' => ''], $response),
            JSON_PRETTY_PRINT
        );
    }

    /**
     * @return array<int, string>
     */
    private function rowsFor(Site $site, string $data, bool $isCidrField): array {
        if ($isCidrField) {
            return $this->resolvedCidr($site, $data);
        }
        return $site->$data ?? [];
    }
}
