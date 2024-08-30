<?php

namespace OpenCCK\Infrastructure\Task;

use Amp\Cancellation;
use Amp\Sync\Channel;

readonly class ShellTask implements TaskInterface {
    public function __construct(private string $command) {
    }

    /**
     * @param Channel $channel
     * @param Cancellation $cancellation
     * @return string
     */
    public function run(Channel $channel, Cancellation $cancellation): mixed {
        return shell_exec($this->command);
    }
}
