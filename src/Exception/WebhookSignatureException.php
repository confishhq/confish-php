<?php

declare(strict_types=1);

namespace Confish\Exception;

/** Webhook signature is missing, malformed, or does not match the payload. */
final class WebhookSignatureException extends WebhookVerificationException
{
}
