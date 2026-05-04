<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Application\Shipment;

use S7codedesign\DExpress\Domain\Address\PhoneNumber;
use S7codedesign\DExpress\Infrastructure\Persistence\AddressSearchRepository;
use WC_Order;

/**
 * Resolves recipient address for API / labels: effective billing vs shipping,
 * street name from street_id when missing, phone from billing.
 */
final class OrderRecipientResolver
{
    public function __construct(
        private readonly AddressSearchRepository $addressSearch,
    ) {}

    /**
     * @return array{
     *   name: string,
     *   street: string,
     *   street_number: string,
     *   address_desc: string,
     *   town_id: int,
     *   phone: string,
     *   city: string
     * }
     */
    public function resolve(WC_Order $order): array
    {
        $hasShip = $order->has_shipping_address();
        $sTown   = (int) $order->get_meta('_shipping_town_id');
        $sStreet = trim((string) $order->get_meta('_shipping_street_name'));
        $sNum    = trim((string) $order->get_meta('_shipping_street_number'));

        $shippingComplete = $hasShip && $sTown > 0 && $sStreet !== '' && $sNum !== '';

        $prefix = $shippingComplete ? 'shipping' : 'billing';

        $name = $prefix === 'shipping'
            ? $order->get_formatted_shipping_full_name()
            : $order->get_formatted_billing_full_name();

        $street     = trim((string) $order->get_meta("_{$prefix}_street_name"));
        $streetNum  = trim((string) $order->get_meta("_{$prefix}_street_number"));
        $addressDesc = trim((string) $order->get_meta("_{$prefix}_address_desc"));
        $townId     = (int) $order->get_meta("_{$prefix}_town_id");
        $streetId   = (int) $order->get_meta("_{$prefix}_street_id");

        if ($street === '' && $streetId > 0) {
            $street = trim((string) ($this->addressSearch->findStreetNameById($streetId) ?? ''));
        }

        if ($street !== '' && $townId > 0 && $streetId <= 0) {
            $resolvedId = $this->addressSearch->findStreetIdExact($townId, $street);
            if ($resolvedId !== null) {
                $streetId = $resolvedId;
            }
        }

        $rawPhone = (string) ($order->get_meta('_billing_phone_api_format') ?: $order->get_billing_phone());
        $phone    = '';
        if ($rawPhone !== '') {
            try {
                $phone = PhoneNumber::fromString($rawPhone)->canonical();
            } catch (\InvalidArgumentException) {
                $phone = '';
            }
        }

        $city = $prefix === 'shipping'
            ? (string) $order->get_shipping_city()
            : (string) $order->get_billing_city();

        return [
            'name'           => $name,
            'street'         => $street,
            'street_number'  => $streetNum,
            'address_desc'   => $addressDesc,
            'town_id'        => $townId,
            'phone'          => $phone,
            'city'           => $city,
        ];
    }
}
