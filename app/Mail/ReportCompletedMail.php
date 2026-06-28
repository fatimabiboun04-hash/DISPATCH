<?php

namespace App\Mail;

use App\Models\Report;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ReportCompletedMail extends Mailable
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
            subject: 'Your Report is Ready - #'.$this->report->id,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.report.completed',
            with: [
                'report' => $this->report,
                'type' => ucfirst($this->report->type),
                'dateRange' => $this->report->start_date->format('M d, Y').' - '.$this->report->end_date->format('M d, Y'),
                'fileType' => strtoupper($this->report->file_type),
                'generatedAt' => $this->report->updated_at->format('M d, Y H:i'),
            ],
        );
    }

    public function attachments(): array
    {
        $path = \Illuminate\Support\Facades\Storage::path($this->report->file_path);

        if (file_exists($path)) {
            return [
                Attachment::fromPath($path)
                    ->as($this->report->type.'_report_'.$this->report->id.'.'.($this->report->file_type === 'excel' ? 'xlsx' : 'pdf'))
                    ->withMime($this->report->file_type === 'excel' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'application/pdf'),
            ];
        }

        return [];
    }
}
