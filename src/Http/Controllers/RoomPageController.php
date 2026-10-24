<?php

namespace daacreators\CreatorsAudioRooms\Http\Controllers;

use daacreators\CreatorsAudioRooms\Contracts\Authorizer;
use daacreators\CreatorsAudioRooms\Enums\RoomAction;
use daacreators\CreatorsAudioRooms\Exceptions\RoomActionDenied;
use daacreators\CreatorsAudioRooms\Http\Middleware\ResolveParticipant;
use daacreators\CreatorsAudioRooms\Models\Room;
use daacreators\CreatorsAudioRooms\Services\RoomAccess;
use daacreators\CreatorsAudioRooms\Services\RoomStatePresenter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Ready-made pages: a room list and a room page. Hosts can also embed the Blade component in their own pages. */
class RoomPageController extends Controller
{
    public function __construct(
        private readonly RoomAccess $access,
        private readonly RoomStatePresenter $presenter,
        private readonly Authorizer $authorizer,
    ) {}

    public function index(Request $request): View
    {
        $who = $request->attributes->get(ResolveParticipant::ATTRIBUTE);

        return view('creators-audio-rooms::pages.index', [
            'rooms' => collect($this->presenter->directory($who)),
            'audienceChoices' => $this->presenter->audienceChoices($who),
            'canCreate' => $this->authorizer->allows($who, RoomAction::Create)
                && $this->authorizer->allows($who, RoomAction::Host),
            'participant' => $who,
        ]);
    }

    public function show(Request $request, string $room): View
    {
        $who = $request->attributes->get(ResolveParticipant::ATTRIBUTE);
        $model = Room::query()->find($room);

        if (! $model || ! $this->access->canView($who, $model)) {
            abort(404, (new RoomActionDenied('room_not_found', 404))->getMessage());
        }

        return view('creators-audio-rooms::pages.show', [
            'room' => $model,
            'participant' => $who,
        ]);
    }
}
