<?php
defined('ABSPATH') || exit;

$order = wc_create_order();
$order->set_status('processing');
$order->save();
echo $order->get_id();
