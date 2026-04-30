<?php

declare(strict_types=1);

namespace Confish\Tests;

use Confish\Action;
use Confish\ActionStatus;
use Confish\Actions;
use Confish\ActionUpdater;
use Confish\Exception\SkipActionException;
use GuzzleHttp\Psr7\Response;

final class ActionsTest extends TestCase
{
    private function pendingJson(string $id = 'a1'): string
    {
        return json_encode([
            'id'              => $id,
            'type'            => 'noop',
            'status'          => 'pending',
            'params'          => null,
            'updates'         => [],
            'result'          => null,
            'expires_at'      => null,
            'acknowledged_at' => null,
            'completed_at'    => null,
            'created_at'      => null,
        ], JSON_THROW_ON_ERROR);
    }

    public function test_list_unwraps_actions_array(): void
    {
        $client = $this->makeClient([
            new Response(200, ['Content-Type' => 'application/json'], '{"actions":['.$this->pendingJson('a1').','.$this->pendingJson('a2').']}'),
        ]);

        $actions = $client->actions->list();

        self::assertCount(2, $actions);
        self::assertSame('a1', $actions[0]->id);
        self::assertInstanceOf(Action::class, $actions[0]);
    }

    public function test_complete_with_result(): void
    {
        $client = $this->makeClient([
            new Response(200, ['Content-Type' => 'application/json'], $this->pendingJson('a1')),
        ]);

        $client->actions->complete('a1', ['order_id' => 'abc']);

        self::assertSame(['result' => ['order_id' => 'abc']], $this->bodyOf(0));
    }

    public function test_complete_without_result_sends_empty_body(): void
    {
        $client = $this->makeClient([
            new Response(200, ['Content-Type' => 'application/json'], $this->pendingJson('a1')),
        ]);

        $client->actions->complete('a1');

        self::assertSame([], $this->bodyOf(0));
    }

    public function test_consume_processes_action_and_completes(): void
    {
        $client = $this->makeClient([
            new Response(200, ['Content-Type' => 'application/json'], '{"actions":['.$this->pendingJson('a1').']}'), // poll
            new Response(200, ['Content-Type' => 'application/json'], $this->pendingJson('a1')),                    // ack
            new Response(200, ['Content-Type' => 'application/json'], $this->pendingJson('a1')),                    // complete
        ]);

        $client->actions->consume(
            handler: function (Action $action, ActionUpdater $updater): array {
                self::assertSame('a1', $action->id);

                return ['filled' => true];
            },
            pollInterval: 0.001,
            shouldStop: fn (): bool => count($this->recorded) >= 3,
        );

        self::assertCount(3, $this->recorded);
        self::assertSame('/c/env_test/actions/a1/complete', $this->recorded[2]['request']->getUri()->getPath());
        self::assertSame(['result' => ['filled' => true]], $this->bodyOf(2));
    }

    public function test_consume_fails_on_handler_exception(): void
    {
        $client = $this->makeClient([
            new Response(200, ['Content-Type' => 'application/json'], '{"actions":['.$this->pendingJson('a1').']}'),
            new Response(200, ['Content-Type' => 'application/json'], $this->pendingJson('a1')), // ack
            new Response(200, ['Content-Type' => 'application/json'], $this->pendingJson('a1')), // fail
        ]);

        $client->actions->consume(
            handler: function (): never {
                throw new \RuntimeException('boom');
            },
            pollInterval: 0.001,
            shouldStop: fn (): bool => count($this->recorded) >= 3,
        );

        self::assertSame('/c/env_test/actions/a1/fail', $this->recorded[2]['request']->getUri()->getPath());
        self::assertSame(['result' => ['error' => 'boom']], $this->bodyOf(2));
    }

    public function test_consume_skips_on_409_ack(): void
    {
        $client = $this->makeClient([
            new Response(200, ['Content-Type' => 'application/json'], '{"actions":['.$this->pendingJson('a1').']}'),
            new Response(409, [], '{"error":"already acknowledged"}'),
        ]);

        $handlerRan = false;
        $client->actions->consume(
            handler: function () use (&$handlerRan): null {
                $handlerRan = true;

                return null;
            },
            pollInterval: 0.001,
            shouldStop: fn (): bool => count($this->recorded) >= 2,
        );

        self::assertFalse($handlerRan);
    }

    public function test_skip_action_keeps_action_acknowledged(): void
    {
        $client = $this->makeClient([
            new Response(200, ['Content-Type' => 'application/json'], '{"actions":['.$this->pendingJson('a1').']}'),
            new Response(200, ['Content-Type' => 'application/json'], $this->pendingJson('a1')), // ack
        ]);

        $client->actions->consume(
            handler: function (): never {
                throw new SkipActionException();
            },
            pollInterval: 0.001,
            shouldStop: fn (): bool => count($this->recorded) >= 2,
        );

        // Only poll + ack; no complete or fail.
        self::assertCount(2, $this->recorded);
    }

    public function test_backoff_delay_constant_for_first_three_polls(): void
    {
        self::assertSame(15.0, Actions::backoffDelay(0, 15.0, 60.0));
        self::assertSame(15.0, Actions::backoffDelay(3, 15.0, 60.0));
    }

    public function test_backoff_delay_doubles_after_three_polls(): void
    {
        self::assertSame(30.0, Actions::backoffDelay(4, 15.0, 60.0));
        self::assertSame(60.0, Actions::backoffDelay(5, 15.0, 60.0));
        self::assertSame(60.0, Actions::backoffDelay(10, 15.0, 60.0));
    }

    public function test_action_status_enum(): void
    {
        $action = Action::fromArray([
            'id' => 'a', 'type' => 'noop', 'status' => 'completed',
        ]);
        self::assertSame(ActionStatus::Completed, $action->status);
    }
}
