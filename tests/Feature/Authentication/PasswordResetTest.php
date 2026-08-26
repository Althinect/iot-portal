<?php

use App\Domain\Shared\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;

it('makes password reset pages available on both panels', function (): void {
    $this->get('/admin/password-reset/request')->assertOk();
    $this->get('/portal/password-reset/request')->assertOk();
});

it('generates password reset links for the correct panel', function (): void {
    $admin = new User(['email' => 'admin@example.com', 'is_super_admin' => true]);
    $portalUser = new User(['email' => 'portal@example.com', 'is_super_admin' => false]);

    $adminResetUrl = (new ResetPassword('admin-token'))->toMail($admin)->actionUrl;
    $portalResetUrl = (new ResetPassword('portal-token'))->toMail($portalUser)->actionUrl;

    expect($adminResetUrl)->toContain('/admin/')
        ->toContain('admin-token')
        ->and($portalResetUrl)->toContain('/portal/')
        ->toContain('portal-token');
});
