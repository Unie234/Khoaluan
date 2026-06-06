<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProgressController extends Controller
{
    public function markWord(Request $request)
    {
        DB::table('learning_progress')->updateOrInsert(
            [
                'user_id' => $request->user_id,
                'vocabulary_id' => $request->vocabulary_id,
            ],
            [
                'topic_id' => $request->topic_id,
                'learned' => 1,
                'remembered' => $request->boolean('remembered'),
                'score' => $request->integer('score'),
                'updated_at' => now(),
            ]
        );

        return response()->json(['status' => 'success']);
    }

    public function topicProgress(Request $request)
    {
        $rows = DB::table('learning_progress')
            ->where('user_id', $request->user_id)
            ->where('topic_id', $request->topic_id)
            ->get();

        return response()->json([
            'status' => 'success',
            'learned_ids' => $rows->pluck('vocabulary_id'),
            'remembered_ids' => $rows->where('remembered', 1)
                ->pluck('vocabulary_id')->values(),
            'learned_count' => $rows->count(),
            'remembered_count' => $rows->where('remembered', 1)->count(),
        ]);
    }

    public function addDailyStats(Request $request)
    {
        $date = now()->toDateString();

        $current = DB::table('learning_daily_stats')
            ->where('user_id', $request->user_id)
            ->where('study_date', $date)
            ->first();

        if ($current) {
            DB::table('learning_daily_stats')
                ->where('id', $current->id)
                ->update([
                    'learned_count' => $current->learned_count
                        + $request->integer('learned_count'),
                    'quiz_correct' => $current->quiz_correct
                        + $request->integer('quiz_correct'),
                    'study_minutes' => $current->study_minutes
                        + $request->integer('study_minutes'),
                ]);
        } else {
            DB::table('learning_daily_stats')->insert([
                'user_id' => $request->user_id,
                'study_date' => $date,
                'learned_count' => $request->integer('learned_count'),
                'quiz_correct' => $request->integer('quiz_correct'),
                'study_minutes' => $request->integer('study_minutes'),
            ]);
        }

        return response()->json(['status' => 'success']);
    }

    public function overview(Request $request)
    {
        $progress = DB::table('learning_progress')
            ->where('user_id', $request->user_id);

        return response()->json([
            'status' => 'success',
            'learned_count' => (clone $progress)->count(),
            'remembered_count' => (clone $progress)
                ->where('remembered', 1)->count(),
            'topic_count' => (clone $progress)
                ->distinct()->count('topic_id'),
            'daily_stats' => DB::table('learning_daily_stats')
                ->where('user_id', $request->user_id)
                ->orderByDesc('study_date')
                ->limit(7)
                ->get(),
        ]);
    }

    public function addPoints(Request $request)
{
    $points = max(0, $request->integer('points'));

    $user = DB::table('users')->where('id', $request->user_id)->first();

    if (!$user) {
        return response()->json([
            'status' => 'error',
            'message' => 'Không tìm thấy người dùng',
        ]);
    }

    DB::table('users')->where('id', $user->id)->update([
        'xp' => ($user->xp ?? 0) + $points,
        'total_xp' => ($user->total_xp ?? 0) + $points,
    ]);

    return response()->json([
        'status' => 'success',
        'xp' => ($user->xp ?? 0) + $points,
        'total_xp' => ($user->total_xp ?? 0) + $points,
    ]);
}

    public function updateStreak(Request $request)
    {
        $user = DB::table('users')
            ->where('username', $request->username)
            ->first();

        if (!$user) {
            return response()->json(['streak' => 0]);
        }

        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $streak = (int) ($user->streak_count ?? 0);

        if ($user->last_login_date === $yesterday) {
            $streak++;
        } elseif ($user->last_login_date !== $today) {
            $streak = 1;
        }

        DB::table('users')->where('id', $user->id)->update([
            'streak_count' => $streak,
            'last_login_date' => $today,
        ]);

        return response()->json(['status' => 'success', 'streak' => $streak]);
    }

    public function topics(Request $request)
    {
        $userId = $request->user_id;

        $topics = DB::table('topics as t')
            ->leftJoin('vocabulary as v', 'v.topic_id', '=', 't.id')
            ->leftJoin('learning_progress as lp', function ($join) use ($userId) {
                $join->on('lp.vocabulary_id', '=', 'v.id')
                    ->where('lp.user_id', '=', $userId);
            })
            ->select(
                't.id',
                't.title',
                DB::raw('COUNT(DISTINCT v.id) as total'),
                DB::raw('COUNT(DISTINCT lp.vocabulary_id) as learned'),
                DB::raw(
                    'COUNT(DISTINCT CASE WHEN lp.remembered = 1
                    THEN lp.vocabulary_id END) as remembered'
                )
            )
            ->groupBy('t.id', 't.title')
            ->orderBy('t.id')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $topics,
        ]);
    }

    public function legacyProgress(Request $request)
    {
        $ids = DB::table('user_progress')
            ->where('username', $request->input('username'))
            ->where('status', 'Passed')
            ->pluck('word_id');

        return response()->json($ids);
    }
}