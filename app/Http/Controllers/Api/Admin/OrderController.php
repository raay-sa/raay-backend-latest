<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $filter = $request->filter ?? 'all';
        $search = $request->search;
        $price_from = $request->price_from;
        $price_to = $request->price_to;
        $payment_method = $request->payment_method;


        $orders = Transaction::with(['programs:id', 'programs.translations', 'student:id,name'])->orderBy('created_at', 'desc')
        ->select(['id', 'student_id', 'total_price', 'payment_brand', 'status', 'created_at'])->get();
        foreach ($orders as $order) {
            $order->setAttribute('programs_count', $order->programs()->count());
            $order->programs->makeHidden(['pivot', 'category', 'teacher']);
        }


        $total_orders = $orders->count();
        $completed_ordered = $orders->where('status', 'completed')->count();
        $failed_ordered = $orders->where('status', 'failed')->count();
        $profit_percentage = Setting::value('profit_percentage');
        $total_profit = Subscription::sum('price') * ($profit_percentage / 100);

        // كل الاوردرات اللي في الشهر الحالي والشهر السابق
        $ordersThisMonth = Transaction::whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->get();

        $ordersLastMonth = Transaction::whereMonth('created_at', now()->subMonth()->month)
            ->whereYear('created_at', now()->subMonth()->year)
            ->get();

        // القيم الحالية
        $current_order = $ordersThisMonth->count();
        $current_completed_order = $ordersThisMonth->where('status', 'completed')->count();
        $current_failed_orders = $ordersThisMonth->where('status', 'failed')->count();
        $current_profit = Subscription::whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('price') * ($profit_percentage / 100);

        // القيم السابقة
        $previous_order = $ordersLastMonth->count();
        $previous_completed_order = $ordersLastMonth->where('status', 'completed')->count();
        $previous_failed_orders = $ordersLastMonth->where('status', 'failed')->count();
        $previous_profit = Subscription::whereMonth('created_at', now()->subMonth()->month)
            ->whereYear('created_at', now()->subMonth()->year)
            ->sum('price') * ($profit_percentage / 100);

        //  لحساب النسبة
        $calcChange = fn($current, $previous) => $previous > 0
            ? round((($current - $previous) / $previous) * 100, 2)
            : 100;

        $order_percentage_change = $calcChange($current_order, $previous_order);
        $completed_order_percentage_change = $calcChange($current_completed_order, $previous_completed_order);
        $failed_order_percentage_change = $calcChange($current_failed_orders, $previous_failed_orders);
        $profit_percentage_change = $calcChange($current_profit, $previous_profit);


        if ($filter === 'latest') {
            $orders = $orders->sortByDesc('created_at')->values();
        } elseif ($filter === 'oldest') {
            $orders = $orders->sortBy('created_at')->values();
        } elseif ($filter === 'name') {
            $orders = $orders->sortBy(fn($order) => mb_strtolower($order->student->name))->values();
        } elseif ($filter === 'completed_status') {
            $orders = $orders->where(fn($order) => $order->status == 'completed')->values();
        } elseif ($filter === 'failed_status') {
            $orders = $orders->where(fn($order) => $order->status == 'failed')->values();
        } elseif ($filter === 'pending_status') {
            $orders = $orders->where(fn($order) => $order->status == 'pending')->values();
        } elseif ($filter === 'cancelled_status') {
            $orders = $orders->where(fn($order) => $order->status == 'cancelled')->values();
        }

        $locale = $request->lang ?? App()->getLocale();

        if ($search) {
            $orders = $orders->filter(function ($order) use ($search, $locale) {
                $studentName = $order->student?->name ?? '';
                // $programTitles = $order->programs->pluck('title')->implode(' ');
                $programTitles = $order->programs->map(function ($program) use ($locale) {
                    return optional($program->translations->firstWhere('locale', $locale))->title;
                })->filter()->implode(', ');

                return mb_stripos($studentName, $search) !== false ||
                       mb_stripos($programTitles, $search) !== false;
            })->values();
        }

        if ($price_from !== null || $price_to !== null) {
            $orders = $orders->filter(function ($order) use ($price_from, $price_to) {
                $price = $order->total_price;

                if ($price_from !== null && $price < $price_from) {
                    return false;
                }

                if ($price_to !== null && $price > $price_to) {
                    return false;
                }

                return true;
            })->values();
        }

        if ($payment_method) {
            $orders = $orders->filter(function ($order) use ($payment_method) {
                return mb_strtolower($order->payment_brand) === mb_strtolower($payment_method);
            })->values();
        }

        $perPage = (int) $request->input('per_page', 10);
        $currentPage = (int) $request->input('page', 1);
        $paginated = paginationData($orders, $perPage, $currentPage);

        return response()->json([
            'success' => true,
            'total_orders' => $total_orders,
            'total_order_percentage' => abs($order_percentage_change),
            'total_order_status' => $order_percentage_change >= 0 ? 'increase' : 'decrease',

            'completed_ordered' => $completed_ordered,
            'completed_order_percentage' => abs($completed_order_percentage_change),
            'completed_order_status' => $completed_order_percentage_change >= 0 ? 'increase' : 'decrease',

            'failed_ordered' => $failed_ordered,
            'failed_order_percentage' => abs($failed_order_percentage_change),
            'failed_order_status' => $failed_order_percentage_change >= 0 ? 'increase' : 'decrease',

            'total_profit' => $total_profit,
            'profit_percentage' => abs($profit_percentage_change),
            'profit_status' => $profit_percentage_change >= 0 ? 'increase' : 'decrease',

            'data' => $paginated
        ]);
    }

    // ================================================
    public function order_excel(Request $request)
    {
        $filter = $request->filter ?? 'all';
        $search = $request->search;
        $price_from = $request->price_from;
        $price_to = $request->price_to;
        $payment_method = $request->payment_method;

        $total_orders = Transaction::count();
        $completed_ordered = Transaction::where('status', 'completed')->count();
        $failed_ordered = Transaction::where('status', 'failed')->count();
        $profit_percentage = Setting::value('profit_percentage');
        $total_profit = Program::sum('price') * ($profit_percentage / 100);

        $orders = Transaction::with(['programs:id', 'programs.translations', 'student:id,name'])->orderBy('created_at', 'desc')
        ->select(['id', 'student_id', 'total_price', 'payment_brand', 'status', 'created_at'])->get();
        foreach ($orders as $order) {
            $order->setAttribute('programs_count', $order->programs()->count());
            $order->programs->makeHidden(['pivot', 'category', 'teacher']);
        }

        if ($filter === 'latest') {
            $orders = $orders->sortByDesc('created_at')->values();
        } elseif ($filter === 'oldest') {
            $orders = $orders->sortBy('created_at')->values();
        } elseif ($filter === 'name') {
            $orders = $orders->sortBy(fn($order) => mb_strtolower($order->student->name))->values();
        } elseif ($filter === 'completed_status') {
            $orders = $orders->where(fn($order) => $order->status == 'completed')->values();
        } elseif ($filter === 'failed_status') {
            $orders = $orders->where(fn($order) => $order->status == 'failed')->values();
        } elseif ($filter === 'pending_status') {
            $orders = $orders->where(fn($order) => $order->status == 'pending')->values();
        } elseif ($filter === 'cancelled_status') {
            $orders = $orders->where(fn($order) => $order->status == 'cancelled')->values();
        }

        if ($search) {
            $orders = $orders->filter(function ($order) use ($search) {
                $studentName = $order->student?->name ?? '';
                // $programTitles = $order->programs->pluck('title')->implode(' ');
                $programTitles = $order->programs->map(function ($program){
                    return optional($program->translations->firstWhere('locale', app()->getLocale()))->title;
                })->filter()->implode(', ');

                return mb_stripos($studentName, $search) !== false ||
                       mb_stripos($programTitles, $search) !== false;
            })->values();
        }

        if ($price_from !== null || $price_to !== null) {
            $orders = $orders->filter(function ($order) use ($price_from, $price_to) {
                $price = $order->total_price;

                if ($price_from !== null && $price < $price_from) {
                    return false;
                }

                if ($price_to !== null && $price > $price_to) {
                    return false;
                }

                return true;
            })->values();
        }

        if ($payment_method) {
            $orders = $orders->filter(function ($order) use ($payment_method) {
                return mb_strtolower($order->payment_brand) === mb_strtolower($payment_method);
            })->values();
        }


        // Create a new Spreadsheet object
        $spreadsheet = new Spreadsheet();

        // Get the active sheet
        $sheet = $spreadsheet->getActiveSheet();
        $columns = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
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
        $sheet->setCellValue('A1', __('trans.order.name'));
        $sheet->setCellValue('B1', __('trans.order.student_name'));
        $sheet->setCellValue('C1', __('trans.order.program'));
        $sheet->setCellValue('D1', __('trans.order.programs_count'));
        $sheet->setCellValue('E1', __('trans.order.total_price'));
        $sheet->setCellValue('F1', __('trans.order.payment_method'));
        $sheet->setCellValue('G1', __('trans.order.status'));
        $sheet->setCellValue('H1', __('trans.order.created_at'));

        foreach ($orders as $key => $order):
            $key = $key+2;
            // $programs = $order->programs->pluck('title')->implode(', ');
            $programs = $order->programs->map(function ($program){
                return optional($program->translations->firstWhere('locale', app()->getLocale()))->title;
            })->filter()->implode(', ');

            $programs_count = $order->programs->count();

            if($order->status == 'completed') {
                $status_msg = __('trans.order.completed');
            } elseif($order->status == 'failed') {
                $status_msg = __('trans.order.failed');
            } elseif($order->status == 'pending') {
                $status_msg = __('trans.order.pending');
            } elseif($order->status == 'cancelled') {
                $status_msg = __('trans.order.cancelled');
            }

            $sheet->setCellValue('A'.$key, __('trans.order.name').'-'.$order->id ?? '-');
            $sheet->setCellValue('B'.$key, $order->student->name ?? '-');
            $sheet->setCellValue('C'.$key, $programs ?: '-');
            $sheet->setCellValue('D'.$key, $programs_count ?? '-');
            $sheet->setCellValue('E'.$key, $order->total_price ?? '-');
            $sheet->setCellValue('F'.$key, $order->payment_brand ?? '-');
            $sheet->setCellValue('G'.$key, $status_msg ?? '-');
            $sheet->setCellValue('H'.$key, Carbon::parse($order->created_at)->format('Y/m/d - H:i') ?? '-');
        endforeach;

        $writer = new Xlsx($spreadsheet);
        $today = date('Y-m-d');
        $fileName = __('trans.order.title')."- $today".".xlsx";
        $writer->save($fileName);
        return response()->download(public_path($fileName))->deleteFileAfterSend(true);
    }
}
