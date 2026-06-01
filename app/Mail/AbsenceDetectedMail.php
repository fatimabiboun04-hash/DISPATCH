<?php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AbsenceDetectedMail extends Mailable
{
    use Queueable, SerializesModels;

    public array $absentee;

    public function __construct(array $absentee)
    {
        $this->absentee = $absentee;
    }

    public function build()
    {
        return $this->subject('Absence Alert - Employee Not Checked In')
            ->view('emails.absence_detected')
            ->with([
                'absentee' => $this->absentee,
            ]);
    }
}
