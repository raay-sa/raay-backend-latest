<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ExamSolutionEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $exam;
    public $student;
    public $recipientId;

    public function __construct($exam, $student, $recipientId)
    {
        $this->exam = $exam;
        $this->student = $student;
        $this->recipientId = $recipientId;
    }

    public function broadcastOn()
    {
        return new PrivateChannel('exam-solution-channel-teacher-' . $this->recipientId);
    }

    public function broadcastAs()
    {
        return 'exam-solution-event';
    }

    public function broadcastWith()
    {
        $data = [
            'message' => '📥 تم تسليم الاختبار للطالب: ' . $this->student->name,
        ];
        return $data;
    }
}
