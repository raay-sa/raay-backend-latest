<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AssignmentSolutionEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $assignment;
    public $student;
    public $recipientId;

    public function __construct($assignment, $student, $recipientId)
    {
        $this->assignment = $assignment;
        $this->student = $student;
        $this->recipientId = $recipientId;
    }

    public function broadcastOn()
    {
        return new PrivateChannel('assignment-solution-channel-teacher-' . $this->recipientId);
    }

    public function broadcastAs()
    {
        return 'assignment-solution-event';
    }

    public function broadcastWith()
    {
        $data = [
            'message' => "📥 تم تسليم مهمة من الطالب: {$this->student->name}",
        ];
        return $data;
    }
}
