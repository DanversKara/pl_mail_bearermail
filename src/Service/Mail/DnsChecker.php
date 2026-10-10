<?php

declare(strict_types=1);

namespace App\Service\Mail;

/**
 * The DNS records a mail domain needs, and whether they are actually set.
 *
 * Shows the exact MX, SPF, DKIM, and DMARC records for the domain, and checks
 * them live via DNS. The DKIM public key comes from the mta's generated key;
 * the rest are derived from the domain and server IP.
 */
final readonly class DnsChecker
{
    /**
     * A stateless service: the domain, IP and selector are arguments rather
     * than constructor strings, which the container cannot autowire.
     *
     * @return list<array{type: string, name: string, expected: string, actual: string|null, ok: bool}>
     */
    public function check(string $domain, string $serverIp, string $dkimSelector = 'mail'): array
    {
        $results = [];

        // MX
        $mxRecords = @dns_get_record($domain, DNS_MX);
        $mxActual = null;
        if (false !== $mxRecords) {
            foreach ($mxRecords as $r) {
                if (10 === (int) $r['pri']) {
                    $mxActual = $r['target'];
                    break;
                }
            }
            $mxActual ??= $mxRecords[0]['target'] ?? null;
        }
        $mxExpected = sprintf('mail.%s', $domain);
        $results[] = [
            'type'     => 'MX',
            'name'     => $domain,
            'expected' => sprintf('10 %s', $mxExpected),
            'actual'   => $mxActual,
            'ok'       => null !== $mxActual && str_ends_with(rtrim($mxActual, '.'), $mxExpected),
        ];

        // SPF
        $spfExpected = '' !== $serverIp
            ? sprintf('v=spf1 ip4:%s -all', $serverIp)
            : 'v=spf1 ip4:<SERVER_IP> -all';
        $spfActual = $this->txtRecord($domain, 'v=spf1');
        $results[] = [
            'type'     => 'TXT (SPF)',
            'name'     => $domain,
            'expected' => $spfExpected,
            'actual'   => $spfActual,
            'ok'       => null !== $spfActual && '' !== $serverIp && str_contains($spfActual, $serverIp),
        ];

        // DKIM
        $dkimName = sprintf('%s._domainkey.%s', $dkimSelector, $domain);
        $dkimActual = $this->txtRecord($dkimName, 'v=DKIM1');
        $results[] = [
            'type'     => 'TXT (DKIM)',
            'name'     => $dkimName,
            'expected' => 'v=DKIM1; k=rsa; p=... (from the mta container logs)',
            'actual'   => $dkimActual ? substr($dkimActual, 0, 60) . '…' : null,
            'ok'       => null !== $dkimActual,
        ];

        // DMARC
        $dmarcName = sprintf('_dmarc.%s', $domain);
        $dmarcExpected = sprintf('v=DMARC1; p=none; rua=mailto:postmaster@%s', $domain);
        $dmarcActual = $this->txtRecord($dmarcName, 'v=DMARC1');
        $results[] = [
            'type'     => 'TXT (DMARC)',
            'name'     => $dmarcName,
            'expected' => $dmarcExpected,
            'actual'   => $dmarcActual,
            'ok'       => null !== $dmarcActual,
        ];

        return $results;
    }

    private function txtRecord(string $name, string $prefix): ?string
    {
        $records = @dns_get_record($name, DNS_TXT);
        if (false === $records) {
            return null;
        }

        foreach ($records as $r) {
            $txt = $r['txt'] ?? '';
            if (str_starts_with($txt, $prefix)) {
                return $txt;
            }
        }

        return null;
    }
}
