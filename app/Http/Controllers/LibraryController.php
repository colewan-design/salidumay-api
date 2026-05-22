<?php

namespace App\Http\Controllers;

use App\Models\Library;
use Illuminate\Http\Request;

class LibraryController extends Controller
{
    public function index(Request $request)
    {
        $items = $request->user()
            ->library()
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $items]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'id'       => 'required|string',
            'title'    => 'required|string',
            'image'    => 'nullable|string',
            'genre'    => 'nullable|string',
            'episodes' => 'nullable|integer',
            'rating'   => 'nullable|numeric',
        ]);

        $item = $request->user()->library()->updateOrCreate(
            ['anime_id' => $request->id],
            [
                'title'    => $request->title,
                'image'    => $request->image,
                'genre'    => $request->genre,
                'episodes' => $request->episodes,
                'rating'   => $request->rating,
            ]
        );

        return response()->json($item, 201);
    }

    public function destroy(Request $request, string $animeId)
    {
        $request->user()
            ->library()
            ->where('anime_id', $animeId)
            ->delete();

        return response()->json(['message' => 'Removed from library']);
    }
}
