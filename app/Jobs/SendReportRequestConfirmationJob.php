<?php

namespace App\Jobs;

use App\Mail\ReportRequestConfirmationMail;
use App\Models\Report;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendReportRequestConfirmationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Report $report;

    public function __construct(Report $report)
    {
        $this->report = $report;
    }

    public function handle(): void
    {
        $requester = $this->report->generator;

        if ($requester && $requester->email) {
            Mail::to($requester->email)->queue(
                new ReportRequestConfirmationMail($this->report)
            );
        }
    }
}
