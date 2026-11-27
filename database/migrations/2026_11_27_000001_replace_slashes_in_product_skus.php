<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection();

        if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            // Keep concurrent catalog writes from invalidating the cross-table collision check.
            $connection->statement('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
        }

        $connection->transaction(function () use ($connection): void {
            // The exact lookup checks variants before products, so they share a lookup namespace.
            $collision = $connection->selectOne(<<<'SQL'
                SELECT REPLACE(sku, '/', '-') AS target_sku
                FROM (
                    SELECT base_sku AS sku FROM products
                    UNION ALL
                    SELECT sku FROM product_variants
                ) AS existing_skus
                GROUP BY REPLACE(sku, '/', '-')
                HAVING COUNT(*) > 1
                   AND SUM(CASE WHEN sku LIKE '%/%' THEN 1 ELSE 0 END) > 0
                ORDER BY target_sku
                LIMIT 1
                SQL);

            if ($collision !== null) {
                throw new \RuntimeException('SKU collision while replacing slashes: '.$collision->target_sku.'. No SKUs were updated.');
            }

            $connection->table('products')
                ->where('base_sku', 'like', '%/%')
                ->update(['base_sku' => $connection->raw("REPLACE(base_sku, '/', '-')")]);

            $connection->table('product_variants')
                ->where('sku', 'like', '%/%')
                ->update(['sku' => $connection->raw("REPLACE(sku, '/', '-')")]);
        });
    }

    public function down(): void
    {
        // Existing hyphens and former slashes cannot be distinguished after conversion.
        throw new \RuntimeException('This SKU migration is irreversible without the original SKU data.');
    }
};
