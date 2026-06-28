<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePlanningRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        // Creating new planning must be current-week-safe; updating existing records may edit historical corrections.
        $weekStart = now()->startOfWeek()->toDateString();
        $dateRules = $this->isMethod('POST')
            ? ['required', 'date', "after_or_equal:{$weekStart}"]
            : ['required', 'date'];

        return [

            'user_id' => ['required', 'exists:users,id'],
            'shift_id' => ['required', 'exists:shifts,id'],
            'date' => $dateRules,
            'team_id' => ['nullable', 'exists:teams,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
