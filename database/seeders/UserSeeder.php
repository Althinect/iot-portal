<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Shared\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public const DEFAULT_SUPER_ADMIN_NAME = 'Admin';

    public const DEFAULT_SUPER_ADMIN_EMAIL = 'admin@admin.com';

    public const DEFAULT_SUPER_ADMIN_PASSWORD = 'password';

    public function run(): void
    {
        User::query()->firstOrCreate(
            ['email' => self::DEFAULT_SUPER_ADMIN_EMAIL],
            [
                'name' => self::DEFAULT_SUPER_ADMIN_NAME,
                'password' => self::DEFAULT_SUPER_ADMIN_PASSWORD,
                'is_super_admin' => true,
            ],
        );
    }
}
