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

class PayFlexValidationModuleFrontController extends ModuleFrontController
{
    /**
     * This class should be use by your Instant Payment
     * Notification system to validate the order remotely
     */
    public function postProcess()
    {
        $cart = $this->context->cart;

        /**
         * If the module is not active anymore, no need to process anything.
         */
        if ($this->module->active == false) {
            die();
        }

        $customer = new Customer($cart->id_customer);
        $total = (float)$cart->getOrderTotal(true, Cart::BOTH);

        /**
         * Restore the context from the $cart_id & the $customer_id to process the validation properly.
         */
        // Context::getContext()->cart = new Cart((int)$cart->$cart_id);
        // Context::getContext()->customer = new Customer((int)$cart->$customer_id);
        // Context::getContext()->currency = new Currency((int)$cart->id_currency);
        // Context::getContext()->language = new Language((int)$customer->id_lang);

        $secure_key = Context::getContext()->customer->secure_key;

        if ($this->isValidOrder() === true) {
            $payment_status = Configuration::get('PS_OS_PAYMENT');
            $message = null;
        } else {
            $payment_status = Configuration::get('PS_OS_ERROR');
            $message = $this->module->l('An error occurred while processing payment');
        }

        $module_name = $this->module->displayName;
        $currency_id = (int)Context::getContext()->currency->id;

        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $this->module->validateOrder(
            $cart_id, $payment_status, $amount,
            $module_name, $message, array(),
            $currency_id, false, $secure_key
        );
        Tools::redirect(
            'index.php?controller=order-confirmation&id_cart='.(int)$cart->id
            .'&id_module='.(int)$this->module->id
            .'&id_order='.$this->module->currentOrder
            .'&key='.$customer->secure_key);
    }

    protected function isValidOrder()
    {
        /**
         * Add your checks right there
         */
        return true;
    }
}
