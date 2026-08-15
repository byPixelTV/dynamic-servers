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
            return;
        }

        if (!$template->auto_creation) {
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
            $this->scheduleNext($template);

            return;
        }

        try {
            $this->scale(
                $template,
                $creationService
            );
        } finally {
            $lock->release();
        }

        $this->scheduleNext($template);
    }

    protected function scale(
        DynamicTemplate $template,
        DynamicServerCreationService $creationService
    ): void {
        if (!$template->owner_id || !$template->owner()->exists()) {
            Log::error('Dynamic template auto scaling stopped: owner missing.', [
                'template_id' => $template->getKey(),
            ]);

            return;
        }

        if (!$template->node_id || !$template->node()->exists()) {
            Log::error('Dynamic template auto scaling stopped: node missing.', [
                'template_id' => $template->getKey(),
            ]);

            return;
        }

        if (!$template->egg_id || !$template->egg()->exists()) {
            Log::error('Dynamic template auto scaling stopped: egg missing.', [
                'template_id' => $template->getKey(),
            ]);

            return;
        }

        $minimum = max(
            0,
            $template->min_servers
        );

        $current = DynamicTemplateServer::query()
            ->where('dynamic_template_id', $template->getKey())
            ->count();

        if ($current >= $minimum) {
            return;
        }

        $needed = $minimum - $current;

        $freeAllocations = Allocation::query()
            ->where('node_id', $template->node_id)
            ->whereNull('server_id')
            ->whereBetween('port', [
                $template->port_range_start,
                $template->port_range_end,
            ])
            ->count();

        if ($freeAllocations <= 0) {
            Log::warning(
                'Dynamic template cannot scale because no free allocations are available.',
                [
                    'template_id' => $template->getKey(),
                    'current' => $current,
                    'minimum' => $minimum,
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
                $creationService->create($template);

                Log::info('Auto-created dynamic server.', [
                    'template_id' => $template->getKey(),
                ]);

            } catch (Throwable $exception) {
                Log::error(
                    'Auto scaling could not create a dynamic server.',
                    [
                        'template_id' => $template->getKey(),
                        'error' => $exception->getMessage(),
                    ]
                );

                break;
            }
        }
    }

    protected function validateTemplateDependencies(
        DynamicTemplate $template
    ): bool {
        if (!$template->owner_id || !$template->owner()->exists()) {
            $this->disableAutoScaling(
                $template,
                'Configured owner no longer exists.'
            );

            return false;
        }

        if (!$template->node_id || !$template->node()->exists()) {
            $this->disableAutoScaling(
                $template,
                'Configured node no longer exists.'
            );

            return false;
        }

        if (!$template->egg_id || !$template->egg()->exists()) {
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
        $template->auto_creation = false;
        $template->save();

        Log::error('Dynamic template auto scaling disabled.', [
            'template_id' => $template->getKey(),
            'reason' => $reason,
        ]);
    }

    protected function scheduleNext(
        DynamicTemplate $template
    ): void {
        if (!$template->auto_creation) {
            return;
        }

        self::dispatch(
            $template->getKey()
        )->delay(now()->addSeconds(5));
    }
}
