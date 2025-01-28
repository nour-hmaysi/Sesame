<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\Log; // Ensure to import the Address class
use Illuminate\Mail\Mailables\Attachment;

class CustomEmail extends Mailable
{
    use Queueable, SerializesModels;
    public $body;
    public $subcopy;
    public $subject;
    public $fromEmail;
    public $fromName;
    public $attachment;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($body,$fromEmail, $fromName, $attachments, $subcopy = null, $subject=null)
    {
        $this->body = $body;
        $this->subcopy = $subcopy;
        $this->subject = $subject;
        $this->fromEmail = $fromEmail;
        $this->fromName = $fromName;
        $this->attachment = $attachments;
    }

    /**
     * Get the message envelope.
     *
     * @return \Illuminate\Mail\Mailables\Envelope
     */

    public function envelope()
    {
        return new Envelope(
            subject: $this->subject,
        from: new Address($this->fromEmail, $this->fromName) // Set the from address correctly
        );
    }

    /**
     * Get the message content definition.
     *
     * @return \Illuminate\Mail\Mailables\Content
     */
    public function content()
    {
        return new Content(
            markdown: 'vendor.mail.html.custom-email', // Use the markdown email template
            with: [
        'body' => $this->body,
        'subcopy' => $this->subcopy,
    ]
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array
     */
    public function attachments()
    {
        return [
            Attachment::fromPath($this->attachment),
        ];
    }
    public function build()
    {
//        $email  = $this->markdown('vendor.mail.html.custom-email')
//            ->with([
//                'body' => $this->body,
//                'subcopy' => $this->subcopy,
//            ]);
//        if ($this->attachment) {
////            $email->attach($this->attachment);
//            $email->attach($this->attachment, [
//                'as' =>  'document.pdf',
//                'mime' => 'application/pdf',
//            ]);
//        }
////        if ($this->attachment && isset($this->attachment['path']) && is_readable($this->attachment['path'])) {
////            $email->attach($this->attachment['path'], [
////                'as' => $this->attachment['name'] ?? 'document.pdf',
////                'mime' => $this->attachment['mime'] ?? 'application/pdf',
////            ]);
////        } else {
////            Log::error('Attachment path is invalid or not readable: ' . json_encode($this->attachment));
////        }
//
//        return $email;
    }
}
