<?php
defined('ABSPATH') || exit;

$order = wc_create_order();
$order->set_payment_method('cod');
$order->set_billing_first_name('Ahmed');
$order->set_billing_last_name('Hasan');
$order->set_billing_phone('01000000001');
$order->set_billing_email('ahmed@example.test');
$order->set_status('processing');
$order->save();

echo "S10_ORDER_ID:" . $order->get_id() . "\n";
