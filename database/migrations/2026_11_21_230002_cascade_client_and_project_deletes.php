<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clients and Projects may be deleted while nothing invoiced references them
 * (Client::canBeDeleted(), Project::canBeDeleted()); their work goes with them.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->refit('projects', 'client_id', 'clients', 'cascade');
        $this->refit('tasks', 'project_id', 'projects', 'cascade');
        $this->refit('time_entries', 'task_id', 'tasks', 'cascade');
        $this->refit('expenses', 'client_id', 'clients', 'cascade');
        $this->refit('expenses', 'project_id', 'projects', 'set null');
    }

    public function down(): void
    {
        $this->refit('projects', 'client_id', 'clients', 'restrict');
        $this->refit('tasks', 'project_id', 'projects', 'restrict');
        $this->refit('time_entries', 'task_id', 'tasks', 'restrict');
        $this->refit('expenses', 'client_id', 'clients', 'restrict');
        $this->refit('expenses', 'project_id', 'projects', 'restrict');
    }

    private function refit(string $table, string $column, string $on, string $onDelete): void
    {
        Schema::table($table, function (Blueprint $blueprint) use ($column, $on, $onDelete) {
            $blueprint->dropForeign([$column]);
            $blueprint->foreign($column)->references('id')->on($on)->onDelete($onDelete);
        });
    }
};
