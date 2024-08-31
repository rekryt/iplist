<?php

namespace OpenCCK\App\Controller;

use OpenCCK\Domain\Entity\Site;
use OpenCCK\Domain\Factory\SiteFactory;

class JsonController extends AbstractIPListController {
    /**
     * @return string
     */
    public function getBody(): string {
        $this->setHeaders(['content-type' => 'application/json']);

        $sites = SiteFactory::normalizeArray($this->request->getQueryParameters()['site'] ?? []);
        $data = $this->request->getQueryParameter('data') ?? '';

        if (count($sites)) {
            $items = array_filter($this->service->sites, fn(Site $siteEntity) => in_array($siteEntity->name, $sites));
        } else {
            $items = $this->service->sites;
        }
        return json_encode($data == '' ? $items : array_map(fn(Site $siteEntity) => $siteEntity->$data, $items));
    }
}
