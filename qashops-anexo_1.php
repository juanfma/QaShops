<?php

class Product
{
    // Stock bloqueado por pedidos en curso
    private function orderLine($productId)
    {
        OrderLine::find()->select('SUM(quantity) as quantity')->joinWith('order')->where("(order.status = '" . Order::STATUS_PENDING . "' OR order.status = '" . Order::STATUS_PROCESSING . "' OR order.status = '" . Order::STATUS_WAITING_ACCEPTANCE . "') AND order_line.product_id = $productId")->scalar();
    }

    // Stock bloqueado por pedidos en curso cacheado
    private function blockStockOrder($productId, $cacheDuration)
    {
        OrderLine::getDb()->cache(function ($db) use ($productId) {
            return $this->orderLine($productId);
        }, $cacheDuration);
    }

    // Stock bloqueado
    private function blockedStock($productId)
    {
        BlockedStock::find()->select('SUM(quantity) as quantity')->joinWith('shoppingCart')->where("blocked_stock.product_id = $productId AND blocked_stock_date > '" . date('Y-m-d H:i:s') . "' AND (shopping_cart_id IS NULL OR shopping_cart.status = '" . ShoppingCart::STATUS_PENDING . "')")->scalar();
    }

    // Stock bloqueado cacheado
    private function blockStock($productId, $cacheDuration)
    {
        BlockedStock::getDb()->cache(function ($db) use ($productId) {
            return $this->blockedStock($productId);
        }, $cacheDuration);
    }

    public static function stock(
        $productId,
        $quantityAvailable,
        $cache = false,
        $cacheDuration = 60,
        $securityStockConfig = null
    ) {
        if ($cache) { // Stock cacheado
            // Stock bloqueado por pedidos en curso cacheado
            $ordersQuantity = $this->blockStockOrder($productId, $cacheDuration);

            // Stock bloqueado cacheado
            $blockedStockQuantity = $this->blockStock($productId, $cacheDuration);
        } else { // Stock NO cacheado
            // Stock bloqueado por pedidos en curso
            $ordersQuantity = $this->orderLine($productId);

            // Stock bloqueado
            $blockedStockQuantity = $this->blockedStock($productId);
        }

        // Calculamos las unidades disponibles
        if (isset($ordersQuantity) || isset($blockedStockQuantity)) {
            $quantity = $quantityAvailable - @$ordersQuantity - @$blockedStockQuantity;
        }

        if ($quantityAvailable >= 0) {
            if (!empty($securityStockConfig)) {
                $quantity = ShopChannel::applySecurityStockConfig(
                    $quantity,
                    @$securityStockConfig->mode,
                    @$securityStockConfig->quantity
                );
            }
            return $quantity > 0 ? $quantity : 0;
        } elseif ($quantityAvailable < 0) {
            return $quantityAvailable;
        }
    }
}
