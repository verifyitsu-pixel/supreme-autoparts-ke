<?php
/** Private companion shell footer; only consumes its paired header buffer. */
defined('ABSPATH') || exit;
$frame=array_pop($GLOBALS['sa_private_woo_shell_stack']);
if(!is_array($frame)||ob_get_level()!==(int)$frame['level']+1)return;
$inner=(string)ob_get_clean();
$order=is_object($frame['email'])?($frame['email']->object??null):null;
$country=$order instanceof WC_Order?(string)($order->get_shipping_country()?:$order->get_billing_country()):'';
echo sa_customer_mail_layout($frame['heading'],$inner,'','','','',$country);
