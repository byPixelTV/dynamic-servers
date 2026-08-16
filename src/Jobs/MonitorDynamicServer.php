<?php

namespace ByPixelTV\Dynamicservers\Jobs;

use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use ByPixelTV\Dynamicservers\Models\DynamicTemplateServer;
use ByPixelTV\Dynamicservers\Support\DynamicServerPowerState;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
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

        $lock = Cache::lock(
            "dynamicservers:monitor:{$server->getKey()}",
            10
        );

        if (!$lock->get()) {
            $this->scheduleNext($dynamicServer);

            return;
        }

        try {
            $server->refresh();

            if ($server->status !== null) {
                $this->scheduleNext($dynamicServer);

                return;
            }

            $details = $repository
                ->setServer($server)
                ->getDetails();

            $state = strtolower(
                (string) ($details['state'] ?? '')
            );

            $powerAction = DynamicServerPowerState::get(
                $server
            );

            Log::debug('Dynamic server monitor state.', [
                'server_id' => $server->getKey(),
                'state' => $state,
            ]);

            $offlineKey =
                "dynamicservers:offline-since:{$server->getKey()}";

            if ($state === 'running') {
                Cache::forget($offlineKey);

                DynamicServerPowerState::clear(
                    $server
                );

                $this->scheduleNext(
                    $dynamicServer
                );

                return;
            }

            if (
                in_array(
                    $state,
                    ['offline', 'missing'],
                    true
                )
                && in_array(
                    $powerAction,
                    ['stop', 'kill'],
                    true
                )
            ) {
                Cache::forget($offlineKey);

                DynamicServerPowerState::clear(
                    $server
                );

                $this->deleteDynamicServer(
                    $server,
                    $repository
                );

                return;
            }

            if (
                in_array(
                    $state,
                    ['offline', 'missing'],
                    true
                )
                && in_array(
                    $powerAction,
                    ['restart', 'start'],
                    true
                )
            ) {
                Cache::forget($offlineKey);

                $this->scheduleNext(
                    $dynamicServer
                );

                return;
            }

            if (
                !in_array(
                    $state,
                    ['offline', 'missing'],
                    true
                )
            ) {
                Cache::forget($offlineKey);

                $this->scheduleNext(
                    $dynamicServer
                );

                return;
            }

            $offlineSince = Cache::get(
                $offlineKey
            );

            if ($offlineSince === null) {
                Cache::put(
                    $offlineKey,
                    now()->timestamp,
                    now()->addMinutes(5)
                );

                $this->scheduleNext(
                    $dynamicServer
                );

                return;
            }

            if (
                now()->timestamp - (int) $offlineSince
                < 10
            ) {
                $this->scheduleNext(
                    $dynamicServer
                );

                return;
            }

            Cache::forget($offlineKey);

            $this->deleteDynamicServer(
                $server,
                $repository
            );
        } catch (Throwable $exception) {
            Log::error(
                'Dynamic server monitor failed.',
                [
                    'server_id' =>
                        $server->getKey(),

                    'error' =>
                        $exception->getMessage(),
                ]
            );

            $this->scheduleNext(
                $dynamicServer
            );
        } finally {
            $lock->release();
        }
    }

    private function scheduleNext(
        DynamicTemplateServer $dynamicServer
    ): void {
        self::dispatch(
            $dynamicServer->getKey()
        )->delay(
            now()->addSeconds(5)
        );
    }

    private function deleteDynamicServer(
        Server $server,
        DaemonServerRepository $repository
    ): void {
        $serverId = $server->getKey();

        Log::info(
            'Dynamic server confirmed offline. Deleting.',
            [
                'server_id' => $serverId,
            ]
        );

        try {
            $repository
                ->setServer($server)
                ->delete();
        } catch (Throwable $exception) {
            Log::warning(
                'Could not delete dynamic server from Wings.',
                [
                    'server_id' => $serverId,
                    'error' =>
                        $exception->getMessage(),
                ]
            );
        }

        try {
            $server->delete();

            Log::info(
                'Dynamic server deleted.',
                [
                    'server_id' => $serverId,
                ]
            );
        } catch (Throwable $exception) {
            Log::error(
                'Could not delete dynamic server from database.',
                [
                    'server_id' => $serverId,
                    'error' =>
                        $exception->getMessage(),
                ]
            );
        }
    }
}
