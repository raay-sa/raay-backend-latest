<?php

//

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;

function paginationData($data, $perPage = 10, $currentPage = 1)
{
    $total = $data->count();
    $results = $data->slice(($currentPage - 1) * $perPage, $perPage)->values();
    $paginated = new LengthAwarePaginator(
        $results,
        $total,
        $perPage,
        $currentPage,
        ['path' => url()->current()]
    );
    return $paginated;
}

function getDataFromCache($key, $modelOrCallback)
{
    $data = collect(Cache::get($key) ?? []);

    if ($data->isEmpty()) {
        // لو الاستدعاء تم باستخدام كولباك مخصص
        if (is_callable($modelOrCallback)) {
            $data = $modelOrCallback();
        } else {
            $instance = new $modelOrCallback;

            $relations = method_exists($instance, 'getWith') ? $instance->getWith() : [];

            $data = $modelOrCallback::with($relations)->get();
        }

        Cache::forever($key, $data);
    }

    return $data->sortByDesc('id')->values();
}


function storeInCache($key, $data, $relations = [])
{
    $cache = collect(Cache::get($key) ?? []);

    if (!empty($relations)) {
        $data = $data->load($relations);
    }

    $cache->prepend($data);

    // ترتيب بعد الإضافة
    $cache = $cache->sortByDesc('id')->values();

    Cache::forever($key, $cache);
}


function updateInCache($key, $data)
{
    // $cachedData = Cache::get($key, collect());
    $cachedData = collect(Cache::get($key) ?? []);


    // Check if the item exists in the collection
    $itemExists = $cachedData->contains(function ($cachedItem) use ($data) {
        return $cachedItem->id === $data->id;
    });

    if ($itemExists) {
        // Update existing item
        $cachedData = $cachedData->map(function ($cachedItem) use ($data) {
            if ($cachedItem->id === $data->id) {
                return $data; // Update the specific row in the collection
            }
            return $cachedItem; // Return all other rows unchanged
        });
    } else {
        // Add new item to the collection
        $cachedData->push($data);
    }

    // Save updated collection back to cache
    Cache::forever($key, $cachedData);
}


function deleteFromCache($key, $data)
{
    $cachedData = collect(Cache::get($key) ?? []);

    $cachedData = $cachedData->reject(function ($cachedItem) use ($data) {
        return $cachedItem->id === $data->id;
    });

    Cache::forever($key, $cachedData->values());
}


function getSingleDataFromCache($key, $model, $conditions = [])
{
    $cached = Cache::get($key);
    // نتحقق هل الكاش موجود وفيه بيانات
    if ($cached) {
        $collection = collect($cached);

        // إذا أعطينا شروط مثل ['phone' => ...] نبحث داخل الكولكشن
        foreach ($conditions as $field => $value) {
            $collection = $collection->where($field, $value);
        }

        return $collection->first();
    }

    // إذا لم يوجد في الكاش، نقرأ من قاعدة البيانات
    $instance = new $model;

    $relations = method_exists($instance, 'getWith') ? $instance->getWith() : [];

    $query = $model::with($relations);
    foreach ($conditions as $field => $value) {
        $query->where($field, $value);
    }

    $data = $query->first();

    // خزّن الكاش ككولكشن فقط إذا ما في شروط — لأننا نخزن مجموعة فقط إذا جلبنا الكل
    if (empty($conditions)) {
        $allData = $model::with($relations)->get();
        Cache::forever($key, $allData);
    }

    return $data;
}


// دالة مساعدة لتنسيق المدة الزمنية
function formatDuration($seconds)
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

 // دالة مساعدة لاستخدام FFmpeg مع الفيديوهات القصيرة
function getDurationWithFFmpeg($filePath)
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

function formatSizeUnits($bytes)
{
    if ($bytes >= 1073741824) {
        $bytes = number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        $bytes = number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        $bytes = number_format($bytes / 1024, 2) . ' KB';
    } elseif ($bytes > 1) {
        $bytes = $bytes . ' bytes';
    } elseif ($bytes == 1) {
        $bytes = $bytes . ' byte';
    } else {
        $bytes = '0 bytes';
    }

    return $bytes;
}

function parseExcel($file): array
{
    $spreadsheet = IOFactory::load($file);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray();

    // الهيدر (الأعمدة)
    $header = array_map('strtolower', $rows[0]);

    $file_data = [];

    // نبدأ من الصف 1 (بعد الهيدر) لحد آخر صف
    for ($i = 1; $i < count($rows); $i++) {
        $row = $rows[$i];
        if (empty(array_filter($row))) {
            continue; // تجاهل الصفوف الفاضية
        }

        $data = [];
        foreach ($header as $index => $column) {
            $value = isset($row[$index]) ? trim($row[$index]) : null;

            // صياغة التاريخ عشان لو اليوزر دخله بكذا شكل
            if (in_array($column, ['date_from', 'date_to']) && !empty($value)) {
                try {
                    if (is_numeric($value)) {
                        $date = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value);
                        $value = $date->format('Y-m-d');
                    } else {
                        $date = \Carbon\Carbon::createFromFormat('d/m/Y', $value);
                        $value = $date->format('Y-m-d');
                    }
                } catch (\Exception $e) {
                    $value = null;
                }
            }

            // صياغة الوقت
            if ($column === 'time' && !empty($value)) {
                try {
                    $time = \Carbon\Carbon::createFromFormat('H:i:s', $value);
                    $value = $time->format('H:i:s');
                } catch (\Exception $e) {
                    try {
                        $time = \Carbon\Carbon::createFromFormat('H:i', $value);
                        $value = $time->format('H:i:s');
                    } catch (\Exception $e2) {
                        $value = null;
                    }
                }
            }

            $data[$column] = $value;
        }

        $file_data[] = $data;
    }

    return $file_data;
}

/**
 * Calculate VAT amount from total price (tax is included in the price)
 * Saudi Arabia VAT Rate: 15%
 * Formula: VAT = Total × (15 / 115)
 */
function calculateVatFromTotal($totalWithTax)
{
    $vatRate = 0.15; // 15%
    return round($totalWithTax * ($vatRate / (1 + $vatRate)), 2);
}

/**
 * Calculate subtotal (price before tax) from total price
 */
function calculateSubtotalFromTotal($totalWithTax)
{
    $vatAmount = calculateVatFromTotal($totalWithTax);
    return round($totalWithTax - $vatAmount, 2);
}

/**
 * Get tax breakdown for a given total price
 * Returns array with subtotal, tax amount, and total
 */
function getTaxBreakdown($totalWithTax)
{
    $vatAmount = calculateVatFromTotal($totalWithTax);
    $subtotal = calculateSubtotalFromTotal($totalWithTax);
    
    return [
        'subtotal' => $subtotal,
        'tax_amount' => $vatAmount,
        'total' => (float) $totalWithTax,
        'tax_rate' => 15, // percentage
        'tax_rate_decimal' => 0.15
    ];
}
