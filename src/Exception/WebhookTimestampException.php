<?php

declare(strict_types=1);

namespace Confish\Exception;

/** Webhook signature matched, but its timestamp is outside the allowed tolerance. */
final class WebhookTimestampException extends WebhookVerificationException
{
}
