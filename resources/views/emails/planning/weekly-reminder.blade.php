<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Weekly Planning Review</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #F59E0B; color: white; padding: 20px; text-align: center; }
        .content { background: #f9fafb; padding: 20px; margin-top: 10px; }
        .footer { text-align: center; color: #9ca3af; margin-top: 20px; font-size: 12px; }
        .button { display: inline-block; background: #3B82F6; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px; margin-top: 15px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Weekly Planning Review Reminder</h1>
        </div>
        <div class="content">
            <p>Hello Admin,</p>
            <p>This is your weekly reminder to review and finalize the planning for:</p>
            <h2 style="text-align: center; color: #3B82F6;">Week {{ $weekNumber }}, {{ $year }}</h2>
            <p style="text-align: center; color: #6b7280;">{{ $weekRange }}</p>
            <p>Please review all employee assignments, check for conflicts, and lock the planning before the Friday deadline.</p>
            <div style="text-align: center;">
                <a href="{{ config('app.url') }}/admin/planning" class="button">Review Planning</a>
            </div>
        </div>
        <div class="footer">
            <p>Dispatch Live - Automated Weekly Reminder</p>
        </div>
    </div>
</body>
</html>