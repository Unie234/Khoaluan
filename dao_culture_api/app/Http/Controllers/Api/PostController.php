<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PostController extends Controller
{
    public function image(Request $request)
    {
        $fileName = basename((string) $request->query('file'));

        if ($fileName === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Thiếu tên ảnh',
            ], 400);
        }

        $candidates = [
            "uploads/posts/{$fileName}",
            "uploads/{$fileName}",
        ];

        foreach ($candidates as $path) {
            if (!Storage::disk('public')->exists($path)) {
                continue;
            }

            return response()->file(
                storage_path("app/public/{$path}"),
                [
                    'Access-Control-Allow-Origin' => '*',
                    'Cross-Origin-Resource-Policy' => 'cross-origin',
                    'Cache-Control' => 'public, max-age=86400',
                ]
            );
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Không tìm thấy ảnh',
        ], 404);
    }

    public function index(Request $request)
    {
        $userId = $request->input('user_id');

        $posts = DB::table('posts as p')
            ->leftJoin('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.status', 'active')
            ->select([
                'p.*',
                'u.username',
                'u.full_name as author_name',
                'u.avatar as author_avatar',
            ])
            ->selectSub(function ($query) {
                $query->from('post_reactions')
                    ->whereColumn('post_id', 'p.id')
                    ->selectRaw('COUNT(*)');
            }, 'reaction_count')
            ->selectSub(function ($query) {
                $query->from('saved_posts')
                    ->whereColumn('post_id', 'p.id')
                    ->selectRaw('COUNT(*)');
            }, 'save_count')
            ->selectSub(function ($query) {
                $query->from('comments')
                    ->whereColumn('post_id', 'p.id')
                    ->selectRaw('COUNT(*)');
            }, 'comment_count')
            ->selectSub(function ($query) use ($userId) {
                $query->from('post_reactions')
                    ->whereColumn('post_id', 'p.id')
                    ->where('user_id', $userId ?: 0)
                    ->select('reaction')
                    ->limit(1);
            }, 'my_reaction')
            ->selectSub(function ($query) use ($userId) {
                $query->from('saved_posts')
                    ->whereColumn('post_id', 'p.id')
                    ->where('user_id', $userId ?: 0)
                    ->selectRaw('1')
                    ->limit(1);
            }, 'is_saved')
            ->orderByDesc('p.created_at')
            ->get();

        $imageEndpoint = $request->getSchemeAndHttpHost()
            . '/api/posts/image.php';

        $posts->transform(function ($post) use ($imageEndpoint) {
            $post->author_avatar = $this->avatarUrl(
                $imageEndpoint,
                (string) ($post->author_avatar ?? '')
            );

            if (($post->media_type ?? 'image') !== 'video') {
                $post->media_url = $this->postImageUrl(
                    $imageEndpoint,
                    $post->media_url ?? ''
                );
            }

            $gallery = json_decode((string) ($post->gallery_urls ?? ''), true);
            if (is_array($gallery)) {
                $post->gallery_urls = array_values(array_filter(array_map(
                    fn ($url) => $this->postImageUrl(
                        $imageEndpoint,
                        (string) $url
                    ),
                    $gallery
                )));
            } else {
                $post->gallery_urls = [];
            }

            return $post;
        });

        return response()->json([
            'status' => 'success',
            'data' => $posts,
        ]);
    }

    private function postImageUrl(string $endpoint, string $value): string
    {
        $path = parse_url(trim($value), PHP_URL_PATH);
        $fileName = basename(is_string($path) ? $path : '');

        if ($fileName === '') {
            return '';
        }

        return $endpoint . '?file=' . rawurlencode($fileName);
    }

    private function avatarUrl(string $imageEndpoint, string $value): string
    {
        $path = parse_url(trim($value), PHP_URL_PATH);
        $fileName = basename(is_string($path) ? $path : '');

        if ($fileName === '') {
            return '';
        }

        $baseUrl = str_replace(
            '/api/posts/image.php',
            '/api/users/avatar.php',
            $imageEndpoint
        );

        return $baseUrl . '?file=' . rawurlencode($fileName);
    }

    public function reaction(Request $request)
    {
        $request->validate([
            'post_id' => 'required|integer',
            'user_id' => 'required|integer',
            'reaction' => 'required|string|max:20',
        ]);

        $existing = DB::table('post_reactions')->where([
            'post_id' => $request->post_id,
            'user_id' => $request->user_id,
        ])->first();

        if ($existing && $existing->reaction === $request->reaction) {
            DB::table('post_reactions')->where('id', $existing->id)->delete();
            return response()->json(['status' => 'removed']);
        }

        DB::table('post_reactions')->updateOrInsert(
            [
                'post_id' => $request->post_id,
                'user_id' => $request->user_id,
            ],
            [
                'reaction' => $request->reaction,
                'updated_at' => now(),
            ]
        );

        return response()->json(['status' => 'success']);
    }

    public function save(Request $request)
    {
        $key = [
            'post_id' => $request->post_id,
            'user_id' => $request->user_id,
        ];

        if (DB::table('saved_posts')->where($key)->exists()) {
            DB::table('saved_posts')->where($key)->delete();
            return response()->json(['status' => 'unsaved']);
        }

        DB::table('saved_posts')->insert($key + ['created_at' => now()]);
        return response()->json(['status' => 'saved']);
    }

    public function report(Request $request)
    {
        DB::table('reports')->insert([
            'post_id' => $request->post_id,
            'user_id' => $request->user_id,
            'reason' => $request->reason,
            'status' => 'pending',
            'created_at' => now(),
        ]);

        return response()->json(['status' => 'success']);
    }

    public function store(Request $request)
{
    $userId = $request->integer('user_id');
    $postId = $request->integer('post_id');
    $content = trim((string) $request->input('content'));

    if (!DB::table('users')->where('id', $userId)->exists()) {
        return response()->json(['status' => 'error'], 404);
    }

    $mediaUrls = [];
    $mediaType = 'image';

    foreach ($request->file('media_files', []) as $file) {
        $path = $file->store('uploads/posts', 'public');
        $mediaUrls[] = url("storage/$path");
    }

    if ($request->hasFile('media_file')) {
        $file = $request->file('media_file');
        $path = $file->store('uploads/posts', 'public');
        $mediaUrls[] = url("storage/$path");

        $mediaType = str_starts_with($file->getMimeType(), 'video/')
            ? 'video'
            : 'image';
    }

    if ($postId > 0) {
        $post = DB::table('posts')->where('id', $postId)->first();

        if (!$post || (int) $post->user_id !== $userId) {
            return response()->json(['status' => 'error'], 403);
        }

        $update = ['content' => $content];

        if ($request->boolean('remove_media')) {
            $update += [
                'media_url' => '',
                'gallery_urls' => '[]',
            ];
        }

        if ($mediaUrls) {
            $update += [
                'media_url' => $mediaUrls[0],
                'gallery_urls' => json_encode($mediaUrls),
                'media_type' => $mediaType,
            ];
        }

        DB::table('posts')->where('id', $postId)->update($update);

        return response()->json([
            'status' => 'success',
            'post_id' => $postId,
        ]);
    }

    $id = DB::table('posts')->insertGetId([
        'user_id' => $userId,
        'content' => $content,
        'media_url' => $mediaUrls[0] ?? '',
        'gallery_urls' => json_encode($mediaUrls),
        'media_type' => $mediaType,
        'status' => 'active',
        'created_at' => now(),
    ]);

    return response()->json(['status' => 'success', 'post_id' => $id]);
}


    public function hide(Request $request)
    {
        $post = DB::table('posts')
            ->where('id', $request->integer('post_id'))
            ->first();

        if (!$post) {
            return response()->json(['status' => 'error'], 404);
        }

        DB::table('posts')->where('id', $post->id)->update([
            'status' => 'hidden',
        ]);

        $banDays = max(0, $request->integer('ban_days'));

        if ($post->user_id) {
            $updates = [
                'violation_count' => DB::raw('violation_count + 1'),
            ];

            if ($banDays > 0) {
                $updates['banned_until'] = now()->addDays($banDays);
            }

            DB::table('users')->where('id', $post->user_id)->update($updates);

            DB::table('notifications')->insert([
                'user_id' => $post->user_id,
                'type' => 'post_hidden',
                'title' => 'Bài viết đã bị ẩn',
                'message' => 'Bài viết của bạn vi phạm tiêu chuẩn cộng đồng.',
                'post_id' => $post->id,
                'unique_key' => 'post_hidden_'.$post->id.'_'.time(),
                'priority' => 'high',
                'is_read' => 0,
                'created_at' => now(),
            ]);
        }

        return response()->json(['status' => 'success']);
    }

    public function like(Request $request)
    {
        $data = $request->json()->all() ?: $request->all();

        $key = [
            'post_id' => (int) ($data['post_id'] ?? 0),
            'user_id' => (string) ($data['user_id'] ?? ''),
        ];

        if (DB::table('likes')->where($key)->exists()) {
            DB::table('likes')->where($key)->delete();
            return response()->json(['status' => 'unliked']);
        }

        DB::table('likes')->insert($key + ['created_at' => now()]);

        return response()->json(['status' => 'liked']);
    }

    public function destroy(Request $request)
    {
        $commentId = $request->integer('comment_id');

        if ($commentId <= 0) {
            $commentId = $request->integer('id');
        }

        DB::table('comments')->where('id', $commentId)->delete();

        return response()->json(['status' => 'success']);
    }
}
