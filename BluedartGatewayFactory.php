<?php

namespace Omnibus\Bluedart;

use Omnibus\Bluedart\Action\CancelAction;
use Omnibus\Bluedart\Action\RatingAction;
use Omnibus\Bluedart\Action\ShippingAction;
use Omnibus\Bluedart\Action\TrackingAction;
use Omnibus\Config;
use Omnibus\GatewayFactory;
use Symfony\Component\HttpClient\HttpClient;

/**
 *   options:
 *     client_id: '%env(BLUEDART_CLIENT_ID)%'        # the API gateway app (bluedart.com/developer)
 *     client_secret: '%env(BLUEDART_CLIENT_SECRET)%'
 *     login_id: '%env(BLUEDART_LOGIN)%'             # the ShipDart profile: login id,
 *     licence_key: '%env(BLUEDART_LICENCE)%'        #   licence key and
 *     customer_code: '%env(BLUEDART_CUSTOMER)%'     #   customer code
 *     origin_area: BOM                              # the account's area code
 *     sandbox: true
 *     rates: [...]                                  # prices from configuration: the Transit API quotes none
 *
 * No pickup points: Blue Dart delivers to the door. Unverified until an account's keys are at hand.
 */
final class BluedartGatewayFactory extends GatewayFactory
{
    protected function populateConfig(Config $config): void
    {
        $config->defaults([
            'omnibus.factory_name' => 'bluedart',
            'omnibus.factory_title' => 'Blue Dart',
            'omnibus.policy' => __DIR__.'/policy.json',
            'omnibus.required_options' => ['client_id', 'client_secret', 'login_id', 'licence_key', 'customer_code'],
            'origin_area' => null,
            'sandbox' => false,
            'omnibus.api' => function (Config $c) {
                $http = $this->http ?? HttpClient::create();

                return new Api($http, (string) $c['client_id'], (string) $c['client_secret'], (string) $c['login_id'], (string) $c['licence_key'], (string) $c['customer_code'], $c['origin_area'] ?: null, (bool) $c['sandbox'], backoff: $c->backoff());
            },
            'omnibus.action.rating' => static fn (Config $c) => $c->get('rates') ? null : new RatingAction(),
            'omnibus.action.shipping' => new ShippingAction(),
            'omnibus.action.tracking' => new TrackingAction(),
            'omnibus.action.cancel' => new CancelAction(),
        ]);
    }
}
