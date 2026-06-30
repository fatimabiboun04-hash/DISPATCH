<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Dispatch Live — Daily Planning {{ $date->format('Y-m-d') }}</title>
    <style>
        @page { margin: 12mm 10mm; size: A4 portrait; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 9px; color: #1f2937; }
        .header { display: flex; justify-content: space-between; border-bottom: 2px solid #1e3a5f; padding-bottom: 6px; margin-bottom: 10px; }
        .header h1 { font-size: 15px; color: #1e3a5f; margin: 0; }
        .header .meta { font-size: 7px; color: #6b7280; text-align: right; }
        .date-banner { padding: 5px 8px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 3px; margin-bottom: 8px; font-size: 10px; font-weight: bold; }
        .team-group { margin-bottom: 14px; page-break-inside: avoid; }
        .team-group-title { font-size: 10px; font-weight: bold; padding: 3px 6px; background: #f1f5f9; border-radius: 3px; margin-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #1e3a5f; color: white; padding: 4px 6px; text-align: left; font-size: 7px; text-transform: uppercase; }
        td { padding: 3px 6px; border-bottom: 1px solid #e5e7eb; font-size: 8px; }
        tr:nth-child(even) { background: #f9fafb; }
        .tasks-list { margin: 0; padding-left: 10px; font-size: 7px; color: #4b5563; }
        .footer { margin-top: 14px; padding-top: 6px; border-top: 1px solid #e5e7eb; font-size: 6px; color: #9ca3af; text-align: center; }
        .badge { display: inline-block; padding: 1px 4px; border-radius: 2px; font-size: 7px; }
        .badge-critical { background: #fee2e2; color: #dc2626; }
        .badge-high { background: #fef3c7; color: #b45309; }
        .badge-normal { background: #dbeafe; color: #1d4ed8; }
        .badge-unlocked { background: #fef3c7; color: #b45309; }
        .badge-locked { background: #dbeafe; color: #1d4ed8; }
    </style>
</head>
<body>

<div class="header">
    <div>
        <h1>Daily Planning</h1>
        <div style="font-size:8px;color:#6b7280;">Week {{ $weekNumber }} {{ $year }}</div>
    </div>
    <div class="meta">
        <div>Generated: {{ now()->format('M d, Y H:i') }}</div>
        <div>{{ $plannings->count() }} assignments today</div>
    </div>
</div>

<div class="date-banner">
    {{ $date->format('l') }} — {{ $date->format('d F Y') }}
</div>

@php $groupedByTeam = $plannings->groupBy(function($p) { return $p->team?->name ?? 'No Team'; }); @endphp

@foreach($groupedByTeam as $teamName => $teamPlannings)
    <div class="team-group">
        <div class="team-group-title">{{ $teamName }} ({{ $teamPlannings->count() }})</div>
        <table>
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Shift</th>
                    <th>Time</th>
                    <th>Hours</th>
                    <th>Status</th>
                    <th>Tasks</th>
                </tr>
            </thead>
            <tbody>
                @foreach($teamPlannings as $p)
                    <tr>
                        <td><strong>{{ $p->user->name }}</strong></td>
                        <td>{{ $p->shift->name }}</td>
                        <td>{{ substr($p->shift->start_time, 0, 5) }} — {{ substr($p->shift->end_time, 0, 5) }}</td>
                        <td>{{ $p->shift?->duration_hours ?? 0 }}h</td>
                        <td>
                            @if($p->is_locked)
                                <span class="badge badge-locked">Locked</span>
                            @else
                                <span class="badge badge-unlocked">Unlocked</span>
                            @endif
                        </td>
                        <td>
                            @if($p->tasks->isNotEmpty())
                                <ul class="tasks-list">
                                    @foreach($p->tasks as $task)
                                        <li>{{ $task->title }} <span style="color:#6b7280;">[{{ $task->status }}]</span></li>
                                    @endforeach
                                </ul>
                            @else
                                <span style="color:#9ca3af;">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endforeach

<div class="footer">
    Dispatch Live — Workforce Management System — Page 1/1 — Generated {{ now()->format('Y-m-d H:i:s') }}
</div>

</body>
</html>
