<?php

namespace ByPixelTV\Dynamicservers\Jobs;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;
use App\Repositories\Daemon\DaemonServerRepository;

class StopAndDeleteDynamicServer implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(
        public int $serverId
    ) {
    }

    /**
     * @throws Throwable
     * @throws ConnectionException
     */
    public function handle(
        DaemonServerRepository $repository
    ): void {
        /** @var Server|null $server */
        $server = Server::query()
            ->find($this->serverId);

        if (!$server) {
            return;
        }

        $cacheKey =
            "dynamicservers:deleting:{$server->getKey()}";

        Cache::put(
            $cacheKey,
            true,
            now()->addMinutes(2)
        );

        try {
            $repository->setServer($server);

            $state = $this->getState(
                $repository
            );

            Log::info(
                'Dynamic server deletion requested.',
                [
                    'server_id' => $server->getKey(),
                    'state' => $state,
                ]
            );

            if (!$this->isOffline($state)) {
                Log::info(
                    'Stopping dynamic server gracefully.',
                    [
                        'server_id' => $server->getKey(),
                    ]
                );

                $repository
                    ->setServer($server)
                    ->power('stop');

                $stopped = $this->waitForOffline(
                    $repository,
                    30
                );

                if (!$stopped) {
                    Log::warning(
                        'Graceful shutdown timed out. Killing dynamic server.',
                        [
                            'server_id' => $server->getKey(),
                        ]
                    );

                    $repository
                        ->setServer($server)
                        ->power('kill');

                    $this->waitForOffline(
                        $repository,
                        10
                    );
                }
            }

            try {
                $repository->delete();
            } catch (Throwable $exception) {
                Log::warning(
                    'Could not delete dynamic server from Wings.',
                    [
                        'server_id' => $server->getKey(),
                        'error' => $exception->getMessage(),
                    ]
                );
            }

            $serverId = $server->getKey();

            $server->delete();

            Log::info(
                'Dynamic server deleted successfully.',
                [
                    'server_id' => $serverId,
                ]
            );
        } catch (Throwable $exception) {
            Log::error(
                'Could not stop and delete dynamic server.',
                [
                    'server_id' => $server->getKey(),
                    'error' => $exception->getMessage(),
                ]
            );

            report($exception);

            throw $exception;
        } finally {
            Cache::forget($cacheKey);
        }
    }

    private function getState(
        DaemonServerRepository $repository
    ): string {
        $details = $repository->getDetails();

        return strtolower(
            (string) ($details['state'] ?? '')
        );
    }

    private function waitForOffline(
        DaemonServerRepository $repository,
        int $timeout
    ): bool {
        $deadline = time() + $timeout;

        while (time() < $deadline) {
            try {
                $state = $this->getState(
                    $repository
                );

                if ($this->isOffline($state)) {
                    return true;
                }
            } catch (Throwable $exception) {
                Log::warning(
                    'Could not retrieve server state while waiting for shutdown.',
                    [
                        'error' => $exception->getMessage(),
                    ]
                );
            }

            sleep(1);
        }

        return false;
    }

    private function isOffline(
        string $state
    ): bool {
        return in_array(
            $state,
            [
                'offline',
                'missing',
            ],
            true
        );
    }
}
