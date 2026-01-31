<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CourseAvailableNotification extends Notification
{
    use Queueable;

    public $banner;
    public $program;
    public $emailSubject;
    public $emailBody;

    /**
     * Create a new notification instance.
     */
    public function __construct($banner, $program, $emailSubject, $emailBody)
    {
        $this->banner = $banner;
        $this->program = $program;
        $this->emailSubject = $emailSubject;
        $this->emailBody = $emailBody;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        // Get frontend URL 
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
        $courseUrl = $frontendUrl . '/student/courses/' . $this->program->id;

        return (new MailMessage)
                    ->subject($this->emailSubject)
                    ->greeting('Hello ' . $notifiable->name . '!')
                    ->line($this->emailBody)
                    ->line('Course: ' . $this->banner->title)
                    ->line('Program: ' . ($this->program ? $this->program->title : 'N/A'))
                    ->action('View Course', $courseUrl)
                    ->line('Thank you for your interest in our courses!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'banner_id' => $this->banner->id,
            'program_id' => $this->program ? $this->program->id : null,
            'type' => 'course_available',
            'title' => $this->emailSubject,
            'message' => $this->emailBody
        ];
    }
}
