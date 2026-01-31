<?php
namespace App\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Pion\Laravel\ChunkUpload\Receiver\FileReceiver;
use Pion\Laravel\ChunkUpload\Handler\HandlerFactory;
use getID3;
use Illuminate\Support\Facades\Log;

class UploadController extends Controller
{
    public function index()
    {
        return view('video');
    }

    public function store2(Request $request)
    {
        $receiver = new FileReceiver("file", $request, HandlerFactory::classFromRequest($request));

        if ($receiver->isUploaded() === false) {
            return response()->json(['error' => 'No file uploaded.'], 400);
        }

        $save = $receiver->receive();

        if ($save->isFinished()) {
            $file = $save->getFile();
            $fileName = time() . '_' . $file->getClientOriginalName();
            $file->move(storage_path('app/public/uploads'), $fileName);

            return response()->json([
                'success' => true,
                'path' => "/storage/uploads/" . $fileName
            ]);
        }

        // Progress
        $handler = $save->handler();
        return [
            "done" => $handler->getPercentageDone(),
            "status" => true
        ];
    }


    public function store3(Request $request)
    {
        $receiver = new FileReceiver("file", $request, HandlerFactory::classFromRequest($request));

        if ($receiver->isUploaded() === false) {
            return response()->json(['error' => 'No file uploaded.'], 400);
        }

        $save = $receiver->receive();

        if ($save->isFinished()) {
            $file = $save->getFile();
            $fileName = time() . '_' . $file->getClientOriginalName();
            $filePath = storage_path('app/public/uploads/' . $fileName);
            $file->move(storage_path('app/public/uploads'), $fileName);

            // قراءة مدة الفيديو
            $getID3 = new getID3;
            $videoInfo = $getID3->analyze($filePath);
            $duration = isset($videoInfo['playtime_string']) ? $videoInfo['playtime_string'] : null;

            return response()->json([
                'success' => true,
                'path' => "/storage/uploads/" . $fileName,
                'duration' => $duration // ⬅ مدة الفيديو في شكل "mm:ss"
            ]);
        }

        // Progress
        $handler = $save->handler();
        return [
            "done" => $handler->getPercentageDone(),
            "status" => true
        ];
    }










    public function store(Request $request)
    {
        try {
            $request->validate([
                'file' => [
                    'required',
                    'file',
                    'mimetypes:video/mp4,video/avi,video/mov,video/wmv,video/flv,video/webm,video/quicktime',
                    'max:200000' // 200MB max
                ]
            ], [
                'file.required' => 'Please select a video file',
                'file.mimetypes' => 'Please upload a valid video file',
                'file.max' => 'Video file size must not exceed 200MB'
            ]);

            $file = $request->file('file');
            $path = $file->store('uploads', 'public');
            $fullPath = storage_path('app/public/' . $path);

            // تحليل الفيديو
            $getID3 = new getID3;
            $getID3->setOption(['option_max_2gb_check' => false]);

            $video_file = $getID3->analyze($fullPath);

            // إعدادات افتراضية للفيديوهات القصيرة
            $duration_seconds = 0;
            $duration_string = '0:00';
            $width = 0;
            $height = 0;
            $bitrate = 0;

            // الحصول على بيانات الفيديو
            if (isset($video_file['playtime_seconds']) && $video_file['playtime_seconds'] > 0) {
                $duration_seconds = round($video_file['playtime_seconds'], 2);
                $duration_string = $video_file['playtime_string'] ?? $this->formatDuration($duration_seconds);
            } else {
                // استخدام FFmpeg للفيديوهات القصيرة أو التي لا يمكن تحليلها
                $duration_seconds = $this->getDurationWithFFmpeg($fullPath);
                if ($duration_seconds > 0) {
                    $duration_string = $this->formatDuration($duration_seconds);
                }
            }

            // الحصول على أبعاد الفيديو
            if (isset($video_file['video']['resolution_x'])) {
                $width = $video_file['video']['resolution_x'];
                $height = $video_file['video']['resolution_y'];
            }

            // الحصول على bitrate
            if (isset($video_file['bitrate'])) {
                $bitrate = $video_file['bitrate'];
            }

            return response()->json([
                'success' => true,
                'path' => $path,
                'duration_seconds' => $duration_seconds,
                'duration_formatted' => $duration_string,
                'file_size' => $file->getSize(),
                'original_name' => $file->getClientOriginalName(),
                'width' => $width,
                'height' => $height,
                'bitrate' => $bitrate,
                'mime_type' => $file->getMimeType()
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            Log::error('Video upload error: ' . $e->getMessage(), [
                'file_name' => $request->file('file')?->getClientOriginalName(),
                'file_size' => $request->file('file')?->getSize(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to process video file. Please try again or contact support.',
                'debug_error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    //  لتنسيق المدة
    private function formatDuration($seconds)
    {
        if ($seconds < 60) {
            return '0:' . sprintf('%02d', $seconds);
        }

        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;

        if ($minutes < 60) {
            return $minutes . ':' . sprintf('%02d', $remainingSeconds);
        }

        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;

        return $hours . ':' . sprintf('%02d', $remainingMinutes) . ':' . sprintf('%02d', $remainingSeconds);
    }

    // لاستخدام FFmpeg مع الفيديوهات القصيرة
    private function getDurationWithFFmpeg($filePath)
    {
        try {
            if (!function_exists('shell_exec')) {
                return 0;
            }

            // التحقق من وجود FFmpeg
            $ffprobeCheck = shell_exec('which ffprobe 2>/dev/null');
            if (empty($ffprobeCheck)) {
                return 0;
            }

            $command = sprintf(
                'ffprobe -v quiet -show_entries format=duration -of csv="p=0" %s 2>/dev/null',
                escapeshellarg($filePath)
            );

            $output = shell_exec($command);

            if ($output && is_numeric(trim($output))) {
                return floatval(trim($output));
            }

            return 0;

        } catch (Exception $e) {
            Log::warning('FFmpeg duration extraction failed: ' . $e->getMessage());
            return 0;
        }
    }


}
