<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Infrastructure\Api;

enum DExpressEndpoints: string
{
    case GetTowns          = '/GetTowns';
    case GetStreets        = '/GetStreets';
    case GetMunicipalities = '/GetMunicipalities';
    case GetStatusCodes    = '/GetStatusCodes';
    case GetDispensers     = '/GetDispensers';
    case GetLocations      = '/GetLocations';
    case GetCentres        = '/GetCentres';
    case GetShops          = '/GetShops';
    case CreateShipment    = '/CreateShipment';
    case CancelShipment    = '/CancelShipment';
    case GetShipmentStatus = '/GetShipmentStatus';
    case PrintLabel        = '/PrintLabel';
    case GetParcels        = '/GetParcels';
}
