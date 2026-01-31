<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('review-channel-admin', function ($user) {
    return $user->type === 'admin';
});

Broadcast::channel('review-channel-teacher-{id}', function ($user, $id) {
    return $user->type === 'teacher' && (int) $user->id === (int) $id;
});

Broadcast::channel('general-notification-student-{id}', function ($user, $id) {
    return $user->type === 'student' && (int) $user->id === (int) $id;
});

Broadcast::channel('general-notification-teacher-{id}', function ($user, $id) {
    return $user->type === 'teacher' && (int) $user->id === (int) $id;
});

Broadcast::channel('live-program-student-{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('live-program-teacher-{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('assignment-solution-channel-teacher-{id}', function ($user, $id) {
    return (int) $user->id === (int) $id; // المدرس صاحب الـ id
});

Broadcast::channel('exam-solution-channel-teacher-{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('certificate-channel-student-{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('warning-channel-student-{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

