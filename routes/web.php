<?php

use App\Http\Controllers\Api\Student\CertificateController;
use App\Http\Controllers\LiveController;
use App\Http\Controllers\UploadController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;


Route::get('/', function() {
    return view('welcome');
});

Route::get('/run-artisan', function () {
    Artisan::call('config:clear');
    Artisan::call('cache:clear');
    Artisan::call('route:clear');
    Artisan::call('view:clear');
    Artisan::call('optimize:clear');
    Artisan::call('migrate', [
        '--force' => true,
    ]);
    return 'Migrations and cache cleared successfully!';
});


// upload video
Route::get('/video', [UploadController::class, 'index']);
Route::post('/upload', [UploadController::class, 'store'])->name('upload');

// live stream by ant media
Route::get('/live-teacher', [LiveController::class, 'index']);
Route::get('/live-student', [LiveController::class, 'live_student']);
Route::post('/fire-event', [LiveController::class, 'sendEvent']);

Route::post('/create-stream', [LiveController::class, 'createStream']);
Route::delete('/delete-stream/{id}', [LiveController::class, 'deleteStream']);

Route::get('/get-stream/program/{program_id}', [LiveController::class, 'getStream']);
Route::post('/viewer/join/{programId}', [LiveController::class, 'join']);
Route::post('/viewer/leave/{programId}', [LiveController::class, 'leave']);


Route::get('/send-cert/{program_id}/{student_id}', [CertificateController::class, 'store'])->name('send-cert');

// Route::post('/send-msg', function() {
//     $content = request()->content;
//     event(new \App\Events\TestEvent($content));

//     return view('welcome');
// })->name('send-msg');


Route::post('/send-msg', function() {
    $content = request()->content;
    event(new \App\Events\TestEvent($content));

    return response()->json(['status' => 'sent']);
})->name('send-msg');



// http://127.0.0.1:8000/test-mail

use Illuminate\Support\Facades\Mail;

Route::get('/test-mail', function () {
    Mail::raw('This is a test email from Laravel', function ($message) {
        $message->to('oa66494@gmail.com')
                ->subject('Laravel SMTP Test');
    });

    return 'Mail Sent!';
});

// use App\Mail\TestMail;
// use Illuminate\Support\Facades\Mail;

// Mail::to('receiver@example.com')->send(new TestMail());
