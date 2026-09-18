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
    ): CursorPaginator {
        $perPage = max(1, min((int) $request->integer('per_page'), $defaultPerPage), $maxPerPage);

        return $query
            ->orderBy($cursorColumn)
            ->cursorPaginate($perPage, ['*'], 'cursor')
            ->withQueryString();
    }
}
