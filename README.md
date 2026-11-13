# omnibus/bluedart

Blue Dart for [omnisense/omnibus](https://github.com/glitchr-omnisense/omnibus): serviceability and
transit time (Transit API), waybills with their labels (Waybill API), tracking (Tracking API) and
cancellation - Blue Dart's API gateway with a JWT.

## Install and a first call

```sh
composer require omnibus/bluedart
```

No account for this first call: Blue Dart's answer is recorded (`Omnibus\Testing\RecordedHttpClient`
answers in place of the API), so nothing leaves the process. With your own credentials, give the
factory your HTTP client (or none: it makes its own) and drop the recorded one.

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnibus\Bluedart\BluedartGatewayFactory;
use Omnibus\Testing\RecordedHttpClient;

// Blue Dart's answer, recorded: no account, and nothing leaves the process.
$http = new RecordedHttpClient([
    '/token/v1/login' => ['JWTToken' => 'jwt'],
    '/tracking/v1/shipment' => ['ShipmentData' => ['Shipment' => ['WaybillNo' => '79123456789', 'StatusType' => 'DL', 'Scans' => ['ScanDetail' => [
        ['Scan' => 'SHIPMENT DELIVERED', 'ScanCode' => '000', 'ScanDate' => '02-Oct-2026', 'ScanTime' => '11:30', 'ScanType' => 'DL', 'ScannedLocation' => 'Bengaluru'],
    ]]]]],
]);
$gateway = (new BluedartGatewayFactory($http))->create(['client_id' => 'id', 'client_secret' => 'secret', 'login_id' => 'BOM12345', 'licence_key' => 'lic', 'customer_code' => '123456', 'origin_area' => 'BOM', 'sandbox' => true]);

$tracking = $gateway->track('79123456789');
echo $tracking->status->value, ' - ', $tracking->latest()?->description, "\n";   // delivered - SHIPMENT DELIVERED
```

A real call needs a Blue Dart account (the credentials below). **Unverified**: the request and
answer shapes come from Blue Dart's published documentation, and the package has not been run
against its service.

## Configuration

```php
$gateway = (new BluedartGatewayFactory($http))->create($options);   // $http: the application's HTTP client - none given, the factory makes its own; the options below
```

No framework needed: the package requires `omnisense/omnibus` and `symfony/http-client`. In a
Symfony application, the same through the bundle's configuration:

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
                sandbox: true
                rates: [...]        # or prices per product through the shipment option "prices"
```

The service is the product code (A Domestic Priority, E Dart Apex, D Dart Surfaceline). Blue Dart
quotes no price: rating checks which products serve the lane and in how many days, and takes the
amount from the shipment option `prices` (product => minor units) or from configured `rates`.
Shipment options: `description`, `instructions`, `sub_product` (P by default), `register_pickup`.
No pickup points: Blue Dart delivers to the door.

Credentials: an app on [Blue Dart's developer portal](https://www.bluedart.com/developer) gives
the client id and secret; the ShipDart profile (login id, licence key, customer code, area) comes
from your account manager.

Built from Blue Dart's published API documentation and tested on recorded answers; **unverified**
against the sandbox until an account's keys are at hand.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by Blue Dart Express Limited. It reaches their service on behalf of whoever runs it, and
it does not redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT
licence covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with Blue Dart's terms
([bluedart.com/webterms](https://www.bluedart.com/webterms)), for obtaining whatever they require
(an account, a key, a licence or subscription, an attribution or a citation, a rate limit to
respect), and for any use made of what it returns. The authors publish it free of charge, make no
commercial use of it, and accept no liability for how it is used (see [LICENSE](LICENSE)).

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
