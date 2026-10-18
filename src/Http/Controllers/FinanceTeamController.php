<?php

namespace SecureAccess\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use SecureAccess\Models\DepartmentAssignment;
use SecureAccess\SecureAccess;
use SecureAccess\Services\DepartmentAssignmentService;

class FinanceTeamController extends Controller
{
    public function index(DepartmentAssignmentService $service): Response
    {
        $service->authorize($this->actor());
        $key = SecureAccess::newUserQuery()->getModel()->getKeyName();
        $adults = SecureAccess::newUserQuery()->where('registration_role', 'self_advocate')->get([$key, 'name'])
            ->map(fn ($user) => ['id' => (string) $user->getKey(), 'name' => $user->name]);
        $officers = $service->eligibleOfficers('finance-accounting')->get([$key, 'name'])
            ->map(fn ($user) => ['id' => (string) $user->getKey(), 'name' => $user->name]);
        $assignments = DepartmentAssignment::query()->where('department', 'finance-accounting')->whereNotNull('assignee_id')->get();
        $audits = DB::table('secure_access_department_assignment_audits')
            ->whereIn('assignment_id', DepartmentAssignment::query()->where('department', 'finance-accounting')->select('id'))
            ->orderByDesc('id')->paginate(50);

        return response()->view('secure-access::finance.index', compact('adults', 'officers', 'assignments', 'audits'))
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    public function store(Request $request, DepartmentAssignmentService $service): RedirectResponse
    {
        $service->authorize($this->actor());
        $data = $request->validate([
            'self_advocate_id' => ['required', 'string', 'max:255'],
            'officer_id' => ['required', 'string', 'max:255'],
        ]);
        $service->assign($this->actor(), $data['self_advocate_id'], 'finance-accounting', $data['officer_id']);

        return redirect()->route('secure-access.finance.index');
    }

    public function destroy(DepartmentAssignment $assignment, DepartmentAssignmentService $service): RedirectResponse
    {
        $service->authorize($this->actor());
        abort_unless($assignment->department === 'finance-accounting', 404);
        $service->remove($this->actor(), $assignment);

        return redirect()->route('secure-access.finance.index');
    }

    private function actor(): Model
    {
        $user = SecureAccess::guard()->user();
        abort_unless($user instanceof Model && SecureAccess::mfaVerified($user), 403);

        return $user;
    }
}
