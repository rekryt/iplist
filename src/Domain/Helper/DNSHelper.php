<?php

namespace OpenCCK\Domain\Helper;

use Amp\Dns\DnsConfig;
use Amp\Dns\DnsConfigLoader;

use Amp\Dns\DnsRecord;
use Amp\Dns\DnsResolver;
use Amp\Dns\HostLoader;
use Amp\Dns\Rfc1035StubDnsResolver;

use OpenCCK\Infrastructure\API\App;
use Throwable;

use function Amp\delay;
use function Amp\Dns\dnsResolver as dnsResolverFactory;
use function OpenCCK\getEnv;

class DNSHelper {
    private float $resolveDelay;
    private bool $isUseIpv4;
    private bool $isUseIpv6;

    public function __construct(private array $dnsServers = []) {
        $this->resolveDelay = (\OpenCCK\getEnv('SYS_DNS_RESOLVE_DELAY') ?? 500) / 1000;
        $this->isUseIpv4 = (getEnv('SYS_DNS_RESOLVE_IP4') ?? 'true') == 'true';
        $this->isUseIpv6 = (getEnv('SYS_DNS_RESOLVE_IP6') ?? 'true') == 'true';
    }

    /**
     * @param array $dnsServers
     * @return DnsResolver
     */
    private function getResolver(array $dnsServers): DnsResolver {
        return dnsResolverFactory(
            new Rfc1035StubDnsResolver(
                null,
                new class ($dnsServers) implements DnsConfigLoader {
                    public function __construct(private readonly array $dnsServers = []) {
                    }

                    public function loadConfig(): DnsConfig {
                        return new DnsConfig($this->dnsServers, (new HostLoader())->loadHosts());
                    }
                }
            )
        );
    }

    /**
     * @param string $domain
     * @return array[]
     */
    public function resolve(string $domain): array {
        $ipv4 = [];
        $ipv6 = [];
        foreach ($this->dnsServers as $server) {
            delay($this->resolveDelay);
            $dnsResolver = $this->getResolver([$server]);
            if ($this->isUseIpv4) {
                try {
                    $ipv4 = array_merge(
                        $ipv4,
                        array_map(
                            fn(DnsRecord $record) => $record->getValue(),
                            $dnsResolver->resolve($domain, DnsRecord::A)
                        )
                    );
                } catch (Throwable $e) {
                    if (!str_starts_with($e->getMessage(), 'Giving up resolution')) {
                        App::getLogger()->error($e->getMessage(), [$server]);
                    }
                }
            }

            delay($this->resolveDelay);

            if ($this->isUseIpv6) {
                try {
                    $ipv6 = array_merge(
                        $ipv6,
                        array_map(
                            fn(DnsRecord $record) => $record->getValue(),
                            $dnsResolver->resolve($domain, DnsRecord::AAAA)
                        )
                    );
                } catch (Throwable $e) {
                    if (!str_starts_with($e->getMessage(), 'Giving up resolution')) {
                        App::getLogger()->error($e->getMessage(), [$server]);
                    }
                }
            }
        }
        App::getLogger()->debug('resolve: ' . $domain, [count($ipv4), count($ipv6)]);
        return [$ipv4, $ipv6];
    }
}
