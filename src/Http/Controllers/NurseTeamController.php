<?php

namespace SecureAccess\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use SecureAccess\Models\TeamMember;
use SecureAccess\SecureAccess;
use SecureAccess\Services\NurseTeamService;

class NurseTeamController extends Controller
{
    public function index(Request $request, NurseTeamService $service): Response
    {
        $service->authorize($this->actor());
        $key = SecureAccess::newUserQuery()->getModel()->getKeyName();
        $adults = SecureAccess::newUserQuery()->where('registration_role', 'self_advocate')
            ->get([$key, 'name'])->map(fn ($user) => ['id' => (string) $user->getKey(), 'name' => $user->name]);
        $nurses = $service->eligibleNurses()->get([$key, 'name'])
            ->map(fn ($user) => ['id' => (string) $user->getKey(), 'name' => $user->name]);
        $assignments = TeamMember::query()->whereNotNull('nurse_assigned_by')->whereNull('ended_at')
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('secure_access_department_assignments')
                ->where('department', 'medical-nursing')
                ->whereColumn('ward_id', 'secure_access_team_members.self_advocate_id')
                ->whereColumn('assignee_id', 'secure_access_team_members.team_member_id'))
            ->orderBy('id')->get(['id', 'self_advocate_id', 'team_member_id', 'nurse_assigned_by', 'nurse_assigned_at']);
        $audits = DB::table('secure_access_nursing_assignment_audits')->orderByDesc('id')->paginate(50);

        return response()->view('secure-access::nursing.index', compact('adults', 'nurses', 'assignments', 'audits'))
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    public function store(Request $request, NurseTeamService $service): RedirectResponse
    {
        $service->authorize($this->actor());
        $data = $request->validate([
            'self_advocate_id' => ['required', 'string', 'max:255'],
            'nurse_id' => ['required', 'string', 'max:255'],
        ]);
        $service->assign($this->actor(), $data['self_advocate_id'], $data['nurse_id']);

        return $this->redirect();
    }

    public function destroy(Request $request, TeamMember $relationship, NurseTeamService $service): RedirectResponse
    {
        $service->remove($this->actor(), $relationship);

        return $this->redirect();
    }

    private function redirect(): RedirectResponse
    {
        return redirect()->route('secure-access.nursing.index')
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    private function actor(): Model
    {
        $user = SecureAccess::guard()->user();
        abort_unless($user && SecureAccess::mfaVerified($user), 403);

        return $user;
    }
}
