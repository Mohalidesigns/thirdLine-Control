<?php

namespace App\Http\Controllers;

use App\Services\SearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __invoke(Request $request, SearchService $search): JsonResponse
    {
        $request->validate(['q' => ['required', 'string', 'max:100']]);

        return response()->json([
            'groups' => $search->search($request->user(), $request->input('q')),
        ]);
    }
}
