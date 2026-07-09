<?php

declare(strict_types=1);

namespace Confish\Exception;

/**
 * Webhook verification failed. Catch this to reject a webhook for any reason;
 * catch the subclasses to distinguish a bad signature from a stale timestamp.
 */
abstract class WebhookVerificationException extends ConfishException
{
}
