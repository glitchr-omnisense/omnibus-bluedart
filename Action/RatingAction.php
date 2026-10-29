<?php

namespace Omnibus\Bluedart\Action;

use Omnibus\Action\ActionInterface;
use Omnibus\Action\ApiAwareInterface;
use Omnibus\Action\ApiAwareTrait;
use Omnibus\Bluedart\Api;
use Omnibus\Model\Rate;
use Omnibus\Request\Rating;
use Omnibus\Request\Request;

/**
 * The Transit API's GetDomesticTransitTimeForPinCodeandProduct: whether the lane
 * is served and in how many days, for each product (A Domestic Priority, E Dart
 * Apex, D Dart Surfaceline). Blue Dart quotes no price: the amount is the
 * shipment's "prices" option per product (minor units), and a product without
 * a price is not rated (nor asked about).
 */
final class RatingAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    private const PRODUCTS = ['A' => 'Domestic Priority', 'E' => 'Dart Apex', 'D' => 'Dart Surfaceline'];

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Rating;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Rating);
        $s = $request->shipment;
        $prices = (array) $s->option('prices', []);
        $rates = [];
        $failure = null;
        $answered = 0;
        foreach ($s->option('products', array_keys(self::PRODUCTS)) as $product) {
            if (!isset($prices[$product])) {
                continue; // no price, no rate: 0 INR would be a price
            }
            try {
                $data = $this->api->call('POST', '/in/transportation/transit/v1/GetDomesticTransitTimeForPinCodeandProduct', ['pPinCodeFrom' => $s->sender->postcode, 'pPinCodeTo' => $s->recipient->postcode, 'pProductCode' => $product, 'pSubProductCode' => (string) $s->option('sub_product', 'P'), 'pPudate' => '/Date('.(($s->shippingDate ?? new \DateTimeImmutable())->getTimestamp() * 1000).')/', 'pPickupTime' => '16:00', 'profile' => $this->api->profile()]);
            } catch (\Omnibus\Exception\CarrierException $e) {
                $failure = $e;
                continue;
            }
            ++$answered;
            $result = $data['GetDomesticTransitTimeForPinCodeandProductResult'] ?? $data;
            if (!empty($result['IsError']) || empty($result['ExpectedDateDelivery'])) {
                continue;
            }
            $days = null;
            if (preg_match('#/Date\((\d+)#', (string) $result['ExpectedDateDelivery'], $m)) {
                $days = max(0, (int) ceil(((int) ($m[1] / 1000) - ($s->shippingDate ?? new \DateTimeImmutable())->getTimestamp()) / 86400));
            }
            $rates[] = new Rate('bluedart', $product, self::PRODUCTS[$product] ?? 'Blue Dart '.$product, (int) $prices[$product], 'INR', $days);
        }
        if (0 === $answered && null !== $failure) {
            throw $failure; // every service refused: the carrier failed, not "no rate"
        }
        usort($rates, static fn (Rate $a, Rate $b) => $a->amount <=> $b->amount);
        $request->setResult($rates);
    }
}
