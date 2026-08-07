<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SystemHealthService;
use Illuminate\View\View;

class SystemHealthController extends Controller
{
    public function __construct(private readonly SystemHealthService $systemHealth) {}

    public function __invoke(): View
    {
        $this->authorize('view-diagnostic-info');

        return view('admin.settings.system-health', [
            'systemHealth' => $this->systemHealth->snapshot(),
        ]);
    }
}
