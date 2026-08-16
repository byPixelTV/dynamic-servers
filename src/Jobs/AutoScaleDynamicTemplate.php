<?php

namespace ByPixelTV\Dynamicservers\Jobs;

use App\Models\Allocation;
use ByPixelTV\Dynamicservers\Models\DynamicTemplate;
use ByPixelTV\Dynamicservers\Models\DynamicTemplateServer;
use ByPixelTV\Dynamicservers\Services\DynamicServerCreationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class AutoScaleDynamicTemplate implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public int $templateId
    ) {
    }

    public function handle(
        DynamicServerCreationService $creationService
    ): void {
        /** @var DynamicTemplate|null $template */
        $template = DynamicTemplate::query()
            ->find($this->templateId);

        if (!$template) {
            Log::warning('AutoScale job stopped because template no longer exists.', [
                'template_id' => $this->templateId,
            ]);

            return;
        }

        Log::info('AutoScale job started.', [
            'template_id' => $template->getKey(),
            'auto_creation' => (bool) $template->auto_creation,
            'min_servers' => (int) $template->min_servers,
        ]);

        if (!$template->auto_creation) {
            Log::debug('AutoScale job stopped because auto creation is disabled.', [
                'template_id' => $template->getKey(),
            ]);

            return;
        }

        if (!$this->validateTemplateDependencies($template)) {
            return;
        }

        $lock = Cache::lock(
            "dynamicservers:autoscale:{$template->getKey()}",
            30
        );

        if (!$lock->get()) {
            Log::debug('AutoScale skipped because another scaling job holds the lock.', [
                'template_id' => $template->getKey(),
            ]);

            $this->scheduleNext($template);

            return;
        }

        try {
            $this->scale(
                $template,
                $creationService
            );
        } catch (Throwable $exception) {
            Log::error('Unexpected error during dynamic template auto scaling.', [
                'template_id' => $template->getKey(),
                'error' => $exception->getMessage(),
            ]);

            report($exception);
        } finally {
            $lock->release();
        }

        $template->refresh();

        $this->scheduleNext($template);
    }

    protected function scale(
        DynamicTemplate $template,
        DynamicServerCreationService $creationService
    ): void {
        $minimum = max(
            0,
            (int) $template->min_servers
        );

        $current = DynamicTemplateServer::query()
            ->where(
                'dynamic_template_id',
                $template->getKey()
            )
            ->count();

        $needed = max(
            0,
            $minimum - $current
        );

        Log::info('Dynamic template scale status.', [
            'template_id' => $template->getKey(),
            'current' => $current,
            'minimum' => $minimum,
            'needed' => $needed,
        ]);

        if ($needed <= 0) {
            return;
        }

        $freeAllocations = Allocation::query()
            ->where(
                'node_id',
                $template->node_id
            )
            ->whereNull('server_id')
            ->whereBetween(
                'port',
                [
                    $template->port_range_start,
                    $template->port_range_end,
                ]
            )
            ->count();

        if ($freeAllocations <= 0) {
            Log::warning(
                'Dynamic template cannot scale because no free allocations are available.',
                [
                    'template_id' => $template->getKey(),
                    'current' => $current,
                    'minimum' => $minimum,
                    'needed' => $needed,
                ]
            );

            return;
        }

        $amountToCreate = min(
            $needed,
            $freeAllocations
        );

        Log::info('Scaling dynamic template.', [
            'template_id' => $template->getKey(),
            'current' => $current,
            'minimum' => $minimum,
            'needed' => $needed,
            'free_allocations' => $freeAllocations,
            'creating' => $amountToCreate,
        ]);

        for ($i = 0; $i < $amountToCreate; $i++) {
            try {
                $server = $creationService->create(
                    $template
                );

                Log::info('Auto-created dynamic server.', [
                    'template_id' => $template->getKey(),
                    'server_id' => $server->getKey(),
                ]);
            } catch (Throwable $exception) {
                Log::error(
                    'Auto scaling could not create a dynamic server.',
                    [
                        'template_id' => $template->getKey(),
                        'iteration' => $i + 1,
                        'requested' => $amountToCreate,
                        'error' => $exception->getMessage(),
                    ]
                );

                report($exception);

                break;
            }
        }
    }

    protected function validateTemplateDependencies(
        DynamicTemplate $template
    ): bool {
        if (
            !$template->owner_id
            || !$template->owner()->exists()
        ) {
            $this->disableAutoScaling(
                $template,
                'Configured owner no longer exists.'
            );

            return false;
        }

        if (
            !$template->node_id
            || !$template->node()->exists()
        ) {
            $this->disableAutoScaling(
                $template,
                'Configured node no longer exists.'
            );

            return false;
        }

        if (
            !$template->egg_id
            || !$template->egg()->exists()
        ) {
            $this->disableAutoScaling(
                $template,
                'Configured egg no longer exists.'
            );

            return false;
        }

        return true;
    }

    protected function disableAutoScaling(
        DynamicTemplate $template,
        string $reason
    ): void {
        $template->forceFill([
            'auto_creation' => false,
        ])->save();

        Log::error('Dynamic template auto scaling disabled.', [
            'template_id' => $template->getKey(),
            'reason' => $reason,
        ]);
    }

    protected function scheduleNext(
        DynamicTemplate $template
    ): void {
        if (!$template->auto_creation) {
            Log::debug('AutoScale loop not rescheduled because auto creation is disabled.', [
                'template_id' => $template->getKey(),
            ]);

            return;
        }

        self::dispatch(
            $template->getKey()
        )->delay(
            now()->addSeconds(5)
        );
    }
}
