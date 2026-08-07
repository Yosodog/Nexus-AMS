<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless(
            $request->user()?->canAny((array) config('admin-settings.access_permissions')),
            403,
        );

        return view('admin.settings');
    }
}
