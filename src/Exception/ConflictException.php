<?php

declare(strict_types=1);

namespace Confish\Exception;

/** HTTP 409 — typically the action is no longer actionable. */
final class ConflictException extends ConfishException
{
}
