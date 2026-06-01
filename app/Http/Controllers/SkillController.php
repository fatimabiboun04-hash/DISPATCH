<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;

class SkillController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => []
        ]);
    }
}