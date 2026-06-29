<?php

namespace Database\Seeders;

use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Seeder;

class ReportSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('role', 'admin')->first();
        $now = now();
        $currentWeek = (int) $now->weekOfYear;
        $currentYear = (int) $now->year;

        $statuses = ['completed', 'completed', 'completed', 'processing', 'failed'];

        // Weekly reports for past 8 weeks
        for ($w = 1; $w <= 8; $w++) {
            $weekNumber = $currentWeek - $w;
            $year = $currentYear;
            if ($weekNumber < 1) {
                $weekNumber = 52 + $weekNumber;
                $year--;
            }

            $startOfWeek = $now->copy()->setISODate($year, $weekNumber)->startOfWeek();

            Report::create([
                'type' => 'weekly',
                'week_number' => $weekNumber,
                'year' => $year,
                'start_date' => $startOfWeek->format('Y-m-d'),
                'end_date' => (clone $startOfWeek)->addDays(6)->format('Y-m-d'),
                'file_path' => "reports/weekly/S{$weekNumber}_{$year}.pdf",
                'file_type' => 'pdf',
                'generated_by' => $admin->id,
                'status' => 'completed',
            ]);
        }

        // Monthly reports for past 3 months
        for ($m = 1; $m <= 3; $m++) {
            $monthStart = $now->copy()->subMonths($m)->startOfMonth();
            $monthEnd = (clone $monthStart)->endOfMonth();

            Report::create([
                'type' => 'monthly',
                'start_date' => $monthStart->format('Y-m-d'),
                'end_date' => $monthEnd->format('Y-m-d'),
                'file_path' => "reports/monthly/{$monthStart->format('Y-m')}.xlsx",
                'file_type' => 'excel',
                'generated_by' => $admin->id,
                'status' => 'completed',
            ]);
        }

        // Custom reports (some still processing, some failed)
        Report::create([
            'type' => 'custom',
            'start_date' => $now->copy()->subDays(14)->format('Y-m-d'),
            'end_date' => $now->copy()->subDays(7)->format('Y-m-d'),
            'file_path' => null,
            'file_type' => 'pdf',
            'generated_by' => $admin->id,
            'status' => 'processing',
        ]);

        Report::create([
            'type' => 'custom',
            'start_date' => $now->copy()->subDays(30)->format('Y-m-d'),
            'end_date' => $now->copy()->subDays(15)->format('Y-m-d'),
            'file_path' => null,
            'file_type' => 'excel',
            'generated_by' => $admin->id,
            'status' => 'failed',
        ]);

        // Latest weekly report for current week (processing)
        Report::create([
            'type' => 'weekly',
            'week_number' => $currentWeek,
            'year' => $currentYear,
            'start_date' => $now->copy()->startOfWeek()->format('Y-m-d'),
            'end_date' => $now->copy()->endOfWeek()->format('Y-m-d'),
            'file_path' => null,
            'file_type' => 'pdf',
            'generated_by' => $admin->id,
            'status' => 'processing',
        ]);
    }
}
