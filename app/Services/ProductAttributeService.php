<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CategoryAttributeDataType;
use App\Models\CategoryAttribute;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use Illuminate\Validation\ValidationException;

final class ProductAttributeService
{
    private const MAX_NUMBER = 99_999_999_999_999;

    /**
     * Sets values keyed by attribute code; null or "" removes a value. Codes not listed stay untouched.
     *
     * @param  array<string, mixed>  $values
     *
     * @throws ValidationException when a code is not in the product's category or a value does not fit its type
     */
    public function apply(Product $product, array $values): void
    {
        if ($values === []) {
            return;
        }

        $attributes = CategoryAttribute::query()
            ->where('category_id', $product->category_id)
            ->get()
            ->keyBy('code');

        $errors = [];
        $upserts = [];
        $removals = [];

        foreach ($values as $code => $value) {
            $attribute = $attributes->get($code);

            if ($attribute === null) {
                $errors["attributes.{$code}"][] = "O atributo {$code} não existe na categoria do produto.";

                continue;
            }

            if ($value === null || $value === '') {
                $removals[] = $attribute->id;

                continue;
            }

            $columns = $this->columnsFor($attribute, $value);

            if (is_string($columns)) {
                $errors["attributes.{$code}"][] = $columns;

                continue;
            }

            $upserts[$attribute->id] = $columns;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        foreach ($upserts as $attributeId => $columns) {
            ProductAttributeValue::query()->updateOrCreate(
                ['product_id' => $product->id, 'category_attribute_id' => $attributeId],
                $columns,
            );
        }

        if ($removals !== []) {
            $product->attributeValues()->whereIn('category_attribute_id', $removals)->delete();
        }
    }

    /**
     * After a category change, values of the previous category's attributes no longer apply.
     */
    public function pruneOutsideCategory(Product $product): void
    {
        $product->attributeValues()
            ->whereNotIn(
                'category_attribute_id',
                CategoryAttribute::query()->where('category_id', $product->category_id)->select('id'),
            )
            ->delete();
    }

    /**
     * @throws ValidationException listing every required attribute still without a value
     */
    public function assertRequiredFilled(Product $product): void
    {
        $missing = CategoryAttribute::query()
            ->where('category_id', $product->category_id)
            ->where('is_required', true)
            ->whereDoesntHave('productValues', fn ($query) => $query->where('product_id', $product->id))
            ->orderBy('position')
            ->get();

        if ($missing->isEmpty()) {
            return;
        }

        throw ValidationException::withMessages(
            $missing->mapWithKeys(fn (CategoryAttribute $attribute): array => [
                "attributes.{$attribute->code}" => ["O atributo {$attribute->label} é obrigatório para enviar o produto à moderação."],
            ])->all(),
        );
    }

    /**
     * @return array{value_string: ?string, value_number: ?string, value_boolean: ?bool}|string columns, or an error message
     */
    private function columnsFor(CategoryAttribute $attribute, mixed $value): array|string
    {
        $columns = ['value_string' => null, 'value_number' => null, 'value_boolean' => null];

        switch ($attribute->data_type) {
            case CategoryAttributeDataType::STRING:
                if (! is_scalar($value) || is_bool($value) || mb_strlen((string) $value) > 255) {
                    return 'Informe um texto de até 255 caracteres.';
                }
                $columns['value_string'] = trim((string) $value);

                return $columns;

            case CategoryAttributeDataType::ENUM:
                $option = is_scalar($value) && ! is_bool($value)
                    ? collect($attribute->options ?? [])->first(fn (string $option): bool => mb_strtolower($option) === mb_strtolower(trim((string) $value)))
                    : null;
                if ($option === null) {
                    return 'Escolha uma das opções: '.implode(', ', $attribute->options ?? []).'.';
                }
                $columns['value_string'] = $option;

                return $columns;

            case CategoryAttributeDataType::INTEGER:
                if (! (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', trim($value)))) || abs((int) $value) > self::MAX_NUMBER) {
                    return 'Informe um número inteiro.';
                }
                $columns['value_number'] = (string) (int) $value;

                return $columns;

            case CategoryAttributeDataType::DECIMAL:
                if (! is_numeric($value) || is_bool($value) || abs((float) $value) > self::MAX_NUMBER) {
                    return 'Informe um número.';
                }
                $columns['value_number'] = is_float($value) ? sprintf('%.4F', $value) : trim((string) $value);

                return $columns;

            case CategoryAttributeDataType::BOOLEAN:
                $boolean = is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($boolean === null) {
                    return 'Informe verdadeiro ou falso.';
                }
                $columns['value_boolean'] = $boolean;

                return $columns;
        }

        return 'Tipo de atributo não suportado.';
    }
}
