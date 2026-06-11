<?php

namespace App\Mail;

use App\Models\BrandInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BrandInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public BrandInvitation $invitation) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Você foi convidado para a marca {$this->invitation->brand->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.brand-invitation',
            with: [
                'invitation' => $this->invitation,
                'acceptUrl' => $this->invitation->acceptUrl(),
            ],
        );
    }
}
