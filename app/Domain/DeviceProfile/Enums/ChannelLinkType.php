<?php

declare(strict_types=1);

namespace App\Domain\DeviceProfile\Enums;

use Filament\Support\Contracts\HasLabel;

enum ChannelLinkType: string implements HasLabel
{
    case StateFeedback = 'state_feedback';
    case AckFeedback = 'ack_feedback';

    public function getLabel(): string
    {
        return match ($this) {
            self::StateFeedback => 'State Feedback',
            self::AckFeedback => 'Ack Feedback',
        };
    }

    public function label(): string
    {
        return $this->getLabel();
    }
}
