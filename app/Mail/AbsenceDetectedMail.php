<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AbsenceDetectedMail extends Mailable
{
    use Queueable, SerializesModels;

    public array $absentee;

    public function __construct(array $absentee)
    {
        $this->absentee = $absentee;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Absence Alert - Employee Not Checked In',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.absence_detected',
            with: ['absentee' => $this->absentee],
        );
    }
}
