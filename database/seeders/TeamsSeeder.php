<?php

namespace Database\Seeders;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;

class TeamsSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('role', 'admin')->first();

        $teams = [
            ['name' => 'Alpha — Intervention Rapide', 'description' => 'Équipe d\'intervention rapide pour les urgences terrain — fibre et radio',  'color' => '#EF4444'],
            ['name' => 'Beta — Maintenance Réseau',   'description' => 'Équipe dédiée à la maintenance préventive et corrective des infrastructures réseau', 'color' => '#3B82F6'],
            ['name' => 'Gamma — Support Technique',   'description' => 'Support technique de niveau 2 pour les incidents clients et internes', 'color' => '#10B981'],
            ['name' => 'Delta — Supervision',         'description' => 'Supervision des opérations et coordination des équipes terrain',  'color' => '#8B5CF6'],
            ['name' => 'Sigma — Logistique',           'description' => 'Gestion des stocks, déploiement et logistique des équipements', 'color' => '#F59E0B'],
        ];

        foreach ($teams as $data) {
            Team::firstOrCreate(
                ['name' => $data['name']],
                [
                    'description' => $data['description'],
                    'color' => $data['color'],
                    'leader_id' => $admin->id,
                ]
            );
        }
    }
}
