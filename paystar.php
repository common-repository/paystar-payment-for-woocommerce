<?php

/*
Plugin Name: PayStar.ir Payment Method for WooCommerce
Plugin URI: https://paystar.ir
Description: <b> درگاه پرداخت پی استار برای افزونه ووکامرس وردپرس </b>
Version: 1.0
Author: ماژول بانک
Author URI: https://www.modulebank.ir
Text Domain: paystar-payment-for-woocommerce
Domain Path: /languages
 */

function woocommerce_paystar_init()
{
	load_plugin_textdomain('paystar-payment-for-woocommerce', false, basename(dirname(__FILE__)) . '/languages');
	if (!class_exists('WC_Payment_Gateway')) return;
	class WC_PayStar extends WC_Payment_Gateway
	{
		public function __construct()
		{
			$this->id = 'paystar';
			$this->plugin_name = __('PayStar.ir Payment Method for WooCommerce', 'paystar-payment-for-woocommerce');
			$this->method_title = __('PayStar Payment Gateway', 'paystar-payment-for-woocommerce');
			$this->icon = plugin_dir_url(__FILE__) . 'images/logo.png';
			$this->has_fields = false;
			$this->init_form_fields();
			$this->init_settings();
			$this->title = $this->settings['title'];
			$this->description = $this->settings['description'];
			$this->terminal = $this->settings['terminal'];
			$this->secret = $this->settings['secret'];
			add_action('woocommerce_api_' . strtolower(get_class($this)), array($this, 'check_paystar_response'));
			add_action('valid-paystar-request', array($this, 'successful_request'));
			add_action('woocommerce_update_options_payment_gateways_paystar', array($this, 'process_admin_options'));
			add_action('woocommerce_receipt_paystar', array($this, 'receipt_page'));
		}

		function init_form_fields()
		{
			$this->form_fields = array(
				'enabled'     => array(
					'title'   => __('Enable / Disable', 'paystar-payment-for-woocommerce'),
					'type'    => 'checkbox',
					'label'   => __('Enable or Disable This Payment Mehod', 'paystar-payment-for-woocommerce'),
					'default' => 'yes'
				),
				'title'           => array(
					'title'       => __('Display Title', 'paystar-payment-for-woocommerce'),
					'type'        => 'text',
					'description' => __('Display Title', 'paystar-payment-for-woocommerce'),
					'default'     => __('PayStar Payment Gateway', 'paystar-payment-for-woocommerce')
				),
				'description'     => array(
					'title'       => __('Payment Instruction', 'paystar-payment-for-woocommerce'),
					'type'        => 'textarea',
					'description' => __('Payment Instruction', 'paystar-payment-for-woocommerce'),
					'default'     => __('Pay by PayStar Payment Gateway', 'paystar-payment-for-woocommerce')
				),
				'terminal'        => array(
					'title'       => __('PayStar Terminal', 'paystar-payment-for-woocommerce'),
					'type'        => 'text',
					'description' => __('Enter PayStar Terminal', 'paystar-payment-for-woocommerce')
				),
				'secret'        => array(
					'title'       => __('PayStar Secret Key', 'paystar-payment-for-woocommerce'),
					'type'        => 'text',
					'description' => __('Enter PayStar Secret Key', 'paystar-payment-for-woocommerce')
				),
			);
		}

		public function admin_options()
		{
			echo '<h3>'.__('PayStar Payment Gateway', 'paystar-payment-for-woocommerce').'</h3>';
			echo '<table class="form-table">';
			$this->generate_settings_html();
			echo '</table>';
		}

		function payment_fields()
		{
			if($this->description) echo wpautop(wptexturize($this->description));
		}

		function receipt_page($order)
		{
			echo '<p>'.__('thank you for your order. you are redirecting to paystar gateway. please wait', 'paystar-payment-for-woocommerce').'</p>';
			echo $this->generate_paystar_form($order);
       }

		function process_payment($order_id)
		{
			$order = new WC_Order($order_id);
			return array('result' => 'success', 'redirect' => $order->get_checkout_payment_url(true)); 
		}

		function check_paystar_response()
		{
			global $woocommerce;
			if (isset($_POST['status'],$_POST['order_id'],$_POST['ref_num']))
			{
				$post_status = sanitize_text_field($_POST['status']);
				$post_order_id = sanitize_text_field($_POST['order_id']);
				$post_ref_num = sanitize_text_field($_POST['ref_num']);
				$post_tracking_code = sanitize_text_field($_POST['tracking_code']);
				$post_card_number = sanitize_text_field($_POST['card_number']);
				$order_id = $post_order_id;
				$order = new WC_Order($order_id);
				if ($post_status != 1)
				{
					$message = __('Payment Cancelled By User', 'paystar-payment-for-woocommerce');
					$order->add_order_note($message);
				}
				elseif (!is_object($order))
				{
					$message = __('Error : Order Not Exists!', 'paystar-payment-for-woocommerce');
				}
				elseif ($order->is_paid())
				{
					$message = __('Error : Order Paid Already!', 'paystar-payment-for-woocommerce');
				}
				else
				{
					if (strlen($post_card_number)) {
						$message = sprintf(__("Paymenter Card Number : %s", 'paystar-payment-for-woocommerce'), '<span dir="ltr" style="direction:ltr">'.$post_card_number.'</span>');
						$order->add_order_note($message);
					}
					$amount = $order->order_total * (get_woocommerce_currency() == 'IRHT' ? 10000 : (get_woocommerce_currency() == 'IRT' ? 10 : 1));
					require_once(dirname(__FILE__) . '/paystar_payment_helper.class.php');
					$p = new PayStar_Payment_Helper($this->terminal, $this->secret);
					$r = $p->paymentVerify($request_payment_data = array(
							'status' => $post_status,
							'order_id' => $post_order_id,
							'ref_num' => $post_ref_num,
							'tracking_code' => $post_tracking_code,
							'amount' => $amount,
							'sign' => hash_hmac('sha512', $amount.'#'.$post_ref_num.'#'.$post_card_number.'#'.$post_tracking_code, $p->secret),
						));
					if ($r)
					{
						$message = sprintf(__("Payment Completed. OrderID : %s . PaymentRefrenceID : %s", 'paystar-payment-for-woocommerce'), $order_id, $p->txn_id);
						$order->payment_complete();
						$order->add_order_note($message);
						$woocommerce->cart->empty_cart();
						wc_add_notice($message, 'success');
						wp_redirect(add_query_arg('wc_status', 'success', $this->get_return_url($order)));
						exit;
					}
					else
					{
						$message = $p->error;
						$order->add_order_note($message);
					}
				}
			}
			else
			{
				$message = __('System (Permission) Error!', 'paystar-payment-for-woocommerce');
			}
			if (isset($message) && $message) wc_add_notice($message, 'error');
			wp_redirect($woocommerce->cart->get_checkout_url());
			exit;
		}

		public function generate_paystar_form($order_id)
		{
			global $woocommerce;
			$order = new WC_Order($order_id);
			require_once(dirname(__FILE__) . '/paystar_payment_helper.class.php');
			$p = new PayStar_Payment_Helper($this->terminal, $this->secret);
			$request_payment_data = array(
					'amount'      => ($order->order_total * (get_woocommerce_currency() == 'IRHT' ? 10000 : (get_woocommerce_currency() == 'IRT' ? 10 : 1))),
					'order_id'    => strval($order_id),
					'name'        => $order->get_formatted_shipping_full_name(),
					'phone'       => $order->get_billing_phone(),
					'mail'        => $order->get_billing_email(),
					'description' => 'WC-Order#'.$order_id,
					'callback'    => add_query_arg(array('wc-api' => get_class($this), 'order_id' => $order_id), get_site_url().'/'),
				);
			$request_payment_data['sign'] = hash_hmac('sha512', $request_payment_data['amount'].'#'.$request_payment_data['order_id'].'#'.$request_payment_data['callback'], $p->secret);
			$r = $p->paymentRequest($request_payment_data);
			if ($r)
			{
				update_post_meta($order_id, 'paystar_token', $p->data->token);
				session_write_close();
				echo '<form name="frmPayStarPayment" method="post" action="'.esc_url($p->getPaymentUrl()).'"><input type="hidden" name="token" value="'.esc_html($p->data->token).'" />';
				echo '<input class="paystar_btn btn button" type="submit" value="'.__('Pay', 'paystar-payment-for-woocommerce').'" /></form>';
				echo '<script>document.frmPayStarPayment.submit();</script>';
			}
			else
			{
				$order->add_order_note(__('Erorr', 'paystar-payment-for-woocommerce') . ' : ' . $p->error);
				echo esc_html($p->error);
			}
		}
	}

	function plugin_action_links($links)
	{
		return array_merge(array('settings' => '<a href="'.admin_url('admin.php?page=wc-settings&tab=checkout&section=paystar').'">'.__('Settings', 'paystar-payment-for-woocommerce').'</a>'), $links);
	}
	add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'plugin_action_links');

	function woocommerce_add_paystar_gateway($methods)
	{
		$methods[] = 'WC_PayStar';
		return $methods;
	}
	add_filter('woocommerce_payment_gateways', 'woocommerce_add_paystar_gateway');
}
add_action('plugins_loaded', 'woocommerce_paystar_init', 666);

?>