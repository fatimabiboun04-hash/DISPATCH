<?php

namespace App\Services;

use App\Models\Report;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class ReportGeneratorService
{
    public function generate(Report $report): string
    {
        $filename = sprintf(
            'reports/%s_report_%d_%s.%s',
            $report->type,
            $report->id,
            now()->format('Ymd_His'),
            $report->file_type === 'excel' ? 'xlsx' : 'pdf'
        );

        if ($report->file_type === 'pdf') {
            return $this->generatePdf($report, $filename);
        }

        return $this->generateExcel($report, $filename);
    }

    protected function generatePdf(Report $report, string $filename): string
    {
        $data = $this->gatherReportData($report);

        $html = view('reports.pdf-template', ['data' => $data, 'report' => $report])->render();

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html);
        $content = $pdf->output();

        Storage::put($filename, $content);

        return $filename;
    }

    protected function generateExcel(Report $report, string $filename): string
    {
        $data = $this->gatherReportData($report);

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Headers
        $sheet->setCellValue('A1', 'Report: ' . ucfirst($report->type));
        $sheet->setCellValue('A2', 'Period: ' . $report->start_date->format('M d, Y') . ' - ' . $report->end_date->format('M d, Y'));
        $sheet->setCellValue('A3', 'Generated: ' . now()->format('M d, Y H:i'));

        // Data rows starting from row 5
        $row = 5;
        foreach ($data as $item) {
            $col = 'A';
            foreach ($item as $value) {
                $sheet->setCellValue($col . $row, $value);
                $col++;
            }
            $row++;
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $tempPath = storage_path('app/' . $filename);
        $writer->save($tempPath);

        return $filename;
    }

    protected function gatherReportData(Report $report): array
    {
        $start = Carbon::parse($report->start_date)->startOfDay();
        $end = Carbon::parse($report->end_date)->endOfDay();

        switch ($report->type) {
            case 'weekly':
                return $this->gatherWeeklyData($start, $end);
            case 'monthly':
                return $this->gatherMonthlyData($start, $end);
            case 'custom':
            default:
                return $this->gatherCustomData($start, $end);
        }
    }

    protected function gatherWeeklyData(Carbon $start, Carbon $end): array
    {
        $plannings = \App\Models\Planning::with(['user', 'shift'])
            ->whereBetween('date', [$start, $end])
            ->get();

        $data = [['Date', 'Employee', 'Shift', 'Team', 'Status']];

        foreach ($plannings as $planning) {
            $data[] = [
                $planning->date->format('Y-m-d'),
                $planning->user->name,
                $planning->shift->name,
                $planning->team?->name ?? 'N/A',
                $planning->is_locked ? 'Locked' : 'Active',
            ];
        }

        return $data;
    }

    protected function gatherMonthlyData(Carbon $start, Carbon $end): array
    {
        $pointages = \App\Models\Pointage::with(['user'])
            ->whereBetween('check_in_at', [$start, $end])
            ->get();

        $data = [['Date', 'Employee', 'Check In', 'Check Out', 'Worked Hours', 'Status']];

        foreach ($pointages as $pointage) {
            $data[] = [
                $pointage->check_in_at->format('Y-m-d'),
                $pointage->user->name,
                $pointage->check_in_at->format('H:i'),
                $pointage->check_out_at?->format('H:i') ?? 'N/A',
                $pointage->worked_minutes ? round($pointage->worked_minutes / 60, 2) : 'N/A',
                $pointage->status,
            ];
        }

        return $data;
    }

    protected function gatherCustomData(Carbon $start, Carbon $end): array
    {
        $leaves = \App\Models\LeaveRequest::with(['user', 'approver'])
            ->whereBetween('start_date', [$start, $end])
            ->get();

        $data = [['Employee', 'Type', 'Start', 'End', 'Status', 'Approved By']];

        foreach ($leaves as $leave) {
            $data[] = [
                $leave->user->name,
                ucfirst($leave->type),
                $leave->start_date->format('Y-m-d'),
                $leave->end_date->format('Y-m-d'),
                ucfirst($leave->status),
                $leave->approver?->name ?? 'Pending',
            ];
        }

        return $data;
    }
}