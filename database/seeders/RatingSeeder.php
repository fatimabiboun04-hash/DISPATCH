<?php

namespace Database\Seeders;

use App\Models\Rating;
use App\Models\User;
use Illuminate\Database\Seeder;

class RatingSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('role', 'admin')->first();
        $employees = User::where('role', 'employee')->where('status', 'active')->get();

        $now = now();
        $currentWeek = (int) $now->weekOfYear;
        $currentYear = (int) $now->year;

        $comments = [
            5 => [
                'Excellent travail cette semaine. Très réactif et professionnel.',
                'Performance remarquable. Continue comme ça !',
                'Un technicien exemplaire, toujours disponible et efficace.',
                'Résultats exceptionnels, aucun incident à signaler.',
            ],
            4 => [
                'Très bon travail, légères améliorations possibles sur la ponctualité.',
                'Bonne performance globale. Objectifs atteints.',
                'Travail sérieux et de qualité. Quelques retards mineurs.',
            ],
            3 => [
                'Travail correct mais peut mieux faire. À suivre.',
                'Performance moyenne. Points à améliorer : organisation.',
                'Résultats acceptables. Encourageons la progression.',
            ],
            2 => [
                'Des efforts sont nécessaires sur la rigueur et la ponctualité.',
                'Performance en dessous des attentes. Entretien souhaité.',
            ],
            1 => [
                'Semaine problématique. Plusieurs absences non justifiées.',
                'Comportement inapproprié sur le terrain. Mesures nécessaires.',
                'Non-respect des procédures de sécurité. Rappel urgent.',
            ],
        ];

        // Give each employee rating history for past 4-8 weeks
        foreach ($employees as $employee) {
            $numWeeks = rand(4, 8);

            for ($w = 0; $w < $numWeeks; $w++) {
                $weekOffset = $w + 1;
                $weekNumber = $currentWeek - $weekOffset;
                $year = $currentYear;

                if ($weekNumber < 1) {
                    $weekNumber = 52 + $weekNumber;
                    $year--;
                }

                // Higher-performing employees tend to get better scores
                $score = $this->weightedRandomScore($employee->name);
                $type = $score >= 4 ? 'excellent' : 'warning';

                $weeklyComments = $comments[$score] ?? ['Évaluation hebdomadaire standard.'];
                $comment = $weeklyComments[array_rand($weeklyComments)];

                Rating::create([
                    'user_id' => $employee->id,
                    'rated_by' => $admin->id,
                    'type' => $type,
                    'score' => $score,
                    'reason' => $comment,
                    'comment' => $comment,
                    'week_number' => $weekNumber,
                    'year' => $year,
                ]);
            }
        }

        // Add current week ratings for most active employees
        $activeEmployees = $employees->shuffle()->take(15);
        foreach ($activeEmployees as $employee) {
            $score = rand(1, 5);
            $type = $score >= 4 ? 'excellent' : 'warning';
            $weeklyComments = $comments[$score] ?? ['Évaluation standard.'];
            $comment = $weeklyComments[array_rand($weeklyComments)];

            Rating::firstOrCreate(
                ['user_id' => $employee->id, 'week_number' => $currentWeek, 'year' => $currentYear],
                [
                    'rated_by' => $admin->id,
                    'type' => $type,
                    'score' => $score,
                    'reason' => $comment,
                    'comment' => $comment,
                ]
            );
        }
    }

    private function weightedRandomScore(string $name): int
    {
        // Deterministic "bias" based on name hash to give some employees consistently better scores
        $hash = crc32($name);
        $bias = abs($hash) % 10; // 0-9

        if ($bias >= 7) return rand(4, 5); // Top performers
        if ($bias >= 4) return rand(3, 5); // Good performers
        if ($bias >= 2) return rand(2, 4); // Average
        return rand(1, 3); // Needs improvement
    }
}
