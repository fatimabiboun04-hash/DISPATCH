<?php

namespace App\Services;

use App\Jobs\SendLeaveRequestEmailJob;
use App\Mail\AbsenceDetectedMail;
use App\Models\LeaveRequest;
use App\Models\Planning;
use App\Models\Pointage;
use App\Models\Team;
use App\Models\User;
use App\Notifications\InAppNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

class NotificationService
{
    protected function notify(User $user, string $type, string $message, array $extra = []): void
    {
        $user->notify(new InAppNotification($type, $message, $extra));
    }

    protected function notifyAdmins(string $type, string $message, array $extra = []): void
    {
        $admins = User::admins()->active()->get();
        if ($admins->isNotEmpty()) {
            Notification::send($admins, new InAppNotification($type, $message, $extra));
        }
    }

    protected function notifyAdminsAndEmployee(User $employee, string $type, string $message, array $extra = []): void
    {
        $recipients = collect([$employee]);
        $admins = User::admins()->active()->get();
        $recipients = $recipients->concat($admins);

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new InAppNotification($type, $message, $extra));
        }
    }

    // ── Leave ─────────────────────────────────────────────────

    public function notifyLeaveSubmitted(LeaveRequest $leave): void
    {
        $this->notifyAdmins(
            'leave_submitted',
            "{$leave->user->name} a soumis une demande de congé",
            ['leave_id' => $leave->id, 'action' => 'submitted'],
        );
    }

    public function notifyLeaveApproved(LeaveRequest $leave): void
    {
        $this->notify(
            $leave->user,
            'leave_approved',
            'Votre demande de congé a été approuvée',
            ['leave_id' => $leave->id, 'action' => 'approved'],
        );
    }

    public function notifyLeaveRejected(LeaveRequest $leave): void
    {
        $this->notify(
            $leave->user,
            'leave_rejected',
            'Votre demande de congé a été refusée',
            ['leave_id' => $leave->id, 'action' => 'rejected'],
        );
    }

    // ── Planning ───────────────────────────────────────────────

    public function notifyPlanningCreated(Planning $planning): void
    {
        if (! $planning->user) {
            return;
        }

        $this->notify(
            $planning->user,
            'planning',
            "Nouvelle affectation le {$planning->date->format('d/m')} : {$planning->shift->name}",
            ['planning_id' => $planning->id, 'action' => 'created'],
        );
    }

    public function notifyPlanningUpdated(Planning $planning): void
    {
        if (! $planning->user) {
            return;
        }

        $this->notify(
            $planning->user,
            'planning',
            "Votre planning du {$planning->date->format('d/m')} a été modifié",
            ['planning_id' => $planning->id, 'action' => 'updated'],
        );
    }

    public function notifyPlanningAssigned(User $user, Planning $planning): void
    {
        $this->notify(
            $user,
            'planning',
            "Nouvelle affectation le {$planning->date->format('d/m')} : {$planning->shift->name}",
            ['planning_id' => $planning->id, 'action' => 'assigned'],
        );
    }

    public function notifyPlanningDeleted(Planning $planning): void
    {
        $message = "Affectation supprimée pour le {$planning->date->format('d/m')}";

        if ($planning->user) {
            $this->notify(
                $planning->user,
                'planning',
                $message,
                ['planning_id' => $planning->id, 'action' => 'deleted'],
            );
        }
    }

    // ── Pointage ───────────────────────────────────────────────

    public function notifyPointageFlagged(Pointage $pointage): void
    {
        $this->notifyAdmins(
            'late_checkin',
            "Pointage suspect : {$pointage->user->name} — {$pointage->flag_reason}",
            ['pointage_id' => $pointage->id],
        );
    }

    public function notifyFlagVerified(Pointage $pointage): void
    {
        $type = $pointage->is_flagged ? 'flagged' : ($pointage->delay_minutes > 0 ? 'late' : 'on_time');

        $this->notify(
            $pointage->user,
            'flag_verified',
            $pointage->is_flagged
                ? 'Votre pointage du '.$pointage->check_in_at->format('d/m').' a été marqué comme invalide'
                : 'Votre pointage du '.$pointage->check_in_at->format('d/m').' a été vérifié et validé',
            ['pointage_id' => $pointage->id, 'status' => $type],
        );
    }

    public function notifyAbsenceDetected(string $employeeName, array $absentee): void
    {
        $this->notifyAdmins(
            'absence',
            "{$employeeName} n'a pas pointé aujourd'hui",
            ['absence' => $absentee],
        );
    }

    // ── Team ───────────────────────────────────────────────────

    public function notifyTeamAssigned(User $user, Team $team): void
    {
        $this->notify(
            $user,
            'team_assigned',
            "Vous avez été ajouté à l'équipe {$team->name}",
            ['team_id' => $team->id],
        );
    }

    public function notifyTeamRemoved(User $user, Team $team): void
    {
        $this->notify(
            $user,
            'team_removed',
            "Vous avez été retiré de l'équipe {$team->name}",
            ['team_id' => $team->id],
        );
    }

    // ── Rating ─────────────────────────────────────────────────

    public function notifyRatingGiven(User $employee, string $ratingType): void
    {
        $messages = [
            'excellent' => 'Vous avez reçu une évaluation Excellent',
            'warning' => 'Vous avez reçu un avertissement',
        ];

        $this->notify(
            $employee,
            'rating_received',
            $messages[$ratingType] ?? 'Votre évaluation a été mise à jour',
            ['rating_type' => $ratingType],
        );
    }

    // ── Employee ───────────────────────────────────────────────

    public function notifyEmployeeCreated(User $employee): void
    {
        $this->notify(
            $employee,
            'employee_welcome',
            'Bienvenue sur Dispatch Live ! Votre compte a été créé.',
            ['employee_id' => $employee->id],
        );
    }

    // ── Mail dispatch helpers ──────────────────────────────────

    public function sendLeaveSubmittedEmail(LeaveRequest $leave): void
    {
        SendLeaveRequestEmailJob::dispatch($leave, 'admin', 'submitted');
    }

    public function sendLeaveApprovedEmail(LeaveRequest $leave): void
    {
        SendLeaveRequestEmailJob::dispatch($leave, 'employee', 'approved');
    }

    public function sendLeaveRejectedEmail(LeaveRequest $leave): void
    {
        SendLeaveRequestEmailJob::dispatch($leave, 'employee', 'rejected');
    }

    public function sendAbsenceDetectedMail(string $adminEmail, array $absentee): void
    {
        Mail::to($adminEmail)->queue(new AbsenceDetectedMail($absentee));
    }
}
