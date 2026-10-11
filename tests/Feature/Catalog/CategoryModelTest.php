<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_slug_is_generated_from_name_when_missing(): void
    {
        $category = Category::create(['name' => 'Home & Garden']);

        $this->assertSame('home-garden', $category->slug);
        $this->assertTrue($category->fresh()->is_active);
    }

    public function test_slug_must_be_unique(): void
    {
        Category::create(['name' => 'Books']);

        $this->expectException(QueryException::class);
        Category::create(['name' => 'Books']);
    }

    public function test_parent_and_children_relations(): void
    {
        $parent = Category::factory()->create();
        $child = Category::factory()->childOf($parent)->create();

        $this->assertTrue($child->parent->is($parent));
        $this->assertTrue($parent->children->first()->is($child));
        $this->assertEqualsCanonicalizing([$parent->id, $child->id], $parent->selfAndChildIds());
    }

    public function test_active_and_roots_scopes(): void
    {
        $root = Category::factory()->create();
        Category::factory()->childOf($root)->create();
        Category::factory()->inactive()->create();

        $this->assertSame(2, Category::query()->active()->count());
        $this->assertSame(2, Category::query()->roots()->count());
    }

    public function test_deleting_a_parent_promotes_children_to_roots(): void
    {
        $parent = Category::factory()->create();
        $child = Category::factory()->childOf($parent)->create();

        $parent->delete();

        $this->assertNull($child->fresh()->parent_id);
    }
}
