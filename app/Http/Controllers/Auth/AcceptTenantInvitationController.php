<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Shared\Models\TenantInvitation;
use App\Domain\Shared\Models\User;
use App\Domain\Shared\Services\TenantInvitationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\AcceptTenantInvitationRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class AcceptTenantInvitationController extends Controller
{
    public function show(TenantInvitation $invitation, string $token): View
    {
        abort_unless($invitation->acceptsToken($token), 410);

        return view('auth.accept-tenant-invitation', [
            'invitation' => $invitation->loadMissing('organization'),
            'token' => $token,
            'existingUser' => User::query()->where('email', $invitation->email)->exists(),
        ]);
    }

    public function store(
        AcceptTenantInvitationRequest $request,
        TenantInvitation $invitation,
        string $token,
        TenantInvitationService $invitationService,
    ): RedirectResponse {
        $invitationService->accept(
            $invitation,
            $token,
            $request->validated('name'),
            $request->validated('password'),
        );

        return redirect('/portal/login')->with(
            'status',
            'Invitation accepted. You can now sign in to the Portal.',
        );
    }
}
