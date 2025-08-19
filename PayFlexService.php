<?php
class PPLog {
  public function write($thing) {
    print_r($thing);
  }
}

class PayFlexOrder {
  // required
  public $order_id = '';
  // required
  public $total = '';
  public $subtotal = '';
  public $payment_phone  = '';
  public $payment_firstname = '';
  public $payment_lastname = '';
  // required
  public $email = '';
  public $payment_address_1 = '';
  public $payment_address_2 = '';
  public $payment_city = '';
  public $payment_zone = '';
  public $payment_postcode = '';
  public $shipping_address_1 = '';
  public $shipping_address_2 = '';
  public $shipping_city = '';
  public $shipping_zone = '';
  public $shipping_postcode = '';
  // required
  public $confirm_url = '';
  // required
  public $cancel_url = '';
  public $status_url = '';
  public $items = [];
}

class PayFlexProduct {
  public $name = '';
  public $product_id = '';
  public $quantity = '';
  public $price = '';
}

const CURL_OK = 0;

class PayFlexService {
  public $environments = array();
  public $log;

  private $env = '';
  private $clientId = '';
  private $secret = '';

  public function __construct($env, $clientId, $secret) {
    $this->createEnvironments();
    $this->log = new PPLog();
    if($env != 'develop' && $env != 'production') {
      throw new Exception('Bad $env parameter');
    }
    if(empty($clientId) || empty($secret)) {
      throw new Exception('Need clientId and secret');
    }
    $this->env = $env;
    $this->clientId = $clientId;
    $this->secret = $secret;
  }

  private function createEnvironments() {
    $this->environments["develop"] = array(
      "name"          => "Sandbox Test",
      "api_url"       => "https://api.uat.payflex.co.za",
      "auth_url"      => "https://auth-uat.payflex.co.za/auth/merchant",
      "web_url"       => "https://api.uat.payflex.co.za",
      "auth_audience" => "https://auth-dev.payflex.co.za",
    );
    $this->environments["production"] = array(
        "name"          => "Production",
        "api_url"       => "https://api.payflex.co.za",
        "auth_url"      => "https://auth.payflex.co.za/auth/merchant",
        "web_url"       => "https://api.payflex.co.za",
        "auth_audience" => "https://auth-production.payflex.co.za",
    );
  }

  /**
   * Get a token from auth0 so we can do stuff - "login"
   * $env = 'develop' or 'production'
   * $clientId = auth0 clientid
   * $secret = auth0 secret
   */
  public function getAuthorizationCode()
  {
      if ($this->env != 'develop' && $this->env != 'production') {
          throw new Exception('Bad $env parameter');
      }
      $cache_file = 'modules/PayFlex/cache.txt';
      if (file_exists($cache_file) && (filemtime($cache_file) > (time() - 60 * 1))) {
          $tokenFromCache = file_get_contents($cache_file);
          if(empty($tokenFromCache)){
            $tokenFromCache = $this->getTokenApiCall();
          }
          return $tokenFromCache;
      } else {
          $freshToken = $this->getTokenApiCall();
          file_put_contents($cache_file, $freshToken, LOCK_EX);
          return $freshToken; 
      }
  }

  public function getTokenApiCall(){
    $AuthURL = $this->environments[$this->env]['auth_url'];
    $Audience = $this->environments[$this->env]['auth_audience'];

    $AuthBody = json_decode('{
      "client_id":"' . $this->clientId . '",
      "client_secret":"' . $this->secret . '",
      "audience":"' . $Audience . '",
      "grant_type":"client_credentials"
    }');

    $args = array(
        'method' => 'POST',
        'headers' => array('Content-Type' => 'application/json'),
        'body' => $AuthBody,
    );

    $response_json = $this->sendCurl($AuthURL, $args);

    if(!is_string($response_json)){
        if(isset($response_json->Errors)){
            throw new Exception($response_json->Errors);
        }
        throw new Exception('Error getting auth token');
    }
    
    $response = json_decode($response_json);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Json parsing error on auth token');
    }
    return $response->access_token;

  }
  private function apiUrl() {
    return $this->environments[$this->env]['api_url'];
  }

  private function orderUrl() {
    return $this->apiUrl() . '/order/productSelect';
  }
  private function transactionStatusCheckUrl($partpayId) {
    return $this->apiUrl() . '/order/'.$partpayId;
  }
  private function configurationUrl() {
    return $this->apiUrl() . '/configuration';
  }
  public function getMerchantConfiguration()
  {
    $access_token = $this->getAuthorizationCode();
    $args = array(
        'headers' => array(
          'Content-Type' => 'application/json',
          'Authorization' => 'Bearer '. $access_token
        )
      );
      $response = $this->sendCurl($this->configurationUrl(), $args);
      $body = json_decode($response);
      return $body;
  }

  // PayFlexProduct[] $products
  public function mapOrderItems($products) {
    $items = array();

    for ($x = 0; $x < count($products); $x++) {
      $product = $products[$x];
      array_push($items,
        '{ "name":"'. htmlentities( (string)substr($product->name, 0, 26) ) .'", "sku":"' . (string)substr($product->product_id, 0, 12) . '", "quantity":'. strval($product->quantity) .', "price":'. strval($product->price) .' }'
      );
    }
    return $items;
  }

  public function createOrderBody($order, $products) {
    $items = $this->mapOrderItems($products);

    $shipping_total = 0;
    if(!empty($order->subtotal)) {
      $shipping_total = $order->total - $order->subtotal;
    }

    $OrderBodyString = '{
      "amount": '. $order->total .',
      "consumer": {
        "phoneNumber":  "'. $order->payment_phone .'",
        "givenNames":  "'. $order->payment_firstname .'",
        "surname":  "'. $order->payment_lastname .'",
        "email":  "'. $order->email .'"
      },
      "billing": {
        "addressLine1":"'.$order->payment_address_1.'",
        "addressLine2": "'.$order->payment_address_2.'",
        "city": "'.$order->payment_city.'",
        "suburb": "'.$order->payment_city.'",
        "state": "'.$order->payment_zone.'",
        "postcode": "'.$order->payment_postcode.'"
      },
      "shipping": {
        "addressLine1": "'. $order->shipping_address_1 .'",
        "addressLine2": " '. $order->shipping_address_2 .'",
        "city": "'.$order->shipping_city.'",
        "suburb":  "'. $order->shipping_city .'",
        "state": "'.$order->shipping_zone.'",
        "postcode": "'. $order->shipping_postcode .'"
      },
      "description": "string",
      "items": ['
    ;
    foreach ($items as $i=>$item) {
      $OrderBodyString .= $item . (($i < count($items)-1) ? ',' : '');
    }
    $OrderBodyString .= '],
      "merchant": {
        "redirectConfirmUrl": "'. $order->confirm_url .'&status=confirmed",
        "redirectCancelUrl": "'. $order->cancel_url .'&status=cancelled"
      },
      "merchantReference": "'. $order->order_id .'",
      "token": "'. $order->order_id .'",
      "taxAmount": '. ($order->total - $order->subtotal) .',
      "shippingAmount":'. ($order->total - $order->subtotal) .'
    }';

    return $OrderBodyString;
  }
  public function getTransactionStatus($partpayId)
  {
    // Get the authorization token
    $access_token = $this->getAuthorizationCode();
    $order_args = array(
      'method' => 'GET',
      'headers' => array(
        'Content-Type' => 'application/json',
        'Authorization' => 'Bearer '. $access_token
      ),
      'timeout' => 30
    );
    $order_response = $this->sendCurl($this->transactionStatusCheckUrl($partpayId), $order_args);
    return json_decode($order_response,true);
  }
  // PayFlexOrder $order,
  // PayFlexProduct[] $products
  public function getCheckoutUrl($order) {

    // Get the authorization token
    $access_token = $this->getAuthorizationCode();

    $OrderBodyString = $this->createOrderBody($order, $order->items);
    $OrderBody = json_decode($OrderBodyString);

    $order_args = array(
      'method' => 'POST',
      'headers' => array(
        'Content-Type' => 'application/json',
        'Authorization' => 'Bearer '. $access_token
      ),
      'body' => $OrderBody,
      'timeout' => 30
    );

    $order_response = $this->sendCurl($this->orderUrl(), $order_args);
    $order_body = json_decode($order_response);
    $redirect = $order_body->redirectUrl;
    // save payflex response for future referencefrom 
    $getPayflexRow = Db::getInstance()->executeS('select id from `' . _DB_PREFIX_ . 'PayFlex` where cart_id = '.$order->order_id);
    if(!empty($getPayflexRow)){
      $sql = 'UPDATE '._DB_PREFIX_.'PayFlex SET token="'.$order_body->token.'",url="'.$redirect.'",
      expire="'.$order_body->expiryDateTime.'",partpay_id="'.$order_body->orderId.'"
      WHERE cart_id='.$order->order_id;
    }else{
      $sql = '
      INSERT INTO `' . _DB_PREFIX_ . 'PayFlex` (`token`, `url`, `expire`, `cart_id`, `partpay_id`)
      VALUES ("' .$order_body->token . '", "' .  $redirect .'", "' .  $order_body->expiryDateTime .'", ' .  $order->order_id .', "' .  $order_body->orderId . '")';
    }
    Db::getInstance()->execute($sql);
    //save payflex response for future reference
    return $redirect;
  }

  /**
   * POST / GET to a URL. For example:
   * $args = array(
   *   'method' => 'GET',
   *   'headers' => array('Content-Type' => 'application/json'),
   *   'body' => json_decode('{
   *       "client_id":"blarg",
   *       "client_secret":"derp",
   *       "audience":"whoknows",
   *       "grant_type":"client_credentials"
   *   }')
   * );
   */
  public function sendCurl($url, $data) {
    $ch = curl_init($url);

    $allHeaders = array();
    foreach($data['headers'] as $key => $value) {
      $allHeaders[] = $key . ': ' . $value;
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $allHeaders);

    if (isset($data['method']) AND $data['method'] === 'POST') {
      // $this->log->write('POST');
      curl_setopt($ch, CURLOPT_POST, 1);
      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data['body']));
    } else {
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_FORBID_REUSE, 1);
    curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);

    $response = curl_exec($ch);

    if (curl_errno($ch) != CURL_OK) {
      $response = new stdClass();
      $response->Errors = "POST Error: " . curl_error($ch) . " URL: $url";
    } else {
      $info = curl_getinfo($ch);
      if ($info['http_code'] != 200 && $info['http_code'] != 201) {
        // $this->log->write($info);
        $response = new stdClass();
        if ($info['http_code'] == 401
          || $info['http_code'] == 404
          || $info['http_code'] == 403) {
          $response->Errors = "Please check the API Key and Password";
        } else {
          $response->Errors = 'Error connecting to PayFlex: ' . $info['http_code'];
        }
        return $response;
      }
    }
    curl_close($ch);
    return $response;
  }

}
