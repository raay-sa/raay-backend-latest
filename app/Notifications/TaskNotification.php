<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Models\Task;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaskNotification extends Notification
{
    use Queueable;

    public $entity;
    public $messageType;

    /**
     * Create a new notification instance.
     */
    public function __construct($entity, $messageType)
    {
        $this->entity = $entity;
        $this->messageType = $messageType;
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
        if ($this->entity instanceof Task)
        {
            if ($this->messageType === 'delivered') { // تسليم
                return (new MailMessage)
                    ->line('A task has been delivered.')
                    ->action('View Task', url('/tasks/' . $this->entity->id))
                    ->line('Thank you for your attention!');
            }
            elseif ($this->messageType === 'sub_task') { // تعديل
                return (new MailMessage)
                    ->line('A new subtask has been assigned for you.')
                    ->action('View Task', url('/tasks/' . $this->entity->id))
                    ->line('Please check the subtask details.');
            }
            elseif ($this->messageType === 'task_rejected') {
                return (new MailMessage)
                    ->line('A task has been rejected.')
                    ->action('View Task', url('/tasks/' . $this->entity->id))
                    ->line('Please check the subtask details.');
            }
            elseif ($this->messageType === 'new_request') {
                return (new MailMessage)
                ->line('A request has been assigned to you.')
                ->action('View Task', url('/tasks/' . $this->entity->id))
                ->line('Thank you for your attention!');
            }
            elseif ($this->messageType === 'request_accepted') {
                return (new MailMessage)
                ->line('Your request has been accepted.')
                ->action('View Task', url('/tasks/' . $this->entity->id))
                ->line('Thank you for your attention!');
            }
            elseif ($this->messageType === 'request_rejected') {
                return (new MailMessage)
                    ->line('Your request has been rejected.')
                    ->action('View Task', url('/tasks/' . $this->entity->id))
                    ->line('Thank you for your attention!');
            }
            else{ // new task
                return (new MailMessage)
                    ->line('A new task has been assigned to you.')
                    ->action('View Task', url('/tasks/' . $this->entity->id))
                    ->line('Thank you for your attention!');
            }
        }

        // Default message or handle other cases
        return (new MailMessage)
            ->line('You have a new notification.')
            ->action('View Notification', url('/'))
            ->line('Thank you for joining our team!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
