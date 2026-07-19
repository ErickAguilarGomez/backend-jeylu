<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class PurchaseOrderController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->query('search', '');
        
        $sql = "
            SELECT 
                po.id,
                po.order_number,
                po.file_url,
                po.provider,
                po.purchase_date,
                po.total_amount,
                po.observations,
                po.status,
                po.created_at,
                (SELECT COUNT(*) FROM products WHERE purchase_order_id = po.id) as products_count
            FROM purchase_orders po
        ";
        
        $params = [];
        if ($search !== '') {
            $sql .= "
                LEFT JOIN products p ON p.purchase_order_id = po.id
                LEFT JOIN product_variants pv ON pv.product_id = p.id
                WHERE po.order_number LIKE ? 
                   OR po.provider LIKE ? 
                   OR po.purchase_date LIKE ?
                   OR p.name LIKE ?
                   OR p.base_sku LIKE ?
                   OR pv.sku LIKE ?
            ";
            $searchTerm = "%$search%";
            $params = [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm];
        }
        
        $sql .= " GROUP BY po.id, po.order_number, po.file_url, po.provider, po.purchase_date, po.total_amount, po.observations, po.status, po.created_at ORDER BY po.id DESC LIMIT 30";
        
        $orders = DB::select($sql, $params);
        
        foreach ($orders as $order) {
            $order->total_amount = $order->total_amount !== null ? (float) $order->total_amount : null;
            $order->products_count = (int) $order->products_count;
        }
        
        return response()->json([
            'success' => true,
            'data' => $orders
        ]);
    }
}
