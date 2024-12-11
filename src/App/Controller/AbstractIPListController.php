<?php

namespace OpenCCK\App\Controller;

use Amp\ByteStream\BufferException;
use Amp\Http\Server\Request;

use OpenCCK\App\Service\IPListService;
use OpenCCK\Domain\Entity\Site;
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
        $wildcard = !!($this->request->getQueryParameter('wildcard') ?? '');
        $exclude = [
            'group' => $this->request->getQueryParameterArray('exclude[group]') ?? [],
            'site' => $this->request->getQueryParameterArray('exclude[site]') ?? [],
            'domain' => $this->request->getQueryParameterArray('exclude[domain]') ?? [],
            'ip4' => $this->request->getQueryParameterArray('exclude[ip4]') ?? [],
            'cidr4' => $this->request->getQueryParameterArray('exclude[cidr4]') ?? [],
            'ip6' => $this->request->getQueryParameterArray('exclude[ip6]') ?? [],
            'cidr6' => $this->request->getQueryParameterArray('exclude[cidr6]') ?? [],
        ];
        $group = $this->request->getQueryParameterArray('group') ?? [];
        return array_map(static function (Site $siteEntity) use ($wildcard, $exclude) {
            $site = clone $siteEntity;
            $site->domains = array_values(
                array_filter(
                    $siteEntity->getDomains($wildcard),
                    fn(string $domain) => !in_array($domain, $exclude['domain'])
                )
            );
            $site->ip4 = array_values(array_filter($site->ip4, fn(string $ip) => !in_array($ip, $exclude['ip4'])));
            $site->cidr4 = array_values(
                array_filter($site->cidr4, fn(string $ip) => !in_array($ip, $exclude['cidr4']))
            );
            $site->ip6 = array_values(array_filter($site->ip6, fn(string $ip) => !in_array($ip, $exclude['ip6'])));
            $site->cidr6 = array_values(
                array_filter($site->cidr6, fn(string $ip) => !in_array($ip, $exclude['cidr6']))
            );

            return $site;
        }, array_filter(
            array_filter(
                $this->service->sites,
                fn(Site $siteEntity) => !in_array($siteEntity->name, $exclude['site']) &&
                    !in_array($siteEntity->group, $exclude['group'])
            ),
            fn(Site $siteEntity) => count($group) === 0 || in_array($siteEntity->group, $group)
        ));
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
