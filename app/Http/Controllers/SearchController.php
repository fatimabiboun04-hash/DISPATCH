<?php

namespace App\Http\Controllers;

use App\Models\LeaveRequest;
use App\Models\Planning;
use App\Models\Report;
use App\Models\Skill;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SearchController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $q = $request->get('q');
        if (mb_strlen($q) < 2) {
            return response()->json(['data' => []]);
        }

        $like = "%{$q}%";
        $data = [];

        // Employees
        User::where('role', 'employee')
            ->where(function ($query) use ($like) {
                $query->where('name', 'like', $like)
                      ->orWhere('email', 'like', $like)
                      ->orWhere('phone', 'like', $like);
            })
            ->limit(5)
            ->get()
            ->each(function ($u) use (&$data) {
                $data[] = [
                    'type' => 'employee', 'id' => $u->id,
                    'label' => $u->name, 'sub' => $u->email,
                    'to' => "/admin/employees/{$u->id}",
                    'category' => 'EMPLOYÉS',
                ];
            });

        // Teams
        Team::where('name', 'like', $like)
            ->orWhere('description', 'like', $like)
            ->limit(3)
            ->get()
            ->each(function ($t) use (&$data) {
                $data[] = [
                    'type' => 'team', 'id' => $t->id,
                    'label' => $t->name, 'sub' => $t->description,
                    'to' => '/admin/teams',
                    'category' => 'ÉQUIPES',
                ];
            });

        // Skills
        Skill::where('name', 'like', $like)
            ->limit(3)
            ->get()
            ->each(function ($s) use (&$data) {
                $data[] = [
                    'type' => 'skill', 'id' => $s->id,
                    'label' => $s->name,
                    'sub' => $s->category ? "Catégorie: {$s->category}" : null,
                    'to' => '/admin/skills',
                    'category' => 'COMPÉTENCES',
                ];
            });

        // Tasks
        Task::where(function ($query) use ($like) {
                $query->where('title', 'like', $like)
                      ->orWhere('description', 'like', $like);
            })
            ->with('user:id,name')
            ->limit(5)
            ->get()
            ->each(function ($t) use (&$data) {
                $data[] = [
                    'type' => 'task', 'id' => $t->id,
                    'label' => $t->title,
                    'sub' => $t->user ? "Assigné à: {$t->user->name}" : null,
                    'to' => '/admin/tasks',
                    'category' => 'TÂCHES',
                ];
            });

        // Planning (search by employee name)
        Planning::whereHas('user', function ($query) use ($like) {
                $query->where('name', 'like', $like);
            })
            ->with('user:id,name', 'shift:id,name')
            ->limit(3)
            ->get()
            ->each(function ($p) use (&$data) {
                $data[] = [
                    'type' => 'planning', 'id' => $p->id,
                    'label' => "{$p->user->name} — {$p->date->format('d/m/Y')}",
                    'sub' => $p->shift ? "Shift: {$p->shift->name}" : null,
                    'to' => '/admin/planning',
                    'category' => 'PLANNING',
                ];
            });

        // Leave requests
        LeaveRequest::where('reason', 'like', $like)
            ->with('user:id,name')
            ->limit(3)
            ->get()
            ->each(function ($l) use (&$data) {
                $data[] = [
                    'type' => 'leave', 'id' => $l->id,
                    'label' => $l->user ? $l->user->name : 'Demande de congé',
                    'sub' => mb_substr($l->reason, 0, 60),
                    'to' => '/admin/leave-requests',
                    'category' => 'CONGÉS',
                ];
            });

        // Reports
        Report::where('type', 'like', $like)
            ->limit(3)
            ->get()
            ->each(function ($r) use (&$data) {
                $label = match ($r->type) {
                    'weekly' => "Rapport Hebdomadaire S{$r->week_number}",
                    'monthly' => 'Rapport Mensuel',
                    default => 'Rapport',
                };
                $data[] = [
                    'type' => 'report', 'id' => $r->id,
                    'label' => $label,
                    'sub' => "{$r->start_date} — {$r->end_date}",
                    'to' => '/admin/reports',
                    'category' => 'RAPPORTS',
                ];
            });

        // Notifications (search inside JSON data)
        $notifications = DB::table('notifications')
            ->where('notifiable_type', 'App\Models\User')
            ->where('data', 'like', $like)
            ->limit(3)
            ->get();

        foreach ($notifications as $n) {
            $parsed = json_decode($n->data, true);
            $data[] = [
                'type' => 'notification', 'id' => $n->id,
                'label' => $parsed['message'] ?? 'Notification',
                'sub' => $n->type,
                'to' => null,
                'category' => 'NOTIFICATIONS',
            ];
        }

        return response()->json(['data' => $data]);
    }
}
