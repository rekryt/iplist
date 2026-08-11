<?php

declare(strict_types=1);

namespace OpenCCK\App\Controller;

use OpenCCK\Infrastructure\Codec\SingboxRuleSetBuilder;

/**
 * sing-box rule-set в source-формате (JSON).
 *
 * Наследуется от TextController, как это делают ClashX/Comma: сбор строк по
 * порталам, применение `replace` через `resolvedCidr()` и cross-site
 * `minimizeSubnets` уже реализованы там, здесь остаётся только конверт JSON.
 *
 * sing-box умеет забирать remote rule-set прямо в этом формате
 * (`"format": "source"` в `route.rule_set`), поэтому бинарный `.srs` (§ SrsController)
 * — это оптимизация размера, а не обязательное условие.
 */
class SingboxController extends TextController {
    /**
     * Ставится наследником (SrsController), когда генерация внутри render()
     * не удалась и в теле лежит текст ошибки, а не результат. Нужен, чтобы
     * getBody() не выдал `# Error: …` под заголовками бинарного вложения.
     */
    protected bool $renderFailed = false;

    /**
     * @return string
     */
    public function getBody(): string {
        $error = $this->validateParameters();
        if ($error !== null) {
            $this->setHeaders(['content-type' => 'text/plain']);
            return $error;
        }

        // Сбор и нормализация строк — в TextController::getBody, оттуда же
        // вызовется наш render()
        $body = parent::getBody();
        $this->setHeaders($this->renderFailed ? ['content-type' => 'text/plain'] : $this->responseHeaders());

        return $body;
    }

    /**
     * @param array<int, string> $response
     * @return string
     */
    protected function render(array $response): string {
        return SingboxRuleSetBuilder::encode(
            SingboxRuleSetBuilder::build(
                $this->version(),
                (string) $this->request->getQueryParameter('data'),
                $response,
                $this->domainType()
            )
        );
    }

    /**
     * Source-формат — обычный JSON, поэтому отдаём его инлайном: в браузере он
     * читается, а sing-box забирает его по ссылке как remote rule-set. Скачивание
     * файлом включается общим для всех форматов `?filesave=1` (см.
     * AbstractIPListController::__construct, там же расширение из `$map`).
     * Бинарный `.srs` — другое дело, там вложение всегда (см. SrsController).
     *
     * @return array<string, string>
     */
    protected function responseHeaders(): array {
        return ['content-type' => 'application/json; charset=utf-8'];
    }

    /**
     * @return ?string текст ошибки для тела ответа либо null, если всё валидно
     */
    protected function validateParameters(): ?string {
        $data = $this->request->getQueryParameter('data') ?? '';
        if ($data == '') {
            return "# Error: The 'data' GET parameter is required in the URL to access this page";
        }
        if (!in_array($data, SingboxRuleSetBuilder::DATA_TYPES, true)) {
            return "# Error: The 'data' GET parameter must be 'domains', 'ip4', 'cidr4', 'ip6' or 'cidr6'";
        }

        $version = $this->request->getQueryParameter('version') ?? '';
        if (
            $version !== '' &&
            (!ctype_digit($version) ||
                (int) $version < SingboxRuleSetBuilder::VERSION_MIN ||
                (int) $version > SingboxRuleSetBuilder::VERSION_MAX)
        ) {
            return "# Error: The 'version' GET parameter must be an integer from " .
                SingboxRuleSetBuilder::VERSION_MIN .
                ' to ' .
                SingboxRuleSetBuilder::VERSION_MAX;
        }

        if (!in_array($this->domainTypeName(), SingboxRuleSetBuilder::DOMAIN_TYPES, true)) {
            return "# Error: The 'domaintype' GET parameter must be 'suffix', 'full', 'keyword' or 'regex'";
        }

        return null;
    }

    protected function version(): int {
        $version = $this->request->getQueryParameter('version') ?? '';

        return $version === '' ? SingboxRuleSetBuilder::DEFAULT_VERSION : (int) $version;
    }

    protected function domainType(): string {
        return $this->domainTypeName();
    }

    private function domainTypeName(): string {
        return $this->request->getQueryParameter('domaintype') ?: SingboxRuleSetBuilder::DOMAIN_TYPE_SUFFIX;
    }
}
