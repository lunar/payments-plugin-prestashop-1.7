<?php

namespace Lunar\Payment\classes;

use \Db;
use \Order;
use \Tools;
use \Module;
use \Context;
use \Message;
use \Currency;
use \Customer;
use \Validate;
use \Configuration;
use \CustomerMessage;
use \CustomerThread;
use \PrestaShopLogger;

use LunarPayment;
use Lunar\Lunar as ApiClient;
use Lunar\Exception\ApiException;

/**
 * 
 */
class AdminOrderHelper
{
	private LunarPayment $module;
	private $paymentMethod; // @TODO make an interface for type hinting
	private Order $order;
	private Context $context;
	private string $action = '';

	private array $actionMap = [
		'capture' => 'Captured',
		'refund' => 'Refunded',
		'cancel' => 'Cancelled',
	];

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
	 * @param float $amount_to_refund - the refund amount
	 *
	 * @return mixed
	 */
	 public function processOrderPayment($id_order, $payment_action, $amount_to_refund = 0)
	 {
		$dbLunarTransaction = $this->module->getLunarTransactionByOrderId($id_order);
		$isTransactionCaptured = $dbLunarTransaction['captured'] == 'YES';
		$transactionId = $dbLunarTransaction["lunar_tid"];
		$refundedAmount = $dbLunarTransaction['refunded_amount'];
		
		$this->action = $payment_action;
		$this->order = new Order((int) $id_order);
		$this->paymentMethod = $this->module->getPaymentMethodByName($dbLunarTransaction["method"]);
		
		$currency = new Currency((int) $this->order->id_currency);
		$currencyCode = $currency->iso_code;
		$customer = new Customer($this->order->id_customer);
		$totalAmount = (string) $this->order->getTotalPaid();

		$secretKey = $this->getConfigValue('TRANSACTION_MODE') == 'live'
						? $this->getConfigValue('LIVE_SECRET_KEY')
						: $this->getConfigValue('TEST_SECRET_KEY');
		$apiClient = new ApiClient($secretKey);

		$fetchedTransaction = $apiClient->payments()->fetch($transactionId);

		if (!$fetchedTransaction && !$fetchedTransaction['authorisationCreated']) {
			return [
				'error'   => 1,
				'message' => $this->message('No transaction or authorization with provided id: ') . $transactionId,
			];
		}

		if (
			($fetchedTransaction['amount']['currency'] ?? '') != $currencyCode
			||
			($fetchedTransaction['amount']['decimal'] ?? '') != $totalAmount
		) {
			return [
				'error'   => 1,
				'message' => $this->message('Currency or amount doesn\'t match.'),
			];
		}
		
		$data = [
			'amount' => [
				'currency' => $currencyCode,
				'decimal' => $totalAmount,
			],
		];

		$apiTransaction = null;
		$newOrderStatus = null;
		$diffAmount = null;

		switch ($this->action) {
			case "capture":
				if ($isTransactionCaptured) {
					return [
						'warning' => 1,
						'message' => $this->message('Transaction already Captured.'),
					];
				}

				$newOrderStatus = (int) $this->getConfigValue('ORDER_STATUS') ;

				break;

			case "refund":
				if (! $isTransactionCaptured) {
					return [
						'warning' => 1,
						'message' => $this->message('You need to Capture Transaction prior to Refund.'),
					];
				} 

				if (! Validate::isPrice($amount_to_refund)) {
					return [
						'error'   => 1,
						'message' => $this->message('Invalid format amount to Refund.'),
					];
				}
				
				/** Round to currency precision */
				$amount_to_refund = round($amount_to_refund, $currency->precision);

				/* Modify amount to refund accordingly */
				$maxAmountToRefund = ($totalAmount - $refundedAmount);

                if ($amount_to_refund > $maxAmountToRefund) {
					return [
						'warning' => 1,
						'message' => $this->message('Cannot Refund more than ' . $maxAmountToRefund . ' ' . $currencyCode),
					];
                }

				$data['amount']['decimal'] = (string) $amount_to_refund;

				/** Leave order status unchanged until full refund */
                ($amount_to_refund == $maxAmountToRefund)
					? $newOrderStatus = (int) _PS_OS_REFUND_
					: $newOrderStatus = null;

				break;

			case "cancel":
				if ($isTransactionCaptured) {
					return [
						'warning' => 1,
						'message' => $this->message('Transaction it\'s already Captured, try to Refund.'),
					];
				}

				$newOrderStatus = (int) _PS_OS_CANCELED_;

				break;
		}

		try {
			$apiTransaction = $apiClient->payments()->{$this->action}($transactionId, $data);

		} catch (ApiException $e) {
			PrestaShopLogger::addLog($e->getMessage(), PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR);
			throw new \Exception($e->getMessage());
		}

		if ($apiTransaction && 'completed' != $apiTransaction["{$this->action}State"]) {
			$message = $apiTransaction['declinedReason']['error'] ?? json_encode($apiTransaction);
			PrestaShopLogger::addLog($message, PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR);
			throw new \Exception($message);
		}

		if($newOrderStatus){
			$this->order->setCurrentState($newOrderStatus, $this->context->employee->id);
		}

		$this->action == 'capture'
			? $this->updateTransaction($transactionId, ['captured' => 'YES'])
			: (
				$this->action == 'refund'
				? $this->updateTransaction($transactionId, ['refunded_amount' => $refundedAmount + $amount_to_refund])
				: null
			);

		$newOrderStatus ?: $diffAmount = $amount_to_refund; // if it's a partial refund
		$this->maybeAddOrderMessage($customer, $fetchedTransaction, $diffAmount);

		return [
			'success' => 1,
			'message' => $this->message('Transaction successfully ' . $this->actionMap[$this->action]),
		];
		
	}

	/**
	 * @TODO where is the message (saved in _messages table) displayed?
	 */
	private function maybeAddOrderMessage(Customer $customer, $fetchedTransaction, $diffAmount = null)
	{
		$transactionAmount = $fetchedTransaction['amount']['decimal'];
		$message = 'Trx ID: ' . $fetchedTransaction['id']  . PHP_EOL
					. 'Authorized Amount: ' . $transactionAmount . PHP_EOL
					. $this->actionMap[$this->action] . ' Amount: ' .  ($diffAmount ?? $transactionAmount) . PHP_EOL
					. 'Order time: ' . date('Y-m-d H:i:s')  . PHP_EOL
					. 'Currency code: ' . $fetchedTransaction['amount']['currency'];

		$message = strip_tags($message, '<br>');

		if (! Validate::isCleanHtml($message)) {
			return;
		}

		if ($this->module->getPSV() == '1.7.2') {
			$id_customer_thread = CustomerThread::getIdCustomerThreadByEmailAndIdOrder($customer->email, $this->order->id);
			if (! $id_customer_thread) {
				$customer_thread			  = new CustomerThread();
				$customer_thread->id_contact  = 0;
				$customer_thread->id_customer = (int) $this->order->id_customer;
				$customer_thread->id_shop     = (int) $this->context->shop->id;
				$customer_thread->id_order    = (int) $this->order->id;
				$customer_thread->id_lang     = (int) $this->context->language->id;
				$customer_thread->email       = $customer->email;
				$customer_thread->status      = 'open';
				$customer_thread->token       = Tools::passwdGen(12);
				$customer_thread->add();
			} else {
				$customer_thread = new CustomerThread((int) $id_customer_thread);
			}

			$customer_message					  = new CustomerMessage();
			$customer_message->id_customer_thread = $customer_thread->id;
			$customer_message->id_employee		  = 0;
			$customer_message->message			  = $message;
			$customer_message->private			  = 1;
			$customer_message->add();

		} else {
			$msg			  = new Message();
			$msg->message	  = $message;
			$msg->id_cart	  = (int) $this->order->id_cart;
			$msg->id_customer = (int) $this->order->id_customer;
			$msg->id_order	  = (int) $this->order->id;
			$msg->private	  = 1;
			$msg->add();
		}
	}

	/**
	 * 
	 */
	private function updateTransaction($lunar_txn_id, $fields = [])
	{
		$order_id = (int) $this->order->id;

		if ($lunar_txn_id && $order_id && ! empty($fields)) {
			$fieldsStr  = '';
			$fieldCount = count($fields);
			$counter	= 0;

			foreach ($fields as $field => $value) {
				$counter ++;
				$fieldsStr .= '`' . pSQL($field). '` = "' . pSQL($value) . '"';

				if ($counter < $fieldCount) {
					$fieldsStr .= ', ';
				}
			}

			$query = 'UPDATE ' . _DB_PREFIX_ . 'lunar_transactions SET ' . $fieldsStr
						. ' WHERE `' . 'lunar_tid`="' . pSQL($lunar_txn_id)
						. '" AND `order_id`="' . $order_id . '"';

			return Db::getInstance()->execute($query);
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
		if (!Tools::isSubmit('doRefundLunar')) {
			return;
		}

		$amountToRefund = 0;

		/* Calculate total amount */
		foreach ($params['productList'] as $product) {
			$amountToRefund += floatval($product['amount']);
		}

		/* Add shipping to total */
		if (Tools::getValue('partialRefundShippingCost')) {
			$amountToRefund += floatval(Tools::getValue('partialRefundShippingCost'));
		}

		/* For prestashop version > 1.7.7 */
		// @TODO is replacing "," with "." still necessary?
		if  ($refundData = Tools::getValue('cancel_product')) {
			$shipping_amount = floatval(str_replace(',', '.', $refundData['shipping_amount']));
			if(isset($refundData['shipping']) && $refundData['shipping'] == 1 && $shipping_amount == 0){
				$shipping_amount = floatval(str_replace(',', '.', $params['order']->total_shipping));
			}
			$amountToRefund += $shipping_amount;
		}

		$response = $this->processOrderPayment($params['order']->id, "refund", $amountToRefund);

		$this->addResponseToCookie($response);
	}

	/**
	 * 
	 */
	public function paymentActionOnOrderStatusChange($params)
	{
		$order_state = $params['newOrderStatus'];
		$id_order	= $params['id_order'];

		$dbLunarTransaction = $this->module->getLunarTransactionByOrderId($id_order);

		if (empty($dbLunarTransaction)) {
			return false;
		}

		$this->paymentMethod = $this->module->getPaymentMethodByName($dbLunarTransaction["method"]);
		$customCaptureStatus = $this->getConfigValue('ORDER_STATUS');
		
		if (
			$order_state->id != $customCaptureStatus
			&& $order_state->id != _PS_OS_CANCELED_
		) {
			return false;
		}

		if ($order_state->id == $customCaptureStatus) {
			$response = $this->processOrderPayment($id_order, "capture");
		}

		if ($order_state->id == _PS_OS_CANCELED_) {
			$response = $this->processOrderPayment($id_order, "cancel");
		}
		
		$this->addResponseToCookie($response);

	}

	/**
	 * 
	 */
	private function addResponseToCookie($response) {
		if (isset($response['error']) && $response['error'] == 1) {
			PrestaShopLogger::addLog($response['message'], PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR);
			$this->context->cookie->__set('response_error', $response['message']);
			$this->context->cookie->write();
		} elseif (isset($response['warning']) && $response['warning'] == 1) {
			PrestaShopLogger::addLog($response['message'], PrestaShopLogger::LOG_SEVERITY_LEVEL_WARNING);
			$this->context->cookie->__set('response_warnings', $response['message']);
			$this->context->cookie->write();
		} else {
			$this->context->cookie->__set('response_confirmations', $response['message']);
			$this->context->cookie->write();
		}
	}

	/**
	 * 
	 */
	private function message($string = 'Fatal error', $translated = true, $htmlentities = true, Context $context = null) {
		if (!$htmlentities) {
			return $this->t('Fatal error', [], 'Admin.Notifications.Error');
		}

		$translated ? $string = $this->t($string) : null;
		return (Tools::htmlentitiesUTF8('Lunar: ' . stripslashes($string)) . '<br/>');
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