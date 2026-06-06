<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CultureController extends Controller
{
    public function articles(Request $request)
    {
        $query = DB::table('culture_articles');

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->mode === 'featured') {
            $query->where('is_featured', 1);
        }

        $query->orderByDesc(
            $request->mode === 'latest' ? 'created_at' : 'view_count'
        );

        if ($request->integer('limit') > 0) {
            $query->limit($request->integer('limit'));
        }

        return response()->json($query->get());
    }

    public function incrementView(Request $request)
    {
        DB::table('culture_articles')
            ->where('id', $request->id)
            ->increment('view_count');

        return response()->json(['status' => 'success']);
    }

    public function mapPlaces(Request $request)
    {
        $query = DB::table('map_places')->orderBy('id');

        if (!$request->boolean('admin')) {
            $query->where('is_active', 1);
        }

        return response()->json([
            'status' => 'success',
            'data' => $query->get(),
        ]);
    }

    public function image(Request $request)
    {
        $fileName = basename((string) $request->query('file'));

        if ($fileName === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Thiếu tên ảnh',
            ], 400);
        }

        $path = "uploads/culture/{$fileName}";

        if (!Storage::disk('public')->exists($path)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Không tìm thấy ảnh',
            ], 404);
        }

        $fullPath = storage_path('app/public/' . $path);

        if (!file_exists($fullPath)) {
            return response()->json([
                'message' => 'Không tìm thấy file'
            ], 404);
        }

        return response()->file($fullPath, [
            'Access-Control-Allow-Origin' => '*',
            'Cross-Origin-Resource-Policy' => 'cross-origin',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    public function saveArticle(Request $request)
    {
        $request->validate([
            'category' => 'required|string|max:50',
            'title' => 'required|string|max:255',
            'content' => 'required|string',
        ]);

        $values = [
            'category' => $request->input('category'),
            'title' => $request->input('title'),
            'subtitle' => $request->input('subtitle', ''),
            'content' => $request->input('content'),
            'image_url' => $request->input('image_url', ''),
            'video_url' => $request->input('video_url', ''),
            'detail_json' => $request->input('detail_json', '{}'),
            'is_featured' => $request->boolean('is_featured') ? 1 : 0,
            'updated_at' => now(),
        ];

        $id = $request->integer('id');

        if ($id > 0) {
            DB::table('culture_articles')->where('id', $id)->update($values);
        } else {
            $values['created_at'] = now();
            $values['view_count'] = 0;
            $id = DB::table('culture_articles')->insertGetId($values);
        }

        return response()->json([
            'status' => 'success',
            'id' => $id,
        ]);
    }

    public function uploadArticleImage(Request $request)
    {
        $request->validate([
            'image' => 'required|image|max:10240',
        ]);

        $path = $request->file('image')
            ->store('uploads/culture', 'public');

        $imageUrl = url("storage/$path");

        return response()->json([
            'status' => 'success',
            'image_url' => $imageUrl,
        ]);
    }

    public function uploadArticleVideo(Request $request)
    {
        $request->validate([
            'video' => 'required|file|mimes:mp4,mov,webm,m4v|max:102400',
        ]);

        $path = $request->file('video')
            ->store('uploads/culture_videos', 'public');

        $videoUrl = url("storage/$path");

        return response()->json([
            'status' => 'success',
            'video_url' => $videoUrl,
        ]);
    }

    public function deleteArticle(Request $request)
    {
        $deleted = DB::table('culture_articles')
            ->where('id', $request->integer('id'))
            ->delete();

        return response()->json([
            'status' => $deleted ? 'success' : 'error',
        ]);
    }

    public function saveMapPlace(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
        ]);

        $gallery = $request->input('gallery_urls', []);

        if (!is_array($gallery)) {
            $decoded = json_decode((string) $gallery, true);
            $gallery = is_array($decoded) ? $decoded : [];
        }

        $values = [
            'name' => $request->input('name'),
            'address' => $request->input('address', ''),
            'short_description' => $request->input('short_description', ''),
            'cultural_description' => $request->input(
                'cultural_description',
                ''
            ),
            'dao_info' => $request->input('dao_info', ''),
            'tag' => $request->input('tag', ''),
            'type' => $request->input('type', 'village'),
            'layer_type' => $request->input('layer_type', 'culture'),
            'image_url' => $request->input('image_url', ''),
            'gallery_urls' => json_encode(
                $gallery,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            'latitude' => $request->input('latitude'),
            'longitude' => $request->input('longitude'),
            'has_directions' => $request->boolean('has_directions') ? 1 : 0,
            'is_active' => $request->boolean('is_active') ? 1 : 0,
            'updated_at' => now(),
        ];

        $id = $request->integer('id');

        if ($id > 0) {
            DB::table('map_places')->where('id', $id)->update($values);
        } else {
            $values['created_at'] = now();
            $id = DB::table('map_places')->insertGetId($values);
        }

        return response()->json([
            'status' => 'success',
            'id' => $id,
        ]);
    }

    public function uploadMapImage(Request $request)
    {
        $request->validate([
            'image' => 'required|image|max:10240',
        ]);

        $path = $request->file('image')
            ->store('uploads/map_places', 'public');

        $imageUrl = url("storage/$path");

        return response()->json([
            'status' => 'success',
            'image_url' => $imageUrl,
        ]);
    }

    public function deleteMapPlace(Request $request)
    {
        $deleted = DB::table('map_places')
            ->where('id', $request->integer('id'))
            ->delete();

        return response()->json([
            'status' => $deleted ? 'success' : 'error',
        ]);
    }

    public function search(Request $request)
{
    $keyword = trim((string) $request->query('keyword', ''));
    $limit = min(max($request->integer('limit', 8), 1), 30);

    if (mb_strlen($keyword) < 2) {
        return response()->json([
            'status' => 'success',
            'data' => [],
        ]);
    }

    $articles = DB::table('culture_articles')
        ->where(function ($query) use ($keyword) {
            $query->where('title', 'like', "%{$keyword}%")
                ->orWhere('subtitle', 'like', "%{$keyword}%")
                ->orWhere('content', 'like', "%{$keyword}%")
                ->orWhere('category', 'like', "%{$keyword}%");
        })
        ->orderByDesc('view_count')
        ->orderByDesc('created_at')
        ->limit($limit)
        ->get();

    return response()->json([
        'status' => 'success',
        'data' => $articles,
    ]);
}
}
