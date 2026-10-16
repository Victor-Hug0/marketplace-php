<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CategoryAttributeDataType;
use App\Models\Category;
use App\Models\CategoryAttribute;
use Illuminate\Database\Seeder;

final class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $electronics = Category::query()->firstOrCreate(
            ['slug' => 'eletronicos'],
            [
                'name' => 'Eletrônicos',
                'description' => 'Produtos eletrônicos e tecnologia.',
                'position' => 1,
                'is_active' => true,
            ],
        );

        $phones = Category::query()->firstOrCreate(
            ['slug' => 'celulares'],
            [
                'parent_id' => $electronics->id,
                'name' => 'Celulares',
                'description' => 'Smartphones e acessórios.',
                'position' => 1,
                'is_active' => true,
            ],
        );

        Category::query()->firstOrCreate(
            ['slug' => 'notebooks'],
            [
                'parent_id' => $electronics->id,
                'name' => 'Notebooks',
                'description' => 'Notebooks e ultrabooks.',
                'position' => 2,
                'is_active' => true,
            ],
        );

        $home = Category::query()->firstOrCreate(
            ['slug' => 'casa'],
            [
                'name' => 'Casa',
                'description' => 'Itens para casa e decoração.',
                'position' => 2,
                'is_active' => true,
            ],
        );

        Category::query()->firstOrCreate(
            ['slug' => 'moveis'],
            [
                'parent_id' => $home->id,
                'name' => 'Móveis',
                'description' => 'Móveis e organização.',
                'position' => 1,
                'is_active' => true,
            ],
        );

        CategoryAttribute::query()->firstOrCreate(
            [
                'category_id' => $phones->id,
                'code' => 'color',
            ],
            [
                'label' => 'Cor',
                'data_type' => CategoryAttributeDataType::ENUM,
                'options' => ['preto', 'branco', 'azul'],
                'is_required' => true,
                'is_filterable' => true,
                'position' => 1,
            ],
        );

        CategoryAttribute::query()->firstOrCreate(
            [
                'category_id' => $phones->id,
                'code' => 'storage',
            ],
            [
                'label' => 'Armazenamento',
                'data_type' => CategoryAttributeDataType::ENUM,
                'options' => ['64GB', '128GB', '256GB'],
                'is_required' => true,
                'is_filterable' => true,
                'position' => 2,
            ],
        );
    }
}
