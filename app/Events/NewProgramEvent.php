<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewProgramEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $program;
    public $teacher;

    public function __construct($program, $teacher)
    {
        $this->program = $program;
        $this->teacher = $teacher;
    }

    public function broadcastOn()
    {
        return new Channel('new-program-channel');
    }

    public function broadcastAs()
    {
        return 'new-program-event';
    }

    public function broadcastWith()
    {
        $data = [
            'message' => "🔔 تم إنشاء دورة جديدة: {$this->program->title} من خلال الخبير: {$this->teacher->name}",
        ];
        return $data;
    }
}
