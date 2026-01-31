<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ReviewEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $review;
    public $user;
    public $recipientType; // توع الشخص الي هيوصله الإشعار
    public $recipientId;

    public function __construct($review, $user, $recipientType, $recipientId)
    {
        $this->review = $review;
        $this->user = $user;
        $this->recipientType = $recipientType;
        $this->recipientId = $recipientId;
    }

    public function broadcastOn()
    {
        if ($this->recipientType === 'admin') {
            return new PrivateChannel("review-channel-admin");
        }

        return new PrivateChannel("review-channel-teacher-{$this->recipientId}");
    }

    public function broadcastAs()
    {
        return 'review-event';
    }

    public function broadcastWith()
    {
        $data = [
            'message' => '🔔 تم تسليم تقييم من الطالب: ' . $this->user->name,
            // 'review' => $this->review,
            // 'student' => $this->user,
        ];
        return $data;
    }
}
