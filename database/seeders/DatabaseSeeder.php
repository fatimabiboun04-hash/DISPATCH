<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    // database/seeders/DatabaseSeeder.php

    public function run(): void
    {
        $this->call([
            ShiftSeeder::class,
            SettingSeeder::class,
            AdminSeeder::class,
        ]);
    }
}
