<?php

namespace App\Http\Controllers;

use App\Models\WatchHistory;
use Illuminate\Http\Request;

class HistoryController extends Controller
{
    public function index(Request $request)
    {
        $items = $request->user()
            ->watchHistory()
            ->orderByDesc('watched_at')
            ->get()
            ->map(fn($item) => [
                'id'        => $item->anime_id,
                'title'     => $item->title,
                'image'     => $item->image,
                'genre'     => $item->genre,
                'episode'   => $item->episode,
                'watchedAt' => $item->watched_at,
            ]);

        return response()->json(['data' => $items]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'id'      => 'required',
            'title'   => 'required|string',
            'image'   => 'nullable|string',
            'genre'   => 'nullable|string',
            'episode' => 'required|integer',
        ]);

        $item = $request->user()->watchHistory()->updateOrCreate(
            ['anime_id' => (string) $request->id],
            [
                'title'      => $request->title,
                'image'      => $request->image,
                'genre'      => $request->genre,
                'episode'    => $request->episode,
                'watched_at' => now(),
            ]
        );

        return response()->json($item, 201);
    }

    public function destroy(Request $request, string $animeId)
    {
        $request->user()
            ->watchHistory()
            ->where('anime_id', $animeId)
            ->delete();

        return response()->json(['message' => 'Removed from history']);
    }

    public function clear(Request $request)
    {
        $request->user()->watchHistory()->delete();

        return response()->json(['message' => 'History cleared']);
    }
}
