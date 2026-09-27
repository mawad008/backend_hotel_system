<?php

namespace App\Support\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Standard API response envelope for /api/v1:
 *
 *   { "success": true,  "message": "...", "data": ..., "meta": {...}? }
 *   { "success": false, "message": "...", "errors": {...}? }
 */
trait ApiResponse
{
    protected function success(mixed $data = null, string $message = 'OK', int $status = 200, array $meta = []): JsonResponse
    {
        $payload = [
            'success' => true,
            'message' => $message,
        ];

        if ($data instanceof ResourceCollection) {
            $wrapped = $data->response()->getData(true);
            $payload['data'] = $wrapped['data'] ?? [];
            $payload['meta'] = array_merge($wrapped['meta'] ?? [], $meta);
        } else {
            if ($data instanceof JsonResource) {
                // resolve() applies the resource's conditional-attribute
                // filtering (when()/whenLoaded()/MissingValue), which a raw
                // toArray() call skips — matching how the ResourceCollection
                // branch above already resolves via ->response().
                $data = $data->resolve(request());
            }

            $payload['data'] = $data;

            if ($meta !== []) {
                $payload['meta'] = $meta;
            }
        }

        return response()->json($payload, $status);
    }

    protected function error(string $message, int $status = 400, mixed $errors = null): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }
}
