<?php

namespace Omnibus\Bluedart;

use Omnibus\Exception\CarrierException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Blue Dart's APIs (apigateway.bluedart.com): a JWT from the Token API with
 * the client id and secret, then the Transit (serviceability and transit
 * time), Waybill (shipments and labels), Tracking and Cancel APIs.
 */
final class Api
{
    public const LIVE = 'https://apigateway.bluedart.com';
    public const TEST = 'https://apigateway-sandbox.bluedart.com';

    private ?string $token = null;
    private int $expiresAt = 0;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $clientId,
        private readonly string $clientSecret,
        public readonly string $loginId,
        public readonly string $licenceKey,
        public readonly string $customerCode,
        public readonly ?string $originArea = null,
        public readonly bool $sandbox = false,
        private readonly int $timeout = 20,
    ) {
    }

    public function base(): string
    {
        return $this->sandbox ? self::TEST : self::LIVE;
    }

    /** @return array<string, mixed> */
    public function profile(): array
    {
        return ['LoginID' => $this->loginId, 'LicenceKey' => $this->licenceKey, 'Api_type' => 'S', 'Area' => $this->originArea ?? 'BOM', 'Customercode' => $this->customerCode, 'IsAdmin' => 'false', 'Version' => '1.10'];
    }

    /** @return array<string, mixed> */
    public function call(string $method, string $path, ?array $body = null, array $query = []): array
    {
        try {
            $response = $this->http->request($method, $this->base().$path, [
                'headers' => ['JWTToken' => $this->token(), 'Content-Type' => 'application/json', 'Accept' => 'application/json'],
                'query' => $query,
                'body' => null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR),
                'timeout' => $this->timeout,
            ]);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
            $data = json_decode($content, true);
        } catch (HttpExceptionInterface|\JsonException $e) {
            throw new CarrierException('bluedart', 'Blue Dart request failed: '.$e->getMessage(), null, $e);
        }
        if (!\is_array($data)) {
            // Tracking answers XML
            $xml = @simplexml_load_string($content);
            if (false !== $xml && $status < 400 && 'html' !== strtolower($xml->getName())) { // not a proxy's error page
                return json_decode(json_encode($xml), true) ?: [];
            }
            throw new CarrierException('bluedart', sprintf('Blue Dart answered HTTP %d with a body that is neither its JSON nor its XML.', $status));
        }
        if ($status >= 400) {
            throw new CarrierException('bluedart', (string) ($data['error-response'][0]['Message'] ?? $data['Message'] ?? $data['message'] ?? $data['fault']['faultstring'] ?? sprintf('HTTP %d', $status)), isset($data['error-response'][0]['ErrorCode']) ? (string) $data['error-response'][0]['ErrorCode'] : null);
        }

        return $data;
    }

    private function token(): string
    {
        if (null !== $this->token && time() < $this->expiresAt - 60) {
            return $this->token;
        }
        try {
            $data = $this->http->request('GET', $this->base().'/in/transportation/token/v1/login', ['headers' => ['ClientID' => $this->clientId, 'clientSecret' => $this->clientSecret, 'Accept' => 'application/json'], 'timeout' => $this->timeout])->toArray(false);
        } catch (HttpExceptionInterface $e) {
            throw new CarrierException('bluedart', 'Blue Dart gave no token: '.$e->getMessage(), null, $e);
        }
        if (empty($data['JWTToken'])) {
            throw new CarrierException('bluedart', (string) ($data['error-response'][0]['Message'] ?? 'Blue Dart gave no token: check the client id and secret.'));
        }
        $this->token = (string) $data['JWTToken'];
        $this->expiresAt = time() + 23 * 3600;

        return $this->token;
    }
}
