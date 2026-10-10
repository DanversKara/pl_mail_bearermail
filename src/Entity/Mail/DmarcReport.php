<?php

declare(strict_types=1);

namespace App\Entity\Mail;

use App\Domain\Trait\TimestampableTrait;
use App\Repository\Mail\DmarcReportRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A parsed DMARC aggregate report.
 *
 * Receivers (Google, Microsoft, etc.) send these daily as zipped XML to the
 * address in the domain's DMARC `rua` tag. Each report covers one receiver
 * and one day, listing every IP that sent mail claiming to be from the
 * domain, with SPF/DKIM/DMARC verdicts per IP.
 *
 * The raw XML is kept for re-parsing; the summary is pre-computed for the
 * admin UI: which IPs passed, which failed, and which are unknown senders
 * worth investigating.
 */
#[ORM\Entity(repositoryClass: DmarcReportRepository::class)]
#[ORM\Table(name: 'dmarc_report')]
#[ORM\Index(name: 'idx_dmarc_report_domain', columns: ['domain'])]
#[ORM\HasLifecycleCallbacks]
class DmarcReport
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    /** The domain the report is about (from the XML's policy_published). */
    #[ORM\Column(length: 255)]
    public string $domain;

    /** Who sent the report (the receiving organisation's name). */
    #[ORM\Column(name: 'reporter', length: 255)]
    public string $reporter;

    /** The date range the report covers. */
    #[ORM\Column(name: 'date_from')]
    public DateTimeImmutable $dateFrom;

    #[ORM\Column(name: 'date_to')]
    public DateTimeImmutable $dateTo;

    /**
     * @var list<array{ip: string, count: int, spf: string, dkim: string, dmarc: string}>
     * One row per sending IP.
     */
    #[ORM\Column(type: 'json')]
    public array $records = [];

    /** Total messages in the report. */
    #[ORM\Column(name: 'message_count')]
    public int $messageCount = 0;

    /** Messages that failed DMARC. */
    #[ORM\Column(name: 'fail_count')]
    public int $failCount = 0;
}
