<?php

namespace OpenCCK\App\Controller;

use Amp\ByteStream\BufferException;
use Amp\Http\Server\Request;

use OpenCCK\App\Service\IPListService;
use OpenCCK\Domain\Entity\Site;
use OpenCCK\Domain\Factory\SiteFactory;
use OpenCCK\Domain\Helper\IP4Helper;
use OpenCCK\Domain\Helper\IP6Helper;
use OpenCCK\Infrastructure\API\App;

use Monolog\Logger;
use Throwable;

abstract class AbstractIPListController extends AbstractController {
    protected Logger $logger;
    protected IPListService $service;

    /**
     * `?native=1` — return raw `cidr4`/`cidr6` without the `replace`
     * substitution. Cached once in the constructor because `siteRows`/
     * `applyReplace` sites in hot per-site loops; re-reading the query
     * parameter array on every iteration is wasted work.
     */
    protected readonly bool $native;

    /**
     * `exclude[cidr4]` / `exclude[cidr6]` materialized as lookup maps.
     * Applied both pre-replace in `getSites()` (to drop key zones and to
     * keep `JsonController`'s raw view honest) and post-replace inside
     * `resolvedCidr()` — without the post-replace pass, exclude can't
     * match CIDRs that only appear after `applyReplace` substitutes a
     * parent zone with its narrower children.
     *
     * @var array<string, true>
     */
    protected readonly array $excludeCidr4;
    /** @var array<string, true> */
    protected readonly array $excludeCidr6;

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
        $this->native = !!($this->request->getQueryParameter('native') ?? '');
        $this->excludeCidr4 = array_fill_keys($this->request->getQueryParameterArray('exclude[cidr4]') ?? [], true);
        $this->excludeCidr6 = array_fill_keys($this->request->getQueryParameterArray('exclude[cidr6]') ?? [], true);

        $isFileSave = !!($this->request->getQueryParameter('filesave') ?? '');
        if ($isFileSave) {
            $map = [
                'json' => 'json',
                'amnezia' => 'json',
                'bat' => 'bat',
            ];
            $ext = $map[$this->request->getQueryParameter('format')] ?? 'txt';
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
            'ip6' => $this->request->getQueryParameterArray('exclude[ip6]') ?? [],
        ]);
        $exclude['cidr4'] = $this->excludeCidr4;
        $exclude['cidr6'] = $this->excludeCidr6;

        // Skip clone + field materialization when no per-field mutation
        // is requested. For the common case (format=mikrotik&data=ip4 without
        // any exclude[*] or wildcard=1) this avoids allocating fresh copies
        // of domains/ip4/cidr4/ip6/cidr6 for every site on every request.
        $needsFieldMutation =
            $wildcard ||
            count($exclude['domain']) > 0 ||
            count($exclude['ip4']) > 0 ||
            count($exclude['cidr4']) > 0 ||
            count($exclude['ip6']) > 0 ||
            count($exclude['cidr6']) > 0;

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
            if (!$needsFieldMutation) {
                $sites[$siteEntity->name] = $siteEntity;
                continue;
            }

            $site = clone $siteEntity;
            if ($wildcard || count($exclude['domain']) > 0) {
                $site->domains = array_values(
                    count($exclude['domain'])
                        ? array_filter(
                            $siteEntity->getDomains($wildcard),
                            fn(string $domain) => !isset($exclude['domain'][$domain])
                        )
                        : $siteEntity->getDomains($wildcard)
                );
            }
            if (count($exclude['ip4']) > 0) {
                $site->ip4 = array_values(array_filter($site->ip4, fn(string $ip) => !isset($exclude['ip4'][$ip])));
            }
            if (count($exclude['cidr4']) > 0) {
                $site->cidr4 = array_values(
                    array_filter($site->cidr4, fn(string $ip) => !isset($exclude['cidr4'][$ip]))
                );
            }
            if (count($exclude['ip6']) > 0) {
                $site->ip6 = array_values(array_filter($site->ip6, fn(string $ip) => !isset($exclude['ip6'][$ip])));
            }
            if (count($exclude['cidr6']) > 0) {
                $site->cidr6 = array_values(
                    array_filter($site->cidr6, fn(string $ip) => !isset($exclude['cidr6'][$ip]))
                );
            }

            $sites[$site->name] = $site;
        }

        return $sites;
    }

    /**
     * Returns the site's CIDR list with the `replace` substitution applied.
     * Pure read helper — does not mutate the Site. Does NOT run
     * minimizeSubnets on the result; callers that aggregate across sites
     * are expected to minimize at the aggregation layer.
     *
     * Fast path: when the site has no replacement configured for the
     * requested field, returns the raw property array (no allocation,
     * no helper call). Callers can further skip per-site `minimizeSubnets`
     * by checking `$site->hasReplace($field)` themselves — both cidr4 and
     * cidr6 are already minimized at load by `SiteFactory::create` and
     * remain minimized across `reload`/`reloadExternal`.
     *
     * @param Site $site
     * @param string $field `cidr4` or `cidr6`
     * @return array<int, string>
     */
    protected function resolvedCidr(Site $site, string $field): array {
        $excludes = $field === 'cidr4' ? $this->excludeCidr4 : $this->excludeCidr6;

        if ($this->native || !$site->hasReplace($field)) {
            return $site->{$field};
        }

        $resolved =
            $field === 'cidr4'
                ? IP4Helper::applyReplace($site->cidr4, $site->replace)
                : IP6Helper::applyReplace($site->cidr6, $site->replace);

        if (!$excludes) {
            return $resolved;
        }
        $filtered = [];
        foreach ($resolved as $cidr) {
            if (!isset($excludes[$cidr])) {
                $filtered[] = $cidr;
            }
        }
        return $filtered;
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
