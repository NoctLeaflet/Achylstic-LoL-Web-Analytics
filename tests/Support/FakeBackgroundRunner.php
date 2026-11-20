<?php

namespace Tests\Support;

use App\Services\Sync\BackgroundRunner;

/** Records the commands instead of starting a process. */
class FakeBackgroundRunner extends BackgroundRunner
{
    /** @var list<string> */
    public array $started = [];

    public bool $succeeds = true;

    public function run(string $artisanCommand): bool
    {
        $this->started[] = $artisanCommand;

        return $this->succeeds;
    }
}
