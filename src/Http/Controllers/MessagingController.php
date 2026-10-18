<?php

namespace SecureAccess\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use SecureAccess\Models\TeamConversation;
use SecureAccess\Models\TeamMessageRead;
use SecureAccess\SecureAccess;
use SecureAccess\Services\TeamMessagingService;

class MessagingController extends Controller
{
    public function __construct(private TeamMessagingService $messaging) {}

    private function actor(): string
    {
        $user = SecureAccess::guard()->user();
        abort_unless($user && SecureAccess::mfaVerified($user), 403);

        return (string) $user->getAuthIdentifier();
    }

    private function page(Request $request, string $view, array $data = [])
    {
        return response()->view('secure-access::messaging.'.$view, $data + [
            'embedded' => $request->boolean('embedded'),
            'messagingUnread' => $request->boolean('embedded') ? $this->messaging->unreadCount($this->actor()) : 0,
        ])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function index(Request $request)
    {
        $actor = $this->actor();

        return $this->page($request, 'index', [
            'conversations' => $this->messaging->conversations($actor)->with('participants')
                ->withCount(['messages as unread_count' => fn ($query) => $query->where('sender_id', '!=', $actor)
                    ->whereDoesntHave('reads', fn ($reads) => $reads->where('user_id', $actor))])
                ->latest('id')->paginate(20),
        ]);
    }

    public function create(Request $request)
    {
        $actor = $this->actor();
        $teams = $this->messaging->teamIds($actor)->map(fn ($id) => [
            'id' => (string) $id,
            'name' => SecureAccess::newUserQuery()->find($id)?->getAttribute('name') ?: __('Team'),
            'members' => SecureAccess::newUserQuery()->whereKey($this->messaging->memberIds((string) $id)->all())->get(),
        ]);

        return $this->page($request, 'create', compact('actor', 'teams'));
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->actor();
        $validated = $request->validate([
            'self_advocate_id' => ['required', 'string', 'max:255'],
            'participants' => ['required', 'array', 'min:2', 'max:100'],
            'participants.*' => ['required', 'string', 'max:255', 'distinct'],
        ]);
        $conversation = $this->messaging->create($actor, $validated['self_advocate_id'], $validated['participants']);

        return redirect()->route('secure-access.messaging.show', [$conversation, 'embedded' => $request->boolean('embedded') ? 1 : 0]);
    }

    public function show(Request $request, TeamConversation $conversation)
    {
        $actor = $this->actor();
        $this->messaging->authorize($actor, $conversation);
        $messages = $conversation->messages()->with(['sender', 'reads'])->latest('id')->paginate(50);
        // Only these retrieved messages may be acknowledged, never a client-provided high-water mark.
        $request->session()->put("secure_access.messaging.retrieved.$actor.{$conversation->id}", $messages->pluck('id')->all());

        return $this->page($request, 'show', compact('conversation', 'messages', 'actor'));
    }

    public function send(Request $request, TeamConversation $conversation): RedirectResponse
    {
        $actor = $this->actor();
        $this->messaging->authorize($actor, $conversation);
        $validator = Validator::make($request->only('body'), ['body' => ['required', 'string', 'max:10000']]);
        // Do not flash private message text to session on validation failure.
        if ($validator->fails()) {
            return redirect()->route('secure-access.messaging.show', [$conversation, 'embedded' => $request->boolean('embedded') ? 1 : 0])
                ->withErrors($validator);
        }
        $conversation->messages()->create(['sender_id' => $actor, 'body' => $validator->validated()['body']]);

        return redirect()->route('secure-access.messaging.show', [$conversation, 'embedded' => $request->boolean('embedded') ? 1 : 0]);
    }

    public function read(Request $request, TeamConversation $conversation): RedirectResponse
    {
        $actor = $this->actor();
        $this->messaging->authorize($actor, $conversation);
        $validated = $request->validate([
            'message_ids' => ['required', 'array', 'max:50'],
            'message_ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $retrieved = $request->session()->get("secure_access.messaging.retrieved.$actor.{$conversation->id}", []);
        abort_unless(collect($validated['message_ids'])->diff($retrieved)->isEmpty(), 422);
        $ids = $conversation->messages()->whereIn('id', $validated['message_ids'])->where('sender_id', '!=', $actor)->pluck('id');
        DB::transaction(function () use ($ids, $actor) {
            foreach ($ids as $id) {
                TeamMessageRead::query()->firstOrCreate(['message_id' => $id, 'user_id' => $actor], ['read_at' => now()]);
            }
        });

        return redirect()->route('secure-access.messaging.show', [$conversation, 'embedded' => $request->boolean('embedded') ? 1 : 0]);
    }
}
