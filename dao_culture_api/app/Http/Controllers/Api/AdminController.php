<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    public function users()
    {
        return response()->json(
            DB::table('users')
                ->select(
                    'id', 'username', 'full_name', 'role',
                    'xp', 'total_xp', 'streak_count', 'avatar',
                    'violation_count', 'banned_until',
                    'is_locked', 'created_at'
                )
                ->orderByDesc('created_at')
                ->get()
        );
    }

    public function updateUserStatus(Request $request)
    {
        $updated = DB::table('users')
            ->where('id', $request->integer('user_id'))
            ->where('role', '!=', 'admin')
            ->update([
                'is_locked' => $request->boolean('is_locked') ? 1 : 0,
            ]);

        return response()->json([
            'status' => $updated ? 'success' : 'error',
            'message' => $updated
                ? 'Đã cập nhật tài khoản'
                : 'Không thể cập nhật tài khoản',
        ]);
    }

    public function deleteUser(Request $request)
    {
        $user = DB::table('users')
            ->where('id', $request->integer('user_id'))
            ->first();

        if (!$user || $user->role === 'admin') {
            return response()->json([
                'status' => 'error',
                'message' => 'Không thể xóa tài khoản này',
            ], 403);
        }

        DB::table('users')->where('id', $user->id)->delete();

        return response()->json(['status' => 'success']);
    }

    public function reports()
    {
        return response()->json(
            DB::table('reports as r')
                ->leftJoin('posts as p', 'p.id', '=', 'r.post_id')
                ->leftJoin('users as reporter', 'reporter.id', '=', 'r.user_id')
                ->leftJoin('users as author', 'author.id', '=', 'p.user_id')
                ->select(
                    'r.id',
                    'r.post_id',
                    'r.user_id',
                    'r.reason',
                    'r.status',
                    'r.created_at',
                    'p.content as post_content',
                    'p.media_url',
                    'reporter.full_name as reporter_name',
                    'author.full_name as author_name'
                )
                ->orderByDesc('r.created_at')
                ->get()
        );
    }

    public function resolveReport(Request $request)
    {
        $status = (string) $request->input('status');

        if (!in_array($status, ['resolved', 'rejected'], true)) {
            return response()->json(['status' => 'error'], 422);
        }

        DB::table('reports')
            ->where('id', $request->integer('report_id'))
            ->update(['status' => $status]);

        return response()->json(['status' => 'success']);
    }
}