<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Remove any orphan tasks that have no planning link
        DB::table('tasks')->whereNull('planning_id')->delete();

        // Drop existing FK and recreate with NOT NULL + CASCADE
        DB::statement('ALTER TABLE tasks DROP FOREIGN KEY tasks_planning_id_foreign');
        DB::statement('ALTER TABLE tasks MODIFY planning_id BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_planning_id_foreign FOREIGN KEY (planning_id) REFERENCES plannings(id) ON DELETE CASCADE');
    }

    public function down()
    {
        DB::statement('ALTER TABLE tasks DROP FOREIGN KEY tasks_planning_id_foreign');
        DB::statement('ALTER TABLE tasks MODIFY planning_id BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_planning_id_foreign FOREIGN KEY (planning_id) REFERENCES plannings(id) ON DELETE SET NULL');
    }
};
