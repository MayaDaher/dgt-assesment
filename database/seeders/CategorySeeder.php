<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    /**
     * @var array<string, list<string>>
     */
    public const TREE = [
        'Electronics' => ['Phones', 'Laptops', 'Audio'],
        'Fashion' => ['Men', 'Women', 'Shoes'],
        'Home & Kitchen' => ['Appliances', 'Furniture'],
        'Books' => [],
        'Sports' => ['Fitness', 'Outdoor'],
    ];

    public function run(): void
    {
        foreach (self::TREE as $parentName => $children) {
            $parent = $this->upsert($parentName);

            foreach ($children as $childName) {
                $this->upsert($childName, $parent);
            }
        }
    }

    private function upsert(string $name, ?Category $parent = null): Category
    {
        $slug = $parent ? Str::slug($parent->name.' '.$name) : Str::slug($name);

        return Category::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'parent_id' => $parent?->id,
                'description' => "Browse our {$name} collection.",
                'is_active' => true,
            ],
        );
    }
}
