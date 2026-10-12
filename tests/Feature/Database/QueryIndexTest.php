<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class QueryIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_common_query_indexes_exist(): void
    {
        $this->assertTrue($this->hasIndex('products', 'products_status_created_at_index', ['status', 'created_at']));
        $this->assertTrue($this->hasIndex('products', 'products_status_category_price_index', ['status', 'category_id', 'price']));
        $this->assertTrue($this->hasIndex('products', 'products_deleted_at_index', ['deleted_at']));
        $this->assertTrue($this->hasIndex('categories', 'categories_active_parent_index', ['is_active', 'parent_id']));
        $this->assertTrue($this->hasIndex('orders', 'orders_user_status_created_at_index', ['user_id', 'status', 'created_at']));
        $this->assertTrue($this->hasIndex('promotions', 'promotions_deleted_at_index', ['deleted_at']));
    }

    /**
     * @param  list<string>  $columns
     */
    private function hasIndex(string $table, string $name, array $columns): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['name'] === $name && $index['columns'] === $columns) {
                return true;
            }
        }

        $this->fail("Expected index [{$name}] on {$table} (".implode(', ', $columns).').');
    }
}
