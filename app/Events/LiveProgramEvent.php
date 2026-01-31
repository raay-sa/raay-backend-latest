<?php
namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LiveProgramEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $program;
    public $recipientType; // student / teacher
    public $recipientId;


    public function __construct($program, $recipientType, $recipientId)
    {
        $this->program = $program;
        $this->recipientType = $recipientType;
        $this->recipientId = $recipientId;
    }

    public function broadcastOn()
    {
        return new PrivateChannel("live-program-{$this->recipientType}-{$this->recipientId}");

        // return new Channel('live-program-channel');
    }

    public function broadcastAs()
    {
        return 'live-program-event'; // Ensure this matches the event name in your frontend
    }

    public function broadcastWith()
    {
        return [
            'message' => "🔔 تم بدأ بث الدورة: {$this->program->title}",
        ];
    }
}
