<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class ReviewController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $filter = $request->filter ?? 'all';
        $search = $request->search;
        $score = $request->score;

        $reviews = getDataFromCache('reviews', function () {
            return Review::with([
                'student:id,name',
                'program' => function ($query) {
                    $query->select('id')->with('translations')
                    ->withSum('sessions as program_duration', 'video_duration');
                }
            ])->get();
        })->map(function ($review) {
            $review->program->program_duration = formatDuration($review->program->program_duration);
            return $review;
        });

        $totalReviews = $reviews->count();
        $positiveReviews = $reviews->where('score', '>=', 3)->count();
        $negativeReviews = $reviews->where('score', '<' , 3)->count();

        $reviewsThisMonth = $reviews->filter(function ($review) {
            return $review->created_at->isCurrentMonth();
        });

        $reviewsLastMonth = $reviews->filter(function ($review) {
            return $review->created_at->isLastMonth();
        });

        $current_reviews = $reviewsThisMonth->count();
        $current_positive_reviews = $reviewsThisMonth->where('score', '>=', 3)->count();
        $current_negative_reviews = $reviewsThisMonth->where('score', '<', 3)->count();

        $previous_reviews = $reviewsLastMonth->count();
        $previous_positive_reviews = $reviewsLastMonth->where('score', '>=', 3)->count();
        $previous_negative_reviews = $reviewsLastMonth->where('score', '<', 3)->count();

        $calcChange = fn($current, $previous) => $previous > 0
            ? round((($current - $previous) / $previous) * 100, 2)
            : 100;

        $review_percentage_change = $calcChange($current_reviews, $previous_reviews);
        $positive_review_percentage_change = $calcChange($current_positive_reviews, $previous_positive_reviews);
        $negative_review_percentage_change = $calcChange($current_negative_reviews, $previous_negative_reviews);


        if ($filter === 'latest') {
            $reviews = $reviews->sortByDesc('created_at')->values();
        } elseif ($filter === 'oldest') {
            $reviews = $reviews->sortBy('created_at')->values();
        } elseif ($filter === 'name') {
            $reviews = $reviews->sortBy(fn($review) => mb_strtolower($review->student->name))->values();
        }

        if ($search) {
            $reviews = $reviews->filter(function ($review) use ($search) {
                return mb_stripos($review->student->name, $search) !== false
                    || mb_stripos($review->program->title, $search) !== false;
            })->values();
        }

        if ($score) {
            $reviews = $reviews->where('score', $score)->values();
        }

        $perPage = (int) $request->input('per_page', 10);
        $currentPage = (int) $request->input('page', 1);
        $paginated = paginationData($reviews, $perPage, $currentPage);

        return response()->json([
            'success' => true,
            'total'    => $totalReviews,
            'review_percentage' => abs($review_percentage_change),
            'review_status' => $review_percentage_change >= 0 ? 'increase' : 'decrease',

            'positive' => $positiveReviews,
            'positive_percentage' => abs($positive_review_percentage_change),
            'positive_status' => $positive_review_percentage_change >= 0 ? 'increase' : 'decrease',

            'negative' => $negativeReviews,
            'negative_percentage' => abs($negative_review_percentage_change),
            'negative_status' => $negative_review_percentage_change >= 0 ? 'increase' : 'decrease',

            'data' => $paginated
        ]);
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
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $review = Review::findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        return response()->json([
            'success' => true,
            'data' => $review,
        ], 200);
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
        $review = Review::findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        $review->delete();
        deleteFromCache('reviews', $review);

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_delete'),
        ], 200);
    }

    public function multi_delete(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'reviews_id' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        $allReviewIds = $request->input('reviews_id', []);
        Review::whereIn('id', $allReviewIds)->delete();
        Cache::forget('reviews');

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_delete'),
        ]);
    }

    public function multi_hide(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'reviews_id' => 'required|array',
            'reviews_id.*' => 'integer|exists:reviews,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        $allReviewIds = $request->input('reviews_id', []);
        $reviews = Review::whereIn('id', $allReviewIds)->get();

        foreach ($reviews as $review) {
            $review->status = !$review->status;
            $review->save();
        }

        Cache::forget('reviews');

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_update'),
        ]);
    }

    public function review_excel(Request $request)
    {
        $filter = $request->filter ?? 'all';
        $search = $request->search;
        $score = $request->score;

        $reviews = getDataFromCache('reviews', function () {
            return Review::with(['student:id,name', 'program:id', 'program.translations'])->get();
        });

        // فرز حسب الفلتر الزمني أو الاسم
        if ($filter === 'latest') {
            $reviews = $reviews->sortByDesc('created_at')->values();
        } elseif ($filter === 'oldest') {
            $reviews = $reviews->sortBy('created_at')->values();
        } elseif ($filter === 'name') {
            $reviews = $reviews->sortBy(fn($review) => mb_strtolower($review->student->name))->values();
        }

        if ($search) {
            $reviews = $reviews->filter(function ($review) use ($search) {
                return mb_stripos($review->student->name, $search) !== false
                    || $review->program->translations->contains(function ($translation) use ($search) {
                        return mb_stripos($translation->title, $search) !== false;
                    });
            })->values();
        }


        if ($score) {
            $reviews = $reviews->where('score', $score)->values();
        }

        $currentDate = Carbon::now();
        // Create a new Spreadsheet object
        $spreadsheet = new Spreadsheet();

        // Get the active sheet
        $sheet = $spreadsheet->getActiveSheet();
        $columns = ['A', 'B', 'C', 'D', 'E', 'F'];
        foreach ($columns as $columnKey => $column):
            $Width = ($columnKey==0||$columnKey==10)? 25 : 15;
            $sheet->getColumnDimension($column)->setWidth($Width);
            $sheet->getStyle($column.'1')->applyFromArray([
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'color' => ['rgb' => 'FFFF00'], // Yellow background
                ],
            ]);
        endforeach;
        $sheet->setRightToLeft(true);

        // Set cell values
        $sheet->setCellValue('A1', __('trans.review.student_name'));
        $sheet->setCellValue('B1', __('trans.review.program_name'));
        $sheet->setCellValue('C1', __('trans.review.comment'));
        $sheet->setCellValue('D1', __('trans.review.reviews'));
        $sheet->setCellValue('E1', __('trans.review.status'));
        $sheet->setCellValue('F1', __('trans.review.created_at'));

        foreach ($reviews as $key => $review):
            $key = $key+2;
            if($review->score == 5){
                $score_value = __('trans.review.excellent');
                $status_value = __('trans.review.positive');
            } elseif($review->score == 4){
                $score_value = __('trans.review.good');
                $status_value = __('trans.review.positive');
            } elseif($review->score == 3){
                $score_value = __('trans.review.accepted');
                $status_value = __('trans.review.positive');
            } elseif($review->score == 2){
                $score_value = __('trans.review.neutral');
                $status_value = __('trans.review.negative');
            } elseif($review->score == 1){
                $score_value = __('trans.review.weak');
                $status_value = __('trans.review.negative');
            }

            $sheet->setCellValue('A'.$key, $review->student->name ?? '-');
            $sheet->setCellValue('B'.$key, $review->program->translations->firstWhere('locale', app()->getLocale())->title ?? '-');
            $sheet->setCellValue('C'.$key, $review->comment ?? '-');
            $sheet->setCellValue('D'.$key, $score_value ?? '-');
            $sheet->setCellValue('E'.$key, $status_value ?? '-');
            $sheet->setCellValue('F'.$key, Carbon::parse($review->created_at)->format('Y/m/d - H:i') ?? '-');
        endforeach;

        $writer = new Xlsx($spreadsheet);
        $today = date('Y-m-d');
        $fileName = __('trans.review.title')."- $today".".xlsx";
        $writer->save($fileName);
        return response()->download(public_path($fileName))->deleteFileAfterSend(true);
    }
}
