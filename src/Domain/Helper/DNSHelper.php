<?php

namespace OpenCCK\Domain\Helper;

use Amp\Dns\DnsConfig;
use Amp\Dns\DnsConfigLoader;
use Amp\Dns\DnsException;
use Amp\Dns\DnsRecord;
use Amp\Dns\HostLoader;
use Amp\Dns\Rfc1035StubDnsResolver;

use OpenCCK\Infrastructure\API\App;
use function Amp\Dns\dnsResolver;
use function Amp\Dns\resolve;

readonly class DNSHelper {
    public function __construct(private array $dnsServers = []) {
    }

    /**
     * @param array $dnsServers
     * @return void
     */
    private function setResolver(array $dnsServers): void {
        dnsResolver(
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
            $this->setResolver([$server]);
            try {
                $ipv4 = array_merge(
                    $ipv4,
                    array_map(fn(DnsRecord $record) => $record->getValue(), resolve($domain, DnsRecord::A))
                );
            } catch (DnsException $e) {
                App::getLogger()->error($e->getMessage(), [$server]);
            }
            try {
                $ipv6 = array_merge(
                    $ipv6,
                    array_map(fn(DnsRecord $record) => $record->getValue(), resolve($domain, DnsRecord::AAAA))
                );
            } catch (DnsException $e) {
                App::getLogger()->error($e->getMessage(), [$server]);
            }
        }
        App::getLogger()->debug('resolve: ' . $domain, [count($ipv4), count($ipv6)]);
        return [$ipv4, $ipv6];
    }
}
