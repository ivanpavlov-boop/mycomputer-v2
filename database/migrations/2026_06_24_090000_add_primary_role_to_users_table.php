<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role')->nullable()->after('is_active')->index();
        });

        foreach ([
            'admin' => User::ROLE_SUPER_ADMIN,
            'manager' => User::ROLE_CATALOG_MANAGER,
            'support' => User::ROLE_ORDER_MANAGER,
        ] as $legacyRole => $primaryRole) {
            $roleId = DB::table('roles')->where('name', $legacyRole)->value('id');

            if (! $roleId) {
                continue;
            }

            DB::table('users')
                ->whereNull('role')
                ->whereIn('id', function ($query) use ($roleId): void {
                    $query
                        ->select('model_id')
                        ->from('model_has_roles')
                        ->where('role_id', $roleId)
                        ->where('model_type', User::class);
                })
                ->update(['role' => $primaryRole]);
        }

        DB::table('users')
            ->whereNull('role')
            ->where('email', 'admin@mycomputer.bg')
            ->update(['role' => User::ROLE_SUPER_ADMIN]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('role');
        });
    }
};
