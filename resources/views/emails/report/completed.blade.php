<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Report Ready</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #10B981; color: white; padding: 20px; text-align: center; }
        .content { background: #f9fafb; padding: 20px; margin-top: 10px; }
        .detail { margin: 10px 0; }
        .label { font-weight: bold; color: #6b7280; }
        .footer { text-align: center; color: #9ca3af; margin-top: 20px; font-size: 12px; }
        .success { display: inline-block; background: #d1fae5; color: #065f46; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Your Report is Ready</h1>
        </div>
        <div class="content">
            <p>Your requested report has been generated successfully.</p>
            
            <div class="detail">
                <span class="label">Report ID:</span> #{{ $report->id }}
            </div>
            <div class="detail">
                <span class="label">Type:</span> {{ $type }}
            </div>
            <div class="detail">
                <span class="label">Date Range:</span> {{ $dateRange }}
            </div>
            <div class="detail">
                <span class="label">Format:</span> {{ $fileType }}
            </div>
            <div class="detail">
                <span class="label">Generated At:</span> {{ $generatedAt }}
            </div>
            <div class="detail">
                <span class="label">Status:</span> <span class="success">Completed</span>
            </div>

            <p style="margin-top: 20px;">The report is attached to this email. You can also download it from the application.</p>
        </div>
        <div class="footer">
            <p>Dispatch Live - Automated Notification</p>
        </div>
    </div>
</body>
</html>