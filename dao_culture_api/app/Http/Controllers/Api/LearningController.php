<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LearningController extends Controller
{
    public function topics()
    {
        return response()->json(DB::table('topics')->orderBy('id')->get());
    }

    public function vocabulary(Request $request)
    {
        return response()->json(
            DB::table('vocabulary')
                ->where('topic_id', $request->topic_id)
                ->orderBy('id')
                ->get()
        );
    }

    public function search(Request $request)
    {
        $word = DB::table('vocabulary')
            ->where('viet_word', 'like', "%{$request->keyword}%")
            ->orWhere('dao_word', 'like', "%{$request->keyword}%")
            ->first();

        if (!$word) {
            return response()->json(['status' => 'error']);
        }

        return response()->json([
            'status' => 'success',
            'id' => $word->id,
            'vietnamese' => $word->viet_word,
            'dao' => $word->dao_word,
            'audio_file' => $word->audio_file,
        ]);
    }

    public function favorites(Request $request)
    {
        return response()->json([
            'status' => 'success',
            'data' => DB::table('dictionary_favorites')
                ->where('user_id', $request->user_id)
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }

    public function toggleFavorite(Request $request)
    {
        $key = [
            'user_id' => $request->user_id,
            'vocabulary_id' => $request->vocabulary_id,
        ];

        if ($request->boolean('favorite')) {
            DB::table('dictionary_favorites')->updateOrInsert($key, [
                'vietnamese_word' => $request->vietnamese_word,
                'dao_word' => $request->dao_word,
            ]);
        } else {
            DB::table('dictionary_favorites')->where($key)->delete();
        }

        return response()->json(['status' => 'success']);
    }

    public function addTopic(Request $request)
    {
        $request->validate(['title' => 'required|string|max:255']);

        $id = DB::table('topics')->insertGetId([
            'title' => $request->title,
        ]);

        return response()->json(['status' => 'success', 'id' => $id]);
    }

    public function updateTopic(Request $request)
    {
        DB::table('topics')->where('id', $request->id)->update([
            'title' => $request->title,
        ]);

        return response()->json(['status' => 'success']);
    }

    public function deleteTopic(Request $request)
    {
        DB::transaction(function () use ($request) {
            DB::table('vocabulary')->where('topic_id', $request->id)->delete();
            DB::table('topics')->where('id', $request->id)->delete();
        });

        return response()->json(['status' => 'success']);
    }

    public function addVocabulary(Request $request)
    {
        $topic = DB::table('topics')->find($request->topic_id);

        $id = DB::table('vocabulary')->insertGetId([
            'topic_id' => $request->topic_id,
            'topic_title' => $topic?->title,
            'dao_word' => $request->dao_word,
            'viet_word' => $request->viet_word,
            'audio_file' => $request->audio_file ?? '',
        ]);

        return response()->json(['status' => 'success', 'id' => $id]);
    }

    public function updateVocabulary(Request $request)
    {
        $topic = DB::table('topics')->find($request->topic_id);

        DB::table('vocabulary')->where('id', $request->id)->update([
            'topic_id' => $request->topic_id,
            'topic_title' => $topic?->title,
            'dao_word' => $request->dao_word,
            'viet_word' => $request->viet_word,
            'audio_file' => $request->audio_file ?? '',
        ]);

        return response()->json(['status' => 'success']);
    }

    public function deleteVocabulary(Request $request)
    {
        DB::table('vocabulary')->where('id', $request->id)->delete();

        return response()->json(['status' => 'success']);
    }
}