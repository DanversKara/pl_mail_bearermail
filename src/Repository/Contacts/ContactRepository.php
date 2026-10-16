<?php

declare(strict_types=1);

namespace App\Repository\Contacts;

use App\Entity\Contacts\Contact;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<Contact>
 */
class ContactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Contact::class);
    }

    /**
     * Address-book hits for compose autocomplete: each contact once, paired
     * with the email the composer should insert. A contact with no emails
     * is skipped. Emails are joined so the chooser does not re-query per
     * contact.
     *
     * The picked email is the contact's primary when it has one, otherwise
     * the email that matched the query, otherwise its first email.
     *
     * @return list<array{contact: Contact, email: string}>
     */
    public function findAddressBookForAutocomplete(UserInterface $user, string $query, int $limit): array
    {
        $q = mb_strtolower(trim($query));

        /** @var list<Contact> $rows */
        $rows = $this->createQueryBuilder('c')
            ->innerJoin('c.emails', 'e')
            ->addSelect('e')
            ->where('c.usr = :user')
            ->andWhere('c.trashedAt IS NULL')
            ->andWhere('(c.displayName ILIKE :like OR c.givenName ILIKE :like OR c.familyName ILIKE :like OR c.nickname ILIKE :like OR c.company ILIKE :like OR e.email ILIKE :like)')
            ->setParameter('user', $user)
            ->setParameter('like', '%' . $q . '%')
            ->orderBy('c.displayName', 'ASC')
            ->distinct()
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        // Defensive dedupe: one contact row can repeat per matching email.
        $seen = [];
        $out = [];
        foreach ($rows as $contact) {
            if (isset($seen[$contact->id])) {
                continue;
            }
            $seen[$contact->id] = true;

            $email = $this->pickAutocompleteEmail($contact, $q);
            if (null === $email) {
                continue; // zero emails — skip
            }

            $out[] = ['contact' => $contact, 'email' => $email];
        }

        return $out;
    }

    /**
     * Paged address-book list. Tag filtering is applied in PHP after the
     * page is fetched: tags live in a JSON column, and portable DQL has no
     * jsonb-containment operator, so a DB-side filter would lock this query
     * to one database. v1 tradeoff — with a tag selected, fewer than $limit
     * rows may come back for a page.
     *
     * @return list<Contact>
     */
    public function findForUser(UserInterface $user, ?string $search, ?string $tag, string $sort, int $limit, int $offset): array
    {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.emails', 'e')
            ->addSelect('e')
            ->where('c.usr = :user')
            ->andWhere('c.trashedAt IS NULL')
            ->setParameter('user', $user);

        if (null !== $search && '' !== trim($search)) {
            $qb->andWhere('(c.displayName ILIKE :search OR c.givenName ILIKE :search OR c.familyName ILIKE :search OR c.nickname ILIKE :search OR c.company ILIKE :search OR e.email ILIKE :search)')
                ->setParameter('search', '%' . mb_strtolower(trim($search)) . '%');
        }

        match ($sort) {
            'recent' => $qb->orderBy('c.updatedAt', 'DESC'),
            default => $qb->orderBy('c.displayName', 'ASC'),
        };

        $qb->setMaxResults($limit)->setFirstResult($offset);

        /** @var list<Contact> $contacts */
        $contacts = $qb->getQuery()->getResult();

        if (null !== $tag && '' !== $tag) {
            $contacts = array_values(array_filter(
                $contacts,
                static fn (Contact $contact): bool => \in_array($tag, $contact->tags, true),
            ));
        }

        return $contacts;
    }

    /**
     * Every tag used by the user's address book, for the list page's tag
     * chips. Flattened in PHP: tags live in a JSON column, and portable DQL
     * has no jsonb-unnest. One indexed read, cheap next to the list query.
     *
     * @return list<string>
     */
    public function findAllTagsForUser(UserInterface $user): array
    {
        /** @var list<array{tags: list<string>}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('c.tags AS tags')
            ->where('c.usr = :user')
            ->andWhere('c.trashedAt IS NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        $tags = [];
        foreach ($rows as $row) {
            foreach ((array) ($row['tags'] ?? []) as $tag) {
                $tag = trim((string) $tag);
                if ('' !== $tag && !isset($tags[$tag])) {
                    $tags[$tag] = true;
                }
            }
        }

        $out = array_keys($tags);
        natcasesort($out);

        return array_values($out);
    }

    private function pickAutocompleteEmail(Contact $contact, string $q): ?string
    {
        $first = null;
        $match = null;
        $primary = null;

        foreach ($contact->emails as $email) {
            $first ??= $email->email;
            if ($email->isPrimary) {
                $primary = $email->email;
            }
            if (null === $match && str_contains($email->email, $q)) {
                $match = $email->email;
            }
        }

        return $primary ?? $match ?? $first;
    }
}
