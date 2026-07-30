<?php

namespace App\Services\ReturnProviderRpc;

use Illuminate\Http\JsonResponse;

/** @phpstan-type JsonObject array<array-key, mixed> */
class JsonRpcResponseFactory
{
    /** @param JsonObject $result */
    public function success(mixed $id, array $result): JsonResponse
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ]);
    }

    public function malformedJson(string $message): JsonResponse
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => [
                'code' => -32700,
                'message' => 'Malformed JSON: '.$message,
            ],
        ], 400);
    }

    public function invalidRequest(mixed $id, string $message): JsonResponse
    {
        return $this->error(
            id: $id,
            code: -32600,
            message: $message,
            data: [],
            status: 200,
        );
    }

    public function invalidParams(mixed $id, string $message): JsonResponse
    {
        return $this->error(
            id: $id,
            code: -32602,
            message: $message,
            data: [],
            status: 200,
        );
    }

    public function methodNotFound(mixed $id, string $message): JsonResponse
    {
        return $this->error(
            id: $id,
            code: -32601,
            message: $message,
            data: [],
            status: 200,
        );
    }

    /** @param JsonObject $data */
    public function error(
        mixed $id,
        int $code,
        string $message,
        array $data,
        int $status,
    ): JsonResponse {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($data !== []) {
            $error['data'] = $data;
        }

        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => $error,
        ], $status);
    }
}
