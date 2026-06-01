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
        // On update (PUT/PATCH), do NOT enforce future-date constraint
        $dateRules = $this->isMethod('POST')|| $this->isMethod('PUT') || $this->isMethod('PATCH')
            ? ['required', 'date', 'after_or_equal:today']
            : ['required', 'date'];
 
        return [
            'user_id'  => ['required', 'exists:users,id'],
            'shift_id' => ['required', 'exists:shifts,id'],
            'date'     => $dateRules,
            'team_id'  => ['nullable', 'exists:teams,id'],
            'notes'    => ['nullable', 'string', 'max:1000'],
        ];
    }
}
