<?php

namespace App\Http\Controllers\Api\Student;

use Alkoumi\LaravelHijriDate\Hijri;
use App\Events\CertificateEvent;
use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\Program;
use App\Models\Student;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use PDF;
// use Barryvdh\DomPDF\Facade\Pdf;

class CertificateController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user || !($user instanceof Student)) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }

        $certificates = Certificate::where('student_id', $user->id)
        ->with(['program.category:id', 'program.category.translations', 'program.teacher:id,name,image'])
        ->get();

        $perPage = (int) $request->input('per_page', 6);
        $currentPage = (int) $request->input('page', 1);
        $paginated = paginationData($certificates, $perPage, $currentPage);

        return response()->json([
            'success' => true,
            'data' => $paginated
        ]);
    }

    public function certificate_program(Request $request, $id)
    {
        $user = $request->user();
        if (!$user || !($user instanceof Student)) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }

        $certificate = Certificate::where('student_id', $user->id)
        ->where('program_id', $id)
        ->with(['program' => function ($query) {
            $query->with(['category:id', 'category.translations', 'teacher:id,name,image'])
            ->select('id', 'type', 'image', 'price', 'date_from', 'date_to', 'time', 'duration', 'category_id', 'teacher_id')
            ->with('translations')
            ->withAvg('reviews', 'score')
            ->withCount('reviews')
            ->withSum('sessions as program_duration', 'video_duration')
            ->withCount('sessions as video_count');
        }])
        ->first();

        $certificate->program->program_duration = formatDuration($certificate->program->program_duration);

        return response()->json([
            'success' => true,
            'certificate' => $certificate
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'program_id'  => 'required|exists:programs,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();
        if (!$user || !($user instanceof Student)) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }

        $subscription = Subscription::where('student_id', $user->id)
            ->where('program_id', $request->program_id)
            ->first();

        if (!$subscription) {
            return response()->json([
                'error' => __('trans.alert.error.Student_not_subscribed_to_this_program')
            ], 422);
        }

        if (($subscription->expire_date && $subscription->expire_date->isPast()) || $subscription->status === 'banned')
        {
            return response()->json([
                'error' => __('trans.alert.error.subscription_has_expired')
            ], 422);
        }

        $course = Program::withSum('sessions as program_duration', 'video_duration')
        ->with('translations')
        ->findOrFail($request->program_id);

        if($course->type == 'registered') {
            $totalSeconds = $course->program_duration; // المدة الإجمالية بالثواني
            $dateFrom = $course->created_at->format('d/m/Y');
            $dateTo = $course->sessions->last()->created_at->format('d/m/Y');
        } else {
            if ($course->date_from && $course->date_to) {
                $totalSeconds = strtotime($course->date_to) - strtotime($course->date_from);
            } else {
                $totalSeconds = 0;
            }
            $dateFrom = $course->date_from;
            $dateTo = $course->date_to;

            // if ($course->duration) {
            //     $totalSeconds = $course->duration * 86400;
            // } else {
            //     $totalSeconds = 0;
            // }

            // $subscription = Subscription::where('student_id', $user->id)
            //     ->where('program_id', $request->program_id)
            //     ->first();

            // $dateFrom = $subscription->start_date;
            // $dateTo = $subscription->expire_date;
        }

        // مدة البرنامج بالأيام والساعات والدقايق
        $days = floor($totalSeconds / 86400); // 86400 ثانية = يوم
        $hours = floor(($totalSeconds % 86400) / 3600);
        $minutes = floor(($totalSeconds % 3600) / 60);

        $months = floor($days / 30);
        $remainingDays = $days % 30;

        if ($months > 0) {
            $durationText = "{$months} شهر " .
                            ($remainingDays > 0 ? "و {$remainingDays} يوم " : '') .
                            ($hours > 0 ? "و {$hours} ساعة" : '');
        } elseif ($days > 0) {
            $durationText = "{$days} يوم " . ($hours > 0 ? "و {$hours} ساعة" : '');
        } elseif ($hours > 0) {
            $durationText = "{$hours} ساعة " . ($minutes > 0 ? "و {$minutes} دقيقة" : '');
        } else {
            $durationText = "{$minutes} دقيقة";
        }

        $certificate = Certificate::where('student_id', $user->id)
        ->where('program_id', $course->id)->first();

        $data = [
            'name' => $user->name,
            'course' => $course->translations->firstWhere('locale', app()->getLocale())->title ?? '-',
            'time' => $durationText,
            'date_from' => $this->convertToArabicNumbers(Hijri::Date('Y/m/d', $dateFrom)),
            'date_to' => $this->convertToArabicNumbers(Hijri::Date('Y/m/d', $dateTo)),
        ];

        // $pdf = PDF::loadView('certificate', $data);
        $pdf = PDF::loadView('certificate', $data, [], [
            'format' => [237, 162],
            'defaultFont' => 'Cairo',
            'isRemoteEnabled' => true,
            'isHtml5ParserEnabled' => true,
            'isPhpEnabled' => true,
            'isFontSubsettingEnabled' => true,
        ]);

        if(!$certificate)
        {
            $directory = storage_path("app/public/certificates/program-{$course->id}");
            if (!File::exists($directory)) {
                File::makeDirectory($directory, 0755, true);
            }

            $fileName = "certificate-student-{$user->id}-". now()->format('Y-m-d') .".pdf";
            $filePath = "{$directory}/{$fileName}";

            $pdf->save($filePath);

            // db path
            $relativePath = "storage/certificates/program-{$course->id}/{$fileName}";

            $certificate = new Certificate;
            $certificate->student_id = $user->id;
            $certificate->program_id = $course->id;
            $certificate->file_path = $relativePath;
            $certificate->save();

            $studentSetting = $user->notification_setting()->first();
            if($studentSetting && $studentSetting->certificate_noti == 1){
                event(new CertificateEvent($course, $user));
            }
        }

        return $pdf->stream("certificate-student-{$user->id}-program-{$course->id}.pdf");
    }

    function convertToArabicNumbers($string)
    {
        $western = ['0','1','2','3','4','5','6','7','8','9'];
        $eastern = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
        return str_replace($western, $eastern, $string);
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
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
