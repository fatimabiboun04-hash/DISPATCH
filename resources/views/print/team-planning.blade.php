<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Dispatch Live — {{ $team->name }} — W{{ $weekNumber }} {{ $year }}</title>
    <style>
        @page { margin: 12mm 10mm; size: A4 landscape; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 8px; color: #1f2937; }
        .header { display: flex; justify-content: space-between; border-bottom: 2px solid {{ $team->color ?? '#1e3a5f' }}; padding-bottom: 6px; margin-bottom: 10px; }
        .header h1 { font-size: 15px; color: #1e3a5f; margin: 0; }
        .header .meta { font-size: 7px; color: #6b7280; text-align: right; }
        .team-banner { padding: 6px 10px; background: #f8fafc; border-left: 4px solid {{ $team->color ?? '#3B82F6' }}; border-radius: 3px; margin-bottom: 10px; font-size: 9px; }
        .day-headers { display: flex; gap: 2px; margin-bottom: 6px; margin-left: 120px; }
        .day-header { flex: 1; text-align: center; font-size: 7px; font-weight: bold; color: #1e3a5f; background: #f1f5f9; padding: 3px; border-radius: 2px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #1e3a5f; color: white; padding: 4px 6px; text-align: left; font-size: 7px; position: sticky; top: 0; }
        td { padding: 3px 4px; border-bottom: 1px solid #e5e7eb; font-size: 7px; vertical-align: top; }
        .employee-name { font-weight: bold; width: 120px; white-space: nowrap; }
        .shift-cell { text-align: center; font-size: 7px; }
        .shift-name { font-weight: bold; color: #1e40af; }
        .footer { margin-top: 12px; padding-top: 6px; border-top: 1px solid #e5e7eb; font-size: 6px; color: #9ca3af; text-align: center; }
        .totals-row { background: #f1f5f9; font-weight: bold; }
        .locked { border-left: 2px solid #3B82F6; }
    </style>
</head>
<body>

<div class="header">
    <div>
        <h1>{{ $team->name }}</h1>
        <div style="font-size:8px;color:#6b7280;">Weekly Planning — W{{ $weekNumber }} ({{ $year }})</div>
    </div>
    <div class="meta">
        <div>Generated: {{ now()->format('M d, Y H:i') }}</div>
        <div>{{ $plannings->count() }} assignments — {{ $plannings->pluck('user_id')->unique()->count() }} members</div>
    </div>
</div>

<div class="team-banner">
    Team: {{ $team->name }} — {{ $startOfWeek->format('M d') }} to {{ $endOfWeek->format('M d, Y') }}
    | Members: {{ $employees->count() }}
    | Assignments: {{ $plannings->count() }}
</div>

<div class="day-headers">
    @foreach($days as $day)
        <div class="day-header">{{ $day }}<br>{{ $startOfWeek->copy()->addDays($loop->index)->format('d/m') }}</div>
    @endforeach
</div>

<table>
    <thead>
        <tr>
            <th style="width:120px;">Employee</th>
            @foreach($days as $day)
                <th style="text-align:center;">{{ $day }}</th>
            @endforeach
            <th style="text-align:center;">Total</th>
        </tr>
    </thead>
    <tbody>
        @foreach($employees as $employee)
            @php
                $empPlannings = $plannings->where('user_id', $employee->id);
                $empTotalHours = $empPlannings->sum(fn($p) => $p->shift?->duration_hours ?? 0);
            @endphp
            <tr>
                <td class="employee-name">{{ $employee->name }}</td>
                @foreach($days as $idx => $day)
                    @php
                        $dateStr = $startOfWeek->copy()->addDays($idx)->toDateString();
                        $dp = $empPlannings->first(fn($p) => $p->date->toDateString() === $dateStr);
                    @endphp
                    <td class="shift-cell @if($dp && $dp->is_locked) locked @endif">
                        @if($dp)
                            <div class="shift-name">{{ $dp->shift->name }}</div>
                            <div style="font-size:6px;color:#6b7280;">{{ substr($dp->shift->start_time,0,5) }}-{{ substr($dp->shift->end_time,0,5) }}</div>
                            <div style="font-size:6px;color:#6b7280;">{{ $dp->shift?->duration_hours ?? 0 }}h</div>
                        @else
                            <span style="color:#d1d5db;">—</span>
                        @endif
                    </td>
                @endforeach
                <td style="text-align:center;font-weight:bold;">{{ $empTotalHours }}h</td>
            </tr>
        @endforeach
        <tr class="totals-row">
            <td>Total per day</td>
            @foreach($days as $idx => $day)
                @php
                    $dateStr = $startOfWeek->copy()->addDays($idx)->toDateString();
                    $dayCount = $plannings->filter(fn($p) => $p->date->toDateString() === $dateStr)->count();
                @endphp
                <td style="text-align:center;">{{ $dayCount }}</td>
            @endforeach
            <td style="text-align:center;">{{ $plannings->count() }}</td>
        </tr>
    </tbody>
</table>

<div class="footer">
    Dispatch Live — Workforce Management System — Page 1/1 — Generated {{ now()->format('Y-m-d H:i:s') }}
</div>

</body>
</html>
