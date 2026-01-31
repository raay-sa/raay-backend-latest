<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\Transaction;
use PDF; // Facade alias for DomPDF

class PaymentSuccessNotification extends Notification
{
    use Queueable;

    public $transaction;

    /**
     * Create a new notification instance.
     */
    public function __construct(Transaction $transaction)
    {
        $this->transaction = $transaction;
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
        // Load transaction with all necessary relationships for invoice
        $transaction = Transaction::with([
            'programs:id,price,category_id',
            'programs.translations',
            'programs.category.translations',
            'student:id,name,phone'
        ])->find($this->transaction->id);

        // Generate PDF invoice
        $pdf = PDF::loadView('invoice', ['data' => $transaction], [], [
            'format' => 'A4',
            'defaultFont' => 'Cairo',
            'isRemoteEnabled' => true,
            'isHtml5ParserEnabled' => true,
            'isPhpEnabled' => true,
            'isFontSubsettingEnabled' => true,
        ]);

        $pdfContent = $pdf->output();
        $filename = 'invoice-' . $transaction->id . '-' . now()->format('Y-m-d-His') . '.pdf';

        // Calculate tax breakdown
        $totalPrice = (float) ($transaction->total_price ?? 0);
        $taxBreakdown = getTaxBreakdown($totalPrice);

        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
        $transactionUrl = $frontendUrl . '/student/invoices/' . $transaction->id;

        return (new MailMessage)
            ->subject('Payment Successful - Invoice #' . $transaction->id)
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('Your payment has been processed successfully.')
            ->line('Transaction Details:')
            ->line('Transaction ID: #' . $transaction->id)
            ->line('Total Amount: ' . number_format($totalPrice, 2) . ' ' . ($transaction->currency ?? 'SAR'))
            ->line('Subtotal: ' . number_format($taxBreakdown['subtotal'], 2) . ' ' . ($transaction->currency ?? 'SAR'))
            ->line('VAT (15%): ' . number_format($taxBreakdown['tax_amount'], 2) . ' ' . ($transaction->currency ?? 'SAR'))
            ->line('Payment Date: ' . $transaction->created_at->format('d/m/Y h:i A'))
            ->action('View Transaction', $transactionUrl)
            ->line('Please find your invoice attached to this email.')
            ->line('Thank you for your purchase!')
            ->attachData($pdfContent, $filename, [
                'mime' => 'application/pdf',
            ]);
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'transaction_id' => $this->transaction->id,
            'type' => 'payment_success',
            'amount' => $this->transaction->total_price,
            'currency' => $this->transaction->currency ?? 'SAR',
        ];
    }
}

