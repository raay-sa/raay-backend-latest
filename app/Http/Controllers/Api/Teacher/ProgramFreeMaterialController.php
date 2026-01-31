<?php

namespace App\Http\Controllers\Api\Teacher;

use App\Http\Controllers\Controller;
use App\Models\FreeMaterial;
use App\Models\ProgramSection;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use getID3;
use Illuminate\Support\Facades\Log;
use Pion\Laravel\ChunkUpload\Receiver\FileReceiver;
use Pion\Laravel\ChunkUpload\Handler\HandlerFactory;

class ProgramFreeMaterialController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title'        => 'required',
            'video'        => 'required_without:files|file|mimes:mp4,mov,avi,mkv,webm',
            'files'        => 'required_without:video|array',
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

        $row = new FreeMaterial();
        $row->title        = $request->title;
        $row->section_id   = $request->section_id;
        $row->save();

        $files = [];
        if ($request->hasFile('files')) {
            foreach ($request->file('files') as $file) {
                $fileName = 'file_' . time() . '_' . uniqid() . '.' . $file->extension();
                $path = 'uploads/programs/program_id_' . $program_section->program->id . '/free_materials';

                $fullPath = public_path($path);
                if (!file_exists($fullPath)) {
                    mkdir($fullPath, 0755, true);
                }

                $file->move($fullPath, $fileName);
                $fileSize = filesize($fullPath . '/' . $fileName); // bytes

                $files[] = [
                    'path' => $path . '/' . $fileName,
                    // 'size' => $fileSize, // Bytes
                    'size' => formatSizeUnits($fileSize)
                ];
            }

            // $row->files = json_encode($files);
            $row->files = $files;
            $row->save();
        }

        // chunk upload
        if ($request->hasFile('video')) {
            $video = $request->file('video');
            $videoName = 'video_' . time() . '_' . uniqid() . '.' . $video->getClientOriginalExtension();
            $path = 'uploads/programs/program_id_' . $program_section->program->id . '/free_materials';

            $fullPath = public_path($path);
            if (!file_exists($fullPath)) {
                mkdir($fullPath, 0755, true);
            }

            $video->move($fullPath, $videoName);
            $videoPath = $fullPath . '/' . $videoName;

            $getID3 = new getID3;
            $getID3->setOption(['option_max_2gb_check' => false]);
            $video_file = $getID3->analyze($fullPath);

            $duration_seconds = 0;
            $duration_string = '0:00';

            if (isset($video_file['playtime_seconds']) && $video_file['playtime_seconds'] > 0) {
                $duration_seconds = round($video_file['playtime_seconds'], 2);
                $duration_string = $video_file['playtime_string'] ?? formatDuration($duration_seconds);
            } else {
                $duration_seconds = getDurationWithFFmpeg($fullPath);
                if ($duration_seconds > 0) {
                    $duration_string = formatDuration($duration_seconds);
                }
            }

            $row->video = $path;
            $row->video_duration = $duration_seconds;
            $row->save();
        }

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_create'),
            'data' => $row
        ]);
    }


    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'title'        => 'required|string|max:255',
            'video'        => 'required_without:files|file|mimes:mp4,mov,avi,mkv,webm',
            'files'        => 'required_without:video|array',
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

        $row = FreeMaterial::find($id);
        if(!$row) {
            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422);
        }

        $program_section = ProgramSection::find($request->section_id);

        if ($request->hasFile('files')) {
            if ($row->files) {
                $oldFiles = is_array($row->files) ? $row->files : json_decode($row->files, true);

                if ($oldFiles) {
                    foreach ($oldFiles as $oldFile) {
                        $filePath = is_array($oldFile) ? $oldFile['path'] : $oldFile;
                        $oldFilePath = public_path($filePath);

                        if (file_exists($oldFilePath)) {
                            unlink($oldFilePath);
                        }
                    }
                }
            }

            foreach ($request->file('files') as $file) {
                $fileName = 'file_' . time() . '_' . uniqid() . '.' . $file->extension();
                $path = 'uploads/programs/program_id_' . $program_section->program->id . '/free_materials';

                $fullPath = public_path($path);
                if (!file_exists($fullPath)) {
                    mkdir($fullPath, 0755, true);
                }

                $file->move($fullPath, $fileName);
                $fileSize = filesize($fullPath . '/' . $fileName); // bytes

                $files[] = [
                    'path' => $path . '/' . $fileName,
                    // 'size' => $fileSize, // Bytes
                    'size' => formatSizeUnits($fileSize)
                ];
            }

            $row->files = $files;
        }

        if ($request->hasFile('video')) {
            if ($row->video) {
                $oldVideoPath = public_path($row->video);

                if (file_exists($oldVideoPath) && is_file($oldVideoPath)) {
                    unlink($oldVideoPath);
                }
            }

            $video = $request->file('video');
            $videoName = 'video_' . time() . '_' . uniqid() . '.' . $video->getClientOriginalExtension();
            $path = 'uploads/programs/program_id_' . $program_section->program->id . '/free_materials';

            $fullPath = public_path($path);
            if (!file_exists($fullPath)) {
                mkdir($fullPath, 0755, true);
            }

            $video->move($fullPath, $videoName);
            $videoFullPath = $fullPath . '/' . $videoName;

            $getID3 = new getID3;
            $getID3->setOption(['option_max_2gb_check' => false]);
            $video_file = $getID3->analyze($videoFullPath);

            $duration_seconds = 0;
            $duration_string = '0:00';

            if (isset($video_file['playtime_seconds']) && $video_file['playtime_seconds'] > 0) {
                $duration_seconds = round($video_file['playtime_seconds'], 2);
                $duration_string = $video_file['playtime_string'] ?? formatDuration($duration_seconds);
            } else {
                $duration_seconds = getDurationWithFFmpeg($videoFullPath);
                if ($duration_seconds > 0) {
                    $duration_string = formatDuration($duration_seconds);
                }
            }

            $row->video = $path . '/' . $videoName;
            $row->video_duration = $duration_seconds;
        }

        $row->title        = $request->title;
        $row->section_id   = $request->section_id;
        $row->save();

        // updateInCache('programs', $row);
        return response()->json([
            'success' => true,
            'data' => $row
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $row = FreeMaterial::findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        if ($row->files) {
            $oldFiles = is_array($row->files) ? $row->files : json_decode($row->files, true);

            if ($oldFiles) {
                foreach ($oldFiles as $oldFile) {
                    if (file_exists(public_path($oldFile))) {
                        unlink(public_path($oldFile));
                    }
                }
            }
        }

        if ($row->video) {
            if (file_exists(public_path($row->video))) {
                unlink(public_path($row->video));
            }
        }

        $row->delete();

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_delete'),
        ]);
    }
}
