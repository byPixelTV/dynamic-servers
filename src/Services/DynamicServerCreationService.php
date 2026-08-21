<?php

namespace ByPixelTV\Dynamicservers\Services;

use App\Exceptions\Service\Deployment\NoViableAllocationException;
use App\Models\Allocation;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Services\Servers\ServerCreationService;
use ByPixelTV\Dynamicservers\Jobs\ApplyTemplateFiles;
use ByPixelTV\Dynamicservers\Jobs\MonitorDynamicServer;
use ByPixelTV\Dynamicservers\Models\DynamicTemplate;
use ByPixelTV\Dynamicservers\Models\DynamicTemplateServer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class DynamicServerCreationService
{
    public function __construct(
        protected ServerCreationService $serverCreationService,
    ) {
    }

    /**
     * @throws NoViableAllocationException
     * @throws  Throwable
     */
    public function create(DynamicTemplate $template): Server
    {
        $template->refresh();

        if (!$template->owner_id || !$template->owner()->exists()) {
            throw new RuntimeException(
                'The configured server owner no longer exists.'
            );
        }

        if (!$template->node_id || !$template->node()->exists()) {
            throw new RuntimeException(
                'The configured node no longer exists.'
            );
        }

        if (!$template->egg_id || !$template->egg()->exists()) {
            throw new RuntimeException(
                'The configured egg no longer exists.'
            );
        }

        $allocation = DB::transaction(function () use ($template) {
            return Allocation::query()
                ->where('node_id', $template->node_id)
                ->whereNull('server_id')
                ->whereBetween('port', [
                    $template->port_range_start,
                    $template->port_range_end,
                ])
                ->orderBy('port')
                ->lockForUpdate()
                ->first();
        });

        if (!$allocation) {
            throw new RuntimeException(
                "No free allocation is available between " .
                "{$template->port_range_start} and {$template->port_range_end}."
            );
        }

        $egg = $template->egg;

        if (!$egg) {
            throw new RuntimeException(
                'The selected egg could not be found.'
            );
        }

        if (!$template->owner_id || !$template->owner()->exists()) {
            throw new RuntimeException(
                'The configured server owner does not exist.'
            );
        }

        $environment = [];

        foreach ($egg->variables ?? [] as $variable) {
            $environment[$variable->env_variable] = $variable->default_value;
        }

        $data = [
            'name' => str($template->name)->kebab()
                . '-'
                . strtolower(str()->random(5)),

            'description' => '',

            'owner_id' => $template->owner_id,

            'node_id' => $template->node_id,
            'egg_id' => $template->egg_id,

            'allocation_id' => $allocation->id,
            'allocation_additional' => [],

            'memory' => $template->memory,
            'disk' => $template->disk,
            'cpu' => $template->cpu,

            'swap' => 0,
            'io' => 500,
            'threads' => null,
            'oom_killer' => false,

            'allocation_limit' => 0,
            'database_limit' => 0,
            'backup_limit' => 0,

            'startup' => filled($template->startup)
                ? $template->startup
                : collect($egg->startup_commands ?? [])->first(),

            'image' => $template->image
                ?: collect($egg->docker_images ?? [])->first(),

            'environment' => $environment,

            'skip_scripts' => false,
            // The server must not start before all template files have been
            // applied. ApplyTemplateFiles starts it after a successful copy.
            'start_on_completion' => false,
        ];

        $server = $this->serverCreationService->handle($data);

        $dynamicServer = new DynamicTemplateServer();
        $dynamicServer->dynamic_template_id = $template->getKey();
        $dynamicServer->server_id = $server->getKey();
        $dynamicServer->save();

        ApplyTemplateFiles::dispatch(
            $dynamicServer->getKey()
        )->delay(now()->addSecond());

        // Delay monitoring by 60 seconds to allow server to fully start
        MonitorDynamicServer::dispatch(
            $dynamicServer->getKey()
        )->delay(now()->addSeconds(60));

        return $server;
    }

    public function applyTemplateFiles(
        DynamicTemplate $template,
        Server $server
    ): void {
        $disk = Storage::disk('local');

        $rootPath = "dynamic-templates-tree/{$template->getKey()}";

        if (!$disk->exists($rootPath)) {
            return;
        }

        $files = $disk->allFiles($rootPath);

        if (empty($files)) {
            return;
        }

        /** @var DaemonFileRepository $repository */
        $repository = app(DaemonFileRepository::class)
            ->setServer($server);

        $failedFiles = [];

        foreach ($files as $filePath) {
            $relativePath = ltrim(
                str($filePath)
                    ->after($rootPath)
                    ->toString(),
                '/'
            );

            if ($relativePath === '') {
                continue;
            }

            $content = $disk->get($filePath);

            $success = false;

            for ($attempt = 1; $attempt <= 3; $attempt++) {
                try {
                    $repository->putContent(
                        $relativePath,
                        $content
                    );

                    $success = true;

                    break;
                } catch (Throwable $exception) {
                    Log::warning(
                        'Could not apply template file.',
                        [
                            'template_id' => $template->getKey(),
                            'server_id' => $server->getKey(),
                            'file' => $relativePath,
                            'attempt' => $attempt,
                            'error' => $exception->getMessage(),
                        ]
                    );

                    if ($attempt < 3) {
                        usleep(300_000);
                    }
                }
            }

            if (!$success) {
                $failedFiles[] = $relativePath;
            }
        }

        if (!empty($failedFiles)) {
            throw new RuntimeException(
                'Could not apply ' .
                count($failedFiles) .
                ' file(s): ' .
                implode(', ', $failedFiles)
            );
        }
    }

}
