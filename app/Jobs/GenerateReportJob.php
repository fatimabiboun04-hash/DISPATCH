<?php

namespace App\Jobs;

use App\Mail\ReportCompletedMail;
use App\Models\Report;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class GenerateReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Report $report;
    public int $tries = 3;
    public int $timeout = 300;

    public function __construct(Report $report)
    {
        $this->report = $report;
    }

    public function handle(): void
    {
        try {
            $generator = new \App\Services\ReportGeneratorService();
            $filePath = $generator->generate($this->report);

            $this->report->update([
                'file_path' => $filePath,
                'status' => 'completed',
            ]);

            // Notify requester that report is ready
            $requester = $this->report->generator;
            if ($requester && $requester->email) {
                Mail::to($requester->email)->queue(
                    new ReportCompletedMail($this->report)
                );
            }

        } catch (\Exception $e) {
            $this->report->update(['status' => 'failed']);
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->report->update(['status' => 'failed']);
    }
}
