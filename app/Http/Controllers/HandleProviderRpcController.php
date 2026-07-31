<?php

namespace App\Http\Controllers;

use App\Services\ReturnProviderRpc\JsonRpcDispatcher;
use App\Services\ReturnProviderRpc\JsonRpcRequestVerifier;
use App\Services\ReturnProviderRpc\JsonRpcResponseFactory;
use BadMethodCallException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use JsonException;
use OutOfBoundsException;
use Throwable;

class HandleProviderRpcController extends Controller
{
    public function __invoke(
        Request $request,
        JsonRpcRequestVerifier $jsonRpcRequestVerifier,
        JsonRpcDispatcher $jsonRpcDispatcher,
        JsonRpcResponseFactory $jsonRpcResponseFactory,
    ): JsonResponse {
        $rawBody = (string) $request->getContent();

        try {
            $decodedPayload = \json_decode($rawBody, false, 512, \JSON_THROW_ON_ERROR);
            $payload = \json_decode($rawBody, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            return $jsonRpcResponseFactory->malformedJson($jsonException->getMessage());
        }

        if (! \is_object($decodedPayload) || ! \is_array($payload)) {
            return $jsonRpcResponseFactory->invalidRequest(
                null,
                'The JSON-RPC body must decode to an object.',
            );
        }

        $requestId = $payload['id'] ?? null;
        $responseId = \is_string($requestId)
            || \is_int($requestId)
            || \is_float($requestId)
            || $requestId === null
                ? $requestId
                : null;

        $authenticationFailure = $jsonRpcRequestVerifier->verify(
            $request,
            $rawBody,
            $payload,
        );

        if ($authenticationFailure !== null) {
            return $jsonRpcResponseFactory->error(
                id: $responseId,
                code: (int) $authenticationFailure['code'],
                message: (string) $authenticationFailure['message'],
                data: $authenticationFailure['data'],
                status: (int) $authenticationFailure['status'],
            );
        }

        if (($payload['jsonrpc'] ?? null) !== '2.0') {
            return $jsonRpcResponseFactory->invalidRequest(
                $responseId,
                'The jsonrpc field must be 2.0.',
            );
        }

        if (! \array_key_exists('id', $payload)) {
            return $jsonRpcResponseFactory->invalidRequest(
                null,
                'The id field is required.',
            );
        }

        if (! \is_string($payload['id'])
            && ! \is_int($payload['id'])
            && ! \is_float($payload['id'])
            && $payload['id'] !== null) {
            return $jsonRpcResponseFactory->invalidRequest(
                null,
                'The id field must be a string, number, or null.',
            );
        }

        if (! \is_string($payload['method'] ?? null) || $payload['method'] === '') {
            return $jsonRpcResponseFactory->invalidRequest(
                $payload['id'],
                'The method field must be a non-empty string.',
            );
        }

        if (! \is_array($payload['params'] ?? null)) {
            return $jsonRpcResponseFactory->invalidParams(
                $payload['id'],
                'The params field must be an object.',
            );
        }

        try {
            $result = $jsonRpcDispatcher->dispatch(
                $payload['method'],
                $payload['params'],
            );

            return $jsonRpcResponseFactory->success($payload['id'], $result);
        } catch (BadMethodCallException $badMethodCallException) {
            return $jsonRpcResponseFactory->methodNotFound(
                $payload['id'],
                $badMethodCallException->getMessage(),
            );
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $jsonRpcResponseFactory->invalidParams(
                $payload['id'],
                $invalidArgumentException->getMessage(),
            );
        } catch (OutOfBoundsException $outOfBoundsException) {
            return $jsonRpcResponseFactory->error(
                id: $payload['id'],
                code: 40401,
                message: $outOfBoundsException->getMessage(),
                data: [],
                status: 200,
            );
        } catch (Throwable $throwable) {
            report($throwable);

            return $jsonRpcResponseFactory->error(
                id: $payload['id'],
                code: -32603,
                message: 'Internal error',
                data: [],
                status: 500,
            );
        }
    }
}
