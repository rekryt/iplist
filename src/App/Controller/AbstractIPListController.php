<?php

namespace OpenCCK\App\Controller;

use Amp\ByteStream\BufferException;
use Amp\Http\Server\Request;

use OpenCCK\App\Service\IPListService;
use OpenCCK\Domain\Entity\Site;
use OpenCCK\Domain\Factory\SiteFactory;
use OpenCCK\Infrastructure\API\App;

use Monolog\Logger;
use Throwable;

abstract class AbstractIPListController extends AbstractController {
    protected Logger $logger;
    protected IPListService $service;

    /**
     * @param Request $request
     * @param array $headers
     * @throws BufferException
     * @throws Throwable
     */
    public function __construct(protected Request $request, protected array $headers = []) {
        parent::__construct($request, $this->headers);

        $this->logger = App::getLogger();
        $this->service = IPListService::getInstance();

        $isFileSave = !!($this->request->getQueryParameter('filesave') ?? '');
        if ($isFileSave) {
            $ext = in_array($this->request->getQueryParameter('format'), ['json', 'amnezia']) ? 'json' : 'txt';
            $this->setHeaders(['content-disposition' => 'attachment; filename="ip-list.' . $ext . '"']);
        }
    }

    /**
     * @return string
     */
    abstract public function getBody(): string;

    /**
     * @return array<string, Site>
     */
    protected function getSites(): array {
        $querySites = SiteFactory::normalizeArray($this->request->getQueryParameters()['site'] ?? []);
        $wildcard = !!($this->request->getQueryParameter('wildcard') ?? '');
        $group = $this->request->getQueryParameterArray('group') ?? [];

        $exclude = array_map(fn($arr) => array_fill_keys($arr, true), [
            'group' => $this->request->getQueryParameterArray('exclude[group]') ?? [],
            'site' => $this->request->getQueryParameterArray('exclude[site]') ?? [],
            'domain' => $this->request->getQueryParameterArray('exclude[domain]') ?? [],
            'ip4' => $this->request->getQueryParameterArray('exclude[ip4]') ?? [],
            'cidr4' => $this->request->getQueryParameterArray('exclude[cidr4]') ?? [],
            'ip6' => $this->request->getQueryParameterArray('exclude[ip6]') ?? [],
            'cidr6' => $this->request->getQueryParameterArray('exclude[cidr6]') ?? [],
        ]);

        $sites = [];
        foreach ($this->service->sites as $siteEntity) {
            if (count($querySites) && !in_array($siteEntity->name, $querySites)) {
                continue;
            }
            if (isset($exclude['site'][$siteEntity->name])) {
                continue;
            }
            if (isset($exclude['group'][$siteEntity->group])) {
                continue;
            }
            if ($group && !in_array($siteEntity->group, $group, true)) {
                continue;
            }
            $site = clone $siteEntity;

            $site->domains = array_values(
                count($exclude['domain'])
                    ? array_filter(
                        $siteEntity->getDomains($wildcard),
                        fn(string $domain) => !isset($exclude['domain'][$domain])
                    )
                    : $siteEntity->getDomains($wildcard)
            );
            if (count($exclude['ip4'])) {
                $site->ip4 = array_values(array_filter($site->ip4, fn(string $ip) => !isset($exclude['ip4'][$ip])));
            }
            if (count($exclude['cidr4'])) {
                $site->cidr4 = array_values(
                    array_filter($site->cidr4, fn(string $ip) => !isset($exclude['cidr4'][$ip]))
                );
            }
            if (count($exclude['ip6'])) {
                $site->ip6 = array_values(array_filter($site->ip6, fn(string $ip) => !isset($exclude['ip6'][$ip])));
            }
            if (count($exclude['cidr6'])) {
                $site->cidr6 = array_values(
                    array_filter($site->cidr6, fn(string $ip) => !isset($exclude['cidr6'][$ip]))
                );
            }

            $sites[$site->name] = $site;
        }

        return $sites;
    }

    /**
     * @return array<string, array<string, Site>>
     */
    protected function getGroups(): array {
        $groups = [];
        foreach ($this->getSites() as $siteEntity) {
            $groups[$siteEntity->group][$siteEntity->name] = $siteEntity;
        }
        return $groups;
    }
}
