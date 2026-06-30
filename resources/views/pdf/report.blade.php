<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ ucfirst($report->type) }} Report #{{ $report->id }}</title>
    <style>
        @page { margin: 15mm 12mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1f2937; line-height: 1.4; }
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 3px solid #3B82F6; padding-bottom: 8px; margin-bottom: 12px; }
        .header h1 { font-size: 18px; color: #1e3a5f; margin: 0; }
        .header .meta { font-size: 8px; color: #6b7280; text-align: right; }
        .summary-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; margin-bottom: 14px; }
        .summary-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 4px; padding: 6px 8px; }
        .summary-card .label { font-size: 7px; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }
        .summary-card .value { font-size: 14px; font-weight: bold; color: #1e3a5f; margin-top: 2px; }
        .section-title { font-size: 11px; font-weight: bold; color: #1e3a5f; border-bottom: 1px solid #cbd5e1; padding-bottom: 4px; margin: 12px 0 6px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 7.5px; }
        th { background: #3B82F6; color: white; padding: 5px 4px; text-align: left; font-weight: 600; font-size: 7px; text-transform: uppercase; }
        td { padding: 3px 4px; border-bottom: 1px solid #e5e7eb; }
        tr:nth-child(even) { background: #f9fafb; }
        .badge { display: inline-block; padding: 1px 5px; border-radius: 3px; font-size: 7px; font-weight: bold; }
        .badge-locked { background: #dbeafe; color: #1d4ed8; }
        .badge-unlocked { background: #fef3c7; color: #b45309; }
        .badge-absent { background: #fee2e2; color: #dc2626; }
        .badge-on-time { background: #dcfce7; color: #16a34a; }
        .badge-late { background: #fef3c7; color: #b45309; }
        .grade-A { color: #16a34a; }
        .grade-B { color: #2563eb; }
        .grade-C { color: #b45309; }
        .grade-D { color: #dc2626; }
        .footer { margin-top: 16px; padding-top: 8px; border-top: 1px solid #e5e7eb; font-size: 7px; color: #9ca3af; text-align: center; }
        .page-break { page-break-after: always; }
        .two-col { display: flex; gap: 10px; }
        .two-col > div { flex: 1; }
        .kpi-row { display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 8px; }
        .kpi-item { background: #f1f5f9; border-radius: 3px; padding: 4px 8px; font-size: 8px; min-width: 80px; text-align: center; }
        .kpi-item .kpi-value { font-weight: bold; font-size: 12px; color: #1e3a5f; }
        .kpi-item .kpi-label { font-size: 6.5px; color: #64748b; text-transform: uppercase; }
        .overtime-pos { color: #dc2626; }
        .normal-hours { color: #16a34a; }
    </style>
</head>
<body>

<div class="header">
    <div>
        <h1>Dispatch Live — {{ ucfirst($report->type) }} Report</h1>
        <div style="font-size:8px;color:#6b7280;margin-top:2px;">{{ $report->start_date->format('M d, Y') }} — {{ $report->end_date->format('M d, Y') }}</div>
    </div>
    <div class="meta">
        <div>Report #{{ $report->id }}</div>
        <div>Generated: {{ $report->updated_at->format('M d, Y H:i') }}</div>
        <div>By: {{ $report->generator?->name ?? 'System' }}</div>
    </div>
</div>

@if(isset($data['quality_score']) && is_array($data['quality_score']) && isset($data['quality_score']['score']))
<div class="summary-grid">
    <div class="summary-card">
        <div class="label">Quality Score</div>
        <div class="value {{ 'grade-' . ($data['quality_score']['grade'] ?? 'N') }}">{{ $data['quality_score']['score'] ?? 'N/A' }} <span style="font-size:10px;">({{ $data['quality_score']['grade'] ?? 'N/A' }})</span></div>
    </div>
    @if(isset($data['quality_score']['stats']))
        @php $qs = $data['quality_score']['stats']; @endphp
        <div class="summary-card">
            <div class="label">Assignments</div>
            <div class="value">{{ $qs['total_assignments'] ?? 0 }}</div>
        </div>
        <div class="summary-card">
            <div class="label">Employees</div>
            <div class="value">{{ $qs['employees_assigned'] ?? 0 }}</div>
        </div>
        <div class="summary-card">
            <div class="label">Overtime</div>
            <div class="value">{{ $qs['overtime_employees'] ?? 0 }}</div>
        </div>
    @endif
</div>
@endif

<div class="kpi-row">
    @foreach(['Total Assignments','Locked','Unlocked','Total Hours','Overtime Employees','Under Hours Employees','Avg Rating','Coverage Avg (%)','Task Count','Pause Count'] as $kpi)
        @if(isset($data['summary'][$kpi]))
        <div class="kpi-item">
            <div class="kpi-value @if(str_contains($kpi, 'Overtime') || $kpi === 'Late Check-ins') overtime-pos @elseif(str_contains($kpi, 'On-Time') || $kpi === 'Coverage') normal-hours @endif">{{ $data['summary'][$kpi] }}</div>
            <div class="kpi-label">{{ $kpi }}</div>
        </div>
        @endif
    @endforeach
</div>

{{-- ASSIGNMENTS TABLE --}}
@if(isset($data['assignments']) && count($data['assignments']) > 1)
<div class="section-title">Assignments ({{ count($data['assignments']) - 1 }})</div>
<table>
    <thead>
        <tr>
            @foreach($data['assignments'][0] as $header)
                <th>{{ $header }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach(array_slice($data['assignments'], 1) as $row)
            <tr>
                @foreach($row as $idx => $cell)
                    <td>
                        @if($idx === 4)
                            <span class="badge badge-{{ $cell === 'on_time' ? 'on-time' : ($cell === 'late' ? 'late' : 'absent') }}">{{ ucfirst($cell) }}</span>
                        @elseif($idx === 7)
                            <span class="badge badge-{{ $cell === 'Yes' ? 'locked' : 'unlocked' }}">{{ $cell }}</span>
                        @else
                            {{ $cell }}
                        @endif
                    </td>
                @endforeach
            </tr>
        @endforeach
    </tbody>
</table>
@endif

{{-- SHIFT DISTRIBUTION --}}
@if(!empty($data['shift_distribution']))
<div class="two-col">
    <div>
        <div class="section-title">Shift Distribution</div>
        <table>
            <thead><tr><th>Shift</th><th>Count</th><th>%</th></tr></thead>
            <tbody>
                @foreach($data['shift_distribution'] as $sd)
                    <tr><td>{{ $sd['name'] }}</td><td>{{ $sd['count'] }}</td><td>{{ $sd['percentage'] }}%</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div>
        <div class="section-title">Team Coverage</div>
        <table>
            <thead><tr><th>Team</th><th>Assigned</th><th>Coverage</th></tr></thead>
            <tbody>
                @foreach($data['coverage_by_team'] as $tc)
                    <tr><td>{{ $tc['team'] }}</td><td>{{ $tc['assigned'] }}</td><td>{{ $tc['coverage'] }}%</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

{{-- COVERAGE BY DAY + TASK DISTRIBUTION --}}
@if(!empty($data['coverage_by_day']))
<div class="two-col">
    <div>
        <div class="section-title">Daily Coverage</div>
        <table>
            <thead><tr><th>Day</th><th>Date</th><th>Assigned</th><th>Checked In</th><th>%</th></tr></thead>
            <tbody>
                @foreach($data['coverage_by_day'] as $cd)
                    <tr>
                        <td>{{ $cd['day'] }}</td>
                        <td>{{ $cd['date'] }}</td>
                        <td>{{ $cd['assigned'] }}</td>
                        <td>{{ $cd['checked_in'] }}</td>
                        <td>{{ $cd['coverage'] }}%</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div>
        @if(!empty($data['task_status_distribution']))
        <div class="section-title">Task Status</div>
        <table>
            <thead><tr><th>Status</th><th>Count</th></tr></thead>
            <tbody>
                @foreach($data['task_status_distribution'] as $ts)
                    <tr><td>{{ ucfirst($ts['status']) }}</td><td>{{ $ts['count'] }}</td></tr>
                @endforeach
            </tbody>
        </table>
        @endif
        @if(!empty($data['task_priority_distribution']))
        <div style="margin-top:6px;">
            <div class="section-title">Task Priority</div>
            <table>
                <thead><tr><th>Priority</th><th>Count</th></tr></thead>
                <tbody>
                    @foreach($data['task_priority_distribution'] as $tp)
                        <tr><td>{{ ucfirst($tp['priority']) }}</td><td>{{ $tp['count'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>
@endif

{{-- OVERTIME + MISSING EMPLOYEES --}}
@if(!empty($data['overtime_employees']) || !empty($data['missing_employees']))
<div class="two-col">
    @if(!empty($data['overtime_employees']))
    <div>
        <div class="section-title">Overtime Employees ({{ count($data['overtime_employees']) }})</div>
        <table>
            <thead><tr><th>Employee</th><th>Hours</th><th>Limit</th><th>Overtime</th></tr></thead>
            <tbody>
                @foreach($data['overtime_employees'] as $oe)
                    <tr><td>{{ $oe['name'] }}</td><td>{{ $oe['hours'] }}</td><td>{{ $oe['limit'] }}</td><td class="overtime-pos">+{{ $oe['overtime'] }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
    @if(!empty($data['missing_employees']))
    <div>
        <div class="section-title">Unassigned Employees ({{ count($data['missing_employees']) }})</div>
        <table>
            <thead><tr><th>Employee</th><th>Team</th></tr></thead>
            <tbody>
                @foreach($data['missing_employees'] as $me)
                    <tr><td>{{ $me['name'] }}</td><td>{{ $me['team'] }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>
@endif

<div class="footer">
    Dispatch Live — Workforce Management System — Generated {{ now()->format('Y-m-d H:i:s') }} — Page 1/1
</div>

</body>
</html>
