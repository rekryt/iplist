<?php

namespace OpenCCK\App\Controller;

class ClashxController extends TextController {
    const DELIMITER = "\n";

    /**
     * @param array $response
     * @return string
     * Поддерживаемые типы правил в ClashX:
     * DOMAIN-SUFFIX — для доменов по суффиксу.
     * DOMAIN — для конкретных доменов.
     * DOMAIN-KEYWORD — для доменов, содержащих определённые ключевые слова.
     * IP-CIDR — для IP-адресов и IPv4-сетей.
     * IP-CIDR6 — для IPv6-сетей.
     * SRC-IP-CIDR — для исходных IP-адресов или сетей.
     * SRC-IP-CIDR6 — для исходных IPv6-адресов или сетей.
     * GEOIP — для определения страны по IP.
     */
    protected function render(array $response): string {
        $data = $this->request->getQueryParameter('data') ?? '';
        $handlers = [
            'domains' => fn(string $row) => 'DOMAIN-SUFFIX,' . $row,
            'ip4' => fn(string $row) => 'IP-CIDR,' . $row . '/32',
            'ip6' => fn(string $row) => 'IP-CIDR6,' . $row . '/128',
            'cidr4' => fn(string $row) => 'IP-CIDR,' . $row,
            'cidr6' => fn(string $row) => 'IP-CIDR6,' . $row,
        ];
        return implode($this::DELIMITER, array_map($handlers[$data] ?? fn(string $row) => $row, $response));
    }
}
