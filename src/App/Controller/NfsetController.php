<?php

namespace OpenCCK\App\Controller;

class NfsetController extends TextController {
    const DELIMITER = "\n";

    /**
     * @param array $response
     * @return string
     */
    protected function render(array $response): string {
        $data = $this->request->getQueryParameter('data') ?? '';

        $prefix = 'nftset=/';
        $suffix = '#inet#fw4#vpn_';
        $handlers = [
            'domains' => fn(string $row) => $prefix . $row . '/4' . $suffix . $data,
            'ip4' => fn(string $row) => $prefix . $row . '/4' . $suffix . $data,
            'ip6' => fn(string $row) => $prefix . $row . '/6' . $suffix . $data,
            'cidr4' => fn(string $row) => $prefix . $row . '/4' . $suffix . $data,
            'cidr6' => fn(string $row) => $prefix . $row . '/6' . $suffix . $data,
        ];
        return implode($this::DELIMITER, array_map($handlers[$data] ?? fn(string $row) => $row, $response));
    }
}
