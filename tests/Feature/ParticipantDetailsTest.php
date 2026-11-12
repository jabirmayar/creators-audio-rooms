<?php

namespace daacreators\CreatorsAudioRooms\Tests\Feature;

/**
 * Participant details ("Grade 6", "Teacher") reach viewers of the configured types only. A member's
 * role in the room makes no difference: a student host sees none of them.
 */
class ParticipantDetailsTest extends ApiTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('creators-audio-rooms.details_visible_to_types', ['staff']);
        $app['config']->set('creators-audio-rooms.guards.web.details_attribute', 'email');
        $app['config']->set('creators-audio-rooms.guards.admin.details_attribute', 'email');
    }

    public function test_staff_see_details_and_students_never_do(): void
    {
        $host = $this->staff();
        $listener = $this->member('pupil@org-a.test');
        $roomId = $this->createLiveRoom($host);
        $this->join($listener, $roomId)->assertOk();

        $staffView = $this->sync($host, $roomId)->assertOk()->json('state');
        $this->assertSame('pupil@org-a.test', collect($staffView['listeners'])->firstWhere('name', $listener->name)['details']);

        $studentView = $this->sync($listener, $roomId, 0, -1)->assertOk()->json('state');
        foreach (array_merge($studentView['hosts'], $studentView['listeners']) as $person) {
            $this->assertArrayNotHasKey('details', $person);
        }
    }

    public function test_a_student_host_does_not_see_details_either(): void
    {
        config(['creators-audio-rooms.limits.max_open_rooms_per_host' => 5]);
        $staff = $this->staff();
        $roomId = $this->createLiveRoom($staff);
        $pupil = $this->member('pupil2@org-a.test');
        $this->join($pupil, $roomId)->assertOk();

        // Hand the room to the pupil: they become a co-host, with moderation rights but a student's view.
        $this->makeCoHost($staff, $roomId, $pupil)->assertOk();

        $view = $this->sync($pupil, $roomId, 0, -1)->assertOk()->json('state');
        $this->assertTrue($view['can']['moderate']);
        foreach (array_merge($view['hosts'], $view['listeners'], $view['speakers']) as $person) {
            $this->assertArrayNotHasKey('details', $person);
        }
    }

    public function test_details_are_off_when_no_type_is_listed(): void
    {
        config(['creators-audio-rooms.details_visible_to_types' => []]);
        $host = $this->staff();
        $roomId = $this->createLiveRoom($host);
        $this->join($this->member(), $roomId)->assertOk();

        $view = $this->sync($host, $roomId)->assertOk()->json('state');
        foreach (array_merge($view['hosts'], $view['listeners']) as $person) {
            $this->assertArrayNotHasKey('details', $person);
        }
    }
}
