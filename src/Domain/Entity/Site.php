<?php

namespace OpenCCK\Domain\Entity;

use OpenCCK\Domain\Factory\SiteFactory;
use OpenCCK\Domain\Helper\DNSHelper;
use OpenCCK\Domain\Helper\IP4Helper;
use OpenCCK\Domain\Helper\IP6Helper;
use OpenCCK\Infrastructure\API\App;

use Revolt\EventLoop;
use stdClass;
use function Amp\async;
use function Amp\Future\await;
use function OpenCCK\getEnv;

final class Site {
    private DNSHelper $dnsHelper;
    private bool $isUseIpv6;
    private bool $isUseIpv4;

    /**
     * @param string $name Name of portal
     * @param string $group Group of portal
     * @param array $domains List of portal domains
     * @param array $dns List of DNS servers for updating IP addresses
     * @param int $timeout Time interval between domain IP address updates (seconds)
     * @param array $ip4 List of IPv4 addresses
     * @param array $ip6 List of IPv6 addresses
     * @param array $cidr4 List of CIDRv4 zones of IPv4 addresses
     * @param array $cidr6 List of CIDRv6 zones of IPv6 addresses
     * @param object $external Lists of URLs to retrieve  data from external sources
     *
     */
    public function __construct(
        public string $name,
        public string $group,
        public array $domains = [],
        public array $dns = [],
        public int $timeout = 1440 * 60,
        public array $ip4 = [],
        public array $ip6 = [],
        public array $cidr4 = [],
        public array $cidr6 = [],
        public object $external = new stdClass()
    ) {
        $this->dnsHelper = new DNSHelper($dns);
        $this->isUseIpv4 = (getEnv('SYS_DNS_RESOLVE_IP4') ?? 'true') == 'true';
        $this->isUseIpv6 = (getEnv('SYS_DNS_RESOLVE_IP6') ?? 'true') == 'true';
    }

    /**
     * @return void
     */
    public function preload(): void {
        $startTime = time();
        App::getLogger()->notice('Preloading for ' . $this->name, ['started']);
        if ($this->timeout) {
            if ($this->isUseIpv4) {
                $this->cidr4 = SiteFactory::normalize(
                    IP4Helper::processCIDR($this->ip4, SiteFactory::normalize($this->cidr4)),
                    true
                );
            }

            if ($this->isUseIpv6) {
                $this->cidr6 = SiteFactory::normalize(
                    IP6Helper::processCIDR($this->ip6, SiteFactory::normalize($this->cidr6)),
                    true
                );
            }
        }
        App::getLogger()->notice('Preloaded for ' . $this->name, ['finished', time() - $startTime]);
    }

    /**
     * @return void
     */
    public function reload(): void {
        $startTime = time();
        App::getLogger()->notice('Reloading for ' . $this->name, ['started']);

        $ip4 = [];
        $ip6 = [];
        foreach (array_chunk($this->domains, \OpenCCK\getEnv('SYS_DNS_RESOLVE_CHUNK_SIZE') ?? 10) as $chunk) {
            $executions = array_map(fn(string $domain) => async(fn() => $this->dnsHelper->resolve($domain)), $chunk);
            foreach (await($executions) as $result) {
                $ip4 = array_merge($ip4, $result[0]);
                $ip6 = array_merge($ip6, $result[1]);
            }
        }

        if ($this->isUseIpv4) {
            $newIp4 = SiteFactory::normalize(array_diff($ip4, $this->ip4), true);
            $this->cidr4 = SiteFactory::normalize(IP4Helper::processCIDR($newIp4, $this->cidr4), true);

            $this->ip4 = SiteFactory::normalize(array_merge($this->ip4, $ip4), true);
        }

        if ($this->isUseIpv6) {
            $newIp6 = SiteFactory::normalize(array_diff($ip6, $this->ip6), true);
            $this->cidr6 = SiteFactory::normalize(IP6Helper::processCIDR($newIp6, $this->cidr6), true);

            $this->ip6 = SiteFactory::normalize(array_merge($this->ip6, $ip6), true);
        }

        $this->saveConfig();
        App::getLogger()->notice('Reloaded for ' . $this->name, ['finished', time() - $startTime]);

        EventLoop::delay($this->timeout, function () {
            $this->reloadExternal();
            $this->reload();
        });
    }

    /**
     * @return void
     */
    private function reloadExternal(): void {
        if (isset($this->external->domains)) {
            foreach ($this->external->domains as $url) {
                $this->domains = SiteFactory::normalize(
                    array_merge($this->domains, explode("\n", file_get_contents($url)))
                );
            }
        }

        if (isset($this->external->ip4) && $this->isUseIpv4) {
            foreach ($this->external->ip4 as $url) {
                $this->ip4 = SiteFactory::normalize(
                    array_merge($this->ip4, explode("\n", file_get_contents($url))),
                    true
                );
            }
        }

        if (isset($this->external->ip6) && $this->isUseIpv6) {
            foreach ($this->external->ip6 as $url) {
                $this->ip6 = SiteFactory::normalize(
                    array_merge($this->ip6, explode("\n", file_get_contents($url))),
                    true
                );
            }
        }

        if (isset($this->external->cidr4) && $this->isUseIpv4) {
            foreach ($this->external->cidr4 as $url) {
                $this->cidr4 = IP4Helper::minimizeSubnets(
                    SiteFactory::normalize(array_merge($this->cidr4, explode("\n", file_get_contents($url))), true)
                );
            }
        }

        if (isset($this->external->cidr6) && $this->isUseIpv6) {
            foreach ($this->external->cidr6 as $url) {
                // todo IP6Helper::minimizeSubnets
                $this->cidr6 = SiteFactory::normalize(
                    array_merge($this->cidr6, explode("\n", file_get_contents($url))),
                    true
                );
            }
        }

        App::getLogger()->debug('External reloaded for ' . $this->name);
    }

    /**
     * @return object
     */
    public function getConfig(): object {
        return (object) [
            'domains' => SiteFactory::normalizeArray($this->domains),
            'dns' => $this->dns,
            'timeout' => $this->timeout,
            'ip4' => SiteFactory::normalizeArray($this->ip4, true),
            'ip6' => SiteFactory::normalizeArray($this->ip6, true),
            'cidr4' => SiteFactory::normalizeArray($this->cidr4, true),
            'cidr6' => SiteFactory::normalizeArray($this->cidr6, true),
            'external' => $this->external,
        ];
    }

    /**
     * @return void
     */
    private function saveConfig(): void {
        file_put_contents(
            PATH_ROOT . '/config/' . $this->group . '/' . $this->name . '.json',
            json_encode($this->getConfig(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * @param bool $wildcard
     * @return array
     */
    public function getDomains(bool $wildcard = false): array {
        if ($wildcard) {
            $domains = [];
            foreach ($this->domains as $domain) {
                $parts = explode('.', $domain);
                $wildcardDomain = array_slice($parts, -2);
                if (in_array(implode('.', $wildcardDomain), SiteFactory::TWO_LEVEL_DOMAIN_ZONES)) {
                    $wildcardDomain = array_slice($parts, -3);
                }
                $domains[] = implode('.', $wildcardDomain);
            }
            return SiteFactory::normalizeArray($domains);
        }

        return $this->domains;
    }
}
