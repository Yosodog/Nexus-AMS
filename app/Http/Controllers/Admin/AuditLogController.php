<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('view-diagnostic-info');

        $filters = [
            'category' => $request->string('category')->toString(),
            'action' => $request->string('action')->toString(),
            'outcome' => $request->string('outcome')->toString(),
            'severity' => $request->string('severity')->toString(),
            'actor_type' => $request->string('actor_type')->toString(),
            'actor_id' => $request->string('actor_id')->toString(),
            'subject_type' => $request->string('subject_type')->toString(),
            'subject_id' => $request->string('subject_id')->toString(),
            'request_id' => $request->string('request_id')->toString(),
            'ip' => $request->string('ip')->toString(),
            'q' => $request->string('q')->toString(),
        ];

        $query = AuditLog::query()->orderByDesc('occurred_at');

        if ($filters['category'] !== '') {
            $query->where('category', $filters['category']);
        }

        if ($filters['action'] !== '') {
            $query->where('action', $filters['action']);
        }

        if ($filters['outcome'] !== '') {
            $query->where('outcome', $filters['outcome']);
        }

        if ($filters['severity'] !== '') {
            $query->where('severity', $filters['severity']);
        }

        if ($filters['actor_type'] !== '') {
            $query->where('actor_type', $filters['actor_type']);
        }

        if ($filters['actor_id'] !== '') {
            $query->where('actor_id', $filters['actor_id']);
        }

        if ($filters['subject_type'] !== '') {
            $query->where('subject_type', $filters['subject_type']);
        }

        if ($filters['subject_id'] !== '') {
            $query->where('subject_id', $filters['subject_id']);
        }

        if ($filters['request_id'] !== '') {
            $query->where('request_id', $filters['request_id']);
        }

        if ($filters['ip'] !== '') {
            $query->where('ip', $filters['ip']);
        }

        if ($filters['q'] !== '') {
            $query->where(function ($builder) use ($filters) {
                $builder->where('message', 'like', '%'.$filters['q'].'%')
                    ->orWhere('actor_name', 'like', '%'.$filters['q'].'%')
                    ->orWhere('action', 'like', '%'.$filters['q'].'%');
            });
        }

        $logs = $query->paginate(50)->withQueryString();

        $categories = AuditLog::query()->distinct()->orderBy('category')->pluck('category');
        $outcomes = AuditLog::query()->distinct()->orderBy('outcome')->pluck('outcome');
        $severities = AuditLog::query()->distinct()->orderBy('severity')->pluck('severity');
        $actorTypes = AuditLog::query()->distinct()->orderBy('actor_type')->pluck('actor_type');

        return view('admin.audit-logs.index', [
            'logs' => $logs,
            'filters' => $filters,
            'categories' => $categories,
            'outcomes' => $outcomes,
            'severities' => $severities,
            'actorTypes' => $actorTypes,
        ]);
    }
}
