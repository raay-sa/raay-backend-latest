<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProgramSection;
use App\Models\ProgramSession;
use App\Models\ProgramSessionTranslation;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use getID3;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Pion\Laravel\ChunkUpload\Receiver\FileReceiver;
use Pion\Laravel\ChunkUpload\Handler\ResumableJSUploadHandler;

class ProgramSessionController extends Controller
{
    public function section_sessions($id)
    {
        $section = ProgramSection::with(['sessions.translations'])->findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        $section->sessions->makeHidden(['video_duration']);

        return response()->json([
            'success' => true,
            'data' => $section->sessions
        ]);

    }

     /**
     * Resumable.js endpoint:
     * - receives chunks (param name "file")
     * - assembles on last chunk
     * - validates title, section_id
     * - moves final file to storage/app/public/videos/sessions
     * - creates Session record and returns it
     *
     * Resumable sends the same query values on every chunk, so title/section_id
     * are available when finishing.
     */
    public function upload(Request $request)
    {
        if ($request->has('url')) {
            $validator = Validator::make($request->all(), [
                'title'      => 'required|array',
                'title.*'    => 'required',
                'section_id' => 'required|exists:program_sections,id',
                'url'        => 'required|url',
                'duration'   => 'required|date_format:H:i:s',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation errors',
                    'errors'  => $validator->errors(),
                ], 422);
            }

            list($hours, $minutes, $seconds) = array_pad(explode(':', $request->duration), 3, 0);
            $duration_seconds = ($hours * 3600) + ($minutes * 60) + $seconds;
            $duration_string  =  $request->duration; //"0:00";

            $session = new ProgramSession();
            $session->section_id     = $request->section_id;
            $session->url            = $request->url;
            $session->video_duration = $duration_seconds;
            $session->save();

            foreach ($request->title as $key => $value) {
                $trans_session = new ProgramSessionTranslation();
                $trans_session->parent_id = $session->id;
                $trans_session->locale = $key;
                $trans_session->title  = $value;
                $trans_session->save();
            }

            return response()->json([
                'success' => true,
                'data'    => [
                    'session_id'      => $session->id,
                    'url'             => $request->url,
                    'video_duration'  => $duration_seconds,
                    'duration_string' => $duration_string,
                ],
            ]);
        }

        // رفع فيديو
        $receiver = new FileReceiver('file', $request, ResumableJSUploadHandler::class);

         if (!$receiver->isUploaded()) {
            return response()->json([
                'success' => false,
                'message' => 'No file uploaded.',
            ], 400);
        }

        $save = $receiver->receive();

        // If upload not finished yet, return progress
        if (!$save->isFinished()) {
            $handler = $save->handler();

            return response()->json([
                'success' => true,
                'partial' => true,
                'done'    => $handler->getPercentageDone(), // 0..100
            ]);
        }

        // Upload finished - we have the assembled file
        $file = $save->getFile();

        $validator = Validator::make($request->all(), [
            'title'        => 'required|array',
            'title.*'      => 'required',
            'section_id'   => 'required|exists:program_sections,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Decide final name & path
        $ext = strtolower($file->getClientOriginalExtension() ?: 'mp4');
        $finalName = Str::uuid() . '.' . $ext;

        // save in public
        $relative = 'sessions/section_' . $request->section_id . '/'.'videos/' . $finalName;
        $absolute = public_path($relative); // بدلاً من storage_path

        // Move final assembled file to public directory
        @mkdir(dirname($absolute), 0775, true);
        $file->move(dirname($absolute), basename($absolute));

        // حساب مدة الفيديو بعد النقل مباشرة
        $duration_seconds = 0;
        $duration_string = '0:00';

        try {
            // getID3 لحساب مدة الفيديو
            $getID3 = new getID3;
            $getID3->setOption(['option_max_2gb_check' => false]);
            $video_file = $getID3->analyze($absolute);

            if (isset($video_file['playtime_seconds']) && $video_file['playtime_seconds'] > 0) {
                $duration_seconds = round($video_file['playtime_seconds'], 2);
                $duration_string = $video_file['playtime_string'] ?? formatDuration($duration_seconds);
            } else {
                $duration_seconds = getDurationWithFFmpeg($absolute);
                if ($duration_seconds > 0) {
                    $duration_string = formatDuration($duration_seconds);
                }
            }
        } catch (Exception $e) {
            Log::warning('Could not determine video duration: ' . $e->getMessage());

            try {
                $duration_seconds = getDurationWithFFmpeg($absolute);
                if ($duration_seconds > 0) {
                    $duration_string = formatDuration($duration_seconds);
                }
            } catch (Exception $e2) {
                Log::error('FFmpeg also failed to get duration: ' . $e2->getMessage());
            }
        }

        // Create session record
        $session = new ProgramSession();
        $session->section_id      = $request->section_id;
        $session->video           = $relative;
        $session->video_duration  = $duration_seconds;
        $session->save();

        // $publicUrl = Storage::disk('public')->url($relative);
        $publicUrl = asset($relative);

        foreach($request->title as $key => $value) {
            $trans_session = new ProgramSessionTranslation();
            $trans_session->parent_id = $session->id;
            $trans_session->locale = $key;
            $trans_session->title = $request->title[$key];
            $trans_session->save();
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'session_id'      => $session->id,
                'video_path'      => $relative,
                'video_url'       => $publicUrl,
                'video_duration'  => $duration_seconds,
                'duration_string' => $duration_string,  // "5:30"
            ],
        ]);
    }

    /**
     * Optional attachments upload after session is created.
     * POST /api/teacher/sessions/{session}/attachments
     * FormData: files[] (multiple)
     */
    public function uploadAttachments(Request $request, ProgramSession $session)
    {
        $request->validate([
            'files.*' => ['file', 'mimes:jpeg,png,jpg,gif,svg,pdf,doc,docx,xls,xlsx'],
        ]);

        $paths = [];
        $files = [];

        if ($request->hasFile('files')) {
            // remove old files if exist
            if ($session->files) {
                foreach ($session->files as $file) {
                    $oldFilePath = public_path($file['path'] ?? $file);
                    if (file_exists($oldFilePath)) {
                        @unlink($oldFilePath);
                    }
                }
            }

            foreach ($request->file('files') as $file) {
                $originalName = $file->getClientOriginalName(); // اسم الملف الأصلي
                $relativePath = 'sessions/section_' . $session->section_id . '/files/' . $originalName;
                $absolutePath = public_path($relativePath);

                @mkdir(dirname($absolutePath), 0755, true);
                $file->move(dirname($absolutePath), $originalName);

                $paths[] = $relativePath;
                $fileSize = filesize($absolutePath);
                $files[] = [
                    'path' => $relativePath,
                    'size' => formatSizeUnits($fileSize),
                    // 'original_name' => $file->getClientOriginalName(),
                    // 'url' => asset($relativePath),
                ];
            }

            $session->files = $files;
            $session->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'Files uploaded successfully',
            'files' => array_map(fn($file) => [
                'path' => $file['path'],
                'size' => $file['size'],
                // 'url' => asset($file['path']),
                // 'original_name' => $f['original_name'] ?? '',
            ], $files),
        ]);
    }

    public function store(Request $request)
    {
        if ($request->has('url')) {
            $validator = Validator::make($request->all(), [
                'title'        => 'required|array',
                'title.*'      => 'required',
                'type'         => 'required|in:registered,live',
                'url'          => 'required_if:type,registered|url',
                'duration'     => 'required_if:type,registered',
                'files'        => 'nullable|array',
                'files.*'      => 'nullable|file|mimes:jpeg,png,jpg,gif,svg,pdf,doc,docx,xls,xlsx|max:2048',
                'section_id'   => 'required|exists:program_sections,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation errors',
                    'errors'  => $validator->errors(),
                ], 422);
            }

            list($hours, $minutes, $seconds) = array_pad(explode(':', $request->duration), 3, 0);
            $duration_seconds = ($hours * 3600) + ($minutes * 60) + $seconds;
            $duration_string = $request->duration;

            $session = new ProgramSession();
            $session->section_id = $request->section_id;
            $session->type = $request->type;
            $session->url = $request->url;
            $session->video = null;
            $session->video_duration = $duration_seconds;
            $session->save();

            ProgramSessionTranslation::where('parent_id', $session->id)->delete();
            foreach ($request->title as $key => $value) {
                $trans = new ProgramSessionTranslation();
                $trans->parent_id = $session->id;
                $trans->locale = $key;
                $trans->title = $value;
                $trans->save();
            }

            // =============================================
            // رفع الملفات الإضافية (Files/Attachments)
            // =============================================
            if ($request->hasFile('files')) {
                $request->validate([
                    'files.*' => ['file', 'mimes:jpeg,png,jpg,gif,svg,pdf,doc,docx,xls,xlsx'],
                ]);

                // حذف الملفات القديمة
                if ($session->files) {
                    foreach ($session->files as $file) {
                        $oldFilePath = public_path($file['path'] ?? $file);
                        if (file_exists($oldFilePath)) {
                            @unlink($oldFilePath);
                        }
                    }
                }

                $files = [];
                foreach ($request->file('files') as $file) {
                    $originalName = $file->getClientOriginalName(); // اسم الملف الأصلي
                    $section_id = $request->section_id ?? $session->section_id;
                    $relativePath = 'sessions/section_' . $section_id . '/files/' . $originalName;
                    $absolutePath = public_path($relativePath);

                    @mkdir(dirname($absolutePath), 0755, true);
                    $file->move(dirname($absolutePath), $originalName);

                    $fileSize = filesize($absolutePath);
                    $files[] = [
                        'path' => $relativePath,
                        'size' => formatSizeUnits($fileSize),
                    ];
                }

                $session->files = $files;
                $session->save();

                $responseData['files'] = $files;
            }

            return response()->json([
                'success' => true,
                'data'    => [
                    'session_id'      => $session->id,
                    'video_url'       => $session->url,
                    'video_duration'  => $session->video_duration,
                    'duration_string' => $duration_string,
                    'files'           => $session->files,
                ],
            ]);
        }

        $validator = Validator::make($request->all(), [
            'title'        => 'required|array',
            'title.*'      => 'required',
            'type'         => 'required|in:registered,live',
            'video'        => 'required_if:type,registered|file|mimes:mp4,mov,avi,mkv,webm',
            'files'        => 'nullable|array',
            'files.*'      => 'nullable|file|mimes:jpeg,png,jpg,gif,svg,pdf,doc,docx,xls,xlsx|max:2048',
            'section_id'   => 'required|exists:program_sections,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        $program_section = ProgramSection::find($request->section_id);

        $session = new ProgramSession();
        $session->type         = $request->type;
        $session->section_id   = $request->section_id;
        $session->save();

        $files = [];
        if ($request->hasFile('files')) {
            foreach ($request->file('files') as $file) {
                $originalName = $file->getClientOriginalName(); // اسم الملف الأصلي
                $path = 'uploads/programs/program_id_' . $program_section->program->id;
                $section_id = $request->section_id;
                $relativePath = 'sessions/section_' . $section_id . '/files/' . $originalName;
                $absolutePath = public_path($relativePath);

                @mkdir(dirname($absolutePath), 0755, true);
                $file->move(dirname($absolutePath), $originalName);

                $fileSize = filesize($absolutePath);
                $files[] = [
                    'path' => $relativePath,
                    'size' => formatSizeUnits($fileSize),
                ];
            }

            // $session->files = json_encode($files);
            $session->files = $files;
            $session->save();

            $responseData['files'] = $files;
        }

        // =============================================
        // رفع فيديو عادي (من Postman مثلاً) - بدون Resumable
        // =============================================
        if ($request->hasFile('video') && !$request->has('resumableChunkNumber')) {
            $file = $request->file('video');

            // حذف الفيديو القديم
            if ($session->video && !filter_var($session->video, FILTER_VALIDATE_URL)) {
                $oldVideoPath = public_path($session->video);
                if (file_exists($oldVideoPath)) {
                    @unlink($oldVideoPath);
                }
            }

            // حفظ الفيديو الجديد
            $ext = strtolower($file->getClientOriginalExtension() ?: 'mp4');
            $finalName = Str::uuid() . '.' . $ext;
            $section_id = $request->section_id ?? $session->section_id;
            $relative = 'videos/section_' . $section_id . '/sessions/' . $finalName;
            $absolute = public_path($relative);

            @mkdir(dirname($absolute), 0775, true);
            $file->move(dirname($absolute), basename($absolute));

            // حساب مدة الفيديو
            $duration_seconds = 0;
            $duration_string = '0:00';

            try {
                $getID3 = new getID3;
                $getID3->setOption(['option_max_2gb_check' => false]);
                $video_file = $getID3->analyze($absolute);

                if (isset($video_file['playtime_seconds']) && $video_file['playtime_seconds'] > 0) {
                    $duration_seconds = round($video_file['playtime_seconds'], 2);
                    $duration_string = $video_file['playtime_string'] ?? formatDuration($duration_seconds);
                } else {
                    $duration_seconds = getDurationWithFFmpeg($absolute);
                    if ($duration_seconds > 0) {
                        $duration_string = formatDuration($duration_seconds);
                    }
                }
            } catch (Exception $e) {
                Log::warning('Could not determine video duration: ' . $e->getMessage());
                try {
                    $duration_seconds = getDurationWithFFmpeg($absolute);
                    if ($duration_seconds > 0) {
                        $duration_string = formatDuration($duration_seconds);
                    }
                } catch (Exception $e2) {
                    Log::error('FFmpeg also failed: ' . $e2->getMessage());
                }
            }

            $session->video = $relative;
            $session->url = null;
            $session->video_duration = $duration_seconds;
            $session->save();

            $responseData['video_path'] = $relative;
            $responseData['video_url'] = asset($relative);
            $responseData['video_duration'] = $duration_seconds;
            $responseData['duration_string'] = $duration_string;
        }

        // =============================================
        // رفع فيديو Resumable (من Frontend مع chunks)
        // =============================================
        elseif ($request->has('resumableChunkNumber') || $request->hasFile('file')) {
            $receiver = new FileReceiver('file', $request, ResumableJSUploadHandler::class);

            if ($receiver->isUploaded()) {
                $save = $receiver->receive();

                // لو لسه بيرفع chunks
                if (!$save->isFinished()) {
                    $handler = $save->handler();
                    return response()->json([
                        'success' => true,
                        'partial' => true,
                        'done'    => $handler->getPercentageDone(),
                    ]);
                }

                // Upload خلص
                $file = $save->getFile();

                // حذف الفيديو القديم
                if ($session->video && !filter_var($session->video, FILTER_VALIDATE_URL)) {
                    $oldVideoPath = public_path($session->video);
                    if (file_exists($oldVideoPath)) {
                        @unlink($oldVideoPath);
                    }
                }

                // حفظ الفيديو الجديد
                $ext = strtolower($file->getClientOriginalExtension() ?: 'mp4');
                $finalName = Str::uuid() . '.' . $ext;
                $section_id = $request->section_id ?? $session->section_id;
                $relative = 'videos/section_' . $section_id . '/sessions/' . $finalName;
                $absolute = public_path($relative);

                @mkdir(dirname($absolute), 0775, true);
                $file->move(dirname($absolute), basename($absolute));

                // حساب مدة الفيديو
                $duration_seconds = 0;
                $duration_string = '0:00';

                try {
                    $getID3 = new getID3;
                    $getID3->setOption(['option_max_2gb_check' => false]);
                    $video_file = $getID3->analyze($absolute);

                    if (isset($video_file['playtime_seconds']) && $video_file['playtime_seconds'] > 0) {
                        $duration_seconds = round($video_file['playtime_seconds'], 2);
                        $duration_string = $video_file['playtime_string'] ?? formatDuration($duration_seconds);
                    } else {
                        $duration_seconds = getDurationWithFFmpeg($absolute);
                        if ($duration_seconds > 0) {
                            $duration_string = formatDuration($duration_seconds);
                        }
                    }
                } catch (Exception $e) {
                    Log::warning('Could not determine video duration: ' . $e->getMessage());
                    try {
                        $duration_seconds = getDurationWithFFmpeg($absolute);
                        if ($duration_seconds > 0) {
                            $duration_string = formatDuration($duration_seconds);
                        }
                    } catch (Exception $e2) {
                        Log::error('FFmpeg also failed: ' . $e2->getMessage());
                    }
                }

                $session->video = $relative;
                $session->url = null;
                $session->video_duration = $duration_seconds;
                $session->save();

                $responseData['video_path'] = $relative;
                $responseData['video_url'] = asset($relative);
                $responseData['video_duration'] = $duration_seconds;
                $responseData['duration_string'] = $duration_string;
            }
        }

        foreach($request->title as $key => $value) {
            $trans_session = new ProgramSessionTranslation();
            $trans_session->parent_id = $session->id;
            $trans_session->locale = $key;
            $trans_session->title = $request->title[$key];
            $trans_session->save();
        }

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_create'),
            'data' => $session,
        ]);

    }

    public function update(Request $request, ProgramSession $session)
    {
        // =============================================
        // URL instead video
        // =============================================
        if ($request->has('url')) {
            $validator = Validator::make($request->all(), [
                'title'    => 'required|array',
                'title.*'  => 'required',
                'url'      => 'required|url',
                'duration' => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation errors',
                    'errors'  => $validator->errors(),
                ], 422);
            }

            if ($session->video && !filter_var($session->video, FILTER_VALIDATE_URL)) {
                $oldVideoPath = public_path($session->video);
                if (file_exists($oldVideoPath)) {
                    @unlink($oldVideoPath);
                }
            }

            list($hours, $minutes, $seconds) = array_pad(explode(':', $request->duration), 3, 0);
            $duration_seconds = ($hours * 3600) + ($minutes * 60) + $seconds;
            $duration_string = $request->duration;

            $session->url = $request->url;
            $session->video = null;
            $session->video_duration = $duration_seconds;
            $session->save();

            ProgramSessionTranslation::where('parent_id', $session->id)->delete();
            foreach ($request->title as $key => $value) {
                $trans = new ProgramSessionTranslation();
                $trans->parent_id = $session->id;
                $trans->locale = $key;
                $trans->title = $value;
                $trans->save();
            }

            // =============================================
            // رفع الملفات الإضافية (Files/Attachments)
            // =============================================
            if ($request->hasFile('files')) {
                $request->validate([
                    'files.*' => ['file', 'mimes:jpeg,png,jpg,gif,svg,pdf,doc,docx,xls,xlsx'],
                ]);

                // حذف الملفات القديمة
                if ($session->files) {
                    foreach ($session->files as $file) {
                        $oldFilePath = public_path($file['path'] ?? $file);
                        if (file_exists($oldFilePath)) {
                            @unlink($oldFilePath);
                        }
                    }
                }

                $files = [];
                foreach ($request->file('files') as $file) {
                    $originalName = $file->getClientOriginalName(); // اسم الملف الأصلي
                    $section_id = $request->section_id ?? $session->section_id;

                    $relativePath = 'sessions/section_' . $section_id . '/files/' . $originalName;
                    $absolutePath = public_path($relativePath);

                    @mkdir(dirname($absolutePath), 0755, true);
                    $file->move(dirname($absolutePath), $originalName);

                    $fileSize = filesize($absolutePath);

                    $files[] = [
                        'path' => $relativePath,
                        'size' => formatSizeUnits($fileSize),
                    ];
                }

                $session->files = $files;
                $session->save();

                $responseData['files'] = $files;
            }

            return response()->json([
                'success' => true,
                'data'    => [
                    'session_id'      => $session->id,
                    'video_url'       => $session->url,
                    'video_duration'  => $session->video_duration,
                    'duration_string' => $duration_string,
                    'files'           => $session->files ?? [],
                ],
            ]);
        }

        // =============================================
        //  Validation
        // =============================================
        $validator = Validator::make($request->all(), [
            'title'        => 'required|array',
            'title.*'      => 'required',
            'section_id'   => 'sometimes|exists:program_sections,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        $responseData = [
            'session_id' => $session->id,
        ];

        // =============================================
        // رفع فيديو عادي (من Postman مثلاً) - بدون Resumable
        // =============================================
        if ($request->hasFile('video') && !$request->has('resumableChunkNumber'))
        {
            $file = $request->file('video');

            // حذف الفيديو القديم
            if ($session->video && !filter_var($session->video, FILTER_VALIDATE_URL)) {
                $oldVideoPath = public_path($session->video);
                if (file_exists($oldVideoPath)) {
                    @unlink($oldVideoPath);
                }
            }

            // حفظ الفيديو الجديد
            $ext = strtolower($file->getClientOriginalExtension() ?: 'mp4');
            $finalName = Str::uuid() . '.' . $ext;
            $section_id = $request->section_id ?? $session->section_id;
            $relative = 'videos/section_' . $section_id . '/sessions/' . $finalName;
            $absolute = public_path($relative);

            @mkdir(dirname($absolute), 0775, true);
            $file->move(dirname($absolute), basename($absolute));

            // حساب مدة الفيديو
            $duration_seconds = 0;
            $duration_string = '0:00';

            try {
                $getID3 = new getID3;
                $getID3->setOption(['option_max_2gb_check' => false]);
                $video_file = $getID3->analyze($absolute);

                if (isset($video_file['playtime_seconds']) && $video_file['playtime_seconds'] > 0) {
                    $duration_seconds = round($video_file['playtime_seconds'], 2);
                    $duration_string = $video_file['playtime_string'] ?? formatDuration($duration_seconds);
                } else {
                    $duration_seconds = getDurationWithFFmpeg($absolute);
                    if ($duration_seconds > 0) {
                        $duration_string = formatDuration($duration_seconds);
                    }
                }
            } catch (Exception $e) {
                Log::warning('Could not determine video duration: ' . $e->getMessage());
                try {
                    $duration_seconds = getDurationWithFFmpeg($absolute);
                    if ($duration_seconds > 0) {
                        $duration_string = formatDuration($duration_seconds);
                    }
                } catch (Exception $e2) {
                    Log::error('FFmpeg also failed: ' . $e2->getMessage());
                }
            }

            $session->video = $relative;
            $session->url = null;
            $session->video_duration = $duration_seconds;
            $session->save();

            $responseData['video_path'] = $relative;
            $responseData['video_url'] = asset($relative);
            $responseData['video_duration'] = $duration_seconds;
            $responseData['duration_string'] = $duration_string;
        }

        // =============================================
        // رفع فيديو Resumable (من Frontend مع chunks)
        // =============================================
        elseif ($request->has('resumableChunkNumber') || $request->hasFile('file')) {
            $receiver = new FileReceiver('file', $request, ResumableJSUploadHandler::class);

            if ($receiver->isUploaded()) {
                $save = $receiver->receive();

                // لو لسه بيرفع chunks
                if (!$save->isFinished()) {
                    $handler = $save->handler();
                    return response()->json([
                        'success' => true,
                        'partial' => true,
                        'done'    => $handler->getPercentageDone(),
                    ]);
                }

                // Upload خلص
                $file = $save->getFile();

                // حذف الفيديو القديم
                if ($session->video && !filter_var($session->video, FILTER_VALIDATE_URL)) {
                    $oldVideoPath = public_path($session->video);
                    if (file_exists($oldVideoPath)) {
                        @unlink($oldVideoPath);
                    }
                }

                // حفظ الفيديو الجديد
                $ext = strtolower($file->getClientOriginalExtension() ?: 'mp4');
                $finalName = Str::uuid() . '.' . $ext;
                $section_id = $request->section_id ?? $session->section_id;
                $relative = 'videos/section_' . $section_id . '/sessions/' . $finalName;
                $absolute = public_path($relative);

                @mkdir(dirname($absolute), 0775, true);
                $file->move(dirname($absolute), basename($absolute));

                // حساب مدة الفيديو
                $duration_seconds = 0;
                $duration_string = '0:00';

                try {
                    $getID3 = new getID3;
                    $getID3->setOption(['option_max_2gb_check' => false]);
                    $video_file = $getID3->analyze($absolute);

                    if (isset($video_file['playtime_seconds']) && $video_file['playtime_seconds'] > 0) {
                        $duration_seconds = round($video_file['playtime_seconds'], 2);
                        $duration_string = $video_file['playtime_string'] ?? formatDuration($duration_seconds);
                    } else {
                        $duration_seconds = getDurationWithFFmpeg($absolute);
                        if ($duration_seconds > 0) {
                            $duration_string = formatDuration($duration_seconds);
                        }
                    }
                } catch (Exception $e) {
                    Log::warning('Could not determine video duration: ' . $e->getMessage());
                    try {
                        $duration_seconds = getDurationWithFFmpeg($absolute);
                        if ($duration_seconds > 0) {
                            $duration_string = formatDuration($duration_seconds);
                        }
                    } catch (Exception $e2) {
                        Log::error('FFmpeg also failed: ' . $e2->getMessage());
                    }
                }

                $session->video = $relative;
                $session->url = null;
                $session->video_duration = $duration_seconds;
                $session->save();

                $responseData['video_path'] = $relative;
                $responseData['video_url'] = asset($relative);
                $responseData['video_duration'] = $duration_seconds;
                $responseData['duration_string'] = $duration_string;
            }
        }

        // =============================================
        // رفع الملفات الإضافية (Files/Attachments)
        // =============================================
        if ($request->hasFile('files')) {
            $request->validate([
                'files.*' => ['file', 'mimes:jpeg,png,jpg,gif,svg,pdf,doc,docx,xls,xlsx'],
            ]);

            // حذف الملفات القديمة
            if ($session->files) {
                foreach ($session->files as $file) {
                    $oldFilePath = public_path($file['path'] ?? $file);
                    if (file_exists($oldFilePath)) {
                        @unlink($oldFilePath);
                    }
                }
            }

            $files = [];
            foreach ($request->file('files') as $file) {
                $originalName = $file->getClientOriginalName(); // اسم الملف الأصلي
                $section_id = $request->section_id ?? $session->section_id;

                $relativePath = 'sessions/section_' . $section_id . '/files/' . $originalName;
                $absolutePath = public_path($relativePath);

                @mkdir(dirname($absolutePath), 0755, true);
                $file->move(dirname($absolutePath), $originalName);

                $fileSize = filesize($absolutePath);

                $files[] = [
                    'path' => $relativePath,
                    'size' => formatSizeUnits($fileSize),
                ];
            }

            $session->files = $files;
            $session->save();

            $responseData['files'] = $files;
        }

        // =============================================
        // تحديث الترجمات (العناوين)
        // =============================================
        ProgramSessionTranslation::where('parent_id', $session->id)->delete();
        foreach ($request->title as $key => $value) {
            $trans = new ProgramSessionTranslation();
            $trans->parent_id = $session->id;
            $trans->locale = $key;
            $trans->title = $value;
            $trans->save();
        }

        // =============================================
        // Return Response
        // =============================================
        return response()->json([
            'success' => true,
            'data' => $responseData,
        ]);
    }

}
