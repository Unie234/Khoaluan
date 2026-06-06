<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiController extends Controller
{
    public function chat(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string|max:30000',
        ]);

        $apiKey = (string) config('services.gemini.key');
        $model = (string) config('services.gemini.model', 'gemini-2.5-flash');

        if ($apiKey === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Backend chưa cấu hình GEMINI_API_KEY.',
            ], 503);
        }

        $prompt = 'Bạn là trợ lý AI của ứng dụng tìm hiểu văn hóa người Dao '
            . 'tại Việt Nam. Hãy ưu tiên làm đúng theo yêu cầu, ngữ cảnh và '
            . 'dữ liệu được gửi trong nội dung bên dưới. Chỉ trả lời các nội '
            . 'dung liên quan đến văn hóa Dao, học tập trong app, phong tục, '
            . 'lễ hội, trang phục, ẩm thực, thảo dược, ngôn ngữ và cộng đồng. '
            . 'Nếu dữ liệu hoặc câu hỏi nêu nhóm Dao cụ thể như Dao Đỏ thì '
            . 'phải giữ đúng tên nhóm đó. Không trả lời toán học, lập trình '
            . 'hoặc chủ đề không liên quan đến ứng dụng. Trả lời bằng tiếng '
            . 'Việt, ngắn gọn, tự nhiên, không dùng markdown nếu không được '
            . "yêu cầu.\n\nNội dung cần xử lý:\n"
            . $validated['message'];

        try {
            $response = Http::withHeaders([
                'x-goog-api-key' => $apiKey,
            ])
                ->acceptJson()
                ->timeout(35)
                ->post(
                    "https://generativelanguage.googleapis.com/v1beta/"
                    . "models/{$model}:generateContent",
                    [
                        'contents' => [
                            [
                                'role' => 'user',
                                'parts' => [
                                    ['text' => $prompt],
                                ],
                            ],
                        ],
                    ]
                );

            if (!$response->successful()) {
                Log::warning('Gemini API error', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);

                $googleMessage = $response->json('error.message');

                return response()->json([
                    'status' => 'error',
                    'message' => $googleMessage
                        ?: 'Gemini không xử lý được yêu cầu.',
                ], 502);
            }

            $text = trim((string) $response->json(
                'candidates.0.content.parts.0.text',
                ''
            ));

            if ($text === '') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Gemini không trả về nội dung.',
                ], 502);
            }

            return response()->json([
                'status' => 'success',
                'text' => $text,
            ]);
        } catch (\Throwable $exception) {
            Log::error('Gemini connection error', [
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Không kết nối được với Gemini.',
            ], 502);
        }
    }
}
