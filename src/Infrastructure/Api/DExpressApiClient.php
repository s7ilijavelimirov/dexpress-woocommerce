<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Infrastructure\Api;

use S7codedesign\DExpress\Infrastructure\Api\Exceptions\ApiException;
use S7codedesign\DExpress\Infrastructure\Api\Exceptions\ApiUnauthorizedException;
use S7codedesign\DExpress\Infrastructure\Options\EncryptedString;
use S7codedesign\DExpress\Infrastructure\Options\OptionsRepository;

/**
 * Thin HTTP wrapper for the D Express External API.
 *
 * Responsibilities: authentication headers, request dispatch, response
 * decoding, typed error throwing. No business logic here.
 *
 * Base URL: https://usersupport.dexpress.rs/ExternalApi
 */
final class DExpressApiClient
{
    private const BASE_URL = 'https://usersupport.dexpress.rs/ExternalApi';
    private const TIMEOUT  = 30;

    public function __construct(
        private readonly OptionsRepository $options,
    ) {}

    /**
     * Sends a GET request to the given endpoint path (e.g. '/data/towns').
     *
     * @param array<string, string|int> $queryParams
     * @return array<mixed>
     * @throws ApiException|ApiUnauthorizedException
     */
    public function get(string $endpoint, array $queryParams = []): array
    {
        $url = self::BASE_URL . $endpoint;

        if (!empty($queryParams)) {
            $url .= '?' . http_build_query($queryParams);
        }

        $response = wp_remote_get($url, [
            'headers' => $this->buildHeaders(),
            'timeout' => self::TIMEOUT,
        ]);

        return $this->decode($response);
    }

    /**
     * Sends a POST request with a JSON body.
     *
     * @param array<mixed> $body
     * @return array<mixed>
     * @throws ApiException|ApiUnauthorizedException
     */
    public function post(string $endpoint, array $body = []): array
    {
        $url = self::BASE_URL . $endpoint;

        $response = wp_remote_post($url, [
            'headers' => $this->buildHeaders(),
            'body'    => wp_json_encode($body),
            'timeout' => self::TIMEOUT,
        ]);

        return $this->decode($response);
    }

    /**
     * Sends an addShipment request. The API returns a plain quoted string —
     * either "OK" (production) or "TEST" — not a JSON object.
     *
     * @param array<mixed> $payload
     * @throws ApiException|ApiUnauthorizedException
     */
    public function addShipment(array $payload): string
    {
        $url = self::BASE_URL . '/data/addshipment';

        $response = wp_remote_post($url, [
            'headers' => $this->buildHeaders(),
            'body'    => wp_json_encode($payload),
            'timeout' => self::TIMEOUT,
        ]);

        if (is_wp_error($response)) {
            throw new ApiException(
                'D Express API: greška pri slanju pošiljke — ' . $response->get_error_message()
            );
        }

        $status = wp_remote_retrieve_response_code($response);

        if ($status === 401) {
            throw new ApiUnauthorizedException();
        }

        // Always read the body — on 400 D Express returns the validation error as plain text
        $body = trim(wp_remote_retrieve_body($response), " \t\n\r\0\x0B\"");

        if ($status !== 200) {
            throw new ApiException(
                sprintf(
                    'D Express API: HTTP %d — %s',
                    $status,
                    $body !== '' ? $body : 'nema odgovora'
                ),
                (int) $status,
            );
        }

        if ($body !== 'OK' && $body !== 'TEST') {
            throw new ApiException(
                sprintf('D Express API: neočekivani odgovor addShipment — "%s".', $body)
            );
        }

        return $body;
    }

    /**
     * POST /data/checkaddress — telo odgovora kao kod addShipment: plain string OK/TEST/greška.
     *
     * @param array<string, mixed> $payload RName, RAddress, RAddressNum, RTownID, opciono RAddressDesc, RCName, RCPhone
     * @throws ApiException|ApiUnauthorizedException
     */
    public function checkAddress(array $payload): string
    {
        $url = self::BASE_URL . '/data/checkaddress';

        $response = wp_remote_post($url, [
            'headers' => $this->buildHeaders(),
            'body'    => wp_json_encode($payload),
            'timeout' => self::TIMEOUT,
        ]);

        if (is_wp_error($response)) {
            throw new ApiException(
                'D Express API: greška pri proveri adrese — ' . $response->get_error_message()
            );
        }

        $status = wp_remote_retrieve_response_code($response);

        if ($status === 401) {
            throw new ApiUnauthorizedException();
        }

        $body = trim(wp_remote_retrieve_body($response), " \t\n\r\0\x0B\"");

        if ($status !== 200) {
            throw new ApiException(
                sprintf('D Express API: HTTP %d — %s', $status, $body !== '' ? $body : 'nema odgovora'),
                (int) $status,
            );
        }

        return $body;
    }

    /**
     * GET /data/viewpayments?PaymentReference={ref}
     *
     * @return array<int, array<string, mixed>>
     * @throws ApiException|ApiUnauthorizedException
     */
    public function viewPayments(string $paymentReference): array
    {
        $reference = trim($paymentReference);
        if ($reference === '') {
            throw new ApiException(
                'D Express API: PaymentReference je obavezan za viewpayments.'
            );
        }

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->get('/data/viewpayments', [
            'PaymentReference' => $reference,
        ]);

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    private function buildHeaders(): array
    {
        $username = $this->options->getString('api.username');
        $password = EncryptedString::fromString(
            $this->options->getString('api.password')
        )->decrypt();

        return [
            'Authorization' => 'Basic ' . base64_encode($username . ':' . $password),
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ];
    }

    /**
     * Validates the WP HTTP response and returns the decoded JSON body.
     *
     * @param array<mixed>|\WP_Error $response
     * @return array<mixed>
     * @throws ApiUnauthorizedException on HTTP 401
     * @throws ApiException on any other error or non-200 status
     */
    private function decode(mixed $response): array
    {
        if (is_wp_error($response)) {
            throw new ApiException(
                'D Express API: greška pri slanju zahteva — ' . $response->get_error_message()
            );
        }

        $status = wp_remote_retrieve_response_code($response);

        if ($status === 401) {
            throw new ApiUnauthorizedException();
        }

        if ($status !== 200) {
            throw new ApiException(
                sprintf(
                    'D Express API: neočekivani HTTP status %d za odgovor.',
                    $status,
                ),
                (int) $status,
            );
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data)) {
            throw new ApiException(
                'D Express API: odgovor nije validan JSON.'
            );
        }

        return $data;
    }
}
