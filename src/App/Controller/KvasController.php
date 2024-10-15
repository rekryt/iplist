<?php

namespace OpenCCK\App\Controller;

/**
 * Format Keenetic (KVAS)
 */
class KvasController extends TextController {
    const DELIMITER = "\n";

    /**
     * @param array $response
     * @return string
     */
    protected function render(array $response): string {
        $data = $this->request->getQueryParameter('data') ?? '';
        $handlers = [
            'domains' => fn(string $row) => 'kvas add ' . $row . ' Y',
            'ip4' => fn(string $row) => 'kvas add ' . $row . '/32',
            'ip6' => fn(string $row) => 'kvas add ' . $row . '/128',
            'cidr4' => fn(string $row) => 'kvas add ' . $row,
            'cidr6' => fn(string $row) => 'kvas add ' . $row,
        ];
        return implode($this::DELIMITER, array_map($handlers[$data] ?? fn(string $row) => $row, $response));
    }
}
