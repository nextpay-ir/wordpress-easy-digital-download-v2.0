<?php
/**
Plugin Name: Nextpay EDD gateway
Version: 2.0
Description: درگاه پرداخت <a href="http://nextpay.ir">نکست پی</a> را به EDD اضافه میکند
Plugin URI: https://github.com/nextpay-ir/wordpress-woocommerce-nextpay-payment-gateway-v2.0
Author: Nextpay Co.
Created by NextPay.ir
Author URI: https://nextpay.ir
Email: info@nextpay.ir
Tags: easy digital downloads,EDD gateways,nextpay,payment gateway,getaway
Requires PHP: 5.6
Requires at least: 4.0
Tested up to: 5.1
Stable tag: 4.9
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
**/

function eddNextpay_load_textdomain() {
	load_plugin_textdomain( 'nextpay_edd', false, dirname( plugin_basename( __FILE__ ) ) . '/langs' );
}
add_action( 'init', 'eddNextpay_load_textdomain' );

@session_start(); // Start Session for get information

//Change Rial to ریال
if ( !function_exists( 'edd_rial' ) ) { // Check if edd_rial() is not available
	function edd_rial( $formatted, $currency, $price ) {
		return $price . __('RIAL', 'nextpay_edd');
	}
	add_filter( 'edd_rial_currency_filter_after', 'edd_rial', 10, 3 );
}

//Registers the gateway
function eddNextpay_register_gateway($gateways) {
	$gateways['nextpay'] = array(
		'admin_label' => __('Nextpay Gateway', 'nextpay_edd'),
		'checkout_label' => __('Do transactions with nextpay gateway', 'nextpay_edd')
	);
	return $gateways;
}
add_filter('edd_payment_gateways', 'eddNextpay_register_gateway');

//Create Custom Credit From
function eddNextpay_nextpay_gateway_cc_form() {
	do_action( 'nextpay_form_action' );
	return;
}
add_action('edd_nextpay_gateway_cc_form', 'eddNextpay_nextpay_gateway_cc_form');

//Processes the payment of Edd for Saman Bank
function eddNextpay_process_payment($purchase_data) {
	global $edd_options;

	// check for any stored errors
	$errors = edd_get_errors();

	if(!$errors) {
		$purchase_summary = edd_get_purchase_summary($purchase_data);

		/**********************************
		* Setup the payment details
		**********************************/
        $payment_data = array(
			'price' => $purchase_data['price'], 
			'date' => $purchase_data['date'], 
			'user_email' => $purchase_data['user_email'],
			'purchase_key' => $purchase_data['purchase_key'],
			'currency' => $edd_options['currency'],
			'downloads' => $purchase_data['downloads'],
			'cart_details' => $purchase_data['cart_details'],
			'user_info' => $purchase_data['user_info'],
			'status' => 'pending'
		);
		
		//Record the pending payment 
		if ($payment = edd_insert_payment($payment_data)) {
			if(!edd_is_test_mode()) {
				$merchant = $edd_options['merchant'];
				unset($_SESSION['nextpay_payment']);
				$amount = str_replace(".00", "", $purchase_data['price']);
				$amount = intval(ceil($amount));
				$_SESSION['nextpay_payment'] = $amount;
				$callBackUrl = add_query_arg( 'order', 'nextpay', get_permalink( $edd_options['success_page'] ) );                
                $callBackUrl = add_query_arg('id', $payment, $callBackUrl);
                $callBackUrl = add_query_arg('price', $amount, $callBackUrl);
                
                $apikey = trim($edd_options['apikey']);
                $order_id = $payment;
                
                include_once dirname(__FILE__).'/nextpay_payment.php';
                try {
                    $parameters = array
                    (
                        "api_key"=>$apikey,
                        "order_id"=> $order_id,
                        "amount"=>$amount,
                        "callback_uri"=>$callBackUrl
                    );

                    $nextpay = new Nextpay_Payment($parameters);
                    $result = $nextpay->token();

                    if(intval($result->code) == -1){
                        $nextpay->send($result->trans_id);
                        ?>
                            <form id="nextpaypeyment" method="GET" action="https://api.nextpay.org/gateway/payment/<?php echo $result->trans_id; ?>">
                                <input type="submit" value="<?php _e('If you have not redirected click here', 'nextpay_edd'); ?>"  />
                            </form>
                            <script>document.getElementById("nextpaypeyment").submit();</script>
                        <?php
                        exit;
                    } else {	
                        edd_update_payment_status($payment, 'failed');
                        wp_redirect(get_permalink($edd_options['failure_page']));
                    }
                } catch (Exception $ex) {
                    $Message = $ex->getMessage();
                    echo $Message;
                    edd_update_payment_status($payment, 'failed');
                    wp_redirect(get_permalink($edd_options['failure_page']));
                }
            
            
                _e('If you have not redirected click here', 'nextpay_edd'); 
            
				exit;
			} else {
				edd_update_payment_status($payment, 'complete');
				unset($_SESSION['nextpay_payment']);
				edd_send_to_success_page();
			}
		} else {
			edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
		}
	}
}
add_action('edd_gateway_nextpay_gateway', 'eddNextpay_process_payment');

function eddNextpay_verify_payment() {
	global $edd_options;

	if (isset($_GET['order']) && $_GET['order'] == 'nextpay') {
        
        $trans_id = isset($_POST['trans_id']) ? sanitize_text_field($_POST['trans_id']) : false ;
        $order_id = isset($_POST['order_id']) ? sanitize_text_field($_POST['order_id']) : false ;
        $amount = isset($_GET['price']) ? intval($_GET['price']) : false ;
        $payment = isset($_GET['id']) ? intval($_GET['id']) : false ;

        if($amount != $_SESSION['nextpay_payment'] || !payment)
            $status	= 'بازگشت ناموفق و نامشخص';
        else
            $status = 'ok';
		
        if ( $trans_id && $order_id && $status == 'ok') {
            
            $apikey = trim($edd_options['apikey']);
            $parameters = array
            (
                'api_key'	=> $apikey,
                'order_id'	=> $order_id,
                'trans_id' 	=> $trans_id,
                'amount'	=> $amount,
            );

            $nextpay = new Nextpay_Payment();
            $result = $nextpay->verify_request($parameters);

            if (intval($result) == 0) {
                unset($_SESSION['nextpay_payment']);
                edd_insert_payment_note($payment, "پرداخت موفق : شماره پیگیری $trans_id");
                edd_update_payment_status($trans_id, 'publish');
                edd_empty_cart();
                edd_send_to_success_page();
            } else {
                $status	= "پرداخت ناموفق : شماره پیگیری $trans_id";
            }

		}
		
		//Check if status failed update payment status
		if($status	!= 'ok'){
            edd_insert_payment_note($payment, 'خطا : '.$status);
			edd_update_payment_status(sanitize_text_field( $trans_id ), 'failed');
			$failed_page = get_permalink($edd_options['failure_page']);
			wp_redirect( $failed_page );
			exit;
		}
	}
}
add_action('init', 'eddNextpay_verify_payment');

function eddNextpay_add_settings($settings) {
	$nextpay_settings = array (
		array (
			'id'	=>	'nextpay_settings',
			'name'	=>	'<strong>'.__('Nextpay Setting', 'nextpay_edd').'</strong>',
			'desc'	=>	__('Setting for Nextpay, Should get from gateway', 'nextpay_edd'),
			'type'	=>	'header'
		),
		array (
			'id'	=>	'apikey',
			'name'	=>	__('Api key', 'nextpay_edd'),
			'desc'	=>	'',
			'type'	=>	'text',
			'size'	=>	'regular'
		)
	);
	return array_merge( $settings, $nextpay_settings );
}
add_filter('edd_settings_gateways', 'eddNextpay_add_settings');

?>
