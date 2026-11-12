<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Screen;
use App\Models\ScreenRolePermission;
use App\Models\User;
use App\Models\WalkthroughVideo;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\TestUserSeeder;
use Database\Seeders\WalkthroughVideoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// Learning centre — narrated walkthroughs.
class LearningCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
        $this->seed(TestUserSeeder::class);
        $this->seed(WalkthroughVideoSeeder::class);
    }

    public function test_a_video_without_a_file_is_visible_to_nobody(): void
    {
        $video = WalkthroughVideo::where('code', 'screen.users')->firstOrFail();

        $this->assertFalse($video->isVisibleTo($this->account('r08.sysadmin')));
    }

    public function test_a_screen_video_follows_the_screen_grant(): void
    {
        $video = $this->uploaded('screen.request_intake');

        $this->assertTrue($video->isVisibleTo($this->account('r01.employee')));
        // R08 files nothing (2026-10-07), so the intake clip is not for it.
        $this->assertFalse($video->isVisibleTo($this->account('r08.sysadmin')));
    }

    public function test_an_action_video_needs_that_action(): void
    {
        $video = WalkthroughVideo::updateOrCreate(['code' => 'screen.users.edit'], [
            'title_ar' => 'تعديل مستخدم', 'screen_code' => 'users', 'action' => 'edit', 'sort_order' => 999,
        ]);
        $video->forceFill(['disk' => 'local', 'path' => 'x.mp4', 'mime_type' => 'video/mp4'])->save();

        $viewer = User::factory()->create();
        $role = Role::create(['code' => 'C90', 'name_ar' => 'قارئ', 'name_en' => 'Reader']);
        ScreenRolePermission::create([
            'screen_id' => Screen::where('code', 'users')->value('id'),
            'role_id' => $role->id,
            'can_view' => true,
        ]);
        $viewer->roles()->attach($role);

        $this->assertTrue($video->isVisibleTo($this->account('r08.sysadmin')));
        $this->assertFalse($video->isVisibleTo($viewer));
    }

    public function test_a_role_video_needs_the_role(): void
    {
        $video = $this->uploaded('task.hr_prepare_file');

        $this->assertTrue($video->isVisibleTo($this->account('r12.hr')));
        $this->assertFalse($video->isVisibleTo($this->account('r01.employee')));
    }

    public function test_the_manager_video_needs_a_live_direct_report(): void
    {
        $video = $this->uploaded('task.manager_review');
        $manager = $this->account('r02.reviewer');
        $report = $this->account('r01.employee');

        $this->assertTrue($video->isVisibleTo($manager));

        $report->update(['is_active' => false]);
        $this->assertFalse($video->isVisibleTo($manager->fresh()));

        $report->update(['is_active' => true]);
        $report->delete();
        $this->assertFalse($video->isVisibleTo($manager->fresh()));
    }

    public function test_reseeding_keeps_an_uploaded_file(): void
    {
        $video = $this->uploaded('screen.users');
        $path = $video->path;

        $this->seed(WalkthroughVideoSeeder::class);

        $this->assertSame($path, $video->fresh()->path);
    }

    public function test_every_catalogue_screen_exists_and_has_a_route(): void
    {
        $actions = array_map(fn ($a) => substr($a, 4), ScreenRolePermission::ACTIONS);

        foreach (WalkthroughVideoSeeder::VIDEOS as [$code, $screen, $action]) {
            $this->assertNotNull(Screen::where('code', $screen)->value('route'), "$code: screen $screen has no route");
            $this->assertContains($action, $actions, "$code: unknown action $action");
        }
    }

    public function test_after_upload_seeds_the_catalogue(): void
    {
        DB::table('walkthrough_videos')->delete();

        Artisan::call('maintenance:after-upload');

        $this->assertSame(count(WalkthroughVideoSeeder::VIDEOS), WalkthroughVideo::count());
    }

    public function test_learning_center_screen_is_open_to_every_role(): void
    {
        $employee = $this->account('r01.employee')->screenPermissions()['learning_center'];
        $this->assertTrue($employee['can_view']);
        $this->assertFalse($employee['can_edit']);

        $this->assertTrue($this->account('r08.sysadmin')->screenPermissions()['learning_center']['can_edit']);
    }

    public function test_the_list_holds_only_visible_uploaded_videos(): void
    {
        $this->uploaded('screen.request_intake');
        $this->uploaded('screen.users');

        $response = $this->actingAs($this->account('r01.employee'))->getJson('/api/learning-center')->assertOk();

        $this->assertSame(['screen.request_intake'], $response->json('data.*.code'));
        $this->assertNull($response->json('manage'));
        $this->assertGreaterThan(0, $response->json('max_upload_bytes'));
        $this->assertArrayNotHasKey('path', $response->json('data.0'));
    }

    public function test_the_admin_list_has_the_whole_catalogue(): void
    {
        $response = $this->actingAs($this->account('r08.sysadmin'))->getJson('/api/learning-center')->assertOk();

        $this->assertCount(count(WalkthroughVideoSeeder::VIDEOS), $response->json('manage'));
    }

    public function test_the_screen_lookup_returns_that_screens_videos(): void
    {
        $this->uploaded('task.hr_prepare_file');
        $screen = WalkthroughVideo::where('code', 'task.hr_prepare_file')->value('screen_code');

        $this->actingAs($this->account('r12.hr'))->getJson("/api/learning-center/screens/{$screen}")
            ->assertOk()->assertJsonPath('data.0.code', 'task.hr_prepare_file');
        $this->actingAs($this->account('r01.employee'))->getJson("/api/learning-center/screens/{$screen}")
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_only_the_admin_uploads_and_removes(): void
    {
        $video = WalkthroughVideo::where('code', 'screen.users')->firstOrFail();
        $url = "/api/learning-center/{$video->id}/file";
        $mp4 = fn () => UploadedFile::fake()->create('a.mp4', 500, 'video/mp4');

        $this->actingAs($this->account('r01.employee'))
            ->postJson($url, ['file' => $mp4(), 'duration_seconds' => 90])->assertForbidden();

        $admin = $this->actingAs($this->account('r08.sysadmin'));
        $admin->postJson($url, ['file' => $mp4(), 'duration_seconds' => 90])
            ->assertOk()->assertJsonPath('data.has_file', true);
        $first = $video->fresh()->path;
        Storage::disk('local')->assertExists($first);

        $admin->postJson($url, ['file' => $mp4(), 'duration_seconds' => 90])->assertOk();
        $second = $video->fresh()->path;
        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);

        $admin->deleteJson($url)->assertOk()->assertJsonPath('data.has_file', false);
        Storage::disk('local')->assertMissing($second);
    }

    public function test_a_non_mp4_upload_is_refused_in_arabic(): void
    {
        $video = WalkthroughVideo::where('code', 'screen.users')->firstOrFail();

        $this->actingAs($this->account('r08.sysadmin'))
            ->postJson("/api/learning-center/{$video->id}/file", [
                'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
                'duration_seconds' => 90,
            ])->assertUnprocessable()->assertJsonValidationErrors('file')
            ->assertJsonPath('errors.file.0', 'يجب أن يكون الملف بصيغة MP4.');
    }

    public function test_play_gives_a_signed_url_that_streams_with_ranges(): void
    {
        $video = $this->uploaded('screen.request_intake');
        Storage::disk('local')->put($video->path, str_repeat('x', 100));

        $url = $this->actingAs($this->account('r01.employee'))
            ->getJson("/api/learning-center/{$video->id}/play")->assertOk()->json('url');

        $this->app['auth']->forgetGuards();
        $this->get($url, ['Range' => 'bytes=0-9'])->assertStatus(206)->assertHeader('Accept-Ranges', 'bytes');

        // The signature covers path and query only, so a proxy's different host still streams.
        $relative = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);
        $this->get('http://other.test'.$relative, ['Range' => 'bytes=0-9'])->assertStatus(206);
    }

    public function test_play_404s_for_a_video_the_viewer_may_not_watch(): void
    {
        $video = $this->uploaded('screen.users');

        $this->actingAs($this->account('r01.employee'))
            ->getJson("/api/learning-center/{$video->id}/play")->assertNotFound();
    }

    public function test_the_stream_refuses_a_tampered_or_expired_url(): void
    {
        $video = $this->uploaded('screen.request_intake');
        Storage::disk('local')->put($video->path, 'x');
        $url = $this->actingAs($this->account('r01.employee'))
            ->getJson("/api/learning-center/{$video->id}/play")->json('url');

        $this->get(preg_replace('/user=\d+/', 'user=999', $url))->assertForbidden();

        $this->travel(3)->hours();
        $this->get($url)->assertForbidden();
    }

    public function test_the_stream_rechecks_the_grant(): void
    {
        $video = $this->uploaded('task.hr_prepare_file');
        Storage::disk('local')->put($video->path, 'x');
        $hr = $this->account('r12.hr');
        $url = $this->actingAs($hr)->getJson("/api/learning-center/{$video->id}/play")->json('url');

        $hr->roles()->detach(Role::where('code', 'R12')->value('id'));

        $this->get($url)->assertNotFound();
    }

    public function test_the_stream_404s_when_the_file_is_missing_on_disk(): void
    {
        $video = $this->uploaded('screen.request_intake');
        $url = $this->actingAs($this->account('r01.employee'))
            ->getJson("/api/learning-center/{$video->id}/play")->json('url');

        $this->get($url)->assertNotFound();
    }

    public function test_the_stream_refuses_an_inactive_user(): void
    {
        $video = $this->uploaded('task.hr_prepare_file');
        Storage::disk('local')->put($video->path, 'x');
        $hr = $this->account('r12.hr');
        $url = $this->actingAs($hr)->getJson("/api/learning-center/{$video->id}/play")->json('url');

        $hr->update(['is_active' => false]);

        $this->get($url)->assertNotFound();
    }

    /** Marks a catalogue row as having a file, without going through the upload API. */
    private function uploaded(string $code): WalkthroughVideo
    {
        $video = WalkthroughVideo::where('code', $code)->firstOrFail();
        $video->forceFill(['disk' => 'local', 'path' => "walkthroughs/{$code}.mp4", 'mime_type' => 'video/mp4'])->save();

        return $video;
    }

    private function account(string $prefix): User
    {
        return User::where('email', "{$prefix}@abusaleem.test")->firstOrFail();
    }
}
