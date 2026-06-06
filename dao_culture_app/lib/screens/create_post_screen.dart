import 'dart:io';
import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:image_cropper/image_cropper.dart';
import 'package:http/http.dart' as http;
// 🟢 MỚI THÊM: Cần có cái này để lấy tên và ID người dùng đăng bài
import 'package:shared_preferences/shared_preferences.dart';

import '../services/api_service.dart'; // Đảm bảo đường dẫn này trỏ đúng file ApiService của bạn
import '../widgets/level_up_celebration_dialog.dart';
import 'login_screen.dart';

class CreatePostScreen extends StatefulWidget {
  final String? postId;
  final String? initialContent;
  final String? initialImageUrl;

  const CreatePostScreen({
    super.key,
    this.postId,
    this.initialContent,
    this.initialImageUrl,
  });

  @override
  State<CreatePostScreen> createState() => _CreatePostScreenState();
}

class _CreatePostScreenState extends State<CreatePostScreen> {
  final TextEditingController _contentController = TextEditingController();
  File? _selectedMedia;
  final List<File> _selectedImages = [];
  String? _existingImageUrl;
  bool _isLoading = false;
  bool _isVideo = false;
  bool _removeExistingMedia = false;

  @override
  void initState() {
    super.initState();
    if (widget.initialContent != null) {
      _contentController.text = widget.initialContent!;
    }
    if (widget.initialImageUrl != null && widget.initialImageUrl!.isNotEmpty) {
      _existingImageUrl = widget.initialImageUrl;
      _isVideo = widget.initialImageUrl!.contains('.mp4');
    }
  }

  // 🔴 MÁY QUÉT TỪ NGỮ VI PHẠM (ĐÃ CHỈNH SỬA CHO PHÙ HỢP VĂN HÓA)
  bool _containsBadWords(String text) {
    final normalizedText = _normalizeModerationText(text);
    const blockedPhrases = [
      'chui bay',
      'chui tuc',
      'chui the',
      'chueir tuc',
      'noi tuc',
      'vang tuc',
      'tuc tiu',
      'me no',
      'may thich gi',
      'do ngu',
      'do dien',
      'do khung',
      'do mat day',
      'mat day',
      'vo hoc',
      'khon nan',
      'con cho',
      'cho chet',
      'bien di',
      'danh chet',
      'giet may',
      'giet no',
      'dit me',
      'du ma',
      'lua dao',
      'me tin',
      'pha hoai',
      'kich dong',
      'tay chay',
    ];

    for (String phrase in blockedPhrases) {
      if (normalizedText.contains(phrase)) return true;
    }

    const blockedWords = ['dm', 'dmm', 'vcl', 'clm', 'ngu'];
    final textWords = normalizedText
        .split(RegExp(r'[^a-z0-9]+'))
        .where((word) => word.isNotEmpty)
        .toSet();
    for (String word in blockedWords) {
      if (textWords.contains(word)) return true;
    }
    return false;
  }

  String _normalizeModerationText(String text) {
    return text
        .trim()
        .toLowerCase()
        .replaceAll(RegExp('[àáạảãâầấậẩẫăằắặẳẵ]'), 'a')
        .replaceAll(RegExp('[èéẹẻẽêềếệểễ]'), 'e')
        .replaceAll(RegExp('[ìíịỉĩ]'), 'i')
        .replaceAll(RegExp('[òóọỏõôồốộổỗơờớợởỡ]'), 'o')
        .replaceAll(RegExp('[ùúụủũưừứựửữ]'), 'u')
        .replaceAll(RegExp('[ỳýỵỷỹ]'), 'y')
        .replaceAll('đ', 'd')
        .replaceAll(RegExp(r'\s+'), ' ');
  }

  // --- HÀM CHỌN ẢNH HOẶC VIDEO ---
  Future<void> _pickMedia(bool isVideoRequest) async {
    final picker = ImagePicker();

    if (isVideoRequest) {
      final pickedFile = await picker.pickVideo(source: ImageSource.gallery);
      if (pickedFile != null) {
        setState(() {
          _isVideo = true;
          _selectedMedia = File(pickedFile.path);
          _selectedImages.clear();
          _existingImageUrl = null;
          _removeExistingMedia = false;
        });
      }
      return;
    }

    final pickedFiles = await picker.pickMultiImage(imageQuality: 82);
    if (pickedFiles.isEmpty) return;

    setState(() {
      _isVideo = false;
      _selectedMedia = null;
      _existingImageUrl = null;
      _removeExistingMedia = false;
      _selectedImages
        ..clear()
        ..addAll(pickedFiles.take(8).map((file) => File(file.path)));
    });
  }

  Future<void> _pickAndCropSingleImage() async {
    final picker = ImagePicker();
    final pickedFile = await picker.pickImage(
      source: ImageSource.gallery,
      imageQuality: 82,
    );
    if (pickedFile == null) return;

    CroppedFile? croppedFile = await ImageCropper().cropImage(
      sourcePath: pickedFile.path,
      uiSettings: [
        AndroidUiSettings(
          toolbarTitle: 'Cắt và chỉnh sửa ảnh',
          toolbarColor: const Color(0xFF1A237E),
          toolbarWidgetColor: Colors.white,
          initAspectRatio: CropAspectRatioPreset.square,
          lockAspectRatio: false,
          aspectRatioPresets: [
            CropAspectRatioPreset.square,
            CropAspectRatioPreset.ratio4x3,
            CropAspectRatioPreset.original,
          ],
        ),
      ],
    );

    setState(() {
      _isVideo = false;
      _selectedMedia = File(croppedFile?.path ?? pickedFile.path);
      _selectedImages.clear();
      _existingImageUrl = null;
      _removeExistingMedia = false;
    });
  }

  Future<void> _takePhoto() async {
    final picker = ImagePicker();
    final pickedFile = await picker.pickImage(
      source: ImageSource.camera,
      imageQuality: 82,
    );
    if (pickedFile == null) return;

    setState(() {
      _isVideo = false;
      _selectedMedia = File(pickedFile.path);
      _selectedImages.clear();
      _existingImageUrl = null;
      _removeExistingMedia = false;
    });
  }

  bool get _hasSelectedMedia {
    return _selectedMedia != null ||
        _selectedImages.isNotEmpty ||
        _existingImageUrl != null;
  }

  String _fileName(File file) {
    final normalized = file.path.replaceAll('\\', '/');
    return normalized.split('/').last;
  }

  String get _mediaStatusText {
    if (_isLoading && (_selectedMedia != null || _selectedImages.isNotEmpty)) {
      return _isVideo
          ? "Đang tải video lên máy chủ..."
          : "Đang tải ảnh lên máy chủ...";
    }

    if (_selectedImages.isNotEmpty) {
      final firstName = _fileName(_selectedImages.first);
      final extra = _selectedImages.length > 1
          ? " và ${_selectedImages.length - 1} ảnh khác"
          : "";
      return "Đã chọn ${_selectedImages.length} ảnh: $firstName$extra";
    }

    if (_selectedMedia != null) {
      return _isVideo
          ? "Đã chọn video: ${_fileName(_selectedMedia!)}"
          : "Đã chọn ảnh: ${_fileName(_selectedMedia!)}";
    }

    if (_existingImageUrl != null) {
      return _isVideo
          ? "Video hiện tại của bài viết"
          : "Ảnh hiện tại của bài viết";
    }

    return "";
  }

  IconData get _mediaStatusIcon {
    if (_isLoading && (_selectedMedia != null || _selectedImages.isNotEmpty)) {
      return Icons.cloud_upload_rounded;
    }
    if (_isVideo) return Icons.videocam_rounded;
    return Icons.check_circle_rounded;
  }

  Future<bool> _ensureCanPost() async {
    final prefs = await SharedPreferences.getInstance();
    final username = (prefs.getString('username') ?? '').trim();
    final userId = (prefs.getString('user_id') ?? '').trim();

    if (userId.isNotEmpty && username.isNotEmpty && username != 'Khách') {
      return true;
    }

    if (!mounted) return false;
    final shouldLogin = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text("Yêu cầu đăng nhập"),
        content: const Text(
          "Bạn cần đăng nhập tài khoản người dùng để đăng bài.",
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext, false),
            child: const Text("Để sau"),
          ),
          ElevatedButton(
            onPressed: () => Navigator.pop(dialogContext, true),
            child: const Text("Đăng nhập"),
          ),
        ],
      ),
    );

    if (shouldLogin == true && mounted) {
      Navigator.pushReplacement(
        context,
        MaterialPageRoute(builder: (_) => const LoginScreen()),
      );
    }
    return false;
  }

  // --- HÀM LƯU BÀI VIẾT (GỌI API XAMPP) ---
  Future<void> _savePost() async {
    if (!await _ensureCanPost()) return;
    if (!mounted) return;

    final content = _contentController.text.trim();

    // 1. Kiểm tra rỗng
    if (content.isEmpty && !_hasSelectedMedia) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text("Uyên ơi, nhập nội dung hoặc chọn file đã nhé!"),
        ),
      );
      return;
    }

    // 2. CHỐT CHẶN BẢO VỆ CỘNG ĐỒNG
    if (_containsBadWords(content)) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text("Bài viết của bạn vi phạm tiêu chuẩn cộng đồng."),
          backgroundColor: Colors.red,
          duration: Duration(seconds: 4),
        ),
      );
      return;
    }

    setState(() => _isLoading = true);

    try {
      // 3. LẤY THÔNG TIN NGƯỜI ĐĂNG TỪ BỘ NHỚ MÁY
      SharedPreferences prefs = await SharedPreferences.getInstance();
      String username = (prefs.getString('username') ?? '').trim();
      String userId = (prefs.getString('user_id') ?? '').trim();

      if (userId.isEmpty || username.isEmpty || username == 'Khách') {
        throw Exception("Bạn cần đăng nhập để đăng bài.");
      }

      // 4. CHUẨN BỊ "XE TẢI" ĐỂ CHỞ CẢ ẢNH VÀ CHỮ LÊN XAMPP TRONG 1 LẦN
      final uri = Uri.parse('${ApiService.baseUrl}/posts/create.php');
      var request = http.MultipartRequest('POST', uri);

      // Xếp chữ lên xe
      request.fields['content'] = content;
      request.fields['user_id'] = userId;
      request.fields['username'] = username;

      // Nếu là chế độ sửa bài viết, gửi kèm postId
      if (widget.postId != null) {
        request.fields['post_id'] = widget.postId!;
        request.fields['remove_media'] = _removeExistingMedia ? '1' : '0';
      }

      // Xếp file (ảnh/video) lên xe nếu có
      if (_selectedMedia != null) {
        if (_isVideo) {
          if (!mounted) return;
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(
              content: Text("Đang tải video lên máy chủ, vui lòng đợi..."),
              duration: Duration(seconds: 4),
            ),
          );
        }

        request.files.add(
          await http.MultipartFile.fromPath('media_file', _selectedMedia!.path),
        );
      }

      for (final image in _selectedImages) {
        request.files.add(
          await http.MultipartFile.fromPath('media_files[]', image.path),
        );
      }

      // 5. Khởi hành gửi lên XAMPP!
      var streamedResponse = await request.send().timeout(
        const Duration(seconds: 180),
      );
      var response = await http.Response.fromStream(streamedResponse);

      if (!mounted) return;

      if (response.statusCode == 200) {
        String cleanBody = response.body.trim();
        if (cleanBody.startsWith('\xEF\xBB\xBF')) {
          cleanBody = cleanBody.substring(3);
        }
        var result = json.decode(cleanBody);

        if (result['status'] == 'success') {
          // Chỉ cộng điểm khi tạo bài mới (không cộng khi sửa bài)
          Map<String, dynamic>? pointsResult;
          if (widget.postId == null) {
            pointsResult = await ApiService.addPointsResult(userId, 15);
            if (!mounted) return;
          }

          final pointsLevel =
              int.tryParse((pointsResult?['level'] ?? '').toString()) ?? 0;
          final didLevelUp =
              pointsResult?['level_up'] == true && pointsLevel > 0;
          final pointsMessage =
              pointsResult != null && pointsResult['status'] == 'success'
              ? "Đăng bài thành công! Bạn nhận +15 EXP."
              : "Đăng bài thành công rồi nè!";

          if (didLevelUp) {
            await showLevelUpCelebrationDialog(
              context,
              level: pointsLevel,
              points: 15,
            );
            if (!mounted) return;
          } else {
            ScaffoldMessenger.of(
              context,
            ).showSnackBar(SnackBar(content: Text(pointsMessage)));
          }
          Navigator.pop(
            context,
            true,
          ); // Đóng form, trả về true để load lại bảng tin
        } else if (result['status'] == 'community_violation') {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: Text(
                result['message'] ??
                    "Bài viết của bạn vi phạm tiêu chuẩn cộng đồng.",
              ),
              backgroundColor: Colors.red,
              duration: const Duration(seconds: 4),
            ),
          );
        } else {
          throw Exception("Máy chủ từ chối: ${result['message']}");
        }
      } else {
        throw Exception(
          "Lỗi kết nối máy chủ XAMPP! Mã lỗi: ${response.statusCode}",
        );
      }
    } catch (e) {
      debugPrint("LỖI XẢY RA: $e");
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text("Lỗi rồi: $e")));
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  @override
  void dispose() {
    _contentController.dispose();
    super.dispose();
  }

  Widget _buildSelectedImageGrid() {
    return GridView.builder(
      padding: const EdgeInsets.all(8),
      physics: const NeverScrollableScrollPhysics(),
      gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: 2,
        crossAxisSpacing: 8,
        mainAxisSpacing: 8,
      ),
      itemCount: _selectedImages.length,
      itemBuilder: (context, index) {
        return ClipRRect(
          borderRadius: BorderRadius.circular(10),
          child: Image.file(_selectedImages[index], fit: BoxFit.cover),
        );
      },
    );
  }

  Widget _buildMediaStatusBar() {
    final isUploading =
        _isLoading && (_selectedMedia != null || _selectedImages.isNotEmpty);
    final color = isUploading
        ? const Color(0xFF1A237E)
        : const Color(0xFF2E7D32);

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: color.withValues(alpha: 0.22)),
      ),
      child: Row(
        children: [
          if (isUploading)
            const SizedBox(
              width: 18,
              height: 18,
              child: CircularProgressIndicator(strokeWidth: 2),
            )
          else
            Icon(_mediaStatusIcon, color: color, size: 20),
          const SizedBox(width: 9),
          Expanded(
            child: Text(
              _mediaStatusText,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                color: color,
                fontSize: 13,
                fontWeight: FontWeight.w800,
              ),
            ),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    bool isEditing = widget.postId != null;

    return Scaffold(
      backgroundColor: Colors.white,
      appBar: AppBar(
        title: Text(
          isEditing ? "Sửa bài viết" : "Tạo bài viết mới",
          style: const TextStyle(
            color: Colors.white,
            fontWeight: FontWeight.bold,
          ),
        ),
        backgroundColor: const Color(0xFF1A237E),
        iconTheme: const IconThemeData(color: Colors.white),
        actions: [
          _isLoading
              ? const Padding(
                  padding: EdgeInsets.only(right: 20),
                  child: Center(
                    child: Row(
                      children: [
                        SizedBox(
                          width: 18,
                          height: 18,
                          child: CircularProgressIndicator(
                            color: Colors.white,
                            strokeWidth: 2,
                          ),
                        ),
                        SizedBox(width: 8),
                        Text(
                          "ĐANG TẢI",
                          style: TextStyle(
                            color: Colors.white,
                            fontSize: 13,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                      ],
                    ),
                  ),
                )
              : TextButton(
                  onPressed: _savePost,
                  child: Text(
                    isEditing ? "LƯU" : "ĐĂNG",
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 16,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                ),
        ],
      ),
      body: Column(
        children: [
          Expanded(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(16),
              child: Column(
                children: [
                  TextField(
                    controller: _contentController,
                    maxLines: null,
                    minLines: 8,
                    decoration: const InputDecoration(
                      hintText: "Bạn đang muốn chia sẻ điều gì...",
                      border: InputBorder.none,
                    ),
                    style: const TextStyle(fontSize: 18),
                  ),
                  const SizedBox(height: 20),
                  if (_hasSelectedMedia)
                    Column(
                      children: [
                        Stack(
                          children: [
                            Container(
                              width: double.infinity,
                              height: 280,
                              decoration: BoxDecoration(
                                color: Colors.black87,
                                borderRadius: BorderRadius.circular(12),
                              ),
                              child: _isVideo
                                  ? const Center(
                                      child: Column(
                                        mainAxisAlignment:
                                            MainAxisAlignment.center,
                                        children: [
                                          Icon(
                                            Icons.video_file,
                                            color: Colors.white,
                                            size: 60,
                                          ),
                                          SizedBox(height: 10),
                                          Text(
                                            "Đã chọn 1 Video",
                                            style: TextStyle(
                                              color: Colors.white,
                                            ),
                                          ),
                                        ],
                                      ),
                                    )
                                  : ClipRRect(
                                      borderRadius: BorderRadius.circular(12),
                                      child: _selectedImages.isNotEmpty
                                          ? _buildSelectedImageGrid()
                                          : _selectedMedia != null
                                          ? Image.file(
                                              _selectedMedia!,
                                              fit: BoxFit.cover,
                                            )
                                          : Image.network(
                                              _existingImageUrl!,
                                              fit: BoxFit.cover,
                                            ),
                                    ),
                            ),
                            if (_isLoading &&
                                (_selectedMedia != null ||
                                    _selectedImages.isNotEmpty))
                              Positioned.fill(
                                child: Container(
                                  decoration: BoxDecoration(
                                    color: Colors.black.withValues(alpha: 0.45),
                                    borderRadius: BorderRadius.circular(12),
                                  ),
                                  child: const Center(
                                    child: Column(
                                      mainAxisSize: MainAxisSize.min,
                                      children: [
                                        CircularProgressIndicator(
                                          color: Colors.white,
                                        ),
                                        SizedBox(height: 12),
                                        Text(
                                          "Đang tải lên...",
                                          style: TextStyle(
                                            color: Colors.white,
                                            fontWeight: FontWeight.w900,
                                          ),
                                        ),
                                      ],
                                    ),
                                  ),
                                ),
                              ),
                            Positioned(
                              right: 8,
                              top: 8,
                              child: GestureDetector(
                                onTap: _isLoading
                                    ? null
                                    : () => setState(() {
                                        _selectedMedia = null;
                                        _selectedImages.clear();
                                        _removeExistingMedia =
                                            _existingImageUrl != null ||
                                            (widget.initialImageUrl != null &&
                                                widget
                                                    .initialImageUrl!
                                                    .isNotEmpty) ||
                                            _removeExistingMedia;
                                        _existingImageUrl = null;
                                        _isVideo = false;
                                      }),
                                child: const CircleAvatar(
                                  backgroundColor: Colors.black54,
                                  radius: 15,
                                  child: Icon(
                                    Icons.close,
                                    color: Colors.white,
                                    size: 18,
                                  ),
                                ),
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 10),
                        _buildMediaStatusBar(),
                      ],
                    ),
                ],
              ),
            ),
          ),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
            decoration: BoxDecoration(
              color: Colors.white,
              border: Border(top: BorderSide(color: Colors.grey.shade200)),
            ),
            child: Row(
              children: [
                const Text(
                  "Thêm vào bài viết: ",
                  style: TextStyle(
                    fontWeight: FontWeight.bold,
                    color: Colors.black87,
                  ),
                ),
                const Spacer(),
                IconButton(
                  onPressed: _isLoading ? null : () => _pickMedia(false),
                  icon: const Icon(
                    Icons.photo_library,
                    color: Colors.green,
                    size: 30,
                  ),
                  tooltip: "Chọn nhiều ảnh",
                ),
                IconButton(
                  onPressed: _isLoading ? null : _takePhoto,
                  icon: const Icon(
                    Icons.photo_camera_rounded,
                    color: Colors.redAccent,
                    size: 30,
                  ),
                  tooltip: "Chụp ảnh",
                ),
                IconButton(
                  onPressed: _isLoading ? null : _pickAndCropSingleImage,
                  icon: const Icon(Icons.crop, color: Colors.orange, size: 30),
                  tooltip: "Chọn và cắt 1 ảnh",
                ),
                IconButton(
                  onPressed: _isLoading ? null : () => _pickMedia(true),
                  icon: const Icon(
                    Icons.videocam,
                    color: Colors.blue,
                    size: 32,
                  ),
                  tooltip: "Video",
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
