<?php

namespace App\Jobs;

use App\Mail\LeaveRequestNotificationMail;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendLeaveRequestEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public LeaveRequest $leaveRequest;

    public string $recipientType;

    public string $action;

    public function __construct(LeaveRequest $leaveRequest, string $recipientType, string $action)
    {
        $this->leaveRequest = $leaveRequest;
        $this->recipientType = $recipientType;
        $this->action = $action;
    }

    public function handle(): void
    {
        if ($this->recipientType === 'admin') {
            $admins = User::admins()->active()->get();
            foreach ($admins as $admin) {
                Mail::to($admin->email)->queue(
                    new LeaveRequestNotificationMail($this->leaveRequest, 'admin', $this->action)
                );
            }

            return;
        }

        // Employee notification
        $employee = $this->leaveRequest->user;
        if ($employee && $employee->email) {
            Mail::to($employee->email)->queue(
                new LeaveRequestNotificationMail($this->leaveRequest, 'employee', $this->action)
            );
        }
    }
}
