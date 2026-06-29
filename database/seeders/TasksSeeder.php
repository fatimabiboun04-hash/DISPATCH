<?php

namespace Database\Seeders;

use App\Models\Task;
use App\Models\User;
use App\Models\Planning;
use Illuminate\Database\Seeder;

class TasksSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('role', 'admin')->first();
        $employees = User::where('role', 'employee')->where('status', 'active')->get();

        $templates = [
            ['title' => 'Inspection fibre zone nord',       'description' => 'Vérifier l\'intégrité des câbles fibre dans le secteur nord',        'priority' => 'high'],
            ['title' => 'Mise à jour équipements réseau',   'description' => 'Appliquer les correctifs de sécurité sur les routeurs',               'priority' => 'critical'],
            ['title' => 'Support client urgent',            'description' => 'Client signalant une panne totale — diagnostic immédiat',              'priority' => 'critical'],
            ['title' => 'Maintenance préventive antenne',   'description' => 'Nettoyage et vérification des antennes relais',                        'priority' => 'medium'],
            ['title' => 'Rapport d\'intervention',           'description' => 'Rédiger le rapport d\'intervention de la semaine',                      'priority' => 'low'],
            ['title' => 'Installation nouveau client',      'description' => 'Déploiement fibre chez nouveau client zone industrielle',               'priority' => 'medium'],
            ['title' => 'Vérification stocks équipements',  'description' => 'Inventaire du matériel en entrepôt',                                    'priority' => 'low'],
            ['title' => 'Test de couverture radio',         'description' => 'Effectuer des mesures de couverture radio secteur sud',                 'priority' => 'medium'],
            ['title' => 'Urgence panne câble principal',    'description' => 'Câble sectionné route principale — intervention rapide',                'priority' => 'critical'],
            ['title' => 'Configuration routeur client',     'description' => 'Paramétrage équipement client VIP',                                     'priority' => 'high'],
            ['title' => 'Audit sécurité trimestriel',       'description' => 'Vérification conforme des accès et habilitations',                      'priority' => 'high'],
            ['title' => 'Réunion coordination équipe',      'description' => 'Point hebdomadaire avec les chefs d\'équipe',                           'priority' => 'medium'],
            ['title' => 'Mise à jour documentation',        'description' => 'Mettre à jour les procédures d\'intervention',                          'priority' => 'low'],
            ['title' => 'Déploiement équipements 4G',       'description' => 'Installation de nouvelles antennes 4G site Est',                         'priority' => 'high'],
            ['title' => 'Vérification alarmes réseau',      'description' => 'Analyser les alertes remontées par le système de supervision',           'priority' => 'medium'],
            ['title' => 'Formation nouvel arrivant',        'description' => 'Former le nouveau technicien aux procédures terrain',                   'priority' => 'low'],
            ['title' => 'Remplacement onduleur défectueux', 'description' => 'Onduleur hors service au NOC — remplacement urgent',                     'priority' => 'high'],
            ['title' => 'Nettoyage baies techniques',       'description' => 'Dépoussiérage et vérification des baies de brassage',                     'priority' => 'low'],
            ['title' => 'Intervention client prioritaire',  'description' => 'Client entreprise sans connexion depuis 2h',                             'priority' => 'critical'],
            ['title' => 'Test débit fibre post-réparation', 'description' => 'Vérifier que le débit est conforme après intervention',                  'priority' => 'medium'],
            ['title' => 'Préparation rapport mensuel',      'description' => 'Compiler les KPI du mois pour la direction',                            'priority' => 'medium'],
            ['title' => 'Vérification GPS véhicules',       'description' => 'S\'assurer que tous les trackers GPS des véhicules sont fonctionnels',   'priority' => 'low'],
            ['title' => 'Réparation câble aérien',         'description' => 'Câble endommagé par intempéries — réparation urgente',                    'priority' => 'high'],
            ['title' => 'Livraison matériel site distant',  'description' => 'Acheminer le matériel de remplacement vers le site de Bouskoura',         'priority' => 'medium'],
        ];

        $statuses = ['pending', 'in_progress', 'completed', 'cancelled'];

        foreach ($templates as $i => $template) {
            $employee = $employees->random();
            $status = $statuses[array_rand($statuses)];
            $daysAgo = rand(0, 30);
            $dueDate = now()->subDays($daysAgo)->addDays(rand(-5, 15));

            // Some tasks link to a planning, some don't
            $planning = Planning::where('user_id', $employee->id)
                ->inRandomOrder()
                ->first();

            Task::create([
                'user_id' => $employee->id,
                'planning_id' => $planning?->id,
                'title' => $template['title'],
                'description' => $template['description'],
                'status' => $status,
                'priority' => $template['priority'],
                'due_date' => $dueDate->format('Y-m-d'),
                'created_by' => $admin->id,
            ]);
        }

        // Add 5 additional completed tasks in the past
        for ($i = 0; $i < 5; $i++) {
            $employee = $employees->random();
            $template = $templates[array_rand($templates)];

            Task::create([
                'user_id' => $employee->id,
                'title' => $template['title'] . ' (archive)',
                'description' => $template['description'] . ' — tâche archivée',
                'status' => 'completed',
                'priority' => $template['priority'],
                'due_date' => now()->subDays(rand(15, 60))->format('Y-m-d'),
                'created_by' => $admin->id,
            ]);
        }
    }
}
