<?php

namespace Lunar\Payment\classes;

use \Db;
use \Cart;
use \Order;
use \Tools;
use \Module;
use \Context;
use \Validate;
use \Configuration;
use \Currency;
use \Customer;
use \CustomerThread;
use \PrestaShopLogger;

use Lunar\Lunar as ApiClient;
use Lunar\Payment\methods\LunarCardMethod;
use Lunar\Payment\methods\LunarMobilePayMethod;

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminOrderHelper
{
    private $module;
    private $paymentMethod;
    private Context $context;

    /**
     * 
     */
	public function __construct($module) {
        $this->module = $module;
		$this->context = Context::getContext();
	}



    /**
	 * Make capture/refund/cancel in admin order view.
	 *
	 * @param string $id_order - the order id
	 * @param string $payment_action - the action to be called.
	 * @param boolean $change_status - change status flag
	 * @param float $plugin_amount_to_refund - the refund amount
	 *
	 * @return mixed
	 */
	 public function processOrderPayment($id_order, $payment_action, $change_status = false, $plugin_amount_to_refund = 0)
	 {
		$order = new Order( (int) $id_order );
		$dbLunarTransaction = $this->module->getLunarTransactionByOrderId($id_order);
		$isTransactionCaptured = $dbLunarTransaction['captured'] == 'YES';
		$transactionId = $dbLunarTransaction["lunar_tid"];
		
		$this->paymentMethod = $this->module->getPaymentMethodByName($dbLunarTransaction["method"]);

		$customer = new Customer( $order->id_customer );
		$currencyCode = (new Currency( (int) $order->id_currency ))->iso_code;
		$totalPrice = $order->getTotalPaid();

		$secretKey = $this->getConfigValue('TRANSACTION_MODE') == 'live'
						? $this->getConfigValue('LIVE_SECRET_KEY')
						: $this->getConfigValue('TEST_SECRET_KEY');
		$apiClient = new ApiClient( $secretKey );

		$fetchedTransaction    = $apiClient->payments()->fetch( $transactionId );

		if (!$fetchedTransaction) {
			$error = 'Fetch API transaction failed: no transaction with provided id: ' . $transactionId;
		}

		switch ( $payment_action ) {
			case "capture":
				if ( $isTransactionCaptured ) {
					$response = array(
						'warning' => 1,
						'message' => $this->displayErrors('Transaction already Captured.'),
					);
				} elseif ( isset( $dbLunarTransaction ) ) {
					$amount   = ( ! empty( $fetchedTransaction['transaction']['pendingAmount'] ) ) ? (int) $fetchedTransaction['transaction']['pendingAmount'] : 0;

					if ( $amount ) {
						/* Capture transaction */
						$data    = array(
							'currency'   => $currencyCode,
							'amount'     => $amount,
						);

						$capture = $apiClient->payments()->capture( $transactionId, $data );

						if ( is_array( $capture ) && ! empty( $capture['error'] ) && $capture['error'] == 1 ) {
							PrestaShopLogger::addLog( $capture['message'] );
							$response = array(
								'error'   => 1,
								'message' => $this->displayErrors( $capture['message'], false ),
							);
						} else {
							if ( ! empty( $capture['transaction'] ) ) {
								//Update order status
								if($change_status){
									$order->setCurrentState( (int) Configuration::get( self::ORDER_STATUS ), $this->context->employee->id );
								}

								/* Update transaction details */
								$fields = array(
									'captured' => 'YES',
								);
								$this->updateTransaction( $transactionId, (int) $id_order, $fields );

								/* Set message */
								$message = 'Trx ID: ' . $transactionId . '
											Authorized Amount: ' . $capture['transaction']['amount'] . '
											Captured Amount: ' . $capture['transaction']['capturedAmount'] . '
											Order time: ' . $capture['transaction']['created'] . '
											Currency code: ' . $capture['transaction']['currency'];

								$message = strip_tags( $message, '<br>' );
								$this->maybeAddOrderMessage($message, $customer, $order);

								/* Set response */
								$response = array(
									'success' => 1,
									'message' => $this->displayErrors('Transaction successfully Captured.'),
								);
							} else {
								if ( ! empty( $capture[0]['message'] ) ) {
									$response = array(
										'warning' => 1,
										'message' => $this->displayErrors( $capture[0]['message'], false ),
									);
								} else {
									$response = array(
										'error'   => 1,
										'message' => $this->displayErrors('Oops! An error occurred while Capture.'),
									);
								}
							}
						}
					} else {
						$response = array(
							'error'   => 1,
							'message' => $this->displayErrors('Invalid amount to Capture.'),
						);
					}
				} else {
					$response = array(
						'error'   => 1,
						'message' => $this->displayErrors('Invalid Lunar Transaction.'),
					);
				}

				break;

			case "refund":
				if ( ! $isTransactionCaptured ) {
					$response = array(
						'warning' => 1,
						'message' => $this->displayErrors('You need to Captured Transaction prior to Refund.'),
					);
				} elseif ( isset( $dbLunarTransaction ) ) {

					if ( ! Validate::isPrice( $plugin_amount_to_refund ) ) {
						$response = array(
							'error'   => 1,
							'message' => $this->displayErrors('Invalid amount to Refund.'),
						);
					} else {
						/* Refund transaction */
						$amount              = $plugin_amount_to_refund;
						$data                = array(
							'descriptor' => '',
							'amount'     => $amount,
						);

						$refund = $apiClient->payments()->refund( $transactionId, $data );

						if ( is_array( $refund ) && ! empty( $refund['error'] ) && $refund['error'] == 1 ) {
							PrestaShopLogger::addLog( $refund['message'] );
							$response = array(
								'error'   => 1,
								'message' => $this->displayErrors( $refund['message'], false ),
							);
						} else {
							if ( ! empty( $refund['transaction'] ) ) {
								//Update order status
								if($change_status){
									$order->setCurrentState( (int) Configuration::get( 'PS_OS_REFUND' ), $this->context->employee->id );
								}

								/* Update transaction details */
								$fields = array(
									'refunded_amount' => $dbLunarTransaction['refunded_amount'] + $plugin_amount_to_refund,
								);
								$this->updateTransaction( $transactionId, (int) $id_order, $fields );

								/* Set message */
								$message = 'Trx ID: ' . $transactionId . '
											Authorized Amount: ' . $refund['transaction']['amount'] . '
											Refunded Amount: ' . $refund['transaction']['refundedAmount'] . '
											Order time: ' . $refund['transaction']['created'] . '
											Currency code: ' . $refund['transaction']['currency'];

								$message = strip_tags( $message, '<br>' );
								$this->maybeAddOrderMessage($message, $customer, $order);

								/* Set response */
								$response = array(
									'success' => 1,
									'message' => $this->displayErrors('Transaction successfully Refunded.'),
								);
							} else {
								if ( ! empty( $refund[0]['message'] ) ) {
									$response = array(
										'warning' => 1,
										'message' => $this->displayErrors( $refund[0]['message'], false ),
									);
								} else {
									$response = array(
										'error'   => 1,
										'message' => $this->displayErrors('Oops! An error occurred while Refund.'),
									);
								}
							}
						}
					}
				} else {
					$response = array(
						'error'   => 1,
						'message' => $this->displayErrors('Invalid Lunar Transaction.'),
					);
				}

				break;

			case "cancel":
				if ( $isTransactionCaptured ) {
					$response = array(
						'warning' => 1,
						'message' => $this->displayErrors('You can\'t Cancel transaction now . It\'s already Captured, try to Refund.'),
					);
				} elseif ( isset( $dbLunarTransaction ) ) {

					/* Cancel transaction */
					$amount = (int) $fetchedTransaction['transaction']['amount'] - $fetchedTransaction['transaction']['refundedAmount'];
					$data   = array(
						'amount' => $amount,
					);

					$cancel   = $apiClient->payments()->cancel( $transactionId, $data );

					if ( is_array( $cancel ) && ! empty( $cancel['error'] ) && $cancel['error'] == 1 ) {
						PrestaShopLogger::addLog( $cancel['message'] );
						$response = array(
							'error'   => 1,
							'message' => $this->displayErrors( $cancel['message'], false ),
						);
					} else {
						if ( ! empty( $cancel['transaction'] ) ) {
							//Update order status
							if($change_status){
								$order->setCurrentState( (int) Configuration::get( 'PS_OS_CANCELED' ), $this->context->employee->id );
							}

							/* Set message */
							$message = 'Trx ID: ' . $transactionId . '
										Authorized Amount: ' . $cancel['transaction']['amount'] . '
										Refunded Amount: ' . $cancel['transaction']['refundedAmount'] . '
										Order time: ' . $cancel['transaction']['created'] . '
										Currency code: ' . $cancel['transaction']['currency'];

							$message = strip_tags( $message, '<br>' );
							$this->maybeAddOrderMessage($message, $customer, $order);

							/* Set response */
							$response = array(
								'success' => 1,
								'message' => $this->displayErrors('Transaction successfully Canceled.'),
							);
						} else {
							if ( ! empty( $cancel[0]['message'] ) ) {
								$response = array(
									'warning' => 1,
									'message' => $this->displayErrors( $cancel[0]['message'], false ),
								);
							} else {
								$response = array(
									'error'   => 1,
									'message' => $this->displayErrors('Oops! An error occurred while Cancel.'),
								);
							}
						}
					}
				} else {
					$response = array(
						'error'   => 1,
						'message' => $this->displayErrors('Invalid Lunar Transaction.'),
					);
				}
				break;
		}
		return $response;
	}

	/**
	 * 
	 */
	private function maybeAddOrderMessage(string $message, Customer $customer, Order $order)
	{
		if ( ! Validate::isCleanHtml( $message ) ) {
			return;
		}

		if ( $this->getPSV() == '1.7.2' ) {
			$id_customer_thread = CustomerThread::getIdCustomerThreadByEmailAndIdOrder( $customer->email, $order->id );
			if ( ! $id_customer_thread ) {
				$customer_thread              = new CustomerThread();
				$customer_thread->id_contact  = 0;
				$customer_thread->id_customer = (int) $order->id_customer;
				$customer_thread->id_shop     = (int) $this->context->shop->id;
				$customer_thread->id_order    = (int) $order->id;
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
			$msg->id_cart     = (int) $order->id_cart;
			$msg->id_customer = (int) $order->id_customer;
			$msg->id_order    = (int) $order->id;
			$msg->private     = 1;
			$msg->add();
		}
	}

	/**
	 * 
	 */
	private function updateTransaction( $lunar_txn_id, $order_id, $fields = [] ) {
		if ( $lunar_txn_id && $order_id && ! empty( $fields ) ) {
			$fieldsStr  = '';
			$fieldCount = count( $fields );
			$counter    = 0;

			foreach ( $fields as $field => $value ) {
				$counter ++;
				$fieldsStr .= '`' . pSQL( $field ). '` = "' . pSQL( $value ) . '"';

				if ( $counter < $fieldCount ) {
					$fieldsStr .= ', ';
				}
			}

			$query = 'UPDATE ' . _DB_PREFIX_ . 'lunar_transactions SET ' . $fieldsStr
						. ' WHERE `' . 'lunar_tid`="' . pSQL( $lunar_txn_id )
						. '" AND `order_id`="' . (int)$order_id . '"';

			return Db::getInstance()->execute( $query );
		} else {
			return false;
		}
	}


	/**
	 * 
	 */
	public function refundPaymentOnSlipAdd($params)
	{
		/* Check if "Refund" checkbox is checked */
		if (Tools::isSubmit('doRefundLunar')) {
			return;
		}

		$id_order = $params['order']->id;
		$amount = 0;

		/* Calculate total amount */
		foreach ($params['productList'] as $product) {
			$amount += floatval($product['amount']);
		}

		/* Add shipping to total */
		if (Tools::getValue('partialRefundShippingCost')) {
			$amount += floatval(Tools::getValue('partialRefundShippingCost'));
		}

		/* For prestashop version > 1.7.7 */
		if  ($refundData = \Tools::getValue('cancel_product')) {
			$shipping_amount = floatval(str_replace(',', '.', $refundData['shipping_amount']));
			if(isset($refundData['shipping']) && $refundData['shipping']==1 && $shipping_amount == 0){
				$shipping_amount = floatval(str_replace(',', '.', $params['order']->total_shipping));
			}
			$amount += $shipping_amount;
		}

		/* Init payment action */
		$response = $this->processOrderPayment($id_order,"refund",false,$amount);
		PrestaShopLogger::addLog( $response['message'] );

		/* Add response to cookies  */
		if(isset($response['error']) && $response['error'] == '1'){
			$this->context->cookie->__set('response_error', $response['message']);
			$this->context->cookie->write();
		}elseif(isset($response['warning']) && $response['warning'] == '1'){
			$this->context->cookie->__set('response_warnings', $response['message']);
			$this->context->cookie->write();
		}else{
			$this->context->cookie->__set('response_confirmations', $response['message']);
			$this->context->cookie->write();
		}
	}

	/**
	 * 
	 */
	public function paymentActionOnOrderStatusChange($params)
	{
		$order_state = $params['newOrderStatus'];
		$id_order    = $params['id_order'];

		/* Skip if no module transaction */
		$dbLunarTransaction = $this->module->getLunarTransactionByOrderId($id_order);
		if ( empty( $dbLunarTransaction ) ) {
			return false;
		}

		$this->paymentMethod = $this->module->getPaymentMethodByName($dbLunarTransaction["method"]);

		/* If Capture or Cancel */
		if ( $order_state->id == (int) $this->getConfigValue('ORDER_STATUS') || $order_state->id == (int) Configuration::get( 'PS_OS_CANCELED' ) ) {
				/* If custom Captured status  */
				if ( $order_state->id == (int) $this->getConfigValue('ORDER_STATUS') ) {
					$response = $this->processOrderPayment($id_order,"capture");
				}
	
				/* If Canceled status */
				if ( $order_state->id == (int) Configuration::get( 'PS_OS_CANCELED' ) ) {
					$response = $this->processOrderPayment($id_order,"cancel");
				}
	
				/* Log response */
				PrestaShopLogger::addLog( $response['message'] );
	
				/* Add response to cookies  */
				if(isset($response['error']) && $response['error'] == '1'){
					$this->context->cookie->__set('response_error', $response['message']);
					$this->context->cookie->write();
				}elseif(isset($response['warning']) && $response['warning'] == '1'){
					$this->context->cookie->__set('response_warnings', $response['message']);
					$this->context->cookie->write();
				}else{
					$this->context->cookie->__set('response_confirmations', $response['message']);
					$this->context->cookie->write();
				}
			}
	}


    /**
     * 
     */
	private function displayErrors( $string = 'Fatal error', $translated = true, $htmlentities = true, Context $context = null ) {
		if ( !$htmlentities ) {
			return $this->t( 'Fatal error', [], 'Admin.Notifications.Error' );
		}
		
		$translated ? $string = $this->t($string) : null;
		return ( Tools::htmlentitiesUTF8( 'Lunar: ' . stripslashes( $string ) ) . '<br/>' );
	}

    /**
     * 
     */
    protected function t($string)
    {
        return $this->module->t($string);
    }

	/**
     * @return mixed
     */
    private function getConfigValue($configKey)
    {
        if (isset($this->paymentMethod->{$configKey})) {
            return Configuration::get($this->paymentMethod->{$configKey});
        }

        return null;
    }
}