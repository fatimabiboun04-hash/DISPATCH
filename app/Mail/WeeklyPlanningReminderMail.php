<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WeeklyPlanningReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public int $weekNumber;
    public int $year;

    public function __construct(int $weekNumber, int $year)
    {
        $this->weekNumber = $weekNumber;
        $this->year = $year;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Weekly Planning Review - Week {$this->weekNumber}, {$this->year}",
        );
    }

    public function content(): Content
    {
        $startOfWeek = now()->setISODate($this->year, $this->weekNumber)->startOfWeek();
        $endOfWeek = $startOfWeek->copy()->endOfWeek();

        return new Content(
            view: 'emails.planning.weekly-reminder',
            with: [
                'weekNumber' => $this->weekNumber,
                'year' => $this->year,
                'weekRange' => $startOfWeek->format('M d') . ' - ' . $endOfWeek->format('M d, Y'),
            ],
        );
    }
}