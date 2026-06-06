<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommentController extends Controller
{
    public function index(Request $request)
    {
        $userId = $request->integer('user_id');
        $postId = $request->integer('post_id');

        $comments = DB::table('comments as c')
            ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->where('c.post_id', $postId)
            ->select(
                'c.id',
                'c.post_id',
                'c.parent_id',
                'c.user_id',
                'c.text as content',
                'c.created_at',
                DB::raw('COALESCE(u.full_name, c.user_name) as author_name'),
                'u.avatar as author_avatar'
            )
            ->selectSub(
                fn ($query) => $query
                    ->from('comment_reactions')
                    ->whereColumn('comment_id', 'c.id')
                    ->selectRaw('COUNT(*)'),
                'reaction_count'
            )
            ->selectSub(
                fn ($query) => $query
                    ->from('comment_reactions')
                    ->whereColumn('comment_id', 'c.id')
                    ->where('user_id', $userId)
                    ->select('reaction')
                    ->limit(1),
                'my_reaction'
            )
            ->orderBy('c.created_at')
            ->get();

        return response()->json($comments);
    }

    public function store(Request $request)
    {
        $userId = $request->integer('user_id');
        $postId = $request->integer('post_id');
        $parentId = $request->input('parent_id');
        $content = trim((string) $request->input('content'));

        $user = DB::table('users')->find($userId);

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Không tìm thấy người dùng',
            ], 404);
        }

        if ($content === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Nội dung không được để trống',
            ], 422);
        }

        $id = DB::table('comments')->insertGetId([
            'post_id' => $postId,
            'parent_id' => $parentId ?: null,
            'user_id' => $userId,
            'user_name' => $user->full_name ?: $user->username,
            'text' => $content,
            'created_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'id' => $id,
        ]);
    }

    public function reaction(Request $request)
    {
        $reaction = (string) $request->input('reaction');

        $key = [
            'comment_id' => $request->integer('comment_id'),
            'user_id' => $request->integer('user_id'),
        ];

        $existing = DB::table('comment_reactions')->where($key)->first();

        if ($existing && $existing->reaction === $reaction) {
            DB::table('comment_reactions')
                ->where('id', $existing->id)
                ->delete();

            return response()->json(['status' => 'removed']);
        }

        DB::table('comment_reactions')->updateOrInsert($key, [
            'reaction' => $reaction,
            'updated_at' => now(),
        ]);

        return response()->json(['status' => 'success']);
    }

    public function destroy(Request $request)
    {
        DB::table('comments')
            ->where('id', $request->integer('comment_id'))
            ->delete();

        return response()->json(['status' => 'success']);
    }
}