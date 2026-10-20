<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CategoryAttributeDataType;
use Database\Factories\CategoryAttributeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CategoryAttribute extends Model
{
    /** @use HasFactory<CategoryAttributeFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'category_id',
        'code',
        'label',
        'data_type',
        'options',
        'is_required',
        'is_filterable',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'data_type' => CategoryAttributeDataType::class,
            'options' => 'array',
            'is_required' => 'boolean',
            'is_filterable' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function productValues(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class);
    }
}
