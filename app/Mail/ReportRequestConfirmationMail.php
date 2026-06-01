<?php

namespace App\Mail;

use App\Models\Report;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ReportRequestConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public Report $report;

    public function __construct(Report $report)
    {
        $this->report = $report;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Report Request Received - #' . $this->report->id,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.report.request-confirmation',
            with: [
                'report' => $this->report,
                'type' => ucfirst($this->report->type),
                'dateRange' => $this->report->start_date->format('M d, Y') . ' - ' . $this->report->end_date->format('M d, Y'),
                'fileType' => strtoupper($this->report->file_type),
            ],
        );
    }
}