<?php

namespace App\Http\Controllers\Concerns;

use App\Models\ContentView;
use App\Models\Reaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Shared view-tracking and reaction (heart) logic for Anime and Film controllers.
 */
trait HandlesEngagement
{
    /**
     * Record a unique-IP view and return the updated total.
     * Safe to call multiple times — duplicate IPs are silently ignored.
     */
    protected function recordView(Request $request, string $contentType, int $id): JsonResponse
    {
        ContentView::insertOrIgnore([
            'content_type' => $contentType,
            'content_id'   => $id,
            'ip_hash'      => hash('sha256', $request->ip()),
            'created_at'   => now(),
        ]);

        return response()->json([
            'views' => ContentView::where('content_type', $contentType)
                           ->where('content_id', $id)
                           ->count(),
        ]);
    }

    /**
     * Toggle the authenticated user's heart reaction.
     * Returns the new liked state and updated total count.
     */
    protected function toggleReaction(Request $request, string $contentType, int $id): JsonResponse
    {
        $userId = $request->user()->id;

        $deleted = Reaction::where('user_id', $userId)
            ->where('content_type', $contentType)
            ->where('content_id', $id)
            ->where('type', 'heart')
            ->delete();

        if (! $deleted) {
            Reaction::create([
                'user_id'      => $userId,
                'content_type' => $contentType,
                'content_id'   => $id,
                'type'         => 'heart',
            ]);
        }

        $count = Reaction::where('content_type', $contentType)
            ->where('content_id', $id)
            ->where('type', 'heart')
            ->count();

        return response()->json(['liked' => ! $deleted, 'count' => $count]);
    }

    /**
     * Return views, total likes, and whether the current request's user has liked the item.
     * Works on both public and auth-protected routes — user will be null if unauthenticated.
     */
    protected function engagementData(string $contentType, int $id): array
    {
        $views = ContentView::where('content_type', $contentType)
            ->where('content_id', $id)
            ->count();

        $likes = Reaction::where('content_type', $contentType)
            ->where('content_id', $id)
            ->where('type', 'heart')
            ->count();

        $userLiked = false;
        $authUser  = Auth::guard('sanctum')->user();
        if ($authUser) {
            $userLiked = Reaction::where('user_id', $authUser->id)
                ->where('content_type', $contentType)
                ->where('content_id', $id)
                ->where('type', 'heart')
                ->exists();
        }

        return ['views' => $views, 'likes' => $likes, 'user_liked' => $userLiked];
    }
}
