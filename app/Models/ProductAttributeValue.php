<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CategoryAttributeDataType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductAttributeValue extends Model
{
    use HasUuids;

    protected $fillable = [
        'product_id',
        'category_attribute_id',
        'value_string',
        'value_number',
        'value_boolean',
    ];

    protected function casts(): array
    {
        return [
            'value_number' => 'decimal:4',
            'value_boolean' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function categoryAttribute(): BelongsTo
    {
        return $this->belongsTo(CategoryAttribute::class);
    }

    /**
     * The stored value typed by the attribute's data type (needs categoryAttribute loaded).
     */
    public function typedValue(): string|int|float|bool|null
    {
        return match ($this->categoryAttribute->data_type) {
            CategoryAttributeDataType::STRING, CategoryAttributeDataType::ENUM => $this->value_string,
            CategoryAttributeDataType::INTEGER => $this->value_number === null ? null : (int) $this->value_number,
            CategoryAttributeDataType::DECIMAL => $this->value_number === null ? null : (float) $this->value_number,
            CategoryAttributeDataType::BOOLEAN => $this->value_boolean,
        };
    }
}
