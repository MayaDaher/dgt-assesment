<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_the_active_category_tree(): void
    {
        $electronics = Category::factory()->create(['name' => 'Electronics']);
        Category::factory()->childOf($electronics)->create(['name' => 'Phones']);
        Category::factory()->childOf($electronics)->inactive()->create(['name' => 'Hidden Child']);
        Category::factory()->create(['name' => 'Books']);
        Category::factory()->inactive()->create(['name' => 'Hidden Root']);

        $this->getJson('/api/categories')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Books')
            ->assertJsonPath('data.1.name', 'Electronics')
            ->assertJsonCount(1, 'data.1.children')
            ->assertJsonPath('data.1.children.0.name', 'Phones');
    }

    public function test_it_shows_a_category_by_slug(): void
    {
        $category = Category::factory()->create(['slug' => 'electronics']);
        Category::factory()->childOf($category)->create();

        $this->getJson('/api/categories/electronics')
            ->assertOk()
            ->assertJsonPath('data.id', $category->id)
            ->assertJsonCount(1, 'data.children');
    }

    public function test_unknown_or_inactive_category_returns_404(): void
    {
        Category::factory()->inactive()->create(['slug' => 'hidden']);

        $this->getJson('/api/categories/hidden')
            ->assertNotFound()
            ->assertJsonPath('message', 'Category not found.');

        $this->getJson('/api/categories/nope')->assertNotFound();
    }
}
