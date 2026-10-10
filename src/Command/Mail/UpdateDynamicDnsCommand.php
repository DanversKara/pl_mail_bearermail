<?php

declare(strict_types=1);

namespace App\Command\Mail;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Updates Cloudflare DNS A records when the server's public IP changes.
 *
 * For home-internet servers with dynamic IPs. Configure via env vars:
 *   CLOUDFLARE_API_TOKEN — token with DNS edit permission on the zone
 *   CLOUDFLARE_ZONE_ID   — the zone ID for the domain
 *   DYNAMIC_DNS_HOSTS    — comma-separated hostnames to update (e.g. "mail.example.com")
 *
 * Run from cron every 5 minutes. Exits 0 when the IP is unchanged or when
 * not configured — an unconfigured dynamic DNS is not an error, just unused.
 *
 * The public IP comes from Cloudflare itself (api.cloudflare.com/cdn-cgi/trace),
 * the same host the update goes to, so this command talks to nobody but
 * Cloudflare. It used to ask an unrelated third-party IP-echo service.
 */
#[AsCommand(
    name: 'app:mail:update-dynamic-dns',
    description: 'Update Cloudflare DNS A records when the public IP changes.',
)]
final class UpdateDynamicDnsCommand extends Command
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        // Real environment variables in Docker (compose env_file), which PHP
        // does not put in $_ENV — read them through the container instead.
        #[Autowire('%env(default::CLOUDFLARE_API_TOKEN)%')]
        private readonly ?string $token = null,
        #[Autowire('%env(default::CLOUDFLARE_ZONE_ID)%')]
        private readonly ?string $zoneId = null,
        #[Autowire('%env(default::DYNAMIC_DNS_HOSTS)%')]
        private readonly ?string $hostList = null,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $token = (string) $this->token;
        $zoneId = (string) $this->zoneId;
        $hosts = array_values(array_filter(array_map('trim', explode(',', (string) $this->hostList))));

        if ('' === $token || '' === $zoneId || [] === $hosts) {
            $io->writeln('Dynamic DNS not configured (CLOUDFLARE_API_TOKEN, CLOUDFLARE_ZONE_ID, DYNAMIC_DNS_HOSTS).');

            return Command::SUCCESS;
        }

        // Current public IP.
        try {
            $trace = $this->httpClient->request('GET', 'https://api.cloudflare.com/cdn-cgi/trace', ['timeout' => 10])->getContent();
            $ip = preg_match('/^ip=(.+)$/m', $trace, $m) ? trim($m[1]) : '';
        } catch (\Throwable $e) {
            $io->warning(sprintf('Could not determine public IP: %s', $e->getMessage()));

            return Command::SUCCESS;
        }

        if (false === filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $io->warning(sprintf('Got an invalid IP: %s', $ip));

            return Command::SUCCESS;
        }

        $headers = [
            'Authorization' => sprintf('Bearer %s', $token),
            'Content-Type'  => 'application/json',
        ];

        foreach ($hosts as $host) {
            try {
                $this->updateHost($host, $ip, $zoneId, $headers, $io);
            } catch (\Throwable $e) {
                $io->warning(sprintf('%s: %s', $host, $e->getMessage()));
            }
        }

        return Command::SUCCESS;
    }

    private function updateHost(string $host, string $ip, string $zoneId, array $headers, SymfonyStyle $io): void
    {
        // Find the existing A record.
        $list = $this->httpClient->request(
            'GET',
            sprintf('https://api.cloudflare.com/client/v4/zones/%s/dns_records', rawurlencode($zoneId)),
            ['headers' => $headers, 'query' => ['type' => 'A', 'name' => $host], 'timeout' => 15],
        )->toArray();

        $records = $list['result'] ?? [];
        if ([] === $records) {
            $io->warning(sprintf('%s: no A record found, skipping', $host));

            return;
        }

        foreach ($records as $record) {
            if ($record['content'] === $ip) {
                $io->writeln(sprintf('%s: already %s', $host, $ip));
                continue;
            }

            $status = $this->httpClient->request(
                'PUT',
                sprintf('https://api.cloudflare.com/client/v4/zones/%s/dns_records/%s', rawurlencode($zoneId), rawurlencode((string) $record['id'])),
                [
                    'headers' => $headers,
                    'timeout' => 15,
                    'json'    => [
                        'type'    => 'A',
                        'name'    => $host,
                        'content' => $ip,
                        'ttl'     => 300,
                        'proxied' => false,
                    ],
                ],
            )->getStatusCode();

            if ($status < 200 || $status >= 300) {
                $io->warning(sprintf('%s: Cloudflare answered HTTP %d', $host, $status));
                continue;
            }

            $io->success(sprintf('%s: %s → %s', $host, $record['content'], $ip));
        }
    }
}
