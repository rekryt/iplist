<?php

namespace OpenCCK\App\Controller;

/**
 * Format Keenetic (DNS)
 */
class WildcardController extends TextController {
    const DELIMITER = "\n";

    public function getBody(): string {
        $data = $this->request->getQueryParameter('data') ?? '';
        if ($data !== 'domains') {
            return "# Error: The 'data' GET parameter must be 'domains'";
        }
        $this->request->setQueryParameter('wildcard', '1');
        return parent::getBody();
    }
}
