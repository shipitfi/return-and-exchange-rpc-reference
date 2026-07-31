<?php

namespace App\Services\ReturnProviderRpc;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** @phpstan-type JsonObject array<array-key, mixed> */
class JsonRpcRequestVerifier
{
    public function __construct(
        private readonly DemoCatalog $demoCatalog,
    ) {}

    /**
     * @param  JsonObject  $payload
     * @return array{status: int, code: int, message: string, data: JsonObject}|null
     */
    public function verify(Request $request, string $rawBody, array $payload): ?array
    {
        $params = $payload['params'] ?? null;
        $shopIdentifier = \is_array($params) ? ($params['shop'] ?? null) : null;

        if (! \is_string($shopIdentifier) || $shopIdentifier === '') {
            return [
                'status' => 401,
                'code' => 40100,
                'message' => 'The shop field is required before signature validation.',
                'data' => [],
            ];
        }

        $sharedSecret = $this->demoCatalog->sharedSecretForShop($shopIdentifier);

        if ($sharedSecret === null) {
            return [
                'status' => 401,
                'code' => 40101,
                'message' => 'Unknown shop or shared secret.',
                'data' => [
                    'shop' => $shopIdentifier,
                ],
            ];
        }

        $timestamp = $request->header('X-Shipit-Timestamp');
        $nonce = $request->header('X-Shipit-Nonce');
        $signature = $request->header('X-Shipit-Signature');

        if (! \is_string($timestamp)
            || ! \is_string($nonce)
            || \trim($nonce) === ''
            || ! \is_string($signature)) {
            return [
                'status' => 401,
                'code' => 40102,
                'message' => 'Missing one or more required signature headers.',
                'data' => [],
            ];
        }

        if (! \ctype_digit($timestamp)) {
            return [
                'status' => 401,
                'code' => 40103,
                'message' => 'The timestamp header must be a Unix timestamp.',
                'data' => [],
            ];
        }

        $timestampSkew = \abs(\time() - (int) $timestamp);

        if ($timestampSkew > $this->configuredPositiveInteger('timestamp_tolerance_seconds', 300)) {
            return [
                'status' => 401,
                'code' => 40104,
                'message' => 'The request timestamp is outside the allowed skew window.',
                'data' => [
                    'timestamp_skew_seconds' => $timestampSkew,
                ],
            ];
        }

        $expectedSignature = $this->sign(
            sharedSecret: $sharedSecret,
            timestamp: $timestamp,
            nonce: $nonce,
            rawBody: $rawBody,
        );

        if (! \hash_equals($expectedSignature, \mb_strtolower($signature))) {
            return [
                'status' => 401,
                'code' => 40106,
                'message' => 'The HMAC signature does not match the request body.',
                'data' => [],
            ];
        }

        $nonceCacheKey = $this->nonceCacheKey($shopIdentifier, $nonce);

        if (! Cache::add(
            $nonceCacheKey,
            true,
            now()->addSeconds($this->configuredPositiveInteger('nonce_ttl_seconds', 600)),
        )) {
            return [
                'status' => 401,
                'code' => 40105,
                'message' => 'The nonce has already been used for this shop.',
                'data' => [],
            ];
        }

        return null;
    }

    public function sign(
        string $sharedSecret,
        string $timestamp,
        string $nonce,
        string $rawBody,
    ): string {
        return \hash_hmac(
            'sha256',
            $timestamp."\n".$nonce."\n".$rawBody,
            $sharedSecret,
        );
    }

    private function configuredPositiveInteger(string $key, int $default): int
    {
        $value = config('shipit_return_rpc.'.$key, $default);

        return \is_int($value) && $value > 0 ? $value : $default;
    }

    private function nonceCacheKey(string $shopIdentifier, string $nonce): string
    {
        return 'shipit-return-rpc:nonce:'.$shopIdentifier.':'.$nonce;
    }
}
