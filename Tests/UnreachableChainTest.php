<?php

namespace Omnibus\Bluedart\Tests;

use Omnibus\Bluedart\Api;
use Omnibus\Exception\CarrierException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Nothing of Blue Dart's credentials anywhere in what a log of a failed call writes: the messages,
 * the previous exception's (a copy of the HTTP client's, the secrets masked) and the traces'
 * arguments, which PHP keeps unless zend.exception_ignore_args is on.
 */
final class UnreachableChainTest extends TestCase
{
    private const SECRETS = ['bluedart-client-secret-7f2b64', 'bluedart-licence-key-0c5e19', 'issued-jwt-bluedart-6d4a83'];

    private string|false $ignoreArgs = false;

    protected function setUp(): void
    {
        $this->ignoreArgs = ini_set('zend.exception_ignore_args', '0');
    }

    protected function tearDown(): void
    {
        ini_set('zend.exception_ignore_args', false !== $this->ignoreArgs ? $this->ignoreArgs : '1');
    }

    /** @return iterable<string, array{\Closure(Api): mixed}> */
    public static function calls(): iterable
    {
        yield 'transit' => [static fn (Api $api): mixed => $api->call('POST', '/in/transportation/transit/v1/GetDomesticTransitTimeForPinCodeandProduct', ['profile' => $api->profile()])];
    }

    #[DataProvider('calls')]
    public function testNoPartOfAnUnreachableChainHoldsTheSecrets(\Closure $call): void
    {
        self::assertClean($call, static function (string $method, string $url): never {
            throw new TransportException(\sprintf('Could not resolve host: x for "%s".', $url), 6);
        });
    }

    #[DataProvider('calls')]
    public function testAnUnreachableCallAfterTheTokenHoldsNoSecret(\Closure $call): void
    {
        self::assertClean($call, static function (string $method, string $url): MockResponse {
            if (str_contains($url, '/token/v1/login')) {
                return new MockResponse(json_encode(['JWTToken' => 'issued-jwt-bluedart-6d4a83']));
            }

            throw new TransportException(\sprintf('Could not resolve host: x for "%s".', $url), 6);
        });
    }

    #[DataProvider('calls')]
    public function testAnErrorAnswerKeepsNoSecretInTheTraces(\Closure $call): void
    {
        self::assertClean($call, static fn (string $method, string $url): MockResponse => str_contains($url, '/token/v1/login') ? new MockResponse(json_encode(['JWTToken' => 'issued-jwt-bluedart-6d4a83'])) : new MockResponse('down', ['http_code' => 500]));
    }

    private static function assertClean(\Closure $call, \Closure $responses): void
    {
        $api = new Api(new MockHttpClient($responses), 'bluedart-client-id', 'bluedart-client-secret-7f2b64', 'BOM00001', 'bluedart-licence-key-0c5e19', '099960', 'BOM', true);
        try {
            $call($api);
            self::fail('no exception');
        } catch (CarrierException $e) {
            self::assertNotEmpty($e->getTrace());
            for ($one = $e; null !== $one; $one = $one->getPrevious()) {
                // A boolean, not assertStringNotContainsString(): a trace with its arguments is too large to export.
                $trace = print_r($one->getTrace(), true).$one->getTraceAsString();
                foreach (self::SECRETS as $secret) {
                    self::assertStringNotContainsString($secret, $one->getMessage());
                    self::assertFalse(str_contains($trace, $secret), \sprintf('The trace of %s holds "%s".', $one::class, $secret));
                }
            }
        }
    }
}
