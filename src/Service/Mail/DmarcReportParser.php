<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Mail\DmarcReport;

/**
 * Parses DMARC aggregate reports (RFC 7489, zipped XML from receivers).
 *
 * A report looks like:
 *   <feedback>
 *     <report_metadata><org_name>receiver.example</org_name>
 *       <date_range><begin>...</begin><end>...</end></date_range>
 *     </report_metadata>
 *     <policy_published><domain>example.com</domain>...</policy_published>
 *     <record><row>
 *       <source_ip>192.0.2.1</source_ip><count>42</count>
 *       <policy_evaluated><disposition>none</disposition>
 *         <dkim>pass</dkim><spf>fail</spf></policy_evaluated>
 *     </row><identifiers>...</identifiers>
 *     <auth_results><dkim><result>pass</result></dkim>...</auth_results>
 *     </record>
 *   </feedback>
 */
final readonly class DmarcReportParser
{
    /**
     * @throws \InvalidArgumentException If the XML is not a DMARC report.
     */
    public function parse(string $xml): DmarcReport
    {
        $doc = new \DOMDocument();
        if (false === @$doc->loadXML($xml)) {
            throw new \InvalidArgumentException('Not valid XML.');
        }

        $xpath = new \DOMXPath($doc);

        $domain = $this->text($xpath, '//policy_published/domain');
        $reporter = $this->text($xpath, '//report_metadata/org_name');
        $begin = (int) $this->text($xpath, '//report_metadata/date_range/begin');
        $end = (int) $this->text($xpath, '//report_metadata/date_range/end');

        if ('' === $domain || '' === $reporter || 0 === $begin) {
            throw new \InvalidArgumentException('Not a DMARC aggregate report.');
        }

        $report = new DmarcReport();
        $report->domain = $domain;
        $report->reporter = $reporter;
        $report->dateFrom = (new \DateTimeImmutable())->setTimestamp($begin);
        $report->dateTo = (new \DateTimeImmutable())->setTimestamp($end);

        $records = [];
        $total = 0;
        $failed = 0;

        foreach ($xpath->query('//record') as $record) {
            /** @var \DOMElement $record */
            $r = new \DOMXPath($record->ownerDocument);
            // Query within this record node.
            $q = function (string $expr) use ($r, $record): string {
                $nodes = $r->query($expr, $record);
                return $nodes->length > 0 ? trim($nodes->item(0)->textContent) : '';
            };

            $ip = $q('.//row/source_ip');
            $count = (int) $q('.//row/count');
            $dkim = strtolower($q('.//row/policy_evaluated/dkim'));
            $spf = strtolower($q('.//row/policy_evaluated/spf'));
            $disposition = strtolower($q('.//row/policy_evaluated/disposition'));

            // DMARC passes if either DKIM or SPF passes (aligned).
            $dmarc = ('pass' === $dkim || 'pass' === $spf) ? 'pass' : 'fail';

            $records[] = [
                'ip'    => $ip,
                'count' => $count,
                'spf'   => $spf,
                'dkim'  => $dkim,
                'dmarc' => $dmarc,
            ];

            $total += $count;
            if ('fail' === $dmarc) {
                $failed += $count;
            }
        }

        // Most mail first.
        usort($records, fn($a, $b) => $b['count'] <=> $a['count']);

        $report->records = $records;
        $report->messageCount = $total;
        $report->failCount = $failed;

        return $report;
    }

    /**
     * Unpacks a .zip or .gz DMARC report attachment, then parses the XML.
     *
     * @throws \InvalidArgumentException
     */
    public function parseFile(string $path, string $filename): DmarcReport
    {
        $lower = strtolower($filename);
        $xml = null;

        if (str_ends_with($lower, '.zip')) {
            $zip = new \ZipArchive();
            if (true !== $zip->open($path)) {
                throw new \InvalidArgumentException('Could not open the zip file.');
            }
            if ($zip->numFiles < 1) {
                throw new \InvalidArgumentException('The zip is empty.');
            }
            $xml = $zip->getFromIndex(0);
            $zip->close();
        } elseif (str_ends_with($lower, '.gz')) {
            $xml = @gzdecode(file_get_contents($path));
        } elseif (str_ends_with($lower, '.xml')) {
            $xml = file_get_contents($path);
        } else {
            throw new \InvalidArgumentException('Upload a .zip, .gz, or .xml DMARC report.');
        }

        if (false === is_string($xml) || '' === trim($xml)) {
            throw new \InvalidArgumentException('Could not read a report from that file.');
        }

        return $this->parse($xml);
    }

    private function text(\DOMXPath $xpath, string $expr): string
    {
        $nodes = $xpath->query($expr);

        return $nodes->length > 0 ? trim($nodes->item(0)->textContent) : '';
    }
}
