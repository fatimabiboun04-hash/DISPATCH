<?php

namespace Database\Seeders;

use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Database\Seeder;

class LeaveSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('role', 'admin')->first();
        $employees = User::where('role', 'employee')->where('status', 'active')->get();

        $leaveTemplates = [
            ['reason' => 'Congé annuel — vacances familiales',                  'type' => 'annual'],
            ['reason' => 'Congé annuel — voyage à l\'étranger',                'type' => 'annual'],
            ['reason' => 'Rendez-vous médical spécialiste',                     'type' => 'sick'],
            ['reason' => 'Arrêt maladie — grippe saisonnière',                  'type' => 'sick'],
            ['reason' => 'Urgence familiale — problème de santé proche',        'type' => 'emergency'],
            ['reason' => 'Congé sans solde — affaires personnelles',            'type' => 'unpaid'],
            ['reason' => 'Hospitalisation — intervention chirurgicale',         'type' => 'sick'],
            ['reason' => 'Mariage — congé exceptionnel',                        'type' => 'annual'],
            ['reason' => 'Décès d\'un proche — congé de deuil',                 'type' => 'emergency'],
            ['reason' => 'Congé annuel — repos et récupération',                'type' => 'annual'],
            ['reason' => 'Accompagnement enfant chez le médecin',              'type' => 'sick'],
            ['reason' => 'Déménagement — affaires personnelles urgentes',       'type' => 'unpaid'],
        ];

        $statuses = ['pending', 'approved', 'rejected', 'approved', 'approved', 'pending'];

        // Create leaves in the past, current, and future
        foreach ($employees->random(min($employees->count(), 12)) as $employee) {
            $template = $leaveTemplates[array_rand($leaveTemplates)];
            $status = $statuses[array_rand($statuses)];

            $daysAgo = rand(0, 60);

            if ($status === 'approved' || $status === 'rejected') {
                $startDate = now()->subDays($daysAgo);
                $endDate = (clone $startDate)->addDays(rand(1, 5));
            } else {
                // Pending leaves can be in the future
                $startDate = now()->addDays(rand(1, 30));
                $endDate = (clone $startDate)->addDays(rand(1, 5));
            }

            $leave = LeaveRequest::create([
                'user_id' => $employee->id,
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
                'reason' => $template['reason'],
                'type' => $template['type'],
                'status' => $status,
            ]);

            if ($status === 'approved') {
                $leave->update([
                    'approved_by' => $admin->id,
                    'approved_at' => now()->subDays($daysAgo + rand(1, 3)),
                ]);
            } elseif ($status === 'rejected') {
                $rejectionReasons = [
                    'Effectif insuffisant pour cette période',
                    'Un autre membre de l\'équipe est déjà en congé',
                    'Période de forte activité — veuillez reporter',
                    'Le quota de congés annuels est dépassé',
                ];
                $leave->update([
                    'approved_by' => $admin->id,
                    'rejection_reason' => $rejectionReasons[array_rand($rejectionReasons)],
                ]);
            }
        }

        // Add a couple of sick leaves in the current week
        for ($i = 0; $i < 2; $i++) {
            $employee = $employees->random();
            $startDate = now()->startOfWeek()->addDays(rand(0, 3));
            LeaveRequest::create([
                'user_id' => $employee->id,
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => (clone $startDate)->addDays(rand(1, 2))->format('Y-m-d'),
                'reason' => 'Arrêt maladie — certificat médical fourni',
                'type' => 'sick',
                'status' => 'approved',
                'approved_by' => $admin->id,
                'approved_at' => now()->subDays(1),
            ]);
        }

        // Add pending leave for next week
        $pendingEmployee = $employees->random();
        $nextWeekStart = now()->addWeek()->startOfWeek();
        LeaveRequest::create([
            'user_id' => $pendingEmployee->id,
            'start_date' => $nextWeekStart->addDays(2)->format('Y-m-d'),
            'end_date' => $nextWeekStart->addDays(4)->format('Y-m-d'),
            'reason' => 'Congé annuel — vacances',
            'type' => 'annual',
            'status' => 'pending',
        ]);
    }
}
