<?php

namespace Database\Seeders;

use App\Models\Skill;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class EmployeesSeeder extends Seeder
{
    public function run(): void
    {
        $teams = Team::all()->keyBy('name');
        $skills = Skill::all()->keyBy('name');

        $employees = [
            // === Alpha — Intervention Rapide ===
            [
                'name' => 'Ahmed Benali',
                'email' => 'ahmed.benali@dispatch.ma',
                'phone' => '+212 6 12 34 56 01',
                'description' => 'Spécialiste fibre optique avec 8 ans d\'expérience. Intervention rapide.',
                'weekly_hours_limit' => 44,
                'status' => 'active',
                'team' => 'Alpha — Intervention Rapide',
                'employee_skills' => ['Fibre Optique' => 'expert', 'Installation' => 'expert', 'Configuration' => 'intermediate'],
            ],
            [
                'name' => 'Karim El Amrani',
                'email' => 'karim.elamrani@dispatch.ma',
                'phone' => '+212 6 12 34 56 02',
                'description' => 'Expert réseau et radio. Certifié CISCO CCNA.',
                'weekly_hours_limit' => 44,
                'status' => 'active',
                'team' => 'Alpha — Intervention Rapide',
                'employee_skills' => ['Réseau' => 'expert', 'Radio' => 'expert', 'Diagnostic' => 'intermediate'],
            ],
            [
                'name' => 'Youssef Idrissi',
                'email' => 'youssef.idrissi@dispatch.ma',
                'phone' => '+212 6 12 34 56 03',
                'description' => 'Technicien installation et configuration. Rigoureux et ponctuel.',
                'weekly_hours_limit' => 40,
                'status' => 'active',
                'team' => 'Alpha — Intervention Rapide',
                'employee_skills' => ['Installation' => 'expert', 'Configuration' => 'intermediate', 'Support' => 'beginner'],
            ],
            [
                'name' => 'Mohamed Ziani',
                'email' => 'mohamed.ziani@dispatch.ma',
                'phone' => '+212 6 12 34 56 04',
                'description' => 'Coordinateur dispatch et opérateur radio expérimenté.',
                'weekly_hours_limit' => 42,
                'status' => 'active',
                'team' => 'Alpha — Intervention Rapide',
                'employee_skills' => ['Dispatch' => 'expert', 'Radio' => 'intermediate', 'Intervention Urgence' => 'intermediate'],
            ],
            [
                'name' => 'Hassan Bakkali',
                'email' => 'hassan.bakkali@dispatch.ma',
                'phone' => '+212 6 12 34 56 05',
                'description' => 'Technicien polyvalent. Actuellement suspendu pour non-respect des procédures.',
                'weekly_hours_limit' => 40,
                'status' => 'suspended',
                'suspension_reason' => 'Non-respect répété des procédures de sécurité sur le terrain',
                'team' => 'Alpha — Intervention Rapide',
                'employee_skills' => ['Maintenance' => 'intermediate', 'Installation' => 'beginner'],
            ],

            // === Beta — Maintenance Réseau ===
            [
                'name' => 'Noureddine Alaoui',
                'email' => 'noureddine.alaoui@dispatch.ma',
                'phone' => '+212 6 12 34 56 06',
                'description' => 'Ingénieur réseau senior. Expert en maintenance préventive.',
                'weekly_hours_limit' => 44,
                'status' => 'active',
                'team' => 'Beta — Maintenance Réseau',
                'employee_skills' => ['Réseau' => 'expert', 'Maintenance' => 'expert', 'Supervision' => 'intermediate'],
            ],
            [
                'name' => 'Abdelkader El Fassi',
                'email' => 'abdelkader.elfassi@dispatch.ma',
                'phone' => '+212 6 12 34 56 07',
                'description' => 'Spécialiste fibre optique. Soudure et raccordement FTTH.',
                'weekly_hours_limit' => 44,
                'status' => 'active',
                'team' => 'Beta — Maintenance Réseau',
                'employee_skills' => ['Fibre Optique' => 'expert', 'Installation' => 'expert', 'Diagnostic' => 'intermediate'],
            ],
            [
                'name' => 'Driss Tazi',
                'email' => 'driss.tazi@dispatch.ma',
                'phone' => '+212 6 12 34 56 08',
                'description' => 'Technicien maintenance. Grande expérience en réparation de câbles.',
                'weekly_hours_limit' => 40,
                'status' => 'active',
                'team' => 'Beta — Maintenance Réseau',
                'employee_skills' => ['Maintenance' => 'expert', 'Fibre Optique' => 'intermediate'],
            ],
            [
                'name' => 'Rachid Bennani',
                'email' => 'rachid.bennani@dispatch.ma',
                'phone' => '+212 6 12 34 56 09',
                'description' => 'Expert en configuration d\'équipements réseau CISCO et Huawei.',
                'weekly_hours_limit' => 42,
                'status' => 'active',
                'team' => 'Beta — Maintenance Réseau',
                'employee_skills' => ['Configuration' => 'expert', 'Réseau' => 'intermediate', 'Sécurité' => 'intermediate'],
            ],
            [
                'name' => 'Samir El Ouahabi',
                'email' => 'samir.elouahabi@dispatch.ma',
                'phone' => '+212 6 12 34 56 10',
                'description' => 'Technicien réseau. Suspendu pour absences répétées.',
                'weekly_hours_limit' => 40,
                'status' => 'suspended',
                'suspension_reason' => 'Absences non justifiées à répétition',
                'team' => 'Beta — Maintenance Réseau',
                'employee_skills' => ['Réseau' => 'intermediate', 'Maintenance' => 'beginner'],
            ],

            // === Gamma — Support Technique ===
            [
                'name' => 'Fatima Zahra El Khayat',
                'email' => 'fatima.elkhayat@dispatch.ma',
                'phone' => '+212 6 12 34 56 11',
                'description' => 'Chef d\'équipe support technique. Réactivité et professionnalisme.',
                'weekly_hours_limit' => 44,
                'status' => 'active',
                'team' => 'Gamma — Support Technique',
                'employee_skills' => ['Support' => 'expert', 'Diagnostic' => 'expert', 'Réseau' => 'intermediate'],
            ],
            [
                'name' => 'Amina Bencheikh',
                'email' => 'amina.bencheikh@dispatch.ma',
                'phone' => '+212 6 12 34 56 12',
                'description' => 'Technicienne support et diagnostic. Spécialiste résolution incidents.',
                'weekly_hours_limit' => 40,
                'status' => 'active',
                'team' => 'Gamma — Support Technique',
                'employee_skills' => ['Diagnostic' => 'expert', 'Support' => 'expert', 'Radio' => 'beginner'],
            ],
            [
                'name' => 'Saad Bennouna',
                'email' => 'saad.bennouna@dispatch.ma',
                'phone' => '+212 6 12 34 56 13',
                'description' => 'Technicien réseau et support. Très bon relationnel client.',
                'weekly_hours_limit' => 40,
                'status' => 'active',
                'team' => 'Gamma — Support Technique',
                'employee_skills' => ['Réseau' => 'intermediate', 'Support' => 'expert', 'Configuration' => 'beginner'],
            ],
            [
                'name' => 'Jawad El Hariri',
                'email' => 'jawad.elhariri@dispatch.ma',
                'phone' => '+212 6 12 34 56 14',
                'description' => 'Maintenance et diagnostic. Intervenant de confiance.',
                'weekly_hours_limit' => 38,
                'status' => 'active',
                'team' => 'Gamma — Support Technique',
                'employee_skills' => ['Maintenance' => 'intermediate', 'Diagnostic' => 'intermediate', 'Support' => 'intermediate'],
            ],

            // === Delta — Supervision ===
            [
                'name' => 'Moncef El Ghazi',
                'email' => 'moncef.elghazi@dispatch.ma',
                'phone' => '+212 6 12 34 56 15',
                'description' => 'Superviseur des opérations. Coordination et dispatch.',
                'weekly_hours_limit' => 48,
                'status' => 'active',
                'team' => 'Delta — Supervision',
                'employee_skills' => ['Supervision' => 'expert', 'Dispatch' => 'expert', 'Gestion Équipe' => 'expert'],
            ],
            [
                'name' => 'Abdelmajid Berrada',
                'email' => 'abdelmajid.berrada@dispatch.ma',
                'phone' => '+212 6 12 34 56 16',
                'description' => 'Superviseur sécurité. Conformité et contrôle des procédures.',
                'weekly_hours_limit' => 44,
                'status' => 'active',
                'team' => 'Delta — Supervision',
                'employee_skills' => ['Supervision' => 'expert', 'Sécurité' => 'expert', 'Gestion Équipe' => 'intermediate'],
            ],
            [
                'name' => 'Hakim Belmahi',
                'email' => 'hakim.belmahi@dispatch.ma',
                'phone' => '+212 6 12 34 56 17',
                'description' => 'Superviseur intervention urgence. Coordinateur crise.',
                'weekly_hours_limit' => 44,
                'status' => 'active',
                'team' => 'Delta — Supervision',
                'employee_skills' => ['Supervision' => 'expert', 'Intervention Urgence' => 'expert', 'Dispatch' => 'intermediate'],
            ],

            // === Sigma — Logistique ===
            [
                'name' => 'Tariq Bennani',
                'email' => 'tariq.bennani@dispatch.ma',
                'phone' => '+212 6 12 34 56 18',
                'description' => 'Responsable logistique et dispatch des équipements.',
                'weekly_hours_limit' => 44,
                'status' => 'active',
                'team' => 'Sigma — Logistique',
                'employee_skills' => ['Logistique' => 'expert', 'Dispatch' => 'intermediate', 'Gestion Équipe' => 'beginner'],
            ],
            [
                'name' => 'Khalid Amiri',
                'email' => 'khalid.amiri@dispatch.ma',
                'phone' => '+212 6 12 34 56 19',
                'description' => 'Installation et configuration des équipements déployés.',
                'weekly_hours_limit' => 40,
                'status' => 'active',
                'team' => 'Sigma — Logistique',
                'employee_skills' => ['Installation' => 'expert', 'Configuration' => 'intermediate', 'Logistique' => 'beginner'],
            ],
            [
                'name' => 'Youness El Khatib',
                'email' => 'youness.elkhatib@dispatch.ma',
                'phone' => '+212 6 12 34 56 20',
                'description' => 'Sécurité et intervention urgence. Ancien pompier civil.',
                'weekly_hours_limit' => 35,
                'status' => 'active',
                'team' => 'Sigma — Logistique',
                'employee_skills' => ['Sécurité' => 'expert', 'Intervention Urgence' => 'expert', 'Logistique' => 'intermediate'],
            ],
        ];

        foreach ($employees as $data) {
            $team = $teams[$data['team']];
            $empSkills = $data['employee_skills'];
            unset($data['team'], $data['employee_skills']);

            $data['password'] = Hash::make('password123');
            $data['role'] = 'employee';

            $user = User::firstOrCreate(
                ['email' => $data['email']],
                $data
            );

            if ($user->wasRecentlyCreated) {
                $user->teams()->attach($team->id, ['joined_at' => now()->subMonths(rand(3, 24))]);

                foreach ($empSkills as $skillName => $level) {
                    $skill = $skills[$skillName] ?? null;
                    if ($skill) {
                        $user->skills()->attach($skill->id, [
                            'level' => $level,
                            'certified_at' => now()->subMonths(rand(1, 18))->format('Y-m-d'),
                        ]);
                    }
                }
            }
        }
    }
}
