<?php

namespace ByPixelTV\Dynamicservers\Services;

use App\Exceptions\DisplayException;
use App\Exceptions\Service\Deployment\NoViableAllocationException;
use App\Models\Allocation;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Services\Servers\ServerCreationService;
use ByPixelTV\Dynamicservers\Jobs\MonitorDynamicServer;
use ByPixelTV\Dynamicservers\Models\DynamicTemplate;
use ByPixelTV\Dynamicservers\Models\DynamicTemplateServer;
use Exception;
use Illuminate\Support\Facades\DB;
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
     * @throws DisplayException
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
            'start_on_completion' => true,
        ];

        $server = $this->serverCreationService->handle($data);

        $dynamicServer = new DynamicTemplateServer();
        $dynamicServer->dynamic_template_id = $template->getKey();
        $dynamicServer->server_id = $server->getKey();
        $dynamicServer->save();

        MonitorDynamicServer::dispatch(
            $dynamicServer->getKey()
        )->delay(now()->addSeconds(5));

        $this->copyTemplateFiles(
            $template,
            $server
        );

        return $server;
    }

    protected function copyTemplateFiles(
        DynamicTemplate $template,
                        $server
    ): void {
        $treeRoot = "dynamic-templates-tree/{$template->getKey()}";

        $allFiles = Storage::disk('local')
            ->allFiles($treeRoot);

        if (empty($allFiles)) {
            return;
        }

        /** @var DaemonFileRepository $fileRepository */
        $fileRepository = app(DaemonFileRepository::class)
            ->setServer($server);

        foreach ($allFiles as $absolutePath) {
            $relativePath = ltrim(
                str($absolutePath)->after($treeRoot),
                '/'
            );

            $content = Storage::disk('local')
                ->get($absolutePath);

            try {
                $fileRepository->putContent(
                    $relativePath,
                    $content
                );
            } catch (Exception) {
                //
            }
        }
    }
}
