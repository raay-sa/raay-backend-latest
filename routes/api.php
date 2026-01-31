<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Events\NewRegistrationEvent;

// Broadcasting authorization for Pusher private channels
Route::post('/broadcasting/auth', function (Request $request) {
    $user = $request->user();
    if (!$user) {
        return response()->json(['error' => 'Unauthorized'], 401);
    }

    $socketId = $request->input('socket_id');
    $channelName = $request->input('channel_name');

    if (!$socketId || !$channelName) {
        return response()->json(['error' => 'Missing socket_id or channel_name'], 400);
    }

    $pusherKey = env('PUSHER_APP_KEY');
    $pusherSecret = env('PUSHER_APP_SECRET');

    if (!$pusherKey || !$pusherSecret) {
        return response()->json(['error' => 'Pusher configuration missing'], 500);
    }

    $stringToSign = $socketId . ':' . $channelName;
    $auth = $pusherKey . ':' . hash_hmac('sha256', $stringToSign, $pusherSecret);

    return response()->json([
        'auth' => $auth
    ]);
})->middleware('auth:sanctum');

// moyasar payment callback
Route::get('/moyasar/success', 'Student\TransactionController@moyasar_success');
Route::get('/moyasar/cancel', 'Student\TransactionController@moyasar_cancel');
Route::post('/moyasar/webhook', 'Student\TransactionController@moyasar_webhook');
Route::post('/moyasar/status', 'Student\TransactionController@moyasar_status');

Route::get('test-event', function () {
    $user = (object)['name' => 'Test User', 'type' => 'student'];
    event(new NewRegistrationEvent($user));
    return 'fired';
});

Route::post('/login', 'Auth\AuthController@login');
Route::post('/refresh_token', 'Auth\AuthController@refreshToken');
Route::post('/register', 'Auth\AuthController@register');
Route::post('/sendOtp', 'Auth\AuthController@sendOtp');
Route::post('/verifyOtp', 'Auth\AuthController@verifyOtp');
Route::post('/verifyOtpRegister', 'Auth\AuthController@verifyOtpRegister');
Route::post('/forgotPassword', 'Auth\AuthController@forgotPassword');

// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');

Route::prefix('public')->group(function () {
    Route::get('/dashboard', 'Public\DashboardController@index');
    Route::resource('categories', 'Public\CategoryController');
    Route::resource('skills', 'Public\SkillsController');
    Route::get('/registered_programs', 'Public\ProgramController@registered_programs');

    // Program routes with optional authentication (to get user info for is_interested)
    Route::middleware('optional_auth')->group(function () {
        Route::get('/online_programs', 'Public\ProgramController@online_programs');
        Route::get('/onsite_programs', 'Public\ProgramController@onsite_programs');
        Route::get('/online_programs_3', 'Public\ProgramController@online_programs_3');
    });

    Route::get('/recent_programs', 'Public\ProgramController@recent_programs');
    Route::get('/programs/category/{id}', 'Public\ProgramController@category_programs');
    Route::resource('programs', 'Public\ProgramController');

    Route::post('/contact_us', 'Public\ContactController@store');
    Route::post('/consulting', 'Public\ConsultingController@store');
    Route::post('/workshops', 'Public\WorkshopController@store');
    Route::resource('company_request', 'Public\CompanyRequestController'); // الاستشارات
});

// Program interest routes
Route::prefix('public/programs')->middleware(['auth:sanctum', 'guard:student'])->group(function () {
    Route::post('/{id}/register-interest', 'Public\ProgramInterestController@registerInterest');
    Route::delete('/{id}/remove-interest', 'Public\ProgramInterestController@removeInterest');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', 'Auth\AuthController@logout');

    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // group for admin routes
    Route::prefix('admin')->middleware('guard:admin')->group(function () {
        Route::resource('profile', 'Admin\ProfileController');
        Route::get('paginated_categories', 'Admin\CategoryController@paginated_categories');
        Route::get('/categories/excel_sheet', 'Admin\CategoryController@excel_sheet_categories');
        Route::get('categories/download', 'Admin\CategoryController@category_excel');
        Route::post('categories/multi_delete', 'Admin\CategoryController@multi_delete');
        Route::resource('categories', 'Admin\CategoryController');

        Route::resource('managers', 'Admin\ManagerController'); // admin accounts

        Route::get('/trainees/excel_sheet', 'Admin\TraineeController@excel_sheet_trainees'); // shape will upload with it
        Route::get('/trainees/download', 'Admin\TraineeController@trainees_excel');
        Route::resource('trainees', 'Admin\TraineeController');

        Route::resource('skills', 'Admin\SkillsController');

        // Banner routes
        Route::resource('banners', 'Admin\BannerController');
        Route::get('banners/{id}/interested-students', 'Admin\BannerController@getInterestedStudents');
        Route::post('banners/{id}/link-to-program', 'Admin\BannerController@linkToProgram');
        Route::post('banners/{id}/test-notification', 'Admin\BannerController@sendTestNotification');
        Route::get('banners-statistics', 'Admin\BannerController@getStatistics');

        Route::resource('students_warning', 'Admin\StudentWarningController');

        Route::get('/program/list', 'Admin\ProgramController@list');
        Route::get('students_list', 'Admin\ProgramController@students_list');
        Route::get('teachers_list', 'Admin\ProgramController@teachers_list');
        Route::get('teacher/{id}/categories', 'Admin\ProgramController@teacher_categories');
        Route::get('programs/requests', 'Admin\ProgramController@requests');
        Route::post('programs/requests/approve', 'Admin\ProgramController@multi_approve');
        Route::get('/program/{id}/students', 'Admin\ProgramController@program_students');
        Route::get('/program/{id}/report', 'Admin\ProgramController@report');
        Route::get('/program/{id}/report_excel', 'Admin\ProgramController@report_excel');
        Route::get('/program/download', 'Admin\ProgramController@program_excel');
        Route::get('programs/{id}/sections', 'Admin\ProgramController@sections');
        Route::delete('programs/{id}/force', 'Admin\ProgramController@hard_delete');
        Route::post('programs/{id}/restore', 'Admin\ProgramController@restore');
        Route::resource('programs', 'Admin\ProgramController');

        Route::get('sections/{id}/sessions', 'Admin\ProgramSessionController@section_sessions');
        // Chunked upload that also CREATES the session after the final chunk
        Route::post('sessions/chunk', 'Admin\ProgramSessionController@upload');
        // Optional: upload attachments after the session is created
        Route::post('sessions/{session}/attachments', 'Admin\ProgramSessionController@uploadAttachments');
        Route::resource('sessions', 'Admin\ProgramSessionController');


        Route::get('program/{id}/assignments', 'Admin\AssignmentController@index');

        Route::get('orders/download', 'Admin\OrderController@order_excel');
        Route::resource('orders', 'Admin\OrderController');

        Route::post('reviews/multi_delete', 'Admin\ReviewController@multi_delete');
        Route::post('reviews/multi_hide', 'Admin\ReviewController@multi_hide');
        Route::get('reviews/download', 'Admin\ReviewController@review_excel');
        Route::resource('reviews', 'Admin\ReviewController');
        Route::resource('workshops', 'Admin\WorkshopController');

        Route::post('notifications/multi_delete', 'Admin\NotificationController@multi_delete');
        Route::resource('notifications', 'Admin\NotificationController');

        Route::post('common_questions/multi_delete', 'Admin\CommonQuestionController@multi_delete');
        Route::post('common_questions/multi_hide', 'Admin\CommonQuestionController@multi_hide');
        Route::resource('common_questions', 'Admin\CommonQuestionController');

        Route::get('consultants/download', 'Admin\ConsultantController@consultant_excel');
        Route::post('consultants/convert_to_teacher', 'Admin\ConsultantController@convert_to_teacher');
        Route::post('consultants/multi_delete', 'Admin\ConsultantController@multi_delete');
        Route::resource('consultants', 'Admin\ConsultantController'); // المستشارين

        Route::resource('consulting', 'Admin\ConsultingController'); // الاستشارات
        Route::resource('company_request', 'Admin\CompanyRequestController'); // الاستشارات
        Route::resource('contact_us', 'Admin\ContactController');
        Route::resource('support', 'Admin\SupportController');
        Route::resource('notifications_setting', 'Admin\NotificationSettingController');
        Route::resource('privacy_terms', 'Admin\PrivacyTermsController');
        Route::get('privacy_terms/{id}/pdf', 'Admin\PrivacyTermsController@show_pdf');
        Route::resource('settings', 'Admin\SettingController');


        Route::prefix('accounts')->group(function () {
            Route::resource('/', 'Admin\AccountManagementController');
            Route::get('/transaction/download', 'Admin\AccountManagementController@transaction_excel');

            Route::get('/student/excel_sheet', 'Admin\AccountManagementController@excel_sheet_students'); // shape will upload with it
            Route::get('/student/download', 'Admin\AccountManagementController@student_excel');
            Route::get('/student/{id}', 'Admin\AccountManagementController@show_student');
            Route::post('/student', 'Admin\AccountManagementController@create_student');
            Route::put('/student/{id}', 'Admin\AccountManagementController@update_student');
            Route::post('/student/multi_delete', 'Admin\AccountManagementController@delete_multi_student');
            Route::delete('/student/{id}', 'Admin\AccountManagementController@delete_student');

            Route::get('/teacher/excel_sheet', 'Admin\AccountManagementController@excel_sheet_teachers'); // shape will upload with it
            Route::get('/teacher/download', 'Admin\AccountManagementController@teacher_excel');
            Route::get('/teacher/{id}', 'Admin\AccountManagementController@show_teacher');
            Route::post('/teacher', 'Admin\AccountManagementController@create_teacher');
            Route::put('/teacher/{id}', 'Admin\AccountManagementController@update_teacher');
            Route::post('/teacher/multi_delete', 'Admin\AccountManagementController@delete_multi_teacher');
            Route::delete('/teacher/{id}', 'Admin\AccountManagementController@delete_teacher');
        });

        Route::prefix('register_request')->group(function () {
            Route::resource('/', 'Admin\RegisterRequestController');
            Route::get('/teacher/download', 'Admin\RegisterRequestController@teacher_excel');
            Route::put('/teacher/{id}', 'Admin\RegisterRequestController@update_teacher');
        });

        Route::prefix('evaluations')->group(function () {
            Route::get('forms/view', 'Admin\EvaluationFormController@view');
            Route::post('forms/send', 'Admin\EvaluationFormController@assignFormToProgram');
            Route::get('forms/download', 'Admin\EvaluationFormController@evaluation_form_excel');
            Route::post('forms/multi_delete', 'Admin\EvaluationFormController@delete_multi_response');
            Route::resource('forms', 'Admin\EvaluationFormController');
        });

        Route::prefix('dashboard')->group(function () {
            Route::resource('/', 'Admin\DashboardController');
            Route::get('/user_registration', 'Admin\DashboardController@userRegistration');
            Route::get('/profit_stats', 'Admin\DashboardController@profitStats');
            Route::get('/content_stats', 'Admin\DashboardController@contentStats');
            Route::get('/program_subscriptions', 'Admin\DashboardController@programSubscriptions');
            Route::get('/reviews', 'Admin\DashboardController@reviews');
            Route::get('/last_transactions', 'Admin\DashboardController@last_transactions');
        });

        Route::prefix('statistics')->group(function () {
            Route::get('/user_registration/excel', 'Admin\StatisticsController@userRegistration_excel');
            Route::get('/user_registration', 'Admin\StatisticsController@userRegistration');
            Route::get('/profit_stats/excel', 'Admin\StatisticsController@profitStats_excel');
            Route::get('/profit_stats', 'Admin\StatisticsController@profitStats');
            Route::get('/content_stats', 'Admin\StatisticsController@contentStats');
            Route::get('/program_subscriptions/excel', 'Admin\StatisticsController@programSubscriptions_excel');
            Route::get('/program_subscriptions', 'Admin\StatisticsController@programSubscriptions');
            Route::get('/reviews', 'Admin\StatisticsController@reviews');
            Route::get('/teachers_activity', 'Admin\StatisticsController@teachersActivity');
        });
    });

    Route::prefix('teacher')->middleware('guard:teacher')->group(function () {
        Route::resource('profile', 'Teacher\ProfileController');
        Route::resource('categories', 'Teacher\CategoryController');

        Route::get('programs/excel_sheet', 'Teacher\ProgramController@excel_sheet_programs');
        Route::get('programs/{id}/sections', 'Teacher\ProgramController@sections');
        Route::get('/program/{id}/students', 'Teacher\ProgramController@program_students');
        Route::get('/program/{id}/report', 'Teacher\ProgramController@report');
        Route::get('/program/{id}/report_excel', 'Teacher\ProgramController@report_excel');
        Route::get('programs/list', 'Teacher\ProgramController@list');
        Route::delete('programs/{id}/force', 'Teacher\ProgramController@hard_delete');
        Route::post('programs/{id}/restore', 'Teacher\ProgramController@restore');
        Route::get('programs/download', 'Teacher\ProgramController@programs_excel');
        Route::resource('programs', 'Teacher\ProgramController');

        Route::get('sections/{id}/sessions', 'Teacher\ProgramSessionController@section_sessions');
        // Chunked upload that also CREATES the session after the final chunk
        Route::post('sessions/chunk', 'Teacher\ProgramSessionController@upload');
        // Optional: upload attachments after the session is created
        Route::post('sessions/{session}/attachments', 'Teacher\ProgramSessionController@uploadAttachments');
        Route::resource('sessions', 'Teacher\ProgramSessionController');

        Route::resource('free_materials', 'Teacher\ProgramFreeMaterialController');

        Route::resource('assignments', 'Teacher\AssignmentController');
        Route::resource('assignment/solutions', 'Teacher\AssignmentSolutionController');

        Route::resource('session_discussions', 'Teacher\SessionDiscussionController');
        Route::get('program/{id}/discussions', 'Teacher\SessionDiscussionController@program_discussions');
        Route::get('session/{id}/discussions', 'Teacher\SessionDiscussionController@session_discussions');

        Route::resource('exams', 'Teacher\ExamController');
        Route::resource('exam_solutions', 'Teacher\ExamSolutionController');
        Route::get('program/{id}/exams', 'Teacher\ExamController@program_exams');

        Route::get('/trainees/excel_sheet', 'Teacher\TraineeController@excel_sheet_trainees'); // shape will upload with it
        Route::get('/trainees/download', 'Teacher\TraineeController@trainees_excel');
        Route::resource('trainees', 'Teacher\TraineeController');

        Route::get('students_list', 'Teacher\StudentController@students_list');
        Route::get('/students/download', 'Teacher\StudentController@students_excel');
        Route::resource('students', 'Teacher\StudentController');

        Route::post('notifications/mark_read', 'Teacher\NotificationController@mark_read');
        Route::resource('notifications', 'Teacher\NotificationController');

        Route::resource('notifications_setting', 'Teacher\NotificationSettingController');
        Route::resource('privacy_terms', 'Teacher\PrivacyTermsController');
        Route::resource('common_questions', 'Teacher\CommonQuestionController');
        Route::resource('support', 'Teacher\SupportController');
        Route::post('create_stream', 'Teacher\LiveProgramController@createStream');
        Route::post('online_program', 'Teacher\LiveProgramController@online');
        Route::post('offline_program', 'Teacher\LiveProgramController@offline');
        Route::delete('delete_stream/{stream_id}', 'Teacher\LiveProgramController@deleteStream');

        Route::prefix('dashboard')->group(function () {
            Route::get('/', 'Teacher\DashboardController@index');
            Route::get('/user_registration', 'Teacher\DashboardController@userRegistration');
            Route::get('/profit_stats', 'Teacher\DashboardController@profitStats');
            Route::get('/content_stats', 'Teacher\DashboardController@contentStats');
            Route::get('/program_interactions', 'Teacher\DashboardController@program_interactions');
            Route::get('/reviews', 'Teacher\DashboardController@reviews');
            Route::get('/last_transactions', 'Teacher\DashboardController@last_transactions');
        });

        Route::prefix('statistics')->group(function () {
            Route::get('/user_registration/excel', 'Teacher\StatisticsController@userRegistration_excel');
            Route::get('/user_registration', 'Teacher\StatisticsController@userRegistration');
            Route::get('/profit_stats/excel', 'Teacher\StatisticsController@profitStats_excel');
            Route::get('/profit_stats', 'Teacher\StatisticsController@profitStats');
            Route::get('/content_stats', 'Teacher\StatisticsController@contentStats');
            Route::get('/program_interactions', 'Teacher\StatisticsController@program_interactions');
            Route::get('/reviews', 'Teacher\StatisticsController@reviews');
            Route::get('/best_trainers/excel', 'Teacher\StatisticsController@best_trainers_excel');
            Route::get('/best_trainers', 'Teacher\StatisticsController@best_trainers');
            Route::get('/achievements/excel', 'Teacher\StatisticsController@achievement_excel');
            Route::get('/achievements', 'Teacher\StatisticsController@achievement');
        });
    });

    Route::prefix('student')->middleware('guard:student')->group(function () {
        Route::resource('profile', 'Student\ProfileController');
        Route::get('search_programs', 'Student\ProgramController@search_programs');
        Route::get('programs/list', 'Student\ProgramController@list');
        Route::get('programs/best_programs', 'Student\ProgramController@bestPrograms');
        Route::resource('programs', 'Student\ProgramController');

        // Banner routes
        Route::resource('banners', 'Student\BannerController');
        Route::post('banners/{id}/register-interest', 'Student\BannerController@registerInterest');
        Route::delete('banners/{id}/remove-interest', 'Student\BannerController@removeInterest');
        Route::get('banners/{id}/with-interest', 'Student\BannerController@getBannerWithInterest');
        Route::get('my-interests', 'Student\BannerController@myInterests');

        Route::resource('session_discussions', 'Student\SessionDiscussionController');
        Route::get('program/{id}/discussions', 'Student\SessionDiscussionController@program_discussions');
        Route::get('session/{id}/discussions', 'Student\SessionDiscussionController@session_discussions');
        Route::post('session/{id}/views', 'Student\ProgramController@session_views');

        Route::get('program/{id}/assignments', 'Student\AssignmentController@program_assignments');
        Route::get('teachers_list', 'Student\AssignmentController@teachers_list');
        Route::resource('assignments', 'Student\AssignmentController');
        Route::resource('assignment/solutions', 'Student\AssignmentSolutionController');

        Route::get('exam/{id}/solution', 'Student\ExamController@solution');
        Route::resource('exams', 'Student\ExamController');
        Route::get('program/{id}/exams', 'Student\ExamController@program_exams');

        // Route::post('transactions/checkout', 'Student\TransactionController@checkout');
        // Route::post('transactions/callback/{order}', 'Student\TransactionController@callback');
        Route::resource('transactions', 'Student\TransactionController');
        Route::get('transactions/{id}/invoice', 'Student\TransactionController@invoice');

        Route::get('evaluation/forms/program/{slug}', 'Student\EvaluationFormController@show');
        Route::resource('evaluation/forms', 'Student\EvaluationFormController');

        Route::get('certificate/program/{id}', 'Student\CertificateController@certificate_program');
        Route::resource('certificates', 'Student\CertificateController');

        Route::post('notifications/mark_read', 'Student\NotificationController@mark_read');
        Route::resource('notifications', 'Student\NotificationController');

        Route::resource('subscriptions', 'Student\SubscriptionController');
        Route::resource('cart', 'Student\CartController');
        Route::get('cart/can-purchase/{programId}', 'Student\CartController@canPurchase');
        Route::post('cart/{cartId}/purchase', 'Student\CartController@purchaseCourse');
        Route::resource('reviews', 'Student\ReviewController');
        Route::resource('support', 'Student\SupportController');
        Route::resource('favorites', 'Student\FavoriteController');
        Route::resource('notifications_setting', 'Student\NotificationSettingController');
        Route::resource('privacy_terms', 'Student\PrivacyTermsController');
        Route::resource('common_questions', 'Student\CommonQuestionController');
        Route::get('/get_stream/program/{program_id}', 'Student\LiveProgramController@getStream');

        Route::prefix('dashboard')->group(function () {
            Route::get('/', 'Student\DashboardController@index');
            Route::get('/student_progress', 'Student\DashboardController@student_progress');
            Route::get('/content_stats', 'Student\DashboardController@contentStats');
            Route::get('/important_data', 'Student\DashboardController@important_data');
            Route::get('/keep_watching', 'Student\DashboardController@keepWatching');
            Route::get('/recent_programs', 'Student\DashboardController@recentPrograms');
            Route::get('/best_programs', 'Student\DashboardController@bestPrograms');
        });
    });
});

Route::get('system-settings', 'Admin\SystemSettingController@index');
Route::post('system-settings', 'Admin\SystemSettingController@update_setting');
