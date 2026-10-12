<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminCategoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->admin()->create());
    }

    public function test_guests_and_customers_cannot_manage_categories(): void
    {
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/admin/categories', ['name' => 'X'])->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/admin/categories', ['name' => 'X'])
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_admin_can_create_update_and_delete_a_category(): void
    {
        $parent = Category::factory()->create(['name' => 'Fashion']);

        $created = $this->postJson('/api/admin/categories', [
            'name' => 'Shoes',
            'parent_id' => $parent->id,
        ])->assertCreated()->assertJsonPath('data.name', 'Shoes')->assertJsonPath('data.slug', 'shoes');

        $id = $created->json('data.id');

        $this->patchJson("/api/admin/categories/{$id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->getJson('/api/admin/categories')
            ->assertOk()
            ->assertJsonPath('data.0.children.0.id', $id);

        $this->deleteJson("/api/admin/categories/{$id}")->assertOk();
        $this->assertDatabaseMissing('categories', ['id' => $id]);
    }

    public function test_a_child_category_cannot_be_used_as_a_parent(): void
    {
        $root = Category::factory()->create();
        $child = Category::factory()->childOf($root)->create();

        $this->postJson('/api/admin/categories', [
            'name' => 'Too Deep',
            'parent_id' => $child->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['parent_id']);
    }

    public function test_a_category_with_children_cannot_become_a_subcategory(): void
    {
        $root = Category::factory()->create();
        $other = Category::factory()->create();
        Category::factory()->childOf($root)->create();

        $this->patchJson("/api/admin/categories/{$root->id}", ['parent_id' => $other->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id']);
    }
}
