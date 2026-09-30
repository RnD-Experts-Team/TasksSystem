<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permissions for the Roadmap module. Additive: creates the permissions on the
 * "sanctum" guard and grants them to the existing "admin" role without touching
 * any other grant (givePermissionTo, not syncPermissions).
 */
class RoadmapPermissionSeeder extends Seeder
{
    public const PERMISSIONS = [
        'manage roadmap',           // boards, statuses, tags, posts, roadmap board
        'moderate roadmap',         // moderation queue, comments, visitors, abuse tools
        'manage roadmap settings',  // branding, limits, moderation rules, uploads
        'manage changelog',         // changelog entries
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission, 'guard_name' => 'sanctum'],
                ['name' => $permission, 'guard_name' => 'sanctum']
            );
        }

        $adminRole = Role::firstOrCreate(
            ['name' => 'admin', 'guard_name' => 'sanctum'],
            ['name' => 'admin', 'guard_name' => 'sanctum']
        );

        $adminRole->givePermissionTo(self::PERMISSIONS);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if ($this->command) {
            $this->command->info('Roadmap permissions created and granted to admin.');
        }
    }
}
