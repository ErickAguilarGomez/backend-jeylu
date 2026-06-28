<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CastResponseNumbers
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);
            if (is_array($data)) {
                $data = $this->castArray($data);
                $response->setData($data);
            }
        }

        return $response;
    }

    /**
     * Recursively cast fields to integer.
     *
     * @param array $data
     * @return array
     */
    private function castArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->castArray($value);
            } elseif (is_string($value)) {
                // Cast IDs, foreign keys, creator/updater references, stocks and quantities
                if ($key === 'id' || str_ends_with($key, '_id') || str_ends_with($key, '_by') || $key === 'stock' || $key === 'total_stock' || $key === 'quantity' || $key === 'total_sales') {
                    if (ctype_digit($value)) {
                        $data[$key] = (int)$value;
                    }
                } elseif ($key === 'price' || $key === 'purchase_price' || $key === 'total' || $key === 'commission_amount' || $key === 'total_amount') {
                    if (is_numeric($value)) {
                        $data[$key] = (float)$value;
                    }
                }
            }
        }
        return $data;
    }
}
