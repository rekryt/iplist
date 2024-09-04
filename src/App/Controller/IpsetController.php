<?php

namespace OpenCCK\App\Controller;

class IpsetController extends TextController {
    const DELIMITER = "\n";

    /**
     * @param array $response
     * @return string
     */
    protected function render(array $response): string {
        $data = $this->request->getQueryParameter('data') ?? '';
        return implode(
            $this::DELIMITER,
            array_map(fn(string $row) => 'ipset=/' . $row . '/vpn_' . $data, $response)
        );
    }
}
