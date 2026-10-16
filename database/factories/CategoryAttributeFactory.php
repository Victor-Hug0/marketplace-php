<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CategoryAttributeDataType;
use App\Models\Category;
use App\Models\CategoryAttribute;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CategoryAttribute>
 */
final class CategoryAttributeFactory extends Factory
{
    public function definition(): array
    {
        $label = fake()->unique()->words(2, true);

        return [
            'category_id' => Category::factory(),
            'code' => Str::slug($label, '_'),
            'label' => ucfirst($label),
            'data_type' => CategoryAttributeDataType::STRING,
            'options' => null,
            'is_required' => false,
            'is_filterable' => true,
            'position' => 0,
        ];
    }

    public function enum(array $options = ['preto', 'branco']): static
    {
        return $this->state(fn (): array => [
            'data_type' => CategoryAttributeDataType::ENUM,
            'options' => $options,
        ]);
    }
}
