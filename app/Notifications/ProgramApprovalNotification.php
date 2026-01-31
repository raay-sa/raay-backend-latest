<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\Program;

class ProgramApprovalNotification extends Notification
{
    use Queueable;

    public $programs;

    /**
     * Create a new notification instance.
     */
    public function __construct($programs)
    {
        $this->programs = $programs;
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
        $programsCount = $this->programs->count();
        $programsList = $this->programs->map(function ($program) {
            $translation = $program->translation;
            return $translation ? $translation->title : 'Program #' . $program->id;
        })->implode(', ');

        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
        $programsUrl = $frontendUrl . '/teacher/programs';

        $subject = $programsCount === 1 
            ? 'Program Approved - Raay'
            : "{$programsCount} Programs Approved - Raay";

        $mailMessage = (new MailMessage)
            ->subject($subject)
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line($programsCount === 1 
                ? 'Great news! Your program has been approved by the admin.'
                : "Great news! Your {$programsCount} programs have been approved by the admin.");

        if ($programsCount === 1) {
            $program = $this->programs->first();
            $translation = $program->translation;
            $programTitle = $translation ? $translation->title : 'Program #' . $program->id;
            $mailMessage->line("Program: {$programTitle}");
        } else {
            $mailMessage->line("Approved Programs: {$programsList}");
        }

        return $mailMessage
            ->action('View Programs', $programsUrl)
            ->line('Thank you for being part of Raay!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'programs' => $this->programs->pluck('id')->toArray(),
            'type' => 'program_approval',
            'count' => $this->programs->count(),
        ];
    }
}

