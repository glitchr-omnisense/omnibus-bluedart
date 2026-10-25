<?php

namespace Omnibus\Bluedart\Tests;

use Omnibus\Bluedart\BluedartGatewayFactory;
use Omnibus\GatewayFactoryInterface;
use Omnibus\Model\Address;
use Omnibus\Model\Parcel;
use Omnibus\Model\Shipment;
use Omnibus\Testing\GatewayContractTests;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** The conformance suite on Blue Dart's recorded answers (API gateway sandbox; the API shape is unverified). */
final class BluedartContractTest extends TestCase
{
    use GatewayContractTests;

    protected function gatewayFactory(HttpClientInterface $http): GatewayFactoryInterface
    {
        return new BluedartGatewayFactory($http);
    }

    protected function gatewayOptions(): array
    {
        return ['client_id' => 'id', 'client_secret' => 'secret', 'login_id' => 'BOM12345', 'licence_key' => 'lic', 'customer_code' => '123456', 'origin_area' => 'BOM', 'sandbox' => true];
    }

    protected function sessionRoutes(): array
    {
        return ['/token/v1/login'];
    }

    /** Blue Dart publishes transit times, not prices: the shop's prices ride on the shipment ("prices" option). */
    protected function contractShipment(): Shipment
    {
        return new Shipment(new Address('Glitch Art', ['Nariman Point'], '400021', 'Mumbai', 'IN', phone: '02212345678'), new Address('Alex Martin', ['MG Road'], '560001', 'Bengaluru', 'IN', phone: '9876543210'), [new Parcel(1500, 30, 20, 10, 150000, 'INR')], reference: 'ORDER-1042', options: ['prices' => ['A' => 45000, 'D' => 18000]]);
    }

    protected function contractTrackingNumber(): string
    {
        return '79123456789';
    }

    protected function recordedAnswers(): array
    {
        return [
            '/token/v1/login' => ['JWTToken' => 'jwt'],
            'GetDomesticTransitTimeForPinCodeandProduct' => static fn (string $method, string $url, array $options) => ['GetDomesticTransitTimeForPinCodeandProductResult' => ['IsError' => false, 'ExpectedDateDelivery' => '/Date('.((time() + (str_contains((string) $options['body'], '"A"') ? 1 : 3) * 86400) * 1000).')/']],
            '/GenerateWayBill' => ['GenerateWayBillResult' => ['IsError' => false, 'AWBNo' => '79123456789', 'AWBPrintContent' => array_map('ord', str_split('%PDF-1.4 bd'))]],
            '/tracking/v1/shipment' => ['ShipmentData' => ['Shipment' => ['WaybillNo' => '79123456789', 'StatusType' => 'DL', 'Scans' => ['ScanDetail' => [
                ['Scan' => 'SHIPMENT DELIVERED', 'ScanCode' => '000', 'ScanDate' => '02-Oct-2026', 'ScanTime' => '11:30', 'ScanType' => 'DL', 'ScannedLocation' => 'Bengaluru'],
                ['Scan' => 'SHIPMENT PICKED UP', 'ScanCode' => '001', 'ScanDate' => '01-Oct-2026', 'ScanTime' => '17:00', 'ScanType' => 'PU', 'ScannedLocation' => 'Mumbai'],
            ]]]]],
            '/CancelWaybill' => ['CancelWaybillResult' => ['IsError' => false]],
        ];
    }
}
