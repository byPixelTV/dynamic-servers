<?php

namespace ByPixelTV\Dynamicservers\Jobs;

use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use ByPixelTV\Dynamicservers\Models\DynamicTemplateServer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class MonitorDynamicServer implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public int $dynamicTemplateServerId
    ) {
    }

    public function handle(
        DaemonServerRepository $repository
    ): void {
        /** @var DynamicTemplateServer|null $dynamicServer */
        $dynamicServer = DynamicTemplateServer::query()
            ->find($this->dynamicTemplateServerId);

        if (!$dynamicServer) {
            return;
        }

        /** @var Server|null $server */
        $server = Server::query()
            ->find($dynamicServer->server_id);

        if (!$server) {
            $dynamicServer->delete();

            return;
        }

        try {
            $server->refresh();

            if ($server->status !== null) {
                self::dispatch($dynamicServer->getKey())
                    ->delay(now()->addSeconds(5));

                return;
            }

            $details = $repository
                ->setServer($server)
                ->getDetails();

            $state = strtolower(
                (string) ($details['state'] ?? '')
            );

            if (in_array($state, ['offline', 'missing'], true)) {
                $this->deleteDynamicServer(
                    $server,
                    $dynamicServer,
                    $repository
                );

                return;
            }

            self::dispatch($dynamicServer->getKey())
                ->delay(now()->addSeconds(5));

        } catch (Throwable $exception) {
            Log::error('Dynamic server monitor failed.', [
                'server_id' => $server->getKey(),
                'error' => $exception->getMessage(),
            ]);

            self::dispatch($dynamicServer->getKey())
                ->delay(now()->addSeconds(5));
        }
    }

    private function deleteDynamicServer(
        Server $server,
        DynamicTemplateServer $dynamicServer,
        DaemonServerRepository $repository
    ): void {
        $serverId = $server->getKey();
        $templateId = $dynamicServer->dynamic_template_id;

        Log::info('Dynamic server is offline. Deleting.', [
            'server_id' => $serverId,
        ]);

        try {
            $repository
                ->setServer($server)
                ->delete();

        } catch (Throwable $exception) {
            Log::warning(
                'Could not delete dynamic server from Wings.',
                [
                    'server_id' => $serverId,
                    'error' => $exception->getMessage(),
                ]
            );
        }

        try {
            $dynamicServer->delete();
            $server->delete();

            Log::info('Dynamic server deleted.', [
                'server_id' => $serverId,
            ]);

            AutoScaleDynamicTemplate::dispatch(
                $templateId
            )->delay(now()->addSecond());

        } catch (Throwable $exception) {
            Log::error(
                'Could not delete dynamic server from database.',
                [
                    'server_id' => $serverId,
                    'error' => $exception->getMessage(),
                ]
            );
        }
    }
}
