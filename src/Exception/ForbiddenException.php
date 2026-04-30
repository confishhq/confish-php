<?php

declare(strict_types=1);

namespace Confish\Exception;

/** HTTP 403 — API key doesn't match the environment, or application disabled. */
final class ForbiddenException extends ConfishException
{
}
