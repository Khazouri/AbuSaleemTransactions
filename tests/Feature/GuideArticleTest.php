<?php

namespace Tests\Feature;

use App\Models\GuideArticle;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 27 — the user guide: everyone reads it, R08 maintains it.
 *
 * That split is not new policy; it is what the `user_guide` screen's seeded
 * grants (view/print to all roles, add/edit/delete to R08) have described
 * since Stage 3. These tests hold the endpoints to it.
 */
class GuideArticleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_an_admin_can_create_read_update_and_delete_an_article(): void
    {
        $admin = $this->admin();

        $created = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/guide-articles', [
                'code' => 'intake.creating-a-transaction',
                'category' => 'المعاملات',
                'title_ar' => 'كيفية إنشاء معاملة',
                'title_en' => 'Creating a transaction',
                'body_ar' => "افتح شاشة استلام المعاملة.\n\nأدخل البيانات ثم أرفق المستندات.",
                'body_en' => 'Open the intake screen.',
                'sort_order' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'intake.creating-a-transaction')
            ->assertJsonPath('data.is_active', true);

        $id = $created->json('data.id');

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/guide-articles/{$id}", [
                'code' => 'intake.creating-a-transaction',
                'title_ar' => 'كيفية إنشاء معاملة جديدة',
                'body_ar' => 'نص محدّث.',
            ])
            ->assertOk()
            ->assertJsonPath('data.title_ar', 'كيفية إنشاء معاملة جديدة');

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/guide-articles/{$id}")
            ->assertOk();

        $this->assertDatabaseMissing('guide_articles', ['id' => $id]);
    }

    public function test_articles_are_ordered_by_category_then_sort_order(): void
    {
        $this->article(['code' => 'b', 'category' => 'الاعتمادات', 'sort_order' => 1]);
        $this->article(['code' => 'c', 'category' => 'المعاملات', 'sort_order' => 2]);
        $this->article(['code' => 'a', 'category' => 'المعاملات', 'sort_order' => 1]);

        $this->actingAs($this->userWithRole('R01'), 'sanctum')
            ->getJson('/api/guide-articles')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'b')
            ->assertJsonPath('data.1.code', 'a')
            ->assertJsonPath('data.2.code', 'c');
    }

    /**
     * A draft is an article someone is still writing. Showing it to readers
     * would defeat the flag entirely, so the two audiences see different lists.
     */
    public function test_drafts_are_hidden_from_readers_and_visible_to_editors(): void
    {
        $this->article(['code' => 'published', 'is_active' => true]);
        $this->article(['code' => 'draft', 'is_active' => false]);

        $this->actingAs($this->userWithRole('R02'), 'sanctum')
            ->getJson('/api/guide-articles')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'published');

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/guide-articles')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_every_role_may_read_the_guide_but_only_r08_may_change_it(): void
    {
        $article = $this->article(['code' => 'reading']);

        // One from each corner of the matrix: intake, review, ministry.
        foreach (['R01', 'R02', 'R06'] as $roleCode) {
            $reader = $this->userWithRole($roleCode);

            $this->actingAs($reader, 'sanctum')->getJson('/api/guide-articles')->assertOk();
            $this->actingAs($reader, 'sanctum')
                ->postJson('/api/guide-articles', ['code' => 'x', 'title_ar' => 'ع', 'body_ar' => 'ن'])
                ->assertForbidden();
            $this->actingAs($reader, 'sanctum')
                ->putJson("/api/guide-articles/{$article->id}", ['code' => 'y', 'title_ar' => 'ع', 'body_ar' => 'ن'])
                ->assertForbidden();
            $this->actingAs($reader, 'sanctum')
                ->deleteJson("/api/guide-articles/{$article->id}")
                ->assertForbidden();
        }

        $this->assertDatabaseHas('guide_articles', ['id' => $article->id, 'code' => 'reading']);
    }

    public function test_validation_rejects_a_duplicate_code_but_allows_an_article_to_keep_its_own(): void
    {
        $existing = $this->article(['code' => 'taken']);
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/guide-articles', [
                'code' => 'taken',
                'title_ar' => 'عنوان',
                'body_ar' => 'محتوى',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        // Saving an article without changing its code is not a collision.
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/guide-articles/{$existing->id}", [
                'code' => 'taken',
                'title_ar' => 'عنوان محدّث',
                'body_ar' => 'محتوى',
            ])
            ->assertOk();
    }

    public function test_arabic_title_and_body_are_required(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/guide-articles', ['code' => 'incomplete', 'title_en' => 'English only'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title_ar', 'body_ar']);
    }

    // --- helpers ------------------------------------------------------------

    /** @param  array<string, mixed>  $attributes */
    private function article(array $attributes = []): GuideArticle
    {
        return GuideArticle::create(array_merge([
            'code' => 'article-'.fake()->unique()->numberBetween(1, 9999),
            'category' => 'عام',
            'title_ar' => 'عنوان المقال',
            'body_ar' => 'محتوى المقال.',
            'sort_order' => 0,
            'is_active' => true,
        ], $attributes));
    }

    private function admin(): User
    {
        return User::where('email', 'admin@abusaleem.test')->firstOrFail();
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
