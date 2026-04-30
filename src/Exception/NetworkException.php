<?php

declare(strict_types=1);

namespace Confish\Exception;

/** Transport-level failure (DNS, TCP, TLS, refused connection). */
final class NetworkException extends ConfishException
{
}
