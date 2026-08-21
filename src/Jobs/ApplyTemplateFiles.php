<?php

namespace ByPixelTV\Dynamicservers\Jobs;

use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use ByPixelTV\Dynamicservers\Models\DynamicTemplate;
use ByPixelTV\Dynamicservers\Models\DynamicTemplateServer;
use ByPixelTV\Dynamicservers\Services\DynamicServerCreationService;
use ByPixelTV\Dynamicservers\Support\DynamicServerPowerState;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ApplyTemplateFiles implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 60;

    public int $timeout = 300;

    public function __construct(
        public int $dynamicTemplateServerId
    ) {}

    /**
     * @throws ConnectionException
     */
    public function handle(
        DynamicServerCreationService $creationService,
        DaemonServerRepository $serverRepository
    ): void {
        /** @var DynamicTemplateServer|null $dynamicServer */
        $dynamicServer = DynamicTemplateServer::query()
            ->find($this->dynamicTemplateServerId);

        if (! $dynamicServer) {
            return;
        }

        /** @var Server|null $server */
        $server = Server::query()
            ->find($dynamicServer->server_id);

        /** @var DynamicTemplate|null $template */
        $template = DynamicTemplate::query()
            ->find($dynamicServer->dynamic_template_id);

        if (! $server || ! $template) {
            Log::warning(
                'Template application stopped because its server or template no longer exists.',
                [
                    'dynamic_template_server_id' => $dynamicServer->getKey(),
                    'server_id' => $dynamicServer->server_id,
                    'template_id' => $dynamicServer->dynamic_template_id,
                ]
            );

            return;
        }

        $server->refresh();

        if ($server->status !== null) {
            throw new RuntimeException(
                "Server {$server->getKey()} is not ready for template files."
            );
        }

        // Keep the monitor from treating this deliberately stopped server as
        // abandoned while a large template is being copied.
        DynamicServerPowerState::set(
            $server,
            'start'
        );

        // applyTemplateFiles throws unless every file was copied. Retrying this
        // job repeats the complete, idempotent copy and fills any prior gaps.
        $creationService->applyTemplateFiles(
            $template,
            $server
        );

        $serverRepository
            ->setServer($server)
            ->power('start');

        Log::info('Template applied and dynamic server started.', [
            'template_id' => $template->getKey(),
            'server_id' => $server->getKey(),
        ]);
    }

    /**
     * @return int[]
     */
    public function backoff(): array
    {
        return [
            5,
            5,
            10,
            10,
            15,
            30,
        ];
    }

    public function failed(Throwable $exception): void
    {
        Log::critical(
            'Template could not be fully applied; dynamic server was not started.',
            [
                'dynamic_template_server_id' => $this->dynamicTemplateServerId,
                'error' => $exception->getMessage(),
            ]
        );
    }
}
