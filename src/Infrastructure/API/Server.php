<?php

namespace OpenCCK\Infrastructure\API;

use Amp\ByteStream\BufferException;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Driver\ConnectionLimitingClientFactory;
use Amp\Http\Server\Driver\ConnectionLimitingServerSocketFactory;
use Amp\Http\Server\Driver\DefaultHttpDriverFactory;
use Amp\Http\Server\Driver\SocketClientFactory;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\HttpServerStatus;
use Amp\Http\Server\Router;
use Amp\Http\Server\SocketHttpServer;
use Amp\Http\Server\StaticContent\DocumentRoot;
use Amp\Socket\BindContext;
use Amp\Sync\LocalSemaphore;
use Amp\Socket;

use Monolog\Logger;
use OpenCCK\App\Service\IPListService;
use OpenCCK\Infrastructure\API\Handler\HTTPHandler;
use Throwable;

use function OpenCCK\getEnv;

final class Server implements AppModuleInterface {
    private static Server $_instance;

    private int $connectionLimit = 100000;
    private int $connectionPerIpLimit = 100000;

    /**
     * @param ?HttpServer $httpServer
     * @param ?ErrorHandler $errorHandler
     * @param ?BindContext $bindContext
     * @param ?Logger $logger
     * @throws Throwable
     */
    private function __construct(
        private ?HttpServer $httpServer,
        private ?ErrorHandler $errorHandler,
        private ?Socket\BindContext $bindContext,
        private ?Logger $logger
    ) {
        $this->logger = $logger ?? App::getLogger();
        $serverSocketFactory = new ConnectionLimitingServerSocketFactory(new LocalSemaphore($this->connectionLimit));
        $clientFactory = new ConnectionLimitingClientFactory(
            new SocketClientFactory($this->logger),
            $this->logger,
            $this->connectionPerIpLimit
        );
        $this->httpServer =
            $httpServer ??
            new SocketHttpServer(
                logger: $this->logger,
                serverSocketFactory: $serverSocketFactory,
                clientFactory: $clientFactory,
                httpDriverFactory: new DefaultHttpDriverFactory(logger: $this->logger, streamTimeout: 60)
            );
        $this->bindContext = $bindContext ?? (new Socket\BindContext())->withoutTlsContext();
        $this->errorHandler = $errorHandler ?? new DefaultErrorHandler();

        // инициализация сервиса
        IPListService::getInstance($this->logger);
    }

    /**
     * @param ?HttpServer $httpServer
     * @param ?ErrorHandler $errorHandler
     * @param ?BindContext $bindContext
     * @param ?Logger $logger
     * @throws BufferException
     * @throws Throwable
     */
    public static function getInstance(
        HttpServer $httpServer = null,
        ErrorHandler $errorHandler = null,
        Socket\BindContext $bindContext = null,
        Logger $logger = null
    ): Server {
        return self::$_instance ??= new self($httpServer, $errorHandler, $bindContext, $logger);
    }

    /**
     * Запуск веб-сервера
     * @return void
     */
    public function start(): void {
        try {
            $this->httpServer->expose(
                new Socket\InternetAddress(getEnv('HTTP_HOST') ?? '0.0.0.0', getEnv('HTTP_PORT') ?? 8080),
                $this->bindContext
            );
            //$this->socketHttpServer->expose(
            //    new Socket\InternetAddress('[::]', $_ENV['HTTP_PORT'] ?? 8080),
            //    $this->bindContext
            //);
            $router = new Router($this->httpServer, $this->logger, $this->errorHandler);
            $httpHandlerInstance = HTTPHandler::getInstance($this->logger);
            $router->addRoute('GET', '/', $httpHandlerInstance->getHandler('main'));
            $router->addRoute('GET', '/favicon', $httpHandlerInstance->getHandler('favicon'));
            $router->addRoute('GET', '/{name:.+}', $httpHandlerInstance->getHandler('main'));
            $router->setFallback(new DocumentRoot($this->httpServer, $this->errorHandler, PATH_ROOT . '/public'));

            $this->httpServer->start($router, $this->errorHandler);
        } catch (Socket\SocketException $e) {
            $this->logger->warning($e->getMessage());
        } catch (Throwable $e) {
            $this->logger->error($e->getMessage());
        }
    }

    /**
     * @return void
     */
    public function stop(): void {
        $this->httpServer->stop();
    }

    /**
     * @return HttpServerStatus
     */
    public function getStatus(): HttpServerStatus {
        return $this->httpServer->getStatus();
    }
}
