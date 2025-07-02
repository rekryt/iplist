<?php

namespace OpenCCK\App\Controller;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;

use Throwable;

abstract class AbstractController implements ControllerInterface {
    private int $httpStatus = HttpStatus::OK;

    /**
     * @param Request $request
     * @param array $headers
     * @throws Throwable
     */
    public function __construct(protected Request $request, protected array $headers = []) {
    }

    /**
     * @return Response
     */
    public function __invoke(): Response {
        $body = $this->getBody();
        return new Response(status: $this->httpStatus, headers: $this->headers, body: $body);
    }

    abstract public function getBody(): string;

    public function setHeaders(array $headers): AbstractController {
        $this->headers = array_merge($this->headers ?? [], $headers);
        return $this;
    }

    /**
     * @param int $httpStatus
     */
    public function setHttpStatus(int $httpStatus): void {
        $this->httpStatus = $httpStatus;
    }

    public function redirect(string $url, bool $permanently = false): void {
        $this->httpStatus = $permanently ? HttpStatus::MOVED_PERMANENTLY : HttpStatus::SEE_OTHER;
        $this->headers = array_merge($this->headers ?? [], ['location' => $url]);
    }

    /**
     * @return string
     */
    public function getBaseURL(): string {
        $schemePort = ['http' => 80, 'https' => 443];
        return $this->request->getUri()->getScheme() .
            '://' .
            $this->request->getUri()->getHost() .
            ($schemePort[$this->request->getUri()->getScheme()] !== $this->request->getUri()->getPort()
                ? ':' . $this->request->getUri()->getPort()
                : '');
    }
}
