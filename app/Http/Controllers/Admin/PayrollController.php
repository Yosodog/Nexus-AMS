<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Payroll\StorePayrollGradeRequest;
use App\Http\Requests\Admin\Payroll\StorePayrollMemberRequest;
use App\Http\Requests\Admin\Payroll\UpdatePayrollGradeRequest;
use App\Http\Requests\Admin\Payroll\UpdatePayrollMemberRequest;
use App\Models\Alliance;
use App\Models\PayrollGrade;
use App\Models\PayrollMember;
use App\Services\PayrollService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class PayrollController extends Controller
{
    public function __construct(private readonly PayrollService $payrollService) {}

    public function index(): View
    {
        Gate::authorize('view_payroll');

        $grades = PayrollGrade::query()
            ->orderBy('name')
            ->get();

        $dailyAmounts = $grades->mapWithKeys(
            fn (PayrollGrade $grade) => [$grade->id => $this->payrollService->calculateDailyAmount((string) $grade->weekly_amount)]
        );

        $members = PayrollMember::query()
            ->with(['grade', 'nation', 'nation.alliance'])
            ->orderByDesc('is_active')
            ->orderBy('nation_id')
            ->get();

        $allianceIds = $members
            ->pluck('nation.alliance_id')
            ->filter()
            ->unique()
            ->values();

        $allianceNames = Alliance::query()
            ->whereIn('id', $allianceIds)
            ->pluck('name', 'id');

        $weeklyTotal = '0.00';
        $dailyTotal = '0.00';

        foreach ($members as $member) {
            if (! $member->is_active || ! $member->grade?->is_enabled) {
                continue;
            }

            $weeklyTotal = bcadd($weeklyTotal, (string) $member->grade->weekly_amount, 2);
            $dailyTotal = bcadd($dailyTotal, $dailyAmounts[$member->payroll_grade_id] ?? '0.00', 2);
        }

        return view('admin.payroll.index', [
            'grades' => $grades,
            'members' => $members,
            'dailyAmounts' => $dailyAmounts,
            'weeklyTotal' => $weeklyTotal,
            'dailyTotal' => $dailyTotal,
            'allianceNames' => $allianceNames,
        ]);
    }

    public function storeGrade(StorePayrollGradeRequest $request): RedirectResponse
    {
        $this->payrollService->createGrade($request->validated(), $request->user());

        return redirect()->route('admin.payroll.index')->with([
            'alert-message' => 'Payroll grade created.',
            'alert-type' => 'success',
        ]);
    }

    public function updateGrade(UpdatePayrollGradeRequest $request, PayrollGrade $payrollGrade): RedirectResponse
    {
        $this->payrollService->updateGrade($payrollGrade, $request->validated());

        return redirect()->route('admin.payroll.index')->with([
            'alert-message' => 'Payroll grade updated.',
            'alert-type' => 'success',
        ]);
    }

    public function destroyGrade(PayrollGrade $payrollGrade): RedirectResponse
    {
        Gate::authorize('edit_payroll');

        $this->payrollService->deleteGrade($payrollGrade);

        return redirect()->route('admin.payroll.index')->with([
            'alert-message' => 'Payroll grade removed.',
            'alert-type' => 'success',
        ]);
    }

    public function storeMember(StorePayrollMemberRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $this->payrollService->addMember($data['nation_id'], $data['payroll_grade_id'], $request->user());

        return redirect()->route('admin.payroll.index')->with([
            'alert-message' => 'Payroll member saved.',
            'alert-type' => 'success',
        ]);
    }

    public function updateMember(UpdatePayrollMemberRequest $request, PayrollMember $payrollMember): RedirectResponse
    {
        $data = $request->validated();

        $this->payrollService->updateMember(
            $payrollMember,
            $data['payroll_grade_id'],
            $request->boolean('is_active')
        );

        return redirect()->route('admin.payroll.index')->with([
            'alert-message' => 'Payroll member updated.',
            'alert-type' => 'success',
        ]);
    }

    public function destroyMember(PayrollMember $payrollMember): RedirectResponse
    {
        Gate::authorize('edit_payroll');

        $this->payrollService->removeMember($payrollMember);

        return redirect()->route('admin.payroll.index')->with([
            'alert-message' => 'Payroll member removed.',
            'alert-type' => 'success',
        ]);
    }
}
