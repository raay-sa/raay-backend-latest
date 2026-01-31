<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CertificateEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $program;
    public $student;

    public function __construct($program, $student)
    {
        $this->program = $program;
        $this->student = $student;
    }

    public function broadcastOn()
    {
        return new PrivateChannel('certificate-channel-student-' . $this->student->id);
    }

    public function broadcastAs()
    {
        return 'certificate-event';
    }

    public function broadcastWith()
    {
        $data = [
            'message' => "🎉 تم إصدار شهادة على الدورة: {$this->program->title}",
        ];
        return $data;
    }
}
