<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class TestNotification extends Notification
{
    protected $row;
    protected $messageType;

    public function __construct($row, $messageType)
    {
        $this->row = $row;
        $this->messageType = $messageType;
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        $subject = '';
        $line1 = '';

        if ($this->messageType == 'create') {
            $subject = 'تم إنشاء عنصر جديد';
            $line1 = 'تم إنشاء التصنيف: ' . $this->row->title;
        } elseif ($this->messageType == 'update') {
            $subject = 'تم تعديل العنصر';
            $line1 = 'تم تعديل التصنيف: ' . $this->row->title;
        }

        return (new MailMessage)
            ->subject($subject)
            ->greeting('') // هنا بيشيل Hello!
            ->line($line1)
            ->line('بتاريخ: ' . $this->row->created_at->format('Y-m-d H:i'))
            ->action('عرض التصنيف', url('/categories/' . $this->row->id))
            ->line('شكراً لاستخدامك تطبيقنا!');
            // ->salutation('مع تحيات فريق عمل راي'); // لإزالة Regards, Laravel
    }
}
