<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GeneralNotificationEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $notification;
    public $recipientType; // student / teacher
    public $recipientId;

    public function __construct($notification, $recipientType, $recipientId)
    {
        $this->notification = $notification;
        $this->recipientType = $recipientType;
        $this->recipientId = $recipientId;
    }

    public function broadcastOn()
    {
        return new PrivateChannel("general-notification-{$this->recipientType}-{$this->recipientId}");
    }

    public function broadcastAs()
    {
        return 'general-notification-event';
    }

    public function broadcastWith()
    {
        $message = match ($this->notification->type) {
            'alert'  => "⚠️ {$this->notification->title}: {$this->notification->content}",
            'offer'  => "🎁 {$this->notification->title}: {$this->notification->content}",
            'notice' => "📢 {$this->notification->title}: {$this->notification->content}",
            default  => $this->notification->content,
        };

        $data = [
            'message' => $message,
            'notification' => $this->notification,
        ];

        return $data;
    }
}
