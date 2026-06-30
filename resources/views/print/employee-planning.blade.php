<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Dispatch Live — {{ $employee->name }} — W{{ $weekNumber }} {{ $year }}</title>
    <style>
        @page { margin: 12mm 10mm; size: A4 portrait; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 9px; color: #1f2937; }
        .header { display: flex; justify-content: space-between; border-bottom: 2px solid #1e3a5f; padding-bottom: 6px; margin-bottom: 10px; }
        .header h1 { font-size: 15px; color: #1e3a5f; margin: 0; }
        .header .meta { font-size: 7px; color: #6b7280; text-align: right; }
        .employee-info { display: flex; gap: 20px; margin-bottom: 12px; padding: 8px; background: #f8fafc; border-radius: 4px; }
        .employee-info .info-item { font-size: 8px; }
        .employee-info .info-item .label { color: #6b7280; font-size: 7px; text-transform: uppercase; }
        .employee-info .info-item .value { font-weight: bold; font-size: 11px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        th { background: #1e3a5f; color: white; padding: 5px 6px; text-align: left; font-size: 7px; text-transform: uppercase; }
        td { padding: 4px 6px; border-bottom: 1px solid #e5e7eb; font-size: 8px; }
        tr:nth-child(even) { background: #f9fafb; }
        .tasks-list { margin: 0; padding-left: 12px; font-size: 7px; color: #4b5563; }
        .pauses-list { margin: 0; padding-left: 12px; font-size: 7px; color: #b45309; }
        .footer { margin-top: 16px; padding-top: 6px; border-top: 1px solid #e5e7eb; font-size: 6px; color: #9ca3af; text-align: center; }
        .badge { display: inline-block; padding: 1px 4px; border-radius: 2px; font-size: 7px; }
        .badge-locked { background: #dbeafe; color: #1d4ed8; }
        .badge-on-time { background: #dcfce7; color: #16a34a; }
        .badge-late { background: #fef3c7; color: #b45309; }
    </style>
</head>
<body>

<div class="header">
    <div>
        <h1>{{ $employee->name }}</h1>
        <div style="font-size:8px;color:#6b7280;">Weekly Planning — W{{ $weekNumber }} ({{ $year }})</div>
    </div>
    <div class="meta">
        <div>Generated: {{ now()->format('M d, Y H:i') }}</div>
        <div>{{ $plannings->count() }} assignments — {{ $totalHours }}h</div>
    </div>
</div>

<div class="employee-info">
    <div class="info-item">
        <div class="label">Total Hours</div>
        <div class="value">{{ $totalHours }}h</div>
    </div>
    <div class="info-item">
        <div class="label">Assignments</div>
        <div class="value">{{ $plannings->count() }}</div>
    </div>
    <div class="info-item">
        <div class="label">Locked</div>
        <div class="value">{{ $plannings->where('is_locked', true)->count() }}</div>
    </div>
    <div class="info-item">
        <div class="label">Tasks</div>
        <div class="value">{{ $plannings->sum(fn($p) => $p->tasks->count()) }}</div>
    </div>
</div>

<table>
    <thead>
        <tr>
            <th>Date</th>
            <th>Day</th>
            <th>Shift</th>
            <th>Team</th>
            <th>Hours</th>
            <th>Status</th>
            <th>Tasks</th>
            <th>Pauses</th>
        </tr>
    </thead>
    <tbody>
        @foreach($plannings as $p)
            <tr>
                <td>{{ $p->date->format('d/m/Y') }}</td>
                <td>{{ $p->date->format('l') }}</td>
                <td>{{ $p->shift->name }} <span style="font-size:7px;color:#6b7280;">({{ substr($p->shift->start_time,0,5) }}-{{ substr($p->shift->end_time,0,5) }})</span></td>
                <td>{{ $p->team?->name ?? 'N/A' }}</td>
                <td>{{ $p->shift?->duration_hours ?? 0 }}h</td>
                <td>
                    @if($p->is_locked)
                        <span class="badge badge-locked">Locked</span>
                    @else
                        <span style="color:#b45309;">Unlocked</span>
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
                <td>
                    @if($p->pauses->isNotEmpty())
                        <ul class="pauses-list">
                            @foreach($p->pauses as $pause)
                                <li>{{ ucfirst($pause->type) }} ({{ $pause->duration_minutes }}min)</li>
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

<div class="footer">
    Dispatch Live — Workforce Management System — Page 1/1 — Generated {{ now()->format('Y-m-d H:i:s') }}
</div>

</body>
</html>
