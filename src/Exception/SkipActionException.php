<?php

declare(strict_types=1);

namespace Confish\Exception;

/**
 * Thrown inside an action handler to leave the action acknowledged without
 * completing or failing it. The action will stay acknowledged until it expires.
 */
final class SkipActionException extends \RuntimeException
{
}
