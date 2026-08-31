<?php

declare(strict_types=1);

namespace App\Domain\Shared\Data;

use App\Domain\Shared\Models\TenantInvitation;

final readonly class TenantInvitationDelivery
{
    public function __construct(
        public TenantInvitation $invitation,
        public string $url,
    ) {}
}
