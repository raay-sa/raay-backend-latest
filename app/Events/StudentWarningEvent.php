<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StudentWarningEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $notification;

    public function __construct($notification)
    {
        $this->notification = $notification;
    }

    public function broadcastOn()
    {
        return new PrivateChannel("warning-channel-student-{$this->notification->student_id}");
    }

    public function broadcastAs()
    {
        return 'warning-event';
    }


    public function broadcastWith()
    {
        if($this->notification->type == 'ban'){
            $message = '🚫 ' . __('trans.global.ban') . ': ' . $this->notification->body;
        } elseif($this->notification->type == 'warning'){
            $message = '⚠️ ' . __('trans.global.warning') . ': ' . $this->notification->body;
        }else{
            $message = '🔔 ' . __('trans.global.alert') . ': ' . $this->notification->body;
        }

        $data = [
            'message' => $message
        ];

        return $data;
    }
}
