<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PWHelperService;
use Illuminate\View\View;

class NelDocsController extends Controller
{
    public function __invoke(): View
    {
        $projects = PWHelperService::PROJECTS;

        return view('admin.nel.docs', ['projects' => $projects]);
    }
}
