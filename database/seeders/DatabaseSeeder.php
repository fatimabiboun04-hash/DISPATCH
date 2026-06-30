<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // Foundation — no dependencies
            ShiftSeeder::class,
            SettingSeeder::class,
            SkillsSeeder::class,

            // Admin user (needed as foreign key for teams/ratings/etc.)
            AdminSeeder::class,

            // Teams & employees — depend on admin + skills
            TeamsSeeder::class,
            EmployeesSeeder::class,

            // Planning — depends on employees, teams, shifts
            PlanningSeeder::class,

            // Pointage — depends on planning, employees, teams
            PointageSeeder::class,

            // Tasks — depends on employees, planning
            TasksSeeder::class,

            // Leaves — depends on employees, admin
            LeaveSeeder::class,

            // Pauses — depends on planning, pointages
            PauseSeeder::class,

            // Ratings — depends on employees, admin
            RatingSeeder::class,

            // Notifications — depends on employees, admin
            NotificationsSeeder::class,

            // Reports — depends on admin
            ReportSeeder::class,

            // Planning history — depends on planning, audit tables
            PlanningHistorySeeder::class,

            // Historical planning — ensures all previous weeks are fully populated
            HistoricalPlanningSeeder::class,
        ]);
    }
}
