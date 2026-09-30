<?php

namespace Tests\Feature\Roadmap;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permission matrix of the admin API:
 *  - anonymous -> 401
 *  - authenticated without permissions -> 403 everywhere
 *  - the "admin" ROLE alone (no direct permissions, none granted to the role in the DB) passes
 *    every group, so a deploy that forgot the seeder still works for admins
 *  - each single permission passes only its own group
 */
class AdminPermissionTest extends RoadmapTestCase
{
    use AdminApiTrait;

    private const MANAGE = 'manage roadmap';

    private const MODERATE = 'moderate roadmap';

    private const SETTINGS = 'manage roadmap settings';

    private const CHANGELOG = 'manage changelog';

    /**
     * [method, uri (relative to the admin prefix), permissions that grant access]
     *
     * @return array<string, array{0:string,1:string,2:string[]}>
     */
    public static function endpoints(): array
    {
        $manage = [self::MANAGE];
        $readOrModerate = [self::MANAGE, self::MODERATE];
        $moderate = [self::MODERATE];
        $visitor = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

        $list = [
            // manage roadmap
            ['GET', '/boards', $manage],
            ['POST', '/boards', $manage],
            ['POST', '/boards/reorder', $manage],
            ['GET', '/boards/1', $manage],
            ['PATCH', '/boards/1', $manage],
            ['DELETE', '/boards/1', $manage],
            ['GET', '/boards/1/statuses', $manage],
            ['POST', '/boards/1/statuses', $manage],
            ['POST', '/boards/1/statuses/reorder', $manage],
            ['POST', '/boards/1/statuses/1/make-default', $manage],
            ['PATCH', '/statuses/1', $manage],
            ['DELETE', '/statuses/1', $manage],
            ['GET', '/boards/1/tags', $manage],
            ['POST', '/boards/1/tags', $manage],
            ['POST', '/boards/1/tags/reorder', $manage],
            ['PATCH', '/tags/1', $manage],
            ['DELETE', '/tags/1', $manage],
            ['GET', '/boards/1/roadmap', $manage],
            ['POST', '/boards/1/roadmap/move', $manage],
            ['POST', '/posts', $manage],
            ['PATCH', '/posts/1', $manage],
            ['DELETE', '/posts/1', $manage],
            ['POST', '/posts/1/status', $manage],
            ['PUT', '/posts/1/response', $manage],
            ['DELETE', '/posts/1/response', $manage],
            ['POST', '/posts/1/pin', $manage],
            ['POST', '/posts/1/merge/preview', $manage],
            ['POST', '/posts/1/merge', $manage],
            ['POST', '/posts/1/move', $manage],
            ['PUT', '/posts/1/tags', $manage],
            // manage OR moderate
            ['GET', '/posts', $readOrModerate],
            ['GET', '/posts/1', $readOrModerate],
            ['POST', '/posts/bulk-moderate', $readOrModerate],
            ['POST', '/posts/1/moderate', $readOrModerate],
            ['GET', '/analytics/summary', $readOrModerate],
            // moderate roadmap
            ['GET', '/comments', $moderate],
            ['POST', '/comments/bulk-moderate', $moderate],
            ['POST', '/comments/1/moderate', $moderate],
            ['DELETE', '/comments/1', $moderate],
            ['POST', '/posts/1/comments', $moderate],
            ['GET', '/visitors', $moderate],
            ['GET', "/visitors/$visitor", $moderate],
            ['POST', "/visitors/$visitor/ban", $moderate],
            ['POST', "/visitors/$visitor/unban", $moderate],
            ['POST', '/abuse/bulk-remove', $moderate],
            ['GET', '/abuse/events', $moderate],
            // manage changelog
            ['GET', '/changelog', [self::CHANGELOG]],
            ['POST', '/changelog', [self::CHANGELOG]],
            ['GET', '/changelog/1', [self::CHANGELOG]],
            ['PATCH', '/changelog/1', [self::CHANGELOG]],
            ['DELETE', '/changelog/1', [self::CHANGELOG]],
            ['POST', '/changelog/1/publish', [self::CHANGELOG]],
            ['POST', '/changelog/1/unpublish', [self::CHANGELOG]],
            ['PUT', '/changelog/1/posts', [self::CHANGELOG]],
            // manage roadmap settings
            ['GET', '/settings', [self::SETTINGS]],
            ['PUT', '/settings', [self::SETTINGS]],
            ['POST', '/settings/asset', [self::SETTINGS]],
            ['DELETE', '/settings/asset/logo', [self::SETTINGS]],
            ['POST', '/settings/theme-preview', [self::SETTINGS]],
            // markdown preview: any content editor
            ['POST', '/markdown/preview', [self::MANAGE, self::CHANGELOG, self::SETTINGS]],
        ];

        $cases = [];
        foreach ($list as [$method, $uri, $allowed]) {
            $cases["$method $uri"] = [$method, $uri, $allowed];
        }

        return $cases;
    }

    private function hit(string $method, string $uri)
    {
        return $this->json($method, self::ADMIN_API.$uri, []);
    }

    #[DataProvider('endpoints')]
    public function test_anonymous_requests_get_401(string $method, string $uri): void
    {
        $this->hit($method, $uri)->assertUnauthorized();
    }

    #[DataProvider('endpoints')]
    public function test_authenticated_user_without_permissions_gets_403(string $method, string $uri): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->hit($method, $uri)->assertForbidden();
    }

    #[DataProvider('endpoints')]
    public function test_role_only_admin_passes_every_group_without_seeded_permissions(string $method, string $uri): void
    {
        // Simulate a deploy that never ran the permission seeder: no permission rows at all.
        Permission::query()->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = $this->admin();
        $this->assertCount(0, $admin->getAllPermissions());
        Sanctum::actingAs($admin, ['*']);

        $status = $this->hit($method, $uri)->getStatusCode();

        $this->assertNotContains($status, [401, 403], "admin role blocked on $method $uri");
    }

    #[DataProvider('endpoints')]
    public function test_each_single_permission_passes_only_its_own_group(string $method, string $uri, array $allowed): void
    {
        foreach ([self::MANAGE, self::MODERATE, self::SETTINGS, self::CHANGELOG] as $permission) {
            Sanctum::actingAs($this->staff($permission), ['*']);

            $status = $this->hit($method, $uri)->getStatusCode();

            if (in_array($permission, $allowed, true)) {
                $this->assertNotContains($status, [401, 403], "'$permission' should reach $method $uri");
            } else {
                $this->assertSame(403, $status, "'$permission' must NOT reach $method $uri");
            }
        }
    }

    public function test_a_user_with_several_permissions_gets_the_union(): void
    {
        Sanctum::actingAs($this->staff(self::MODERATE, self::CHANGELOG), ['*']);

        $this->getJson(self::ADMIN_API.'/comments')->assertOk();
        $this->getJson(self::ADMIN_API.'/changelog')->assertOk();
        $this->getJson(self::ADMIN_API.'/settings')->assertForbidden();
        $this->getJson(self::ADMIN_API.'/boards')->assertForbidden();
    }
}
