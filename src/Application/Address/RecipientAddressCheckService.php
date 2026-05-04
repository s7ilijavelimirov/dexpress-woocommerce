<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Application\Address;

use S7codedesign\DExpress\Domain\Address\PhoneNumber;
use S7codedesign\DExpress\Infrastructure\Api\DExpressApiClient;
use S7codedesign\DExpress\Infrastructure\Api\Exceptions\ApiException;
use S7codedesign\DExpress\Infrastructure\Api\Exceptions\ApiUnauthorizedException;
use S7codedesign\DExpress\Infrastructure\Logging\Logger;
use S7codedesign\DExpress\Infrastructure\Options\OptionsRepository;
use WP_Error;

/**
 * Provera adrese primaoca preko D Express {@see DExpressApiClient::checkAddress}.
 */
final class RecipientAddressCheckService
{
    public function __construct(
        private readonly DExpressApiClient $api,
        private readonly OptionsRepository $options,
        private readonly Logger $logger,
    ) {}

    public function isEnabled(): bool
    {
        return $this->options->getBool('validate_address.enabled', false);
    }

    /**
     * Blokirajuća validacija na checkout-u (ADR-017) kada je opcija uključena i izabrana je D Express dostava.
     *
     * @param array<string, mixed> $data
     */
    public function validateCheckoutBlocking(array $data, WP_Error $errors): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        if (!$this->checkoutUsesDexpressShipping($data)) {
            return;
        }

        $useShipping = !empty($data['ship_to_different_address'])
            && ((string) ($data['shipping_country'] ?? '')) === 'RS';

        $payload = $this->buildPayloadFromCheckoutData($data, $useShipping);
        if ($payload === null) {
            return;
        }

        try {
            $result = trim($this->api->checkAddress($payload));
        } catch (ApiUnauthorizedException $e) {
            $errors->add('dexpress_address', __('D Express: nevažeći API kredencijali (provera adrese).', 'dexpress-woocommerce'));

            return;
        } catch (ApiException $e) {
            $errors->add(
                'dexpress_address',
                sprintf(
                    /* translators: %s: API error */
                    __('D Express: provera adrese nije uspela — %s', 'dexpress-woocommerce'),
                    $e->getMessage(),
                ),
            );

            return;
        }

        if ($result !== 'OK' && $result !== 'TEST') {
            $errors->add(
                'dexpress_address',
                sprintf(
                    /* translators: %s: API response text */
                    __('D Express: adresa nije prihvaćena — %s', 'dexpress-woocommerce'),
                    $result,
                ),
            );
        }
    }

    /**
     * Pre addShipment: log upozorenja; ne blokira kreiranje (šifarnik može kasniti).
     *
     * @param array{name:string,street:string,street_number:string,address_desc:string,town_id:int,phone:string} $recipient
     */
    public function logPreflightForShipment(int $orderId, array $recipient): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $payload = [
            'RName'        => $recipient['name'],
            'RAddress'     => $recipient['street'],
            'RAddressNum'  => $recipient['street_number'],
            'RAddressDesc' => $recipient['address_desc'],
            'RTownID'      => $recipient['town_id'],
            'RCName'       => $recipient['name'],
            'RCPhone'      => $recipient['phone'],
        ];

        try {
            $result = trim($this->api->checkAddress($payload));
        } catch (ApiException | ApiUnauthorizedException $e) {
            $this->logger->warning('[checkaddress] API greška za narudžbinu ' . $orderId . ': ' . $e->getMessage());

            return;
        }

        if ($result !== 'OK' && $result !== 'TEST') {
            $this->logger->warning('[checkaddress] Upozorenje za narudžbinu ' . $orderId . ': ' . $result);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public function checkoutUsesDexpressShipping(array $data): bool
    {
        $methods = (array) ($data['shipping_method'] ?? []);

        foreach ($methods as $m) {
            if (is_string($m) && str_contains($m, 'dexpress')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function buildPayloadFromCheckoutData(array $data, bool $useShipping): ?array
    {
        if ($useShipping) {
            $townId = (int) ($data['shipping_dexpress_town_id'] ?? 0);
            $name    = trim((string) ($data['shipping_first_name'] ?? '') . ' ' . (string) ($data['shipping_last_name'] ?? ''));
            $street  = trim((string) ($data['shipping_address_1'] ?? ''));
            $num     = trim((string) ($data['shipping_dexpress_street_number'] ?? ''));
            $desc    = trim((string) ($data['shipping_address_2'] ?? ''));
            $phone   = trim((string) ($data['shipping_phone'] ?? $data['billing_phone'] ?? ''));
        } else {
            $townId = (int) ($data['billing_dexpress_town_id'] ?? 0);
            $name   = trim((string) ($data['billing_first_name'] ?? '') . ' ' . (string) ($data['billing_last_name'] ?? ''));
            $street = trim((string) ($data['billing_address_1'] ?? ''));
            $num    = trim((string) ($data['billing_dexpress_street_number'] ?? ''));
            $desc   = trim((string) ($data['billing_address_2'] ?? ''));
            $phone  = trim((string) ($data['billing_phone'] ?? ''));
        }

        $name = trim($name);
        if ($townId <= 0 || $street === '' || $num === '' || $name === '') {
            return null;
        }

        $rcPhone = $phone;
        if ($rcPhone !== '') {
            try {
                $rcPhone = PhoneNumber::fromString($rcPhone)->canonical();
            } catch (\InvalidArgumentException) {
                // Ostavi sirov unos; checkout validacija bi trebalo da uhvati pre ovoga.
            }
        }

        return [
            'RName'        => mb_substr($name, 0, 50),
            'RAddress'     => mb_substr($street, 0, 50),
            'RAddressNum'  => mb_substr($num, 0, 10),
            'RAddressDesc' => mb_substr($desc, 0, 50),
            'RTownID'      => $townId,
            'RCName'       => mb_substr($name, 0, 50),
            'RCPhone'      => $rcPhone,
        ];
    }
}
