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

    protected function render(array $response): string {
        $chunks = array_chunk($response, 300);

        $result = [];
        foreach ($chunks as $chunk) {
            $result[] = implode(self::DELIMITER, $chunk);
        }

        // 5 пустых строк между блоками
        return implode(str_repeat(self::DELIMITER, 6), $result);
    }
}
