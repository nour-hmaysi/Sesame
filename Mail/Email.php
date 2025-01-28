<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Queue\SerializesModels;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Contracts\Queue\ShouldQueue;

class Email extends Mailable
{
    use Queueable, SerializesModels;
    public $body;
    public $subcopy;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct( $name,  $attachedFile){
        $this->name = $name;
        $this->attachedFile = $attachedFile;
}

/**
 * Get the message envelope.
 *
 * @return \Illuminate\Mail\Mailables\Envelope
 */
public function envelope()
{
    return new Envelope(
        subject: 'My Test Email',
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
        markdown: 'vendor.mail.html.custom-email',
            with: ['name' => $this->name],
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
        Attachment::fromPath($this->attachedFile),
    ];

}
}
