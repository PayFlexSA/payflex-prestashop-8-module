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

include_once(_PS_MODULE_DIR_.'PayFlex/PayFlexService.php');

class PayFlexRedirectModuleFrontController extends ModuleFrontController
{
    /**
     * Do whatever you have to before redirecting the customer on the website of your payment processor.
     */
    public function postProcess()
    {
        $live = Configuration::get('PAYFLEX_LIVE_MODE', true);
        if($live == false) {
            return $this->displayError('PayFlex is not set to live');
        }
        $prod = Configuration::get('PAYFLEX_PRODUCTION', false);
        $clientId = Configuration::get('PAYFLEX_CLIENTID', null);
        $secret = Configuration::get('PAYFLEX_SECRET', null);
        $env = $prod ? 'production' : 'develop';

        $ppService = new PayFlexService($env, $clientId, $secret);

        $cart = Context::getContext()->cart;
        $customer = Context::getContext()->customer;

        $ppo = $this->toPayFlexOrder($cart, $customer);

        $redirectLink = $ppService->getCheckoutUrl($ppo);
        Tools::redirect($redirectLink);
    }

    private function toPayFlexOrder($cart, $customer) {
        $link = Context::getContext()->link->getModuleLink(
            'PayFlex',
            'confirmation',
            ['cart_id' => $cart->id,
            'secure_key' => $cart->secure_key],
            true
        );

        //load the biling and delivery details as variables as well as other required data from the core payment module.
        $order = new PayFlexOrder();
        $order->id_address_invoice = (int)$this->context->cart->id_address_invoice;
        $invoice = new Address((int)$order->id_address_invoice);
        $invoice_state = $invoice->id_state ? new State((int)$invoice->id_state) : false;

        $order->id_address_delivery = (int)$this->context->cart->id_address_delivery;
        $delivery = new Address((int)$order->id_address_delivery);
        $delivery_state = $delivery->id_state ? new State((int)$delivery->id_state) : false;

        $products = [];
        $order->order_id = $cart->id;
        $order->total = (float)$cart->getOrderTotal(true, Cart::BOTH);
        $order->subtotal = (float)$cart->subtotals->totals->amount;
        // Set billing details from the invoice variable.
        $order->payment_phone = $invoice->phone;
        $order->payment_address_1 = $invoice->address1;
        $order->payment_address_2 = $invoice->address2;
        $order->payment_city = $invoice->city;
        $order->payment_zone = $invoice_state->name;
        $order->payment_postcode = $invoice->postcode;

      //Set shipping details from the delivery variable.
        $order->shipping_address_1 = $delivery->address1;
        $order->shipping_address_2 = $delivery->address2;
        $order->shipping_city = $delivery->city;
        $order->shipping_zone = $delivery_state->name;
        $order->shipping_postcode = $delivery->postcode;

        $order->payment_firstname = $customer->firstname;
        $order->payment_lastname = $customer->lastname;

        $order->email = $customer->email;
        $order->confirm_url = $link;
        $order->cancel_url = $link;

        $prestap = $cart->getProducts(true);
        for($x = 0; $x < count($prestap); $x++) {
            $ppp = new PayFlexProduct();
            $ppp->name = $prestap[$x]['name'];
            $ppp->product_id = $prestap[$x]['id_product'];
            $ppp->quantity = $prestap[$x]['cart_quantity'];
            $ppp->price = $prestap[$x]['price'];
            array_push($products, $ppp);
        }
        $order->items = $products;
        return $order;
    }

    protected function displayError($message, $description = false)
    {
        /**
         * Create the breadcrumb for your ModuleFrontController.
         */
        $this->context->smarty->assign('path', '
			<a href="'.$this->context->link->getPageLink('order', null, null, 'step=3').'">'.$this->module->l('Payment').'</a>
			<span class="navigation-pipe">&gt;</span>'.$this->module->l('Error'));

        /**
         * Set error message and description for the template.
         */
        array_push($this->errors, $this->module->l($message), $description);

        return $this->setTemplate('error.tpl');
    }
}
