<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Rest;

use S7codedesign\DExpress\Infrastructure\Async\WebhookJobScheduler;
use S7codedesign\DExpress\Infrastructure\Logging\Logger;
use S7codedesign\DExpress\Infrastructure\Options\OptionsRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbWebhookLogRepository;
use WP_REST_Request;
use WP_REST_Response;

final class WebhookController
{
    public function __construct(
        private readonly WpdbWebhookLogRepository $webhookLogs,
        private readonly OptionsRepository $options,
        private readonly Logger $logger,
        private readonly WebhookJobScheduler $jobs,
    ) {}

    public function registerRoutes(): void
    {
        add_action('rest_api_init', [$this, 'registerRestRoutes']);
    }

    public function registerRestRoutes(): void
    {
        register_rest_route(
            'dexpress/v1',
            '/notify',
            [
                'methods'             => 'PUT',
                'callback'            => [$this, 'handlePut'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'cc'   => ['required' => false, 'sanitize_callback' => 'sanitize_text_field'],
                    'nID'  => ['required' => false, 'sanitize_callback' => 'sanitize_text_field'],
                    'code' => ['required' => false, 'sanitize_callback' => 'sanitize_text_field'],
                    'rID'  => ['required' => false, 'sanitize_callback' => 'sanitize_text_field'],
                    'sID'  => ['required' => false, 'sanitize_callback' => 'sanitize_text_field'],
                    'dt'   => ['required' => false, 'sanitize_callback' => 'sanitize_text_field'],
                ],
            ],
        );

        register_rest_route(
            'dexpress/v1',
            '/notify',
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'handlePost'],
                'permission_callback' => '__return_true',
            ],
        );
    }

    public function handlePut(WP_REST_Request $request): WP_REST_Response
    {
        return $this->handle(
            (string) $request->get_param('cc'),
            (string) $request->get_param('nID'),
            (string) $request->get_param('code'),
            (string) $request->get_param('rID'),
            (string) $request->get_param('sID'),
            (string) $request->get_param('dt'),
            (array) $request->get_params(),
        );
    }

    public function handlePost(WP_REST_Request $request): WP_REST_Response
    {
        // Notify POST: polja u JSON telu (primarno); fallback form-urlencoded / query — JSON/form imaju prioritet nad query.
        $query = $request->get_query_params();
        if (!is_array($query)) {
            $query = [];
        }
        $form = $request->get_body_params();
        if (!is_array($form)) {
            $form = [];
        }
        $json = $request->get_json_params();
        if (!is_array($json)) {
            $json = [];
        }
        $body = array_merge($query, $form, $json);

        return $this->handle(
            (string) ($body['cc'] ?? ''),
            (string) ($body['nID'] ?? ''),
            (string) ($body['code'] ?? ''),
            (string) ($body['rID'] ?? ''),
            (string) ($body['sID'] ?? ''),
            (string) ($body['dt'] ?? ''),
            $body,
        );
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function handle(
        string $cc,
        string $nID,
        string $code,
        string $rID,
        string $sID,
        string $dt,
        array $raw,
    ): WP_REST_Response {
        if ($nID === '' || $code === '' || $sID === '') {
            $this->logger->warning('[WEBHOOK] Malformed payload (missing nID, code, or sID).');
            return new WP_REST_Response('', 200);
        }

        $stored = $this->options->getString('webhook.passcode');
        if ($stored === '' || !hash_equals($stored, $cc)) {
            $this->logger->warning('[WEBHOOK] Invalid or missing passcode (cc). nID=' . $nID);
            return new WP_REST_Response('', 200);
        }

        if ($this->webhookLogs->existsByNotificationId($nID)) {
            return new WP_REST_Response('', 200);
        }

        $occurredAtUtc = $this->parseDtUtcMysql($dt);

        try {
            $logId = $this->webhookLogs->insert([
                'notification_id' => $nID,
                'package_code'    => $code,
                'reference_id'    => $rID !== '' ? $rID : null,
                'sid'             => $sID,
                'occurred_at'     => $occurredAtUtc,
                'received_at'     => current_time('mysql', true),
                'raw_payload'     => wp_json_encode($raw, JSON_UNESCAPED_UNICODE) ?: '{}',
                'processed'       => 0,
            ]);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'Duplicate entry') && str_contains($msg, 'notification_id')) {
                return new WP_REST_Response('', 200);
            }
            $this->logger->error('[WEBHOOK] DB insert failed: ' . $msg);
            return new WP_REST_Response('ERROR', 500);
        }

        $this->jobs->scheduleProcessWebhookLog($logId, 5);

        return new WP_REST_Response('', 200);
    }

    private function parseDtUtcMysql(string $dt): string
    {
        $dt = trim($dt);
        if ($dt === '') {
            return current_time('mysql', true);
        }

        $local = \DateTimeImmutable::createFromFormat(
            'YmdHis',
            $dt,
            new \DateTimeZone('Europe/Belgrade'),
        );

        if ($local === false) {
            $this->logger->warning('[WEBHOOK] Unparseable dt: ' . $dt);

            return current_time('mysql', true);
        }

        return $local->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
