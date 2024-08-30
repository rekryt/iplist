<?php

namespace OpenCCK\Infrastructure\API;

use Amp\ByteStream\WritableResourceStream;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;

use Revolt\EventLoop;
use Revolt\EventLoop\UnsupportedFeatureException;

use Closure;
use Dotenv\Dotenv;
use Monolog\Logger;
use Psr\Log\LogLevel;

use function Amp\trapSignal;
use function OpenCCK\getEnv;
use function sprintf;

final class App {
    private static App $_instance;

    /**
     * @param array<AppModuleInterface> $modules
     */
    private array $modules = [];

    private bool $isEventLoopStarted = false;

    /**
     * @param ?Logger $logger
     */
    private function __construct(private ?Logger $logger = null) {
        ini_set('memory_limit', getEnv('SYS_MEMORY_LIMIT') ?? '2048M');

        if (!defined('PATH_ROOT')) {
            define('PATH_ROOT', dirname(__DIR__, 3));
        }

        $dotenv = Dotenv::createImmutable(PATH_ROOT);
        $dotenv->safeLoad();

        if ($timezone = getEnv('SYS_TIMEZONE')) {
            date_default_timezone_set($timezone);
        }

        $this->logger = $logger ?? new Logger(getEnv('COMPOSE_PROJECT_NAME') ?? 'iplist');
        $logHandler = new StreamHandler(new WritableResourceStream(STDOUT));
        $logHandler->setFormatter(new ConsoleFormatter());
        $logHandler->setLevel(getEnv('DEBUG') === 'false' ? LogLevel::INFO : LogLevel::DEBUG);
        $this->logger->pushHandler($logHandler);

        EventLoop::setErrorHandler(function ($e) {
            $this->logger->error($e->getMessage());
        });
    }

    public static function getInstance(?Logger $logger = null): self {
        return self::$_instance ??= new self($logger);
    }

    /**
     * @param Closure<AppModuleInterface> $handler
     * @return $this
     */
    public function addModule(Closure $handler): self {
        $module = $handler($this);
        $this->modules[$module::class] = $module;
        return $this;
    }

    public function getModules(): array {
        return $this->modules;
    }

    public static function getLogger(): ?Logger {
        return self::$_instance->logger;
    }

    public function start(): void {
        foreach ($this->getModules() as $module) {
            $module->start();
        }
        if (defined('SIGINT') && defined('SIGTERM')) {
            // Await SIGINT or SIGTERM to be received.
            try {
                $signal = trapSignal([SIGINT, SIGTERM]);
                $this->logger->info(sprintf('Received signal %d, stopping server', $signal));
            } catch (UnsupportedFeatureException $e) {
                $this->logger->error($e->getMessage());
            }
            $this->stop();
        } else {
            if (!$this->isEventLoopStarted && !defined('PHPUNIT_COMPOSER_INSTALL')) {
                $this->isEventLoopStarted = true;
                EventLoop::run();
            }
        }
    }

    public function stop(): void {
        foreach ($this->modules as $module) {
            $module->stop();
        }
    }
}
