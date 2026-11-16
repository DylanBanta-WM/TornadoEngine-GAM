<?php

namespace TornadoEngine\Gam\Tests;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
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

    public function test_any_command_can_run(): void
    {
        $this->gamPrints('done');

        $this->assertSame(0, app(Gam::class)->run(['update', 'user', 'ann@school.test', 'org', '/Staff'])['exit']);
        Http::assertSent(fn (Request $request) => $request['args'] === ['update', 'user', 'ann@school.test', 'org', '/Staff']);
    }

    public function test_every_command_carries_the_admins_limits(): void
    {
        $this->gamPrints("email\nann@school.test\n");
        Setting::put('gam.limits', json_encode(['slots' => 3, 'wait' => 5, 'timeout' => 90]));

        app(Gam::class)->run(['info', 'domain']);
        Http::assertSent(fn ($request) => $request['slots'] === 3 && $request['wait'] === 5 && $request['timeout'] === 90);

        // Scheduled jobs share the same number of slots but wait and run longer.
        app(Gam::class)->forJob()->groupMembers('teachers@school.test');
        Http::assertSent(fn ($request) => $request['slots'] === 3 && $request['wait'] === 600 && $request['timeout'] === 1800);
    }

    public function test_a_busy_gam_is_reported(): void
    {
        Http::fake(['gam:8000/run' => Http::response(['error' => 'GAM is busy'], 429)]);

        $this->expectExceptionMessage('GAM is busy');
        app(Gam::class)->run(['info', 'domain']);
    }

    public function test_admins_set_the_limits(): void
    {
        Http::fake(['gam:8000/status' => Http::response(['configured' => true])]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/administration/gam')->assertSee('Commands at once')->assertSee('value="5"', false);

        $this->actingAs($admin)->from('/administration/gam')->post('/administration/gam/limits', ['slots' => 0, 'wait' => 10, 'timeout' => 60])->assertSessionHasErrors('slots');
        $this->actingAs($admin)->post('/administration/gam/limits', ['slots' => 2, 'wait' => 15, 'timeout' => 120]);
        $this->assertSame(['slots' => 2, 'wait' => 15, 'timeout' => 120], Gam::limits());

        $this->actingAs(User::factory()->create())->post('/administration/gam/limits', ['slots' => 50, 'wait' => 0, 'timeout' => 3600])->assertForbidden();
    }

    public function test_an_unreachable_sidecar_is_reported(): void
    {
        Http::fake(fn () => throw new ConnectionException('down'));

        $this->expectException(GamException::class);
        app(Gam::class)->run(['info', 'domain']);
    }

    public function test_admins_upload_gams_files_and_check_the_connection(): void
    {
        Http::fake([
            'gam:8000/status' => Http::response(['configured' => false]),
            'gam:8000/config' => Http::response(['configured' => true]),
            'gam:8000/run' => Http::response(['exit' => 0, 'stdout' => '', 'stderr' => '']),
        ]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/administration/gam')->assertSee('GAM')->assertSee('oauth2service.json')->assertDontSee('Remove files');

        $this->actingAs($admin)->from('/administration')->post('/administration/gam/config', [
            'oauth2_txt' => UploadedFile::fake()->createWithContent('oauth2.txt', '{"token": "x"}'),
            'oauth2service_json' => UploadedFile::fake()->createWithContent('oauth2service.json', '{"type": "service_account"}'),
            'client_secrets_json' => UploadedFile::fake()->createWithContent('client_secrets.json', '{"installed": {}}'),
        ])->assertRedirect('/administration');

        Http::assertSent(fn (Request $request) => $request->url() === 'http://gam:8000/config'
            && $request['files']['oauth2service.json'] === '{"type": "service_account"}');

        $this->actingAs($admin)->from('/administration')->post('/administration/gam/check')
            ->assertSessionHas('gam_status', 'GAM can reach your Google Workspace.')
            ->assertSessionHas('gam_ok', true);
    }

    public function test_once_uploaded_the_files_can_be_removed(): void
    {
        $this->gamPrints('');
        Http::fake(['gam:8000/config/remove' => Http::response(['configured' => false])]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/administration/gam')->assertSee('Remove files')->assertDontSee('oauth2service.json');

        $this->actingAs($admin)->from('/administration')->post('/administration/gam/remove')->assertRedirect('/administration');
        Http::assertSent(fn (Request $request) => $request->url() === 'http://gam:8000/config/remove');
    }

    public function test_a_failed_connection_check_shows_an_error(): void
    {
        $this->gamPrints('', exit: 1);

        $this->actingAs(User::factory()->create(['is_admin' => true]))->from('/administration')->post('/administration/gam/check')
            ->assertSessionHasErrors('gam');
    }

    public function test_only_admins_can_change_gam(): void
    {
        $this->actingAs(User::factory()->create())->post('/administration/gam/check')->assertForbidden();
    }

    public function test_the_website_makes_the_sidecar_token_when_env_has_none(): void
    {
        $file = storage_path('app/gam/sidecar-token');
        $saved = is_file($file) ? file_get_contents($file) : null;
        @unlink($file);

        try {
            $token = Gam::sidecarToken();
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
            $this->assertSame($token, Gam::sidecarToken(), 'made once, then kept');
            $this->assertSame('0644', substr(sprintf('%o', fileperms($file)), -4));
        } finally {
            $saved !== null ? file_put_contents($file, $saved) : @unlink($file);
        }
    }
}
