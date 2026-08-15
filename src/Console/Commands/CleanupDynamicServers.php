<?php

namespace ByPixelTV\Dynamicservers\Console\Commands;

use ByPixelTV\Dynamicservers\Services\DynamicServerCleanupService;
use Illuminate\Console\Command;

class CleanupDynamicServers extends Command
{
    protected $signature = 'dynamicservers:cleanup';

    protected $description = 'Delete dynamic template servers that are offline';

    public function handle(
        DynamicServerCleanupService $cleanupService
    ): int {
        $this->info('Checking dynamic servers...');

        $cleanupService->checkAll();

        $this->info('Done.');

        return self::SUCCESS;
    }
}
