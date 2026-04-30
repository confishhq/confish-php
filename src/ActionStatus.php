<?php

declare(strict_types=1);

namespace Confish;

enum ActionStatus: string
{
    case Pending = 'pending';
    case Acknowledged = 'acknowledged';
    case Completed = 'completed';
    case Failed = 'failed';
    case Expired = 'expired';
}
