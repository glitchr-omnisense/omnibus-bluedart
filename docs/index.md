# omnibus/bluedart

Blue Dart for [omniscient/omnibus](https://github.com/glitchr-studio/omnibus): serviceability and
transit times, waybills with their PDF labels, tracking and cancellation, through Blue Dart's API
gateway on a JWT. Blue Dart quotes no price: rating says which products serve the lane and in how
many days, and the amounts come from configuration. The gateway calls:

| Operation | API | Request |
|---|---|---|
| token | Token | `GET /in/transportation/token/v1/login` |
| rate | Transit | `POST /in/transportation/transit/v1/GetDomesticTransitTimeForPinCodeandProduct`, once per product |
| ship | Waybill | `POST /in/transportation/waybill/v1/GenerateWayBill` |
| cancel | Waybill | `POST /in/transportation/waybill/v1/CancelWaybill` |
| track | Tracking | `GET /in/transportation/tracking/v1/shipment` |

on `https://apigateway.bluedart.com` (live) or `https://apigateway-sandbox.bluedart.com`
(`sandbox: true`). Every call sends the token as `JWTToken` and has a 20-second timeout.

## Installation

```sh
composer require omnibus/bluedart
```

Requires PHP 8.2, `omniscient/omnibus` (2.x) and `symfony/http-client` (6.4, 7 or 8); given no
HTTP client, the factory makes one with `HttpClient::create()`. In a Symfony application with
`Omnibus\Bridge\Symfony\OmnibusBundle`, the factory is registered as soon as the package is
installed.

## Credentials

- An app on [Blue Dart's developer portal](https://www.bluedart.com/developer): its **client id**
  and **client secret**, for the token.
- The ShipDart profile, from the account manager: the **login id**, the **licence key**, the
  **customer code** and the account's **area** code. The profile is sent with every rating,
  shipping and cancellation request (`LoginID`, `LicenceKey`, `Api_type S`, `Area`, `Customercode`,
  `IsAdmin false`, `Version 1.10`); tracking sends the login id and licence key in its query.

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `client_id` | string | - | yes | the developer portal app's client id |
| `client_secret` | string | - | yes | its client secret |
| `login_id` | string | - | yes | the ShipDart login id |
| `licence_key` | string | - | yes | the ShipDart licence key |
| `customer_code` | string | - | yes | the customer code (also the shipper's `CustomerCode`) |
| `origin_area` | ?string | `null` (sent as `BOM`) | no | the account's area code |
| `sandbox` | bool | `false` | no | `true`: the sandbox gateway |
| `rates` | list | - | no | prices from configuration instead of the Transit API ([the rates option](https://github.com/glitchr-studio/omnibus/blob/2.x/docs/configuration.md#the-rates-option)): with it, the Transit API is not called |

Shipment options (`Shipment::$options`):

| Option | Read by | Default | |
|---|---|---|---|
| `products` | rate | `A`, `E`, `D` | the products to check |
| `prices` | rate | `[]` | product => amount in minor units (paise); a product without one is rated at 0 |
| `description` | ship | `Merchandise` | the commodity |
| `instructions` | ship | `''` | special instructions, 100 characters |
| `sub_product` | ship | `P` | the sub-product code |
| `register_pickup` | ship | `false` | `true`: Blue Dart registers a pickup with the waybill |

## Capabilities

| Request | Supported |
|---|---|
| `Rating` | yes: serviceability and transit days from the Transit API, amounts from `prices`; or configured rates with `rates` |
| `Shipping` | yes |
| `Tracking` | yes |
| `Pickup` | no: Blue Dart delivers to the door |
| `GetSlip` | no |
| `Cancel` | yes |
| `Paperless` | no |

## Behaviour

**Token.** Asked for on the first call with the headers `ClientID` and `clientSecret`; the
`JWTToken` returned is kept for 23 hours (less one minute), whatever Blue Dart says of its life.

**Products.** The service is Blue Dart's product code:

| Code | Product |
|---|---|
| `A` | Domestic Priority (the default when booking) |
| `E` | Dart Apex |
| `D` | Dart Surfaceline |

**Rate.** For each product (`products`, else the three above, whatever `Shipment::$service`
says): the Transit API from the sender's PIN code to the recipient's, sub-product `P` (the
`sub_product` option is not read here), the pickup date `Shipment::$shippingDate` (else now) as
`/Date(milliseconds)/` and the pickup time `16:00`. A product answering with an error or without
an expected delivery date does not serve the lane and gives no rate. A product that does gives a
rate: the amount `prices[<code>]` (0 when the option has none) in `INR`, `days` the whole days
from the pickup date to the expected delivery date. When every call is refused, the last refusal
is raised. Rates come cheapest first; `toPickupPoint` is `false`.

> [!WARNING]
> Without `prices` (or `rates`), every served product is rated at 0 INR: give the contract's
> prices in one of the two.

**Ship.** `GenerateWayBill`:

- the consignee: two address lines and the city (30 characters each), the person as attention,
  the company (else the person) as name, the email, the phone's digits as mobile and telephone,
  the PIN code;
- the shipper, also the return address: the same fields from the sender, the customer code, the
  origin area (`BOM` by default), the person as sender, not paid by the recipient;
- services: the total weight in kilograms (at least 0.1), no amount to collect, the
  `description`, the shipment's `reference` (else a generated `omnibus...` id) as credit
  reference (50 characters), the declared value (the sum of the parcels' `value`), one dimension
  line per parcel (a missing length, width or height counts as 10 cm), the pickup date and time
  `1600`, the number of parcels, the product `Shipment::$service` (else `A`), product type
  `Dutables`, `register_pickup`, `sub_product`, the `instructions`.

An answer with `IsError` raises its first status. The tracking number is the `AWBNo`; the label is
`AWBPrintContent`, given as a list of bytes or base64, `application/pdf`. `trackingUrl` is
`https://www.bluedart.com/tracking?trackfor=0&trackno=<number>`. A response without a number is a
`CarrierException` (`Blue Dart issued no waybill.`).

**Track.** The tracking query (`handler=tnt`, `action=custawbquery`, `verno=1.3`, `scan=1`) with
the login id and licence key; Blue Dart answers XML or JSON, both read. The scans of the first
shipment: date and time `d-M-Y H:i` (a scan whose date cannot be read is dated now), the scan text
as description, the scanned location, the scan code as `code`; sorted oldest first. The status is
the latest scan's, or `DELIVERED` when the shipment's `StatusType` is `DL`. A scan's status, in
this order:

| Scan type | Or the text contains | `TrackingStatus` |
|---|---|---|
| `DL` | `delivered` | `DELIVERED` |
| | `out for delivery` | `OUT_FOR_DELIVERY` |
| `RT` | `return` | `RETURNED` |
| `UD` | `undelivered`, `attempt` | `EXCEPTION` |
| | `softdata`, `shipment data` | `PENDING` |
| any other type, or any other text | | `IN_TRANSIT` |
| neither | | `UNKNOWN` |

`undelivered` contains `delivered`: an "undelivered" scan without the type `UD` reads as
`DELIVERED`.

**Cancel.** `CancelWaybill` for the AWB number: `true` unless the answer has `IsError`.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnibus\Bluedart\BluedartGatewayFactory;
use Omnibus\Model\Address;
use Omnibus\Model\Parcel;
use Omnibus\Model\Shipment;
use Symfony\Component\HttpClient\HttpClient;

$bluedart = (new BluedartGatewayFactory(HttpClient::create()))->create([
    'client_id' => getenv('BLUEDART_CLIENT_ID'),
    'client_secret' => getenv('BLUEDART_CLIENT_SECRET'),
    'login_id' => getenv('BLUEDART_LOGIN'),
    'licence_key' => getenv('BLUEDART_LICENCE'),
    'customer_code' => getenv('BLUEDART_CUSTOMER'),
    'origin_area' => 'BOM',
    'sandbox' => true,
]);

$shipment = new Shipment(
    new Address('Glitch Art', ['1 Marine Drive'], '400020', 'Mumbai', 'IN', phone: '+91 22 1234 5678'),
    new Address('Ada Lovelace', ['1 MG Road'], '560001', 'Bengaluru', 'IN', email: 'ada@example.org', phone: '+91 80 1234 5678'),
    [new Parcel(1500, 30, 20, 10, value: 150000, currency: 'INR')],
    service: 'A',
    reference: 'CMD-1042',
    options: ['prices' => ['A' => 32000, 'E' => 41000, 'D' => 18000], 'description' => 'Prints'],
);

foreach ($bluedart->rate($shipment) as $rate) {
    printf("%s %-18s %7.2f %s, %s days\n", $rate->service, $rate->label, $rate->amount / 100, $rate->currency, $rate->days ?? '?');
}

$label = $bluedart->ship($shipment);
file_put_contents($label->trackingNumber.'.pdf', (string) $label->content);
echo $label->trackingNumber, ' ', $label->trackingUrl, "\n";

$tracking = $bluedart->track($label->trackingNumber);
echo $tracking->status->value, ' ', $tracking->latest()?->location, "\n";

var_dump($bluedart->cancel($label->trackingNumber));
```

In Symfony:

```yaml
omnibus:
    gateways:
        bluedart:
            factory: bluedart
            options:
                client_id: '%env(BLUEDART_CLIENT_ID)%'
                client_secret: '%env(BLUEDART_CLIENT_SECRET)%'
                login_id: '%env(BLUEDART_LOGIN)%'
                licence_key: '%env(BLUEDART_LICENCE)%'
                customer_code: '%env(BLUEDART_CUSTOMER)%'
                origin_area: BOM
                sandbox: '%env(bool:BLUEDART_SANDBOX)%'
```

## Errors

Every failure is an `Omnibus\Exception\CarrierException` whose `carrier` is `bluedart`:

- an HTTP status of 400 or more: `error-response[0].Message`, else `Message`, else `message`, else
  the fault's `faultstring`, else `HTTP <status>`; `error-response[0].ErrorCode` in `carrierCode`;
- a body that is neither JSON nor XML (an HTML page included): `Blue Dart answered HTTP <status>
  with a body that is neither its JSON nor its XML.`; a network error: `Blue Dart request failed:
  ...`;
- no token: `Blue Dart gave no token: ...`, Blue Dart's message, or `Blue Dart gave no token:
  check the client id and secret.`;
- a waybill refused (`IsError`): its first status's `StatusInformation` (else `Blue Dart refused
  the waybill.`), its `StatusCode` in `carrierCode`; no number: `Blue Dart issued no waybill.`

A missing option among the five credentials is an `InvalidConfigException` when the gateway is
built; `Pickup`, `GetSlip` and `Paperless` raise `RequestNotSupportedException` before any call.

## Verified, and not

Tested offline: `Tests/BluedartGatewayTest.php` (served products rated with configured prices and
transit days, a product not served left out, `INR`, the profile and the `JWTToken` header; a
waybill generated with its PDF, the product, the consignee's PIN code, the customer code and the
declared value; tracking with its location; the cancellation) and `Tests/BluedartContractTest.php`,
the family's conformance suite on recorded answers. The example above was run in the core's
harness on those recorded answers.

Not verified: the README and the factory mark the package **unverified** against the sandbox until
an account's keys are at hand; it is built from Blue Dart's published API documentation.

## Responsibility

An independent, unofficial client, not affiliated with Blue Dart Express Limited. Whoever runs it
complies with Blue Dart's terms ([bluedart.com/webterms](https://www.bluedart.com/webterms)): see
the README's Responsibility section.
