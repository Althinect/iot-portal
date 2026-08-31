<?php

declare(strict_types=1);

namespace App\Domain\Shared\Services;

use App\Domain\Authorization\Enums\TenantRole;
use App\Domain\Authorization\Services\TenantAuthorization;
use App\Domain\Authorization\Services\TenantRoleManager;
use App\Domain\Shared\Data\TenantInvitationDelivery;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\TenantInvitation;
use App\Domain\Shared\Models\User;
use App\Domain\Shared\Permissions\OrganizationMemberPermission;
use App\Notifications\TenantInvitationNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TenantInvitationService
{
    private const int EXPIRATION_DAYS = 7;

    public function __construct(
        private TenantAuthorization $authorization,
        private TenantRoleManager $roleManager,
    ) {}

    public function invite(
        Organization $organization,
        string $email,
        TenantRole $tenantRole,
        User $invitedBy,
        bool $sendNotification = true,
    ): TenantInvitationDelivery {
        $this->authorizeInvitation($organization, $invitedBy);
        $normalizedEmail = Str::lower(trim($email));

        if ($organization->users()->where('email', $normalizedEmail)->exists()) {
            throw ValidationException::withMessages([
                'email' => 'This user is already a member of the organization.',
            ]);
        }

        $delivery = DB::transaction(function () use (
            $organization,
            $normalizedEmail,
            $tenantRole,
            $invitedBy,
        ): TenantInvitationDelivery {
            $token = Str::random(64);
            $invitation = TenantInvitation::query()
                ->where('organization_id', $organization->id)
                ->where('email', $normalizedEmail)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->latest('id')
                ->first() ?? new TenantInvitation;

            $invitation->forceFill([
                'organization_id' => $organization->id,
                'invited_by_user_id' => $invitedBy->id,
                'email' => $normalizedEmail,
                'tenant_role_key' => $tenantRole,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDays(self::EXPIRATION_DAYS),
                'last_sent_at' => now(),
                'accepted_at' => null,
                'revoked_at' => null,
            ])->save();

            return new TenantInvitationDelivery(
                invitation: $invitation,
                url: route('portal.invitations.show', [
                    'invitation' => $invitation,
                    'token' => $token,
                ]),
            );
        });

        if ($sendNotification) {
            Notification::route('mail', $delivery->invitation->email)
                ->notify(new TenantInvitationNotification($delivery->invitation, $delivery->url));
        }

        return $delivery;
    }

    public function resend(
        TenantInvitation $invitation,
        User $invitedBy,
        bool $sendNotification = true,
    ): TenantInvitationDelivery {
        $invitation->loadMissing('organization');

        return $this->invite(
            $invitation->organization,
            $invitation->email,
            $invitation->tenant_role_key,
            $invitedBy,
            $sendNotification,
        );
    }

    public function revoke(TenantInvitation $invitation, User $revokedBy): void
    {
        $invitation->loadMissing('organization');
        $this->authorizeInvitation($invitation->organization, $revokedBy);

        $invitation->forceFill(['revoked_at' => now()])->save();
    }

    public function accept(
        TenantInvitation $invitation,
        string $token,
        ?string $name,
        ?string $password,
    ): User {
        return DB::transaction(function () use ($invitation, $token, $name, $password): User {
            $lockedInvitation = TenantInvitation::query()
                ->lockForUpdate()
                ->findOrFail($invitation->id);

            if (! $lockedInvitation->acceptsToken($token)) {
                throw ValidationException::withMessages([
                    'invitation' => 'This invitation is invalid or has expired.',
                ]);
            }

            $user = User::query()->where('email', $lockedInvitation->email)->first();

            if (! $user instanceof User) {
                if (! is_string($name) || trim($name) === '' || ! is_string($password)) {
                    throw ValidationException::withMessages([
                        'name' => 'Your name and password are required.',
                    ]);
                }

                $user = User::query()->create([
                    'name' => trim($name),
                    'email' => $lockedInvitation->email,
                    'password' => Hash::make($password),
                    'is_super_admin' => false,
                ]);
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            $lockedInvitation->loadMissing('organization');
            $lockedInvitation->organization->users()->syncWithoutDetaching([$user->id]);
            $this->roleManager->assign(
                $user,
                $lockedInvitation->organization,
                $lockedInvitation->tenant_role_key,
            );
            $lockedInvitation->forceFill(['accepted_at' => now()])->save();

            return $user;
        });
    }

    private function authorizeInvitation(Organization $organization, User $user): void
    {
        if (! $this->authorization->allows(
            $user,
            OrganizationMemberPermission::INVITE,
            $organization->id,
        )) {
            throw new AuthorizationException;
        }
    }
}
