<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\CategoryTranslation;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class CategoryController extends Controller
{
    public function index()
    {
        $categories = getDataFromCache('categories', function () {
            return Category::with('translations:locale,title,parent_id')->get();
        });

        return response()->json([
            'success' => true,
            'data' => $categories
        ]);
    }

    public function paginated_categories(Request $request)
    {
        $categories = Category::with('translations:locale,title,parent_id')->get();

        $perPage = (int) $request->input('per_page', 10);
        $currentPage = (int) $request->input('page', 1);
        $paginated = paginationData($categories, $perPage, $currentPage);

        return response()->json([
            'success' => true,
            'data' => $paginated
        ]);
    }

    public function excel_sheet_categories(Request $request)
    {
        // shape
        // Create a new Spreadsheet object
        $spreadsheet = new Spreadsheet();

        // Get the active sheet
        $sheet = $spreadsheet->getActiveSheet();
        $columns = ['A', 'B'];
        foreach ($columns as $columnKey => $column):
            $Width = ($columnKey==0||$columnKey==7||$columnKey==8||$columnKey==9||$columnKey==12)? 25 : 15;
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
        $sheet->setCellValue('A1', __('trans.excel_sheet.category').' ('.__('trans.excel_sheet.arabic').')');
        $sheet->setCellValue('B1', __('trans.excel_sheet.category').' ('.__('trans.excel_sheet.english').')');

        $sheet->setCellValue('A2', __('trans.excel_sheet.category').' ('.__('trans.excel_sheet.arabic').')');
        $sheet->setCellValue('B2', __('trans.excel_sheet.category').' ('.__('trans.excel_sheet.english').')');

        $writer = new Xlsx($spreadsheet);
        $today = date('Y-m-d');
        $fileName = "categories-excel-sheet.xlsx";
        $writer->save($fileName);
        return response()->download(public_path($fileName))->deleteFileAfterSend(true);
    }


    public function store(Request $request)
    {
        // الحالة ١: رفع ملف Excel
        if ($request->hasFile('file'))
        {
            $request->validate([
                'file' => 'required|file|mimes:xls,xlsx',
            ]);

            $importedRows = parseExcel($request->file('file'));
            $duplicates = [];
            $created    = [];

            foreach ($importedRows as $key => $row) {
                $row_number = $key + 2; // رقم الصف في الإكسل

                $title_ar = $row[__('trans.excel_sheet.category').' ('.__('trans.excel_sheet.arabic').')'] ?? null;
                $title_en = $row[__('trans.excel_sheet.category').' ('.__('trans.excel_sheet.english').')'] ?? null;

                if (!$title_ar || !$title_en) {
                    continue;
                }

                $isDuplicate = CategoryTranslation::where('title', $title_ar)
                    ->orWhere('title', $title_en)
                    ->exists();

                if ($isDuplicate) {
                    $duplicates[] = [
                        'ar' => $title_ar,
                        'en' => $title_en,
                    ];
                    continue;
                }

                $category = new Category();
                $category->save();

                $new = new CategoryTranslation();
                $new->locale = 'ar';
                $new->parent_id = $category->id;
                $new->title = $title_ar;
                $new->save();

                $new = new CategoryTranslation();
                $new->locale = 'en';
                $new->parent_id = $category->id;
                $new->title = $title_en;
                $new->save();

                $created[] = $category->load('translations');
            }

            return response()->json([
                'success' => true,
                'message' => count($created) > 0 ? __('trans.alert.success.done_create') : null,
                'created_count' => count($created),
                'failed_count' => count($duplicates),
                'reason' => count($duplicates) > 0 ? __('trans.alert.error.category_exists') : null,
                'faliled_categories_list' => $duplicates,
            ]);
        }

        $validator = Validator::make($request->all(), [
            'title'   => 'required|array',
            'title.*' => 'required|string|max:255|unique:category_translations,title',
            'image_ar'=> 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'image_en'=> 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        $row = new Category();
        $image_ar = null;
        $image_en = null;

        if ($request->hasFile('image_ar')) {
            $file = $request->file('image_ar');
            $fileName = 'image_ar_' . time() . '_' . uniqid() . '.' . $file->extension();
            $path = 'uploads/categories';

            $fullPath = public_path($path);
            if (!file_exists($fullPath)) {
                mkdir($fullPath, 0755, true);
            }
            $file->move($fullPath, $fileName);
            $image_ar = $path . '/' . $fileName;
        }

        if ($request->hasFile('image_en')) {
            $file = $request->file('image_en');
            $fileName = 'image_en_' . time() . '_' . uniqid() . '.' . $file->extension();
            $path = 'uploads/categories';

            $fullPath = public_path($path);
            if (!file_exists($fullPath)) {
                mkdir($fullPath, 0755, true);
            }
            $file->move($fullPath, $fileName);
            $image_en = $path . '/' . $fileName;
        }

        $row->image_ar = $image_ar;
        $row->image_en = $image_en;
        $row->save();

        foreach( $request->title as $key => $col ):
            $new = new CategoryTranslation();
            $new->locale      = $key;
            $new->parent_id   = $row->id;
            $new->title       = $request->title[$key];
            $new->save();
        endforeach;

        $row->load('translations');

        storeInCache('categories', $row);

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_create'),
            'data' => $row
        ]);
    }

    public function show($id)
    {
        $row = Category::with('translations')->findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        return response()->json([
            'success' => true,
            'row' => $row
        ]);
    }

    public function edit($id)
    {
        //
    }

    public function update(Request $request, $id)
    {
        $category = Category::findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        $rules = [
            'title' => 'required|array',
            'image_ar' => 'sometimes|nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'image_en' => 'sometimes|nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ];

        foreach ($request->title as $locale => $title) {
            $rules["title.$locale"] = [
                'string',
                'max:255',
                Rule::unique('category_translations', 'title')
                    ->where(function ($q) use ($locale, $id) {
                        return $q->where('locale', $locale);
                    })
                    ->ignore($id, 'parent_id'),
            ];
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($request->hasFile('image_ar')) {
            // remove old image
            if ($category->image_ar) {
                unlink(public_path($category->image_ar));
            }
            $image_ar = $request->file('image_ar');
            $imageName = 'image_ar_' . time() . '.' . $image_ar->getClientOriginalExtension();
            $path = 'uploads/categories';

            $fullPath = public_path($path);
            if (!file_exists($fullPath)) {
                mkdir($fullPath, 0755, true);
            }
            $image_ar->move($fullPath, $imageName);
            $category->image_ar = $path . '/' . $imageName;
        }

        if ($request->hasFile('image_en')) {
            // remove old image
            if ($category->image_en) {
                unlink(public_path($category->image_en));
            }
            $image_en = $request->file('image_en');
            $imageName = 'image_en_' . time() . '.' . $image_en->getClientOriginalExtension();
            $path = 'uploads/categories';

            $fullPath = public_path($path);
            if (!file_exists($fullPath)) {
                mkdir($fullPath, 0755, true);
            }
            $image_en->move($fullPath, $imageName);
            $category->image_en = $path . '/' . $imageName;
        }


        $category->save();

        CategoryTranslation::where('parent_id', $category->id)->delete();

        foreach( $request->title as $key => $col ):
            $new = new CategoryTranslation;
            $new->locale      = $key;
            $new->parent_id   = $category->id;
            $new->title       = $request->title[$key];
            $new->save();
        endforeach;

        $category->load('translations');
        // save in cache (helper method)
        updateInCache('categories', $category);

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_update'),
            'data' => $category
        ]);
    }

    public function destroy($id)
    {
        $row = Category::find($id);

        if(!$row) {
            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422);
        }

        if($row->image_ar) {
            unlink(public_path($row->image_ar));
        }

        if($row->image_en) {
            unlink(public_path($row->image_en));
        }

        // delete from cache (helper method)
        deleteFromCache('categories', $row);
        $row->delete();

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_delete'),
        ]);
    }

    public function multi_delete(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'categories_id' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        $allCategoryIds = $request->input('categories_id', []);
        // remove_images
        foreach ($allCategoryIds as $categoryId) {
            $category = Category::find($categoryId);
            if ($category && $category->image_ar) {
                unlink(public_path($category->image_ar));
            }

            if ($category && $category->image_en) {
                unlink(public_path($category->image_en));
            }
        }
        Category::whereIn('id', $allCategoryIds)->delete();
        Cache::forget('categories');

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_delete'),
        ]);
    }

    public function category_excel(Request $request)
    {
        $categories = getDataFromCache('categories', Category::class);

        // Create a new Spreadsheet object
        $spreadsheet = new Spreadsheet();

        // Get the active sheet
        $sheet = $spreadsheet->getActiveSheet();
        $columns = ['A', 'B'];
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
        $sheet->setCellValue('A1', __('trans.category.title'));
        $sheet->setCellValue('B1', __('trans.category.created_at'));

        foreach ($categories as $key => $category):
            $key = $key+2;

            $sheet->setCellValue('A'.$key, $category->category->title ?? '-');
            $sheet->setCellValue('B'.$key, Carbon::parse($category->created_at)->format('Y/m/d - H:i') ?? '-');
        endforeach;

        $writer = new Xlsx($spreadsheet);
        $today = date('Y-m-d');
        $fileName = __('trans.category.categories')."- $today".".xlsx";
        $writer->save($fileName);
        return response()->download(public_path($fileName))->deleteFileAfterSend(true);
    }
}


