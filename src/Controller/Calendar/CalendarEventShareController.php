<?php

declare(strict_types=1);

namespace App\Controller\Calendar;

use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarEventOccurrenceRepository;
use App\Repository\Calendar\CalendarRepository;
use App\Security\Voter\OwnershipVoter;
use App\Service\Calendar\CalendarTimeResolver;
use App\Service\Calendar\Ics\IcsExporter;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * BearerMail: putting one of your events INTO an email.
 *
 * The composer's "Insert event" button lists what is coming up and, for the
 * one picked, writes the details into the message and attaches the event as an
 * .ics file — so the recipient's mail app (Gmail, Outlook, Apple Mail…) offers
 * a one-tap "Add to calendar".
 *
 * Read-only: nothing here changes an event.
 */
#[IsGranted('ROLE_USER')]
#[Route('/calendar', name: 'app_calendar_share_')]
final class CalendarEventShareController extends AbstractController
{
    /** How far ahead the picker looks. */
    private const int DAYS_AHEAD = 120;

    private const int MAX_EVENTS = 150;

    public function __construct(
        private readonly CalendarRepository $calendars,
        private readonly CalendarEventOccurrenceRepository $occurrences,
        private readonly CalendarTimeResolver $time,
        private readonly IcsExporter $exporter,
    ) {
    }

    #[Route('/events/upcoming.json', name: 'upcoming', methods: ['GET'])]
    public function upcoming(): JsonResponse
    {
        $user = $this->currentUser();
        $zone = $this->time->zoneFor($user);
        $utc  = new DateTimeZone('UTC');

        $calendarIds = [];
        foreach ($this->calendars->findVisibleForUser($user) as $calendar) {
            $calendarIds[] = (int) $calendar->id;
        }

        $now  = new DateTimeImmutable('now', $zone);
        $from = $now->setTime(0, 0)->setTimezone($utc);
        $to   = $now->modify('+' . self::DAYS_AHEAD . ' days')->setTimezone($utc);

        $rows = [];
        foreach ($this->occurrences->findInRange($user, $calendarIds, $from, $to) as $occurrence) {
            $event = $occurrence->event;
            if (null === $event || null === $occurrence->startsAt) {
                continue;
            }

            // All-day events are floating dates; show them as the date they say.
            $shown  = true === $event->isAllDay ? $occurrence->startsAt : $occurrence->startsAt->setTimezone($zone);
            $ends   = null === $occurrence->endsAt ? null
                : (true === $event->isAllDay ? $occurrence->endsAt : $occurrence->endsAt->setTimezone($zone));
            $key    = $event->id . '@' . $occurrence->startsAt->getTimestamp();

            $rows[$key] = [
                'id'       => $event->id,
                'title'    => (string) ($event->title ?: '(no title)'),
                'start'    => $shown->format(\DateTimeInterface::ATOM),
                'end'      => $ends?->format(\DateTimeInterface::ATOM),
                'allDay'   => $event->isAllDay,
                'location' => $event->location,
                'sort'     => $occurrence->startsAt->getTimestamp(),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);
        $rows = array_map(static function (array $row): array {
            unset($row['sort']);

            return $row;
        }, array_slice(array_values($rows), 0, self::MAX_EVENTS));

        return new JsonResponse(['events' => $rows, 'timeZone' => $zone->getName()]);
    }

    /** The event as an .ics file, for attaching to a message. */
    #[Route('/event/{id}/ics', name: 'ics', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function ics(CalendarEvent $event): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $event);

        $name = $this->exporter->fileNameFor((string) ($event->title ?: 'event'));
        if (false === str_ends_with(strtolower($name), '.ics')) {
            $name .= '.ics';
        }

        $response = new Response($this->exporter->one($event));
        $response->headers->set('Content-Type', 'text/calendar; charset=utf-8; method=PUBLISH');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $name,
            preg_replace('~[^A-Za-z0-9._-]+~', '-', $name) ?: 'event.ics',
        ));

        return $response;
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (false === $user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
