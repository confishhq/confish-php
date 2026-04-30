<?php

declare(strict_types=1);

namespace Confish;

/** Passed to action handlers so they can append timeline updates. */
final class ActionUpdater
{
    public function __construct(
        private readonly Actions $actions,
        private readonly string $actionId,
    ) {
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public function update(string $message, ?array $data = null): Action
    {
        return $this->actions->update($this->actionId, $message, $data);
    }
}
