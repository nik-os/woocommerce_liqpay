<?php
/*
Plugin Name: WooCommerce Liqpay 
Plugin URI: http://woothemes.com/woocommerce
Description: Extends WooCommerce with an Liqpay gateway.
Version: 0.01
Author: Nikita Kotenko
Author URI: http://samsonos.com
*/
if ( ! defined( 'ABSPATH' ) ) exit;
define( 'Liqpay__PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once( Liqpay__PLUGIN_DIR . 'liqpaysdk.php' );

add_action('plugins_loaded', 'woocommerce_init', 0);

function woocommerce_init() {

	if ( !class_exists( 'WC_Payment_Gateway' ) ) return;
    
	class WC_Gateway_Liqpay extends WC_Payment_Gateway{

        public function __construct(){
			
			global $woocommerce;
			
            $this->id = 'liqpay';
            $this->has_fields         = false;
            $this->method_title 	  = __( 'Liqpay', 'woocommerce' );
            $this->method_description = __( 'Liqpay', 'woocommerce' );
			$this->init_form_fields();
            $this->init_settings();
            $this->title 			  =  $this->get_option( 'title' );
            $this->description        =  $this->get_option('description');
            $this->merchant_id        =  $this->get_option('merchant_id');
            $this->merchant_sig       =  $this->get_option('merchant_sig');
			
            // Actions
            add_action( 'woocommerce_receipt_liqpay', array( $this, 'receipt_page' ) );
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			
			// Payment listener/API hook
			add_action( 'woocommerce_api_wc_gateway_liqpay', array( $this, 'check_liqpay_response' ) );
            
            if (!$this->is_valid_for_use()) {
                $this->enabled = false;
            }
        }

        public function admin_options() {
		?>
		<h3><?php _e( 'Liqpay', 'woocommerce' ); ?></h3>
        
        <?php if ( $this->is_valid_for_use() ) : ?>
        
			<table class="form-table">
			<?php
    			
    			$this->generate_settings_html();
			?>

			</table>
            
		<?php else : ?>
		<div class="inline error"><p><strong><?php _e('Шлюз отключен', 'woocommerce'); ?></strong>: <?php _e('Liqpay не поддерживает валюты Вашего магазина.', 'woocommerce' ); ?></p></div>
		<?php
			endif;
		}
		
        public function init_form_fields(){

            $this->form_fields = array(
                'enabled' => array(
                    'title' => __( 'Включить/Отключить', 'woocommerce' ),
                    'type' => 'checkbox',
                    'label' => __( 'Включить', 'woocommerce' ),
                    'default' => 'yes'
                                ),
                'title' => array(
                    'title' => __( 'Заголовок', 'woocommerce' ),
                    'type' => 'text',
                    'description' => __( 'Заголовок, который отображается на странице оформления заказа', 'woocommerce' ),
                    'default' => 'Оплата картой',
                    'desc_tip' => true,
                                ),
                'description' => array(
                    'title' => __( 'Описание', 'woocommerce' ),
                    'type' => 'textarea',
                    'description' => __( 'Описание, которое отображается в процессе выбора формы оплаты', 'woocommerce' ),
                    'default' => __( 'Оплатить через электронную платежную систему Liqpay', 'woocommerce' ),
                ),
                'merchant_id' => array(
                    'title' => __( 'Merchant ID', 'woocommerce' ),
                    'type' => 'text',
                    'description' => __( 'Уникальный идентификатор магазина в системе Liqpay.', 'woocommerce' ),
                ),
                'merchant_sig' => array(
                    'title' => __( 'Секретный ключ', 'woocommerce' ),
                    'type' => 'text',
                    'description' => __( 'Секретный ключ', 'woocommerce' ),
                )
            );
        }
        
        
        function is_valid_for_use()
        {
            if (!in_array(get_option('woocommerce_currency'), array('EUR','UAH','USD','RUB','RUR'))){
                return false;
            }
		return true;
	    }

        function process_payment($order_id)
        {
                $order = new WC_Order($order_id);
				return array(
        			'result' => 'success',
        			'redirect'	=> add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, get_permalink(woocommerce_get_page_id('pay'))))
        		);
                
         }

        public function receipt_page($order){
            echo '<p>'.__('Спасибо за Ваш заказ, пожалуйста, нажмите кнопку ниже, чтобы заплатить.', 'woocommerce').'</p>';
            echo $this->generate_form($order);
        }

        public function generate_form($order_id){
			global $woocommerce;

            $gate = new LiqPay($this->merchant_id, $this->merchant_sig);
			
            $order = new WC_Order( $order_id );
            $result_url = str_replace( 'https:', 'http:', add_query_arg( 'wc-api', 'wc_gateway_liqpay', home_url( '/' ) ) );

            switch (get_woocommerce_currency()) {
				case 'UAH':
					$currency = 'UAH';
					break;
				case 'USD':
					$currency = 'USD';
					break;
				case 'RUB':
					$currency = 'RUB';
					break;    
			}

            $html = $gate->cnb_form(array(
                'version'        => '3',
                'amount'         => $order->order_total,
                'currency'       => $currency,
                'description'    => "Оплата за заказ - ".$order_id,
                'order_id'       => $order_id,
                'result_url'     => get_permalink(get_option('woocommerce_thanks_page_id')).'order-received/'.$order_id,
                'server_url'     => $result_url
            ));

            return $html;
        }

        function check_liqpay_response(){
            global $woocommerce;
            if (isset($_POST['data'])  )
            {	
				$xml_decoded = base64_decode($_POST['data']);
                $hash = base64_encode(sha1($this->merchant_sig.$_POST['data'].$this->merchant_sig,1));
				$result = json_decode($xml_decoded);

              if ($hash == $_POST['signature'])
              {
                  $order_id = (string)$result->order_id;
                  $order = new WC_Order($order_id );

                  if( (string)$result->status == 'success'){
                      $order->update_status('on-hold', __('Платеж успешно оплачен', 'woocommerce'));
                      $order->payment_complete();
                      $woocommerce->cart->empty_cart();
                  }

                  if((string) $result->status == 'wait_secure')
                    $order->update_status('on-hold', __('Ожидание оплаты', 'woocommerce'));

                  if((string) $result->status == 'failure')
                      $order->update_status('failed', __('Платеж не оплачен', 'woocommerce'));
                      //wp_redirect(add_query_arg('key', $order->order_key, add_query_arg('order', $order_id , get_permalink(get_option('woocommerce_thanks_page_id')))));
                  exit;
              }
              else
              {
                  $order_id = $result->order_id;
                  $order = new WC_Order($order_id );
                  $order->update_status('failed', __('Платеж не оплачен', 'woocommerce'));
                  exit;
              }
            }
            else
            {
                wp_die('LiqPay Request Failure');
            }
        }
    }

	
	function woocommerce_add_liqpay_gateway($methods) {
		$methods[] = 'WC_Gateway_Liqpay';
		return $methods;
	}
	
	add_filter('woocommerce_payment_gateways', 'woocommerce_add_liqpay_gateway' );
	
}

?>
