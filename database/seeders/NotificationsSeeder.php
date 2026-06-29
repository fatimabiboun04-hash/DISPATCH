<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Team;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class NotificationsSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('role', 'admin')->first();
        $employees = User::where('role', 'employee')->where('status', 'active')->get();

        $notificationTemplates = [
            // Planning notifications
            ['type' => 'planning_created',     'data' => ['message' => 'Votre planning pour la semaine prochaine a été publié.', 'link' => '/planning']],
            ['type' => 'planning_updated',      'data' => ['message' => 'Un changement a été apporté à votre planning.', 'link' => '/planning']],
            ['type' => 'planning_reminder',     'data' => ['message' => 'Rappel : vous travaillez demain matin (06h00).', 'link' => '/planning']],

            // Leave notifications
            ['type' => 'leave_approved',        'data' => ['message' => 'Votre demande de congé a été approuvée.', 'link' => '/leaves']],
            ['type' => 'leave_rejected',        'data' => ['message' => 'Votre demande de congé a été refusée.', 'link' => '/leaves']],
            ['type' => 'leave_pending',         'data' => ['message' => 'Vous avez une nouvelle demande de congé en attente.', 'link' => '/admin/leaves']],

            // Task notifications
            ['type' => 'task_assigned',         'data' => ['message' => 'Une nouvelle tâche vous a été assignée.', 'link' => '/tasks']],
            ['type' => 'task_completed',        'data' => ['message' => 'Une tâche a été marquée comme terminée.', 'link' => '/tasks']],
            ['type' => 'task_urgent',           'data' => ['message' => 'URGENT : Tâche critique à traiter immédiatement.', 'link' => '/tasks']],

            // Rating notifications
            ['type' => 'rating_received',       'data' => ['message' => 'Votre évaluation de la semaine a été mise à jour.', 'link' => '/profile']],
            ['type' => 'rating_excellent',      'data' => ['message' => 'Excellent travail cette semaine ! Consultez votre évaluation.', 'link' => '/profile']],

            // Report notifications
            ['type' => 'report_generated',      'data' => ['message' => 'Le rapport hebdomadaire est disponible au téléchargement.', 'link' => '/admin/reports']],
            ['type' => 'report_ready',          'data' => ['message' => 'Votre rapport personnalisé est prêt.', 'link' => '/reports']],
        ];

        // Create notifications for employees (past notifications — some read, some unread)
        foreach ($employees as $employee) {
            $numNotifications = rand(2, 5);
            $usedTypes = [];

            for ($i = 0; $i < $numNotifications; $i++) {
                $template = $notificationTemplates[array_rand($notificationTemplates)];
                if (in_array($template['type'], $usedTypes)) continue;
                $usedTypes[] = $template['type'];

                $daysAgo = rand(0, 14);
                $isRead = rand(0, 1);

                $employee->notifications()->create([
                    'id' => (string) Str::uuid(),
                    'type' => $template['type'],
                    'data' => $template['data'],
                    'read_at' => $isRead ? now()->subHours(rand(1, 48)) : null,
                    'created_at' => now()->subDays($daysAgo)->subHours(rand(0, 12)),
                    'updated_at' => now()->subDays($daysAgo)->subHours(rand(0, 12)),
                ]);
            }
        }

        // Create admin notifications (leave requests, reports)
        $adminNotifications = [
            ['type' => 'leave_requested', 'data' => ['message' => 'Un employé a soumis une nouvelle demande de congé.', 'link' => '/admin/leaves']],
            ['type' => 'report_completed', 'data' => ['message' => 'Le rapport mensuel est prêt pour validation.', 'link' => '/admin/reports']],
            ['type' => 'system_alert',    'data' => ['message' => 'Alerte : taux d\'absence élevé cette semaine (>15%).', 'link' => '/admin/dashboard']],
            ['type' => 'pointage_flag',   'data' => ['message' => 'Plusieurs pointages ont été signalés comme suspects.', 'link' => '/admin/pointages']],
        ];

        foreach ($adminNotifications as $notification) {
            $admin->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => $notification['type'],
                'data' => $notification['data'],
                'read_at' => rand(0, 1) ? now()->subHours(rand(1, 72)) : null,
                'created_at' => now()->subDays(rand(0, 7)),
                'updated_at' => now()->subDays(rand(0, 7)),
            ]);
        }
    }
}
