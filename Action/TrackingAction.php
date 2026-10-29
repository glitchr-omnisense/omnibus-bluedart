<?php

namespace Omnibus\Bluedart\Action;

use Omnibus\Action\ActionInterface;
use Omnibus\Action\ApiAwareInterface;
use Omnibus\Action\ApiAwareTrait;
use Omnibus\Bluedart\Api;
use Omnibus\Model\Tracking as TrackingModel;
use Omnibus\Model\TrackingEvent;
use Omnibus\Model\TrackingStatus;
use Omnibus\Request\Request;
use Omnibus\Request\Tracking;

/** The Tracking API: the waybill's scans, oldest first. */
final class TrackingAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Tracking;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Tracking);
        $data = $this->api->call('GET', '/in/transportation/tracking/v1/shipment', null, ['handler' => 'tnt', 'loginid' => $this->api->loginId, 'numbers' => $request->trackingNumber, 'format' => 'json', 'lickey' => $this->api->licenceKey, 'scan' => 1, 'action' => 'custawbquery', 'verno' => '1.3', 'awb' => 'awb']);
        $shipment = $data['ShipmentData']['Shipment'] ?? $data['Shipment'] ?? [];
        $shipment = isset($shipment[0]) ? $shipment[0] : $shipment;
        $scans = $shipment['Scans']['ScanDetail'] ?? [];
        $scans = isset($scans[0]) ? $scans : [$scans];
        $events = [];
        foreach ($scans as $scan) {
            if (!\is_array($scan) || empty($scan['ScanDate'])) {
                continue;
            }
            $events[] = new TrackingEvent(\DateTimeImmutable::createFromFormat('d-M-Y H:i', $scan['ScanDate'].' '.($scan['ScanTime'] ?? '00:00')) ?: new \DateTimeImmutable(), self::status($scan['ScanType'] ?? null, $scan['Scan'] ?? null), (string) ($scan['Scan'] ?? ''), $scan['ScannedLocation'] ?? null, $scan['ScanCode'] ?? null);
        }
        usort($events, static fn (TrackingEvent $a, TrackingEvent $b) => $a->at <=> $b->at);
        $status = $events ? $events[array_key_last($events)]->status : TrackingStatus::UNKNOWN;
        if (!empty($shipment['StatusType']) && 'DL' === $shipment['StatusType']) {
            $status = TrackingStatus::DELIVERED;
        }
        $request->setResult(new TrackingModel('bluedart', $request->trackingNumber, $status, $events));
    }

    /**
     * The scan type decides first; without a known type, the words, the failed deliveries
     * ("undelivered", "not delivered") before "delivered", which they contain.
     */
    private static function status(?string $type, ?string $scan): TrackingStatus
    {
        $s = strtolower((string) $scan);

        return match (true) {
            'DL' === $type => TrackingStatus::DELIVERED,
            'UD' === $type => TrackingStatus::EXCEPTION,
            'RT' === $type => TrackingStatus::RETURNED,
            str_contains($s, 'undelivered') || str_contains($s, 'not delivered') || str_contains($s, 'attempt') => TrackingStatus::EXCEPTION,
            str_contains($s, 'delivered') => TrackingStatus::DELIVERED,
            str_contains($s, 'out for delivery') => TrackingStatus::OUT_FOR_DELIVERY,
            str_contains($s, 'return') => TrackingStatus::RETURNED,
            str_contains($s, 'softdata') || str_contains($s, 'shipment data') => TrackingStatus::PENDING,
            null !== $type && '' !== $type || '' !== $s => TrackingStatus::IN_TRANSIT,
            default => TrackingStatus::UNKNOWN,
        };
    }
}
