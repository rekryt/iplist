<?php

namespace OpenCCK\Infrastructure\Storage;

use OpenCCK\Infrastructure\API\App;
use Revolt\EventLoop;

class CIDRStorage implements StorageInterface {
    const FILENAME = 'cidr.json';

    private static CIDRStorage $_instance;
    private array $data = [];

    private function __construct() {
        $path = PATH_ROOT . '/storage/' . self::FILENAME;
        if (is_file($path)) {
            $this->data = (array) json_decode(file_get_contents($path)) ?? [];
        }

        EventLoop::repeat(\OpenCCK\getEnv('STORAGE_SAVE_INTERVAL') ?? 120, $this->save(...));
    }

    public static function getInstance(): CIDRStorage {
        return self::$_instance ??= new self();
    }

    public function get(string $key): ?array {
        return $this->data[$key] ?? null;
    }

    public function set(string $key, mixed $value): bool {
        $this->data[$key] = $value;
        return true;
    }

    public function has(string $key): bool {
        return isset($this->data[$key]);
    }

    private function save(): void {
        file_put_contents(PATH_ROOT . '/storage/' . self::FILENAME, json_encode($this->data, JSON_PRETTY_PRINT));
        App::getLogger()->notice('Whois storage saved', [count($this->data) . ' items']);
    }
}
