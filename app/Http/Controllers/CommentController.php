<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    public function index(int $animeId)
    {
        $comments = Comment::with('user:id,name,avatar')
            ->where('anime_id', $animeId)
            ->latest()
            ->paginate(20);

        return response()->json($comments);
    }

    public function store(Request $request, int $animeId)
    {
        $request->validate([
            'body' => 'required|string|max:1000',
        ]);

        $comment = Comment::create([
            'user_id'  => $request->user()->id,
            'anime_id' => $animeId,
            'body'     => $request->body,
        ]);

        $comment->load('user:id,name,avatar');

        return response()->json($comment, 201);
    }

    public function destroy(Request $request, int $animeId, Comment $comment)
    {
        if ($comment->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $comment->delete();

        return response()->json(['message' => 'Deleted']);
    }
}
