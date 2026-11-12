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
