<?php
/**
 * 2007-2020 PrestaShop SA and Contributors
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2007-2020 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 * International Registered Trademark & Property of PrestaShop SA
 */

/*
 * This file can be called using a cron to get data from payflex automatically
 */
include dirname(__FILE__) . '/../../config/config.inc.php';
include_once _PS_MODULE_DIR_ . 'PayFlex/PayFlexService.php';

/* Check security token */
if (!Tools::isPHPCLI()) {
    include dirname(__FILE__) . '/../../init.php';

    if (Tools::substr(Tools::encrypt('payflex/cron'), 0, 10) != Tools::getValue('token') || !Module::isInstalled('PayFlex')) {
        die('Bad token');
    }
}
$module_name = 'PayFlex';
$payflex = Module::getInstanceByName($module_name);

/* Check if the module is enabled */
if ($payflex->active) {
    /* Check if the requested shop exists */
    $shops = Db::getInstance()->ExecuteS('SELECT id_shop FROM `' . _DB_PREFIX_ . 'shop`');
    $list_id_shop = array();
    foreach ($shops as $shop) {
        $list_id_shop[] = (int) $shop['id_shop'];
    }
    $id_shop = (Tools::getIsset('id_shop') && in_array(Tools::getValue('id_shop'), $list_id_shop)) ? (int) Tools::getValue('id_shop') : (int) Configuration::get('PS_SHOP_DEFAULT');
    $payflex->cron = true;

    $sql = '
    SELECT c.id_cart, c.id_lang, cu.id_customer, c.id_shop, cu.firstname, cu.lastname, cu.email,pf.*, c.secure_key
    FROM ' . _DB_PREFIX_ . 'cart c
    LEFT JOIN ' . _DB_PREFIX_ . 'orders o ON (o.id_cart = c.id_cart)
    RIGHT JOIN ' . _DB_PREFIX_ . 'customer cu ON (cu.id_customer = c.id_customer)
    RIGHT JOIN ' . _DB_PREFIX_ . 'cart_product cp ON (cp.id_cart = c.id_cart)
    RIGHT JOIN ' . _DB_PREFIX_ . 'PayFlex pf ON (pf.cart_id = c.id_cart)

    WHERE DATE_SUB(CURDATE(),INTERVAL 2 DAY) <= c.date_add AND o.id_order IS NULL';
    $sql .= Shop::addSqlRestriction(Shop::SHARE_CUSTOMER, 'c');
    $carts = Db::getInstance()->executeS($sql);
    $payment_status = Configuration::get('PS_OS_PAYMENT');
    $prod = Configuration::get('PAYFLEX_PRODUCTION', false);
    $clientId = Configuration::get('PAYFLEX_CLIENTID', null);
    $secret = Configuration::get('PAYFLEX_SECRET', null);
    $env = $prod ? 'production' : 'develop';
    $ppService = new PayFlexService($env, $clientId, $secret);
    $total=0;
    if(!empty($carts)){
        foreach ($carts as $cartOrder) {
            $ppOrderId = $cartOrder['partpay_id'];
            $payflexApiResponse = $ppService->getTransactionStatus($ppOrderId);
            if ($payflexApiResponse) {
                if (isset($payflexApiResponse["orderStatus"]) && $payflexApiResponse["orderStatus"] == "Approved"
                    && $payflexApiResponse["merchantReference"] == $cartOrder['id_cart']
                ) {
                    $cart_id = $cartOrder['id_cart'];
                    $payment_status = Configuration::get('PS_OS_PAYMENT');
                    $message = 'OrderId: ' . $ppOrderId;
                    $secure_key = $cartOrder['secure_key'];
                    $currency_id = (int) Context::getContext()->currency->id;
                    $loadCart = new Cart((int) $cart_id);
                    try {
                        $payflex->validateOrder(
                            $cart_id,
                            $payment_status,
                            $loadCart->getOrderTotal(),
                            $module_name,
                            $message, array(),
                            $currency_id, false,
                            $secure_key);
                    } catch (Exception $e) {

                    }
                    $order_id = Order::getOrderByCartId((int) $loadCart->id);
                    try {
                        $order = new Order((int) $order_id);
                        $payments = $order->getOrderPaymentCollection();
                        $payments[0]->transaction_id = $ppOrderId;
                        $payments[0]->update();
                    } catch (Exception $e) {
                    }
                }
            }
            $total++;
        }
        die('Total:-'.$total.'order fetched from Payflex');
    }else{
        die('No Data to Fetch');
    }    
}
