<?php
/**
 *
 * @author    Lunar <support@lunar.app>
 * @copyright Copyright (c) permanent, Lunar
 * @license   Addons PrestaShop license limitation
 * @link      https://lunar.app
 *
 */

use Lunar\Lunar as ApiClient;

/**
 * 
 */
class LunarpaymentPaymentReturnModuleFrontController extends ModuleFrontController {

	public function __construct() {
		parent::__construct();
		$this->display_column_right = false;
		$this->display_column_left  = false;
		$this->context              = Context::getContext();
	}

	public function init() {
		parent::init();
		$cart = $this->context->cart;
		if ( $cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0 || ! $this->module->active ) {
			Tools::redirect( 'index.php?controller=order&step=1' );
		}

		$authorized = false;
		foreach ( Module::getPaymentModules() as $module ) {
			if ( $module['name'] == 'lunarpayment' ) {
				$authorized = true;
				break;
			}
		}

		if ( ! $authorized ) {
			die( $this->module->l( 'Lunar payment method is not available.', 'paymentreturn' ) );
		}

		$customer = new Customer( $cart->id_customer );
		if ( ! Validate::isLoadedObject( $customer ) ) {
			Tools::redirect( 'index.php?controller=order&step=1' );
		}

		$secretKey = 'live' == Configuration::get( $this->module::TRANSACTION_MODE )
						? Configuration::get( $this->module::LIVE_SECRET_KEY )
						: Configuration::get( $this->module::TEST_SECRET_KEY );

		$apiClient = new ApiClient( $secretKey );

		$cart_total = $cart->getOrderTotal( true, Cart::BOTH );
		$currency = new Currency( (int) $cart->id_currency );
		$cart_amount = $cart_total;
		$status_paid = (int) Configuration::get( $this->module::ORDER_STATUS );
		// $status_paid = Configuration::get('PS_OS_PAYMENT');

		$transactionId = Tools::getValue( 'transactionid' );

		$transaction_failed = false;

		if ( Configuration::get( $this->module::CHECKOUT_MODE ) == 'delayed' ) {
			$fetch = $apiClient->payments()->fetch( $transactionId );

			if ( is_array( $fetch ) && isset( $fetch['error'] ) && $fetch['error'] == 1 ) {
				PrestaShopLogger::addLog( $fetch['message'] );
				$this->context->smarty->assign( array(
					"lunar_order_error"   => 1,
					"lunar_error_message" => $fetch['message']
				) );

				return $this->setTemplate( 'module:lunarpayment/views/templates/front/payment_error.tpl' );

			} elseif ( is_array( $fetch ) && $fetch['transaction']['currency'] == $currency->iso_code ) {

				$total = $fetch['transaction']['amount'];

				$message = 'Trx ID: ' . $transactionId . '
                    Authorized Amount: ' . $total . '
                    Captured Amount: ' . $fetch['transaction']['capturedAmount'] . '
                    Order time: ' . $fetch['transaction']['created'] . '
                    Currency code: ' . $fetch['transaction']['currency'];
					
				if ( $this->module->validateOrder( (int) $cart->id, 2, $total, $this->module->displayName, $message, array('transaction_id' => $transactionId), null, false, $customer->secure_key ) ) {

					if ( Validate::isCleanHtml( $message ) ) {
						if ( $this->module->getPSV() == '1.7.2' ) {
							$id_customer_thread = CustomerThread::getIdCustomerThreadByEmailAndIdOrder( $customer->email, $this->module->currentOrder );
							
							if ( ! $id_customer_thread ) {
								$customer_thread              = new CustomerThread();
								$customer_thread->id_contact  = 0;
								$customer_thread->id_customer = (int) $customer->id;
								$customer_thread->id_shop     = (int) $this->context->shop->id;
								$customer_thread->id_order    = (int) $this->module->currentOrder;
								$customer_thread->id_lang     = (int) $this->context->language->id;
								$customer_thread->email       = $customer->email;
								$customer_thread->status      = 'open';
								$customer_thread->token       = Tools::passwdGen( 12 );
								$customer_thread->add();
							} else {
								$customer_thread = new CustomerThread( (int) $id_customer_thread );
							}

							$customer_message                     = new CustomerMessage();
							$customer_message->id_customer_thread = $customer_thread->id;
							$customer_message->id_employee        = 0;
							$customer_message->message            = $message;
							$customer_message->private            = 1;

							$customer_message->add();
						}
					}

					$this->module->storeTransactionID( $transactionId, $this->module->currentOrder, $total, $captured = 'NO' );

					Tools::redirectLink( __PS_BASE_URI__ . 'index.php?controller=order-confirmation&id_cart=' . $cart->id . '&id_module=' . $this->module->id . '&id_order=' . $this->module->currentOrder . '&key=' . $customer->secure_key );
				} else {
					$transaction_failed = true;
					$apiClient->payments()->cancel( $transactionId, array( 'amount' => $total ) ); //Cancel Order
				}
			} else {
				$transaction_failed = true;
			}
		} else {

			$data = array(
				'currency'   => $currency->iso_code,
				'amount'     => $cart_amount,
			);
			$capture = $apiClient->payments()->capture( $transactionId, $data );

			if ( is_array( $capture ) && ! empty( $capture['error'] ) && $capture['error'] == 1 ) {
				PrestaShopLogger::addLog( $capture['message'] );
				$this->context->smarty->assign( array(
					"lunar_order_error"   => 1,
					"lunar_error_message" => $capture['message']
				) );

				return $this->setTemplate( 'module:lunarpayment/views/templates/front/payment_error.tpl' );

			} elseif ( ! empty( $capture['transaction'] ) ) {

				$total = $capture['transaction']['amount'];

				$validOrder = $this->module->validateOrder( (int) $cart->id, $status_paid, $total, $this->module->displayName, null, array('transaction_id' => $transactionId), null, false, $customer->secure_key );

				$message = 'Trx ID: ' . $transactionId . '
                    Authorized Amount: ' . $total . '
                    Captured Amount: ' . $capture['transaction']['capturedAmount'] . '
                    Order time: ' . $capture['transaction']['created'] . '
                    Currency code: ' . $capture['transaction']['currency'];

				$message = strip_tags( $message, '<br>' );
				if ( Validate::isCleanHtml( $message ) ) {
					if ( $this->module->getPSV() == '1.7.2' ) {
						$id_customer_thread = CustomerThread::getIdCustomerThreadByEmailAndIdOrder( $customer->email, $this->module->currentOrder );
						if ( ! $id_customer_thread ) {
							$customer_thread              = new CustomerThread();
							$customer_thread->id_contact  = 0;
							$customer_thread->id_customer = (int) $customer->id;
							$customer_thread->id_shop     = (int) $this->context->shop->id;
							$customer_thread->id_order    = (int) $this->module->currentOrder;
							$customer_thread->id_lang     = (int) $this->context->language->id;
							$customer_thread->email       = $customer->email;
							$customer_thread->status      = 'open';
							$customer_thread->token       = Tools::passwdGen( 12 );
							$customer_thread->add();
						} else {
							$customer_thread = new CustomerThread( (int) $id_customer_thread );
						}

						$customer_message                     = new CustomerMessage();
						$customer_message->id_customer_thread = $customer_thread->id;
						$customer_message->id_employee        = 0;
						$customer_message->message            = $message;
						$customer_message->private            = 1;

						$customer_message->add();
					} else {
						$msg              = new Message();
						$msg->message     = $message;
						$msg->id_cart     = (int) $cart->id;
						$msg->id_customer = (int) $cart->id_customer;
						$msg->id_order    = (int) $this->module->currentOrder;
						$msg->private     = 1;
						$msg->add();
					}
				}

				$this->module->storeTransactionID( $transactionId, $this->module->currentOrder, $total, $captured = 'YES' );
				$redirectLink = __PS_BASE_URI__ . 'index.php?controller=order-confirmation&id_cart=' . $cart->id . '&id_module=' . $this->module->id . '&id_order=' . $this->module->currentOrder . '&key=' . $customer->secure_key;
				Tools::redirectLink( $redirectLink );
			} else {
				$transaction_failed = true;
			}
		}

		if ( $transaction_failed ) {
			$this->context->smarty->assign( "lunar_order_error", 1 );

			return $this->setTemplate( 'module:lunarpayment/views/templates/front/payment_error.tpl' );
		}
	}
}
