<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewRegistrationEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $user;

    public function __construct($user)
    {
        $this->user = $user;
    }

    public function broadcastOn()
    {
        return new Channel('new-registration-channel');
    }

    public function broadcastAs()
    {
        return 'new-registration-event';
    }

    public function broadcastWith()
    {
        $user_type = $this->user->type == 'student' ? 'الطالب' : 'الخبير';
        $data = [
            'message' => "🔔 تم تسجيل حساب جديد من {$user_type}: {$this->user->name}",
        ];
        return $data;
    }
}
