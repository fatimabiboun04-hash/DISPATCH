<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Planning Notification</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #3B82F6; color: white; padding: 20px; text-align: center; }
        .content { background: #f9fafb; padding: 20px; margin-top: 10px; }
        .detail { margin: 10px 0; }
        .label { font-weight: bold; color: #6b7280; }
        .footer { text-align: center; color: #9ca3af; margin-top: 20px; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{{ $recipientType === 'admin' ? 'Planning Completed' : 'Your Planning Assignment' }}</h1>
        </div>
        <div class="content">
            @if($recipientType === 'admin')
                <p>A new planning has been completed and assigned to <strong>{{ $employee->name }}</strong>.</p>
            @else
                <p>Hello <strong>{{ $employee->name }}</strong>, your planning assignment has been finalized.</p>
            @endif

            <div class="detail">
                <span class="label">Date:</span> {{ $date }}
            </div>
            <div class="detail">
                <span class="label">Shift:</span> {{ $shift->name }} ({{ $shift->start_time->format('H:i') }} - {{ $shift->end_time->format('H:i') }})
            </div>
            @if($team)
            <div class="detail">
                <span class="label">Team:</span> {{ $team->name }}
            </div>
            @endif
            @if($planning->notes)
            <div class="detail">
                <span class="label">Notes:</span> {{ $planning->notes }}
            </div>
            @endif
        </div>
        <div class="footer">
            <p>Dispatch Live - Automated Notification</p>
        </div>
    </div>
</body>
</html>