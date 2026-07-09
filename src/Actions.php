<?php

declare(strict_types=1);

namespace Confish;

use Confish\Exception\ConflictException;
use Confish\Exception\SkipActionException;

final class Actions
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $envId,
    ) {
    }

    /**
     * @return list<Action>
     */
    public function list(): array
    {
        $response = $this->http->request('GET', "/c/{$this->envId}/actions");
        /** @var list<array<string, mixed>> $actions */
        $actions = is_array($response) && isset($response['actions']) && is_array($response['actions'])
            ? $response['actions']
            : [];

        return array_map(static fn (array $a): Action => Action::fromArray($a), $actions);
    }

    public function ack(string $actionId): Action
    {
        return Action::fromArray(
            $this->http->request('POST', "/c/{$this->envId}/actions/{$actionId}/ack") ?? []
        );
    }

    /**
     * Appends a progress note to the action's timeline.
     *
     * @param  array<string, mixed>|null  $data
     */
    public function progress(string $actionId, string $message, ?array $data = null): Action
    {
        $body = ['message' => $message];
        if ($data !== null) {
            $body['data'] = $data;
        }

        return Action::fromArray(
            $this->http->request('POST', "/c/{$this->envId}/actions/{$actionId}/update", $body) ?? []
        );
    }

    /** @param  array<string, mixed>|null  $result */
    public function complete(string $actionId, ?array $result = null): Action
    {
        $body = $result !== null ? ['result' => $result] : [];

        return Action::fromArray(
            $this->http->request('POST', "/c/{$this->envId}/actions/{$actionId}/complete", $body) ?? []
        );
    }

    /** @param  array<string, mixed>|null  $result */
    public function fail(string $actionId, ?array $result = null): Action
    {
        $body = $result !== null ? ['result' => $result] : [];

        return Action::fromArray(
            $this->http->request('POST', "/c/{$this->envId}/actions/{$actionId}/fail", $body) ?? []
        );
    }

    /**
     * Long-running consumer loop. Polls for pending actions, acknowledges them,
     * runs $handler, and reports completion or failure based on the handler's outcome.
     *
     * The handler receives an Action and ActionUpdater. Returning an array becomes
     * the action's `result` on completion. Throwing fails the action with
     * `{"error": <message>}`. Throwing SkipActionException leaves the action
     * acknowledged without completing or failing it.
     *
     * After 3 consecutive empty polls the loop doubles its sleep up to
     * $maxPollInterval, resetting to $pollInterval the moment any action is processed.
     *
     * Pass $shouldStop to halt the loop — typically wired to pcntl_signal handlers.
     *
     * @param  callable(Action, ActionUpdater): (array<string, mixed>|null)  $handler
     * @param  callable(\Throwable, Action): void|null  $onError
     * @param  callable(): bool|null  $shouldStop
     */
    public function consume(
        callable $handler,
        float $pollInterval = 15.0,
        float $maxPollInterval = 60.0,
        ?callable $shouldStop = null,
        ?callable $onError = null,
    ): void {
        if ($pollInterval <= 0) {
            $pollInterval = 15.0;
        }
        if ($maxPollInterval <= 0) {
            $maxPollInterval = 60.0;
        }
        $stop = $shouldStop ?? static fn (): bool => false;
        $emptyPolls = 0;
        $placeholder = new Action('', '', ActionStatus::Pending);

        while (! $stop()) {
            try {
                $actions = $this->list();
            } catch (\Throwable $e) {
                if ($onError !== null) {
                    $onError($e, $placeholder);
                }
                $this->sleep(self::backoffDelay($emptyPolls, $pollInterval, $maxPollInterval), $stop);

                continue;
            }

            $pending = array_values(array_filter(
                $actions,
                static fn (Action $a): bool => $a->status === ActionStatus::Pending,
            ));

            if ($pending === []) {
                $emptyPolls++;
                $this->sleep(self::backoffDelay($emptyPolls, $pollInterval, $maxPollInterval), $stop);

                continue;
            }

            $emptyPolls = 0;

            foreach ($pending as $action) {
                if ($stop()) {
                    return;
                }
                $this->processAction($action, $handler, $onError, $stop);
            }
        }
    }

    /**
     * @param  callable(Action, ActionUpdater): (array<string, mixed>|null)  $handler
     * @param  callable(\Throwable, Action): void|null  $onError
     * @param  callable(): bool  $stop
     */
    private function processAction(Action $action, callable $handler, ?callable $onError, callable $stop): void
    {
        try {
            $this->ack($action->id);
        } catch (ConflictException) {
            return;
        } catch (\Throwable $e) {
            if ($onError !== null) {
                $onError($e, $action);
            }

            return;
        }

        $updater = new ActionUpdater($this, $action->id);

        try {
            $result = $handler($action, $updater);
        } catch (SkipActionException) {
            return;
        } catch (\Throwable $e) {
            if ($onError !== null) {
                $onError($e, $action);
            }
            if ($stop()) {
                return;
            }
            try {
                $this->fail($action->id, ['error' => $e->getMessage()]);
            } catch (\Throwable $failExc) {
                if ($onError !== null) {
                    $onError($failExc, $action);
                }
            }

            return;
        }

        if ($stop()) {
            return;
        }

        try {
            $this->complete($action->id, is_array($result) ? $result : null);
        } catch (\Throwable $e) {
            if ($onError !== null) {
                $onError($e, $action);
            }
        }
    }

    /** @param  callable(): bool  $stop */
    private function sleep(float $seconds, callable $stop): void
    {
        $end = microtime(true) + $seconds;
        while (microtime(true) < $end) {
            if ($stop()) {
                return;
            }
            usleep(50_000); // 50ms slices so $stop is responsive
        }
    }

    /** @internal */
    public static function backoffDelay(int $emptyPolls, float $base, float $max): float
    {
        if ($emptyPolls <= 3) {
            return $base;
        }

        return min($base * (2 ** ($emptyPolls - 3)), $max);
    }
}
