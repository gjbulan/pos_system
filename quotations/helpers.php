<?php

function quotation_next_number(): string
{
    return 'QT-' . date('Ymd-His');
}

function quotation_valid_date(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $date;
}

function quotation_status_badge(string $status): string
{
    return match ($status) {
        'converted' => 'success',
        'issued' => 'primary',
        'cancelled' => 'danger',
        default => 'secondary',
    };
}

function quotation_calculate_discount(
    string $discountType,
    float $discountValue,
    float $subtotal,
    ?int $customerId,
    bool $seniorDiscountEnabled,
    bool $pwdDiscountEnabled
): array {
    $discountType = strtolower(trim($discountType));
    $subtotal = round(max(0, $subtotal), 2);

    if ($discountType === '' || $discountType === 'none' || $subtotal <= 0) {
        return [null, 0.0, 0.0];
    }

    if (!in_array($discountType, ['percentage', 'fixed', 'senior', 'pwd'], true)) {
        throw new RuntimeException('Invalid discount type.');
    }

    if ($discountType === 'senior' || $discountType === 'pwd') {
        if ($customerId === null) {
            throw new RuntimeException('Senior/PWD discounts require a selected customer.');
        }

        if ($discountType === 'senior' && !$seniorDiscountEnabled) {
            throw new RuntimeException('Senior discount is disabled in Settings.');
        }

        if ($discountType === 'pwd' && !$pwdDiscountEnabled) {
            throw new RuntimeException('PWD discount is disabled in Settings.');
        }

        $discountValue = 20.0;
        return [$discountType, $discountValue, round(min($subtotal, $subtotal * 0.20), 2)];
    }

    if ($discountValue <= 0) {
        throw new RuntimeException('Discount value must be greater than zero.');
    }

    if ($discountType === 'percentage') {
        if ($discountValue > 100) {
            throw new RuntimeException('Percentage discount cannot exceed 100%.');
        }

        return [$discountType, $discountValue, round(min($subtotal, $subtotal * ($discountValue / 100)), 2)];
    }

    if ($discountValue > $subtotal) {
        throw new RuntimeException('Fixed discount cannot exceed the quotation subtotal.');
    }

    return [$discountType, round($discountValue, 2), round($discountValue, 2)];
}

function quotation_product_options(array $products, string $selectedId = ''): string
{
    $html = '<option value="">Select product</option>';

    foreach ($products as $product) {
        $label = $product['name'];
        if (!empty($product['sku'])) {
            $label .= ' - SKU: ' . $product['sku'];
        }
        if (!empty($product['barcode'])) {
            $label .= ' - Barcode: ' . $product['barcode'];
        }
        $label .= ' - Stock: ' . (int)$product['stock_qty'];
        $label .= ' - Price: ' . number_format((float)$product['price'], 2);

        $selected = $selectedId === (string)$product['id'] ? ' selected' : '';
        $html .= '<option value="' . (int)$product['id'] . '" data-price="' . htmlspecialchars((string)$product['price'], ENT_QUOTES, 'UTF-8') . '"' . $selected . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
    }

    return $html;
}

function quotation_product_map(array $products): array
{
    $map = [];
    foreach ($products as $product) {
        $map[(int)$product['id']] = $product;
    }

    return $map;
}
