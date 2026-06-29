<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Leave Request Update</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 500px; margin: 0 auto; padding: 20px; }
        .box { background: #f3f4f6; padding: 20px; border-radius: 8px; }
        .message { font-size: 16px; margin-bottom: 15px; }
        .detail { font-size: 13px; color: #6b7280; margin: 5px 0; }
        .footer { text-align: center; color: #9ca3af; margin-top: 20px; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="box">
            <p class="message">{{ $notificationMessage }}</p>
            <div class="detail">
                <strong>Employee:</strong> {{ $employee->name }}<br>
                <strong>Type:</strong> {{ ucfirst($leaveRequest->type) }}<br>
                <strong>Period:</strong> {{ $leaveRequest->start_date->format('M d, Y') }} - {{ $leaveRequest->end_date->format('M d, Y') }}<br>
                <strong>Reason:</strong> {{ $leaveRequest->reason }}
            </div>
            @if($action === 'rejected' && $leaveRequest->rejection_reason)
            <div class="detail">
                <strong>Rejection Reason:</strong> {{ $leaveRequest->rejection_reason }}
            </div>
            @endif
        </div>
        <div class="footer">Dispatch Live - Automated Notification</div>
    </div>
</body>
</html>