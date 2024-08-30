<?php

namespace OpenCCK\Infrastructure\Storage;

interface StorageInterface {
    public function get(string $key): mixed;
    public function set(string $key, mixed $value): bool;
    public function has(string $key): bool;
}
