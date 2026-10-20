<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Method\Calendar;

use App\Entity\Note\Note;
use App\Jmap\Method\Calendar\CalendarEventGetMethod;

/**
 * BearerMail's `isLinked` on CalendarEvent/get: true when a note links to the
 * event (or one of its occurrences), false otherwise, and only sent when it
 * was asked for or everything was.
 */
final class CalendarEventGetIsLinkedTest extends CalendarMethodTestCase
{
    public function testANoteLinkMarksTheEventAndNothingElse(): void
    {
        $calendar = $this->seedCalendar('Home');
        $linked   = $this->seedEvent($calendar, 'Hair', $this->baseDay()->setTime(10, 0));
        $plain    = $this->seedEvent($calendar, 'Shuttle', $this->baseDay()->setTime(14, 0));

        $note        = new Note();
        $note->usr   = $this->user;
        $note->title = 'Ask about colour';
        $note->links = [['type' => 'event', 'id' => $linked->id . '_20261020T100000Z', 'title' => 'Hair']];
        $this->em->persist($note);
        $this->em->flush();

        $method = self::getContainer()->get(CalendarEventGetMethod::class);
        $result = $method->handle([
            'accountId'  => $this->accountId(),
            'ids'        => [(string) $linked->id, (string) $plain->id],
            'properties' => ['id', 'title', 'isLinked'],
        ], $this->context());

        $byId = array_column($result['list'], 'isLinked', 'id');
        self::assertTrue($byId[(string) $linked->id]);
        self::assertFalse($byId[(string) $plain->id]);

        $without = $method->handle([
            'accountId'  => $this->accountId(),
            'ids'        => [(string) $linked->id],
            'properties' => ['id', 'title'],
        ], $this->context());
        self::assertArrayNotHasKey('isLinked', $without['list'][0]);
    }
}
