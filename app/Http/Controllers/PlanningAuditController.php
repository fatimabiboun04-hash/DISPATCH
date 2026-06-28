<?php

namespace App\Http\Controllers;

use App\Models\PlanningAudit;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class PlanningAuditController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $query = PlanningAudit::with('user');

        if ($request->has('planning_id')) {
            $query->where('planning_id', $request->planning_id);
        }

        if ($request->has('action')) {
            $query->where('action', $request->action);
        }

        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $audits = $query->latest('created_at')->paginate(50);

        return $this->paginatedResponse($audits);
    }
}
