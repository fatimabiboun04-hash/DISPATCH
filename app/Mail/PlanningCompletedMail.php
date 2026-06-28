<?php

namespace App\Mail;

use App\Models\Planning;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PlanningCompletedMail extends Mailable
{
    use Queueable, SerializesModels;

    public Planning $planning;

    public string $recipientType;

    public function __construct(Planning $planning, string $recipientType)
    {
        $this->planning = $planning;
        $this->recipientType = $recipientType;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->recipientType === 'admin'
                ? 'Planning Completed - Employee Assigned'
                : 'Your Planning Assignment is Ready',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.planning.completed',
            with: [
                'planning' => $this->planning,
                'recipientType' => $this->recipientType,
                'employee' => $this->planning->user,
                'shift' => $this->planning->shift,
                'team' => $this->planning->team,
                'date' => $this->planning->date->format('l, F j, Y'),
            ],
        );
    }
}
