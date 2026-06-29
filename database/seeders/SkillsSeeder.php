<?php

namespace Database\Seeders;

use App\Models\Skill;
use Illuminate\Database\Seeder;

class SkillsSeeder extends Seeder
{
    public function run(): void
    {
        $skills = [
            ['name' => 'Réseau',        'category' => 'Technique'],
            ['name' => 'Fibre Optique',  'category' => 'Technique'],
            ['name' => 'Radio',          'category' => 'Technique'],
            ['name' => 'Support',        'category' => 'Service'],
            ['name' => 'Maintenance',    'category' => 'Technique'],
            ['name' => 'Supervision',    'category' => 'Management'],
            ['name' => 'Dispatch',       'category' => 'Opérationnel'],
            ['name' => 'Installation',   'category' => 'Technique'],
            ['name' => 'Configuration',  'category' => 'Technique'],
            ['name' => 'Diagnostic',     'category' => 'Technique'],
            ['name' => 'Sécurité',       'category' => 'Sécurité'],
            ['name' => 'Intervention Urgence', 'category' => 'Opérationnel'],
            ['name' => 'Gestion Équipe', 'category' => 'Management'],
            ['name' => 'Logistique',     'category' => 'Opérationnel'],
        ];

        foreach ($skills as $skill) {
            Skill::firstOrCreate(
                ['name' => $skill['name']],
                ['category' => $skill['category']]
            );
        }
    }
}
