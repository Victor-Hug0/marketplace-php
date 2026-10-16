<?php

namespace App\Support\Pagination;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

trait CursorPaginates
{
    protected function cursorPaginate(
        Builder $query,
        Request $request,
        int $defaultPerPage = 20,
        int $maxPerPage = 100,
        string $cursorColumn = 'id',
        bool $descending = false,
    ): CursorPaginator {
        $perPage = max(1, min(
            $request->integer('per_page', $defaultPerPage),
            $maxPerPage,
        ));

        $ordered = $descending
            ? $query->orderByDesc($cursorColumn)
            : $query->orderBy($cursorColumn);

        return $ordered
            ->cursorPaginate($perPage, ['*'], 'cursor')
            ->withQueryString();
    }
}
