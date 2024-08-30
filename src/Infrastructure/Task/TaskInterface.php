<?php

namespace OpenCCK\Infrastructure\Task;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;

interface TaskInterface extends Task {
    public function run(Channel $channel, Cancellation $cancellation): mixed;
}
