<?php

namespace TornadoEngine\Gam\Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use TornadoEngine\Gam\Gam;
use TornadoEngine\Gam\GamException;

class GamTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(Gam::class, new Gam('http://gam:8000', 'test-token'));
    }

    /** Fakes the sidecar: GAM prints this CSV for any command. */
    private function gamPrints(string $csv, int $exit = 0): void
    {
        Http::fake([
            'gam:8000/status' => Http::response(['configured' => true]),
            'gam:8000/run' => Http::response(['exit' => $exit, 'stdout' => $csv, 'stderr' => $exit ? 'ERROR: Not authorized' : 'Getting...']),
            'gam:8000/config' => Http::response(['configured' => true]),
        ]);
    }

    public function test_it_reads_groups(): void
    {
        $this->gamPrints("email,name\nTeachers@School.test,Teachers\nstaff@school.test,\"Staff, all\"\n");
        $this->assertSame([
            ['email' => 'teachers@school.test', 'name' => 'Teachers'],
            ['email' => 'staff@school.test', 'name' => 'Staff, all'],
        ], app(Gam::class)->groups());
    }

    public function test_it_reads_group_members_once_each(): void
    {
        $this->gamPrints("group,email\nteachers@school.test,Ann@school.test\nteachers@school.test,bo@school.test\nteachers@school.test,ann@school.test\n");
        $this->assertSame(['ann@school.test', 'bo@school.test'], app(Gam::class)->groupMembers('teachers@school.test'));
    }

    public function test_it_reads_people_in_an_ou_and_leaves_out_suspended_accounts(): void
    {
        $this->gamPrints("primaryEmail,name.fullName,orgUnitPath,suspended\nann@school.test,Ann Example,/Staff,False\nold@school.test,Old Account,/Staff,True\n");

        $this->assertSame([['email' => 'ann@school.test', 'name' => 'Ann Example', 'ou' => '/Staff']], app(Gam::class)->usersIn('/Staff'));

        Http::assertSent(fn (Request $request) => $request->url() === 'http://gam:8000/run'
            && $request['args'] === ['ou_and_children', '/Staff', 'print', 'users', 'fields', 'primaryemail,fullname,ou,suspended']
            && $request->hasHeader('Authorization', 'Bearer test-token'));
    }

    public function test_gam_errors_are_reported(): void
    {
        $this->gamPrints('', exit: 1);

        $this->expectException(GamException::class);
        $this->expectExceptionMessage('Not authorized');
        app(Gam::class)->orgUnits();
    }

    public function test_commands_the_sidecar_refuses_are_reported(): void
    {
        Http::fake(['gam:8000/run' => Http::response(['error' => 'command not allowed'], 403)]);

        $this->expectException(GamException::class);
        app(Gam::class)->run(['delete', 'user', 'ann@school.test']);
    }

    public function test_admins_upload_gams_files_and_check_the_connection(): void
    {
        $this->gamPrints('');
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/administration')->assertSee('GAM')->assertSee('oauth2service.json');

        $this->actingAs($admin)->from('/administration')->post('/administration/gam/config', [
            'oauth2_txt' => UploadedFile::fake()->createWithContent('oauth2.txt', '{"token": "x"}'),
            'oauth2service_json' => UploadedFile::fake()->createWithContent('oauth2service.json', '{"type": "service_account"}'),
            'client_secrets_json' => UploadedFile::fake()->createWithContent('client_secrets.json', '{"installed": {}}'),
        ])->assertRedirect('/administration');

        Http::assertSent(fn (Request $request) => $request->url() === 'http://gam:8000/config'
            && $request['files']['oauth2service.json'] === '{"type": "service_account"}');

        $this->actingAs($admin)->from('/administration')->post('/administration/gam/check')
            ->assertSessionHas('gam_status', 'GAM can reach your Google Workspace.');
    }

    public function test_only_admins_can_change_gam(): void
    {
        $this->actingAs(User::factory()->create())->post('/administration/gam/check')->assertForbidden();
    }
}
