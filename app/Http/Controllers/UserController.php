<?php

namespace App\Http\Controllers;

use App\Http\Resources\CursorPaginatedResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\Pagination\CursorPaginates;
use Illuminate\Http\Request;

class UserController extends Controller
{
    use CursorPaginates;

    public function me(Request $request)
    {
        $user = $request->user()->load(['roles', 'store']);

        return response()->json([
            'user' => new UserResource($user),
        ]);
    }

    public function index(Request $request)
    {
        $users = $this->cursorPaginate(User::query(), $request);

        return CursorPaginatedResource::make($users);
    }
}
