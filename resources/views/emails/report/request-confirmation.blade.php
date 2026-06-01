<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Report Request Confirmation</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #10B981; color: white; padding: 20px; text-align: center; }
        .content { background: #f9fafb; padding: 20px; margin-top: 10px; }
        .detail { margin: 10px 0; }
        .label { font-weight: bold; color: #6b7280; }
        .footer { text-align: center; color: #9ca3af; margin-top: 20px; font-size: 12px; }
        .status { display: inline-block; background: #dbeafe; color: #1e40af; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Report Request Received</h1>
        </div>
        <div class="content">
            <p>Your report request has been received and is now being processed.</p>
            
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
                <span class="label">Status:</span> <span class="status">Processing</span>
            </div>

            <p style="margin-top: 20px;">You will receive another email once your report is ready for download.</p>
        </div>
        <div class="footer">
            <p>Dispatch Live - Automated Notification</p>
        </div>
    </div>
</body>
</html>