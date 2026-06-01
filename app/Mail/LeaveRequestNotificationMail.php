<?php

namespace App\Mail;

use App\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LeaveRequestNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public LeaveRequest $leaveRequest;
    public string $recipientType; // 'admin' | 'employee'
    public string $action;        // 'submitted' | 'approved' | 'rejected'

    public function __construct(LeaveRequest $leaveRequest, string $recipientType, string $action)
    {
        $this->leaveRequest = $leaveRequest;
        $this->recipientType = $recipientType;
        $this->action = $action;
    }

    public function envelope(): Envelope
    {
        $subjects = [
            'submitted' => 'New Leave Request Submitted',
            'approved'  => 'Your Leave Request Has Been Approved',
            'rejected'  => 'Your Leave Request Has Been Rejected',
        ];

        return new Envelope(
            subject: $subjects[$this->action] ?? 'Leave Request Update',
        );
    }

    public function content(): Content
    {
        $messages = [
            'submitted' => 'A new leave request has been submitted by an employee.',
            'approved'  => 'Your leave request has been approved by admin.',
            'rejected'  => 'Your leave request has been rejected by admin.',
        ];

        return new Content(
            view: 'emails.leave.notification',
            with: [
                'message' => $messages[$this->action],
                'leaveRequest' => $this->leaveRequest,
                'employee' => $this->leaveRequest->user,
                'approver' => $this->leaveRequest->approver,
                'action' => $this->action,
            ],
        );
    }
}