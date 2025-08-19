<?php
/**
* 2007-2018 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
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
*  @author    PrestaShop SA <contact@prestashop.com>
*  @copyright 2007-2018 PrestaShop SA
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

class PayFlexConfirmationModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        $status = Tools::getValue('status');
        $ppToken = Tools::getValue('token');
        $ppOrderId = Tools::getValue('orderId');

        // Bail if no cart id
        if ((Tools::isSubmit('cart_id') == false)) {
            Tools::redirect('index.php?controller=order&step=1');
            return false;
        }

        // Also bail if it doesn't look like a PP callback
        if(empty($ppToken) || empty($ppOrderId)) {
            Tools::redirect('index.php?controller=order&step=1');
            return false;
        }

        if($status == 'cancelled' || $status != 'confirmed') {
            Tools::redirect('index.php?controller=order&step=1');
            return false;
        }
        // ****************** Payflex backend validations ******************
            $prod = Configuration::get('PAYFLEX_PRODUCTION', false);
            $clientId = Configuration::get('PAYFLEX_CLIENTID', null);
            $secret = Configuration::get('PAYFLEX_SECRET', null);
            $env = $prod ? 'production' : 'develop';
            $ppService = new PayFlexService($env, $clientId, $secret);
            $payflexApiResponse = $ppService->getTransactionStatus($ppOrderId);
            $payflexOrderAmount  = isset($payflexApiResponse['amount']) ? $payflexApiResponse['amount'] : 0;
            $payflexOrderStatus = isset($payflexApiResponse['orderStatus']) ? $payflexApiResponse['orderStatus'] : '';
            $payflexOrderID = isset($payflexApiResponse['orderId']) ? $payflexApiResponse['orderId'] : ''; 
            
            if($payflexOrderStatus  != 'Approved' || $ppOrderId != $payflexOrderID) {
                Tools::redirect('index.php?controller=order&step=1');
                return false;
            }
            
        // ****************** Payflex backend validations ******************

        $cart_id = Tools::getValue('cart_id');
        $secure_key = Tools::getValue('secure_key');

        $cart = new Cart((int)$cart_id);
        $customer = new Customer((int)$cart->id_customer);

        if($secure_key != $customer->secure_key) {
            // This customer doesn't belong to this cart
            // perhaps the prestashop session expired or something
            $this->errors[] = $this->module->l('An error occured. Please contact the merchant for more information');
            return $this->setTemplate('error.tpl');
        }


        // Default value for a payment that succeed.
        $payment_status = Configuration::get('PS_OS_PAYMENT');

         // Currently doesn't work due to bug:
         // https://github.com/PrestaShop/PrestaShop/issues/9783
        $message = 'OrderId: ' . $ppOrderId;

        /**
         * Converting cart into a valid order
         */
        $module_name = $this->module->displayName;
        $currency_id = (int)Context::getContext()->currency->id;

        if (!isset($this->context)) {
                $this->context = Context::getContext();
            }

        $this->context->cart = new Cart((int) $cart_id);

        /***
        Check if order exists before initialising PrestaShop's order validation.
        The validation will only run once via he Ajax Request.
        If the request fails then the validation will run on the page redirect.
        ***/
        if(!$this->context->cart->OrderExists()){
        try {
            $this->module->validateOrder(
                $cart_id,
                $payment_status,
                $payflexOrderAmount,
                $module_name,
                $message, array(),
                $currency_id, false,
                $secure_key);
        } catch(Exception $e) {
            // Not sure what to do here
            $this->errors[] = $this->module->l('An error occured. Please contact the merchant for more information');
            return $this->setTemplate('error.tpl');
        }

        $order_id = Order::getOrderByCartId((int)$cart->id);

        try {
            // Hack to get the transation id into the order
            $order = new Order((int)$order_id);
            $payments = $order->getOrderPaymentCollection();
            $payments[0]->transaction_id = $ppOrderId;
            $payments[0]->update();
        } catch(Exception $e) {
            // This is less bad than above, the order worked but we couldn't
            // update the transation id. It'll just be harder to link the order
            // between PP and Presta, but it can be done.
        }
        if ($order_id && ($secure_key == $customer->secure_key)) {
            /**
             * The order has been placed so we redirect the customer on the confirmation page.
             */
            $module_id = $this->module->id;
            Tools::redirect('index.php?controller=order-confirmation&id_cart='.$cart_id.'&id_module='.$module_id.'&id_order='.$order_id.'&key='.$secure_key);
        } else {
            /**
             * An error occured and is shown on a new page.
             */
            $this->errors[] = $this->module->l('An error occured. Please contact the merchant for more information');
            return $this->setTemplate('error.tpl');
        }

    }else{
            /**
             * Validation has ran already once and order exists redirect the customer to the confirmation page.
             */
            $module_id = $this->module->id;
            Tools::redirect('index.php?controller=order-confirmation&id_cart='.$cart_id.'&id_module='.$module_id.'&id_order='.$order_id.'&key='.$secure_key);
        }
    }
}
