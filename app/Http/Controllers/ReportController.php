<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateReportJob;
use App\Jobs\SendReportRequestConfirmationJob;
use App\Models\Report;
use App\Services\AuditService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ReportController extends Controller
{
    use ApiResponse;

    /**
     * List generated reports.
     */
    public function index(Request $request)
    {
        $reports = Report::with('generator')
            ->latest()
            ->paginate(15);

        return $this->paginatedResponse($reports);
    }

    /**
     * Request report generation (queued).
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'type' => 'required|in:weekly,monthly,custom',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'file_type' => 'required|in:pdf,excel',
        ]);

        // Extract ISO week number and year from start_date
        $startDate = \Carbon\Carbon::parse($validated['start_date']);
        $weekNumber = (int) $startDate->isoWeek();
        $year = (int) $startDate->isoWeekYear();

        $report = Report::create([
            'type' => $validated['type'],
            'week_number' => $weekNumber,
            'year' => $year,
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'file_type' => $validated['file_type'],
            'generated_by' => auth()->id(),
            'status' => 'queued',
        ]);

        GenerateReportJob::dispatch($report);
        SendReportRequestConfirmationJob::dispatch($report);

        AuditService::log('requested', Report::class, $report->id);

        return $this->successResponse($report, 'Report generation queued', 202);
    }

    /**
     * Show individual report metadata.
     */
    public function show(Report $report)
    {
        $report->load('generator');

        return $this->successResponse($report);
    }

    /**
     * Download generated report.
     */
    public function download(Report $report)
    {
        if ($report->generated_by !== auth()->id() && auth()->user()->role !== 'admin') {
            return $this->errorResponse('Unauthorized', 403);
        }

        if ($report->status !== 'completed') {
            return $this->errorResponse('Report not ready', 422);
        }

        if (! $report->file_path || ! Storage::exists($report->file_path)) {
            return $this->errorResponse('File not found', 404);
        }

        return Storage::download($report->file_path);
    }
}
