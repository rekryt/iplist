<?php

namespace OpenCCK\Domain\Entity;

use OpenCCK\Domain\Factory\SiteFactory;
use OpenCCK\Domain\Helper\DNSHelper;
use OpenCCK\Domain\Helper\IP4Helper;
use OpenCCK\Domain\Helper\IP6Helper;
use OpenCCK\Infrastructure\API\App;

use Revolt\EventLoop;
use stdClass;

final class Site {
    private DNSHelper $dnsHelper;

    /**
     * @param string $name Name of portal
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
        EventLoop::delay(0, $this->reload(...));
    }

    /**
     * @return void
     */
    private function reload(): void {
        $ip4 = [];
        $ip6 = [];
        foreach ($this->domains as $domain) {
            [$ipv4results, $ipv6results] = $this->dnsHelper->resolve($domain);
            $ip4 = array_merge($ip4, $ipv4results);
            $ip6 = array_merge($ip6, $ipv6results);
        }

        $newIp4 = SiteFactory::normalize(array_diff($ip4, $this->ip4));
        $this->cidr4 = SiteFactory::normalize(IP4Helper::processCIDR($newIp4, $this->cidr4));

        $newIp6 = SiteFactory::normalize(array_diff($ip6, $this->ip6));
        $this->cidr6 = SiteFactory::normalize(IP6Helper::processCIDR($newIp6, $this->cidr6));

        $this->ip4 = SiteFactory::normalize(array_merge($this->ip4, $ip4));
        $this->ip6 = SiteFactory::normalize(array_merge($this->ip6, $ip6));

        App::getLogger()->debug('Reloaded for ' . $this->name);

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

        if (isset($this->external->ip4)) {
            foreach ($this->external->ip4 as $url) {
                $this->ip4 = SiteFactory::normalize(array_merge($this->ip4, explode("\n", file_get_contents($url))));
            }
        }

        if (isset($this->external->ip6)) {
            foreach ($this->external->ip6 as $url) {
                $this->ip6 = SiteFactory::normalize(array_merge($this->ip6, explode("\n", file_get_contents($url))));
            }
        }

        if (isset($this->external->cidr4)) {
            foreach ($this->external->cidr4 as $url) {
                $this->cidr4 = SiteFactory::normalize(
                    array_merge($this->cidr4, explode("\n", file_get_contents($url)))
                );
            }
        }

        if (isset($this->external->cidr6)) {
            foreach ($this->external->cidr6 as $url) {
                $this->cidr6 = SiteFactory::normalize(
                    array_merge($this->cidr6, explode("\n", file_get_contents($url)))
                );
            }
        }

        App::getLogger()->debug('External reloaded for ' . $this->name);
    }
}
