<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('tenant_role_key', 50)->nullable()->after('organization_id');
        });

        DB::table('roles')
            ->whereNotNull('organization_id')
            ->orderBy('id')
            ->get(['id', 'organization_id', 'name'])
            ->groupBy('organization_id')
            ->each(function ($roles): void {
                foreach ([
                    'viewer' => ['viewer', 'portal-viewer'],
                    'operator' => ['operator'],
                    'tenant-admin' => ['tenant-admin', 'admin'],
                ] as $roleKey => $roleNames) {
                    $role = collect($roleNames)
                        ->map(fn (string $name) => $roles->firstWhere('name', $name))
                        ->first(fn (mixed $candidate): bool => $candidate !== null);

                    if ($role !== null) {
                        DB::table('roles')
                            ->where('id', $role->id)
                            ->update(['tenant_role_key' => $roleKey]);
                    }
                }
            });

        Schema::table('roles', function (Blueprint $table) {
            $table->unique(
                ['organization_id', 'tenant_role_key'],
                'roles_organization_tenant_role_key_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique('roles_organization_tenant_role_key_unique');
            $table->dropColumn('tenant_role_key');
        });
    }
};
