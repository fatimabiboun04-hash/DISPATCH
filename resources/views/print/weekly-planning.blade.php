<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Dispatch Live — Weekly Planning W{{ $weekNumber }} {{ $year }}</title>
    <style>
        @page { margin: 12mm 10mm; size: A4 landscape; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 8px; color: #1f2937; }
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #1e3a5f; padding-bottom: 6px; margin-bottom: 10px; }
        .header h1 { font-size: 16px; color: #1e3a5f; margin: 0; }
        .header .meta { font-size: 7px; color: #6b7280; text-align: right; }
        .week-days { display: flex; gap: 2px; margin-bottom: 10px; }
        .day-header { flex: 1; text-align: center; font-size: 7px; font-weight: bold; color: #1e3a5f; background: #f1f5f9; padding: 4px 2px; border-radius: 3px; }
        .team-section { margin-bottom: 12px; page-break-inside: avoid; }
        .team-title { font-size: 10px; font-weight: bold; color: #1e3a5f; background: #e2e8f0; padding: 3px 6px; border-radius: 3px; margin-bottom: 4px; }
        .employee-row { display: flex; align-items: center; padding: 2px 0; border-bottom: 1px dotted #e5e7eb; }
        .employee-name { width: 140px; font-weight: 600; font-size: 8px; padding: 2px 4px; flex-shrink: 0; }
        .day-cells { display: flex; gap: 2px; flex: 1; }
        .day-cell { flex: 1; min-height: 20px; padding: 2px 3px; font-size: 6.5px; border-radius: 2px; background: #f9fafb; }
        .day-cell .shift-name { font-weight: bold; color: #1e40af; }
        .day-cell .task-item { color: #6b7280; font-size: 6px; }
        .day-cell .pause-indicator { color: #b45309; font-size: 6px; }
        .empty-day { background: #f3f4f6; color: #9ca3af; text-align: center; font-size: 7px; }
        .locked-day { border-left: 2px solid #3B82F6; background: #eff6ff; }
        .summary-bar { display: flex; gap: 8px; padding: 4px 8px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 4px; margin-top: 8px; font-size: 7px; }
        .summary-bar .stat { text-align: center; }
        .summary-bar .stat .value { font-weight: bold; font-size: 10px; color: #1e3a5f; }
        .summary-bar .stat .label { color: #6b7280; font-size: 6px; text-transform: uppercase; }
        .footer { margin-top: 12px; padding-top: 6px; border-top: 1px solid #e5e7eb; font-size: 6px; color: #9ca3af; text-align: center; }
    </style>
</head>
<body>

<div class="header">
    <div>
        <h1>Dispatch Live — Weekly Planning</h1>
        <div style="font-size:7px;color:#6b7280;">Week {{ $weekNumber }} — {{ $startOfWeek->format('M d') }} to {{ $endOfWeek->format('M d, Y') }}</div>
    </div>
    <div class="meta">
        <div>Generated: {{ now()->format('M d, Y H:i') }}</div>
        <div>Total assignments: {{ $plannings->count() }}</div>
    </div>
</div>

<div class="week-days">
    @foreach($days as $day)
        <div class="day-header">{{ $day['day_name_en'] }}<br>{{ \Carbon\Carbon::parse($day['date'])->format('d/m') }}</div>
    @endforeach
</div>

@foreach($teams as $team)
    @php
        $teamPlannings = $plannings->where('team_id', $team->id);
        $teamEmployees = $team->users;
    @endphp
    @if($teamPlannings->isNotEmpty() || $teamEmployees->isNotEmpty())
    <div class="team-section">
        <div class="team-title" style="border-left: 3px solid {{ $team->color ?? '#3B82F6' }};">{{ $team->name }}</div>

        @foreach($teamEmployees as $employee)
            @php $empPlannings = $teamPlannings->where('user_id', $employee->id); @endphp
            <div class="employee-row">
                <div class="employee-name">{{ $employee->name }}</div>
                <div class="day-cells">
                    @foreach($days as $day)
                        @php
                            $dp = $empPlannings->first(fn ($p) => $p->date->toDateString() === $day['date']);
                        @endphp
                        <div class="day-cell @if($dp && $dp->is_locked) locked-day @elseif(!$dp) empty-day @endif">
                            @if($dp)
                                <div class="shift-name">{{ $dp->shift->name }}</div>
                                <div>{{ substr($dp->shift->start_time, 0, 5) }}-{{ substr($dp->shift->end_time, 0, 5) }}</div>
                                @if($dp->tasks->isNotEmpty())
                                    @foreach($dp->tasks->take(2) as $task)
                                        <div class="task-item">• {{ \Illuminate\Support\Str::limit($task->title, 20) }}</div>
                                    @endforeach
                                @endif
                                @if($dp->is_locked)
                                    <div style="color:#3B82F6;font-size:5px;">🔒</div>
                                @endif
                            @else
                                —
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
    @endif
@endforeach

<div class="summary-bar">
    <div class="stat"><div class="value">{{ $plannings->count() }}</div><div class="label">Assignments</div></div>
    <div class="stat"><div class="value">{{ $plannings->pluck('user_id')->unique()->count() }}</div><div class="label">Employees</div></div>
    <div class="stat"><div class="value">{{ $teams->count() }}</div><div class="label">Teams</div></div>
    <div class="stat"><div class="value">{{ $plannings->where('is_locked', true)->count() }}</div><div class="label">Locked</div></div>
    <div class="stat"><div class="value">{{ $plannings->sum(fn($p) => $p->shift?->duration_hours ?? 0) }}</div><div class="label">Total Hours</div></div>
</div>

<div class="footer">
    Dispatch Live — Workforce Management System — Page 1/1 — Generated {{ now()->format('Y-m-d H:i:s') }}
</div>

</body>
</html>
