<?php
/**
 * Redirects the browser to paypal to perform payment
 *
 * @package     Joomla.Plugin
 * @subpackage  Fabrik.form.paypal
 * @copyright   Copyright (C) 2005-2013 fabrikar.com - All rights reserved.
 * @license     GNU/GPL http://www.gnu.org/copyleft/gpl.html
 */

// No direct access
defined('_JEXEC') or die('Restricted access');

// Require the abstract plugin class
require_once COM_FABRIK_FRONTEND . '/models/plugin-form.php';

/**
 * Redirects the browser to paypal to perform payment
 *
 * @package     Joomla.Plugin
 * @subpackage  Fabrik.form.paypal
 * @since       3.0
 */
class PlgFabrik_FormPaypal extends PlgFabrik_Form
{
	/**
	 * Run right at the end of the form processing
	 * form needs to be set to record in database for this to hook to be called
	 *
	 * @return	bool
	 */
	public function onAfterProcess()
	{
		$params = $this->getParams();
		$formModel = $this->getModel();
		$input = $this->app->input;
		$this->data = $this->getProcessData();
		JTable::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_fabrik/tables');
		$log = FabTable::getInstance('log', 'FabrikTable');

		if (!$this->shouldProcess('paypal_conditon', null, $params))
		{
			return true;
		}

		$w = new FabrikWorker;
		$userId = $this->user->get('id');
		$ipn = $this->getIPNHandler($params);

		if ($ipn !== false)
		{
			if (method_exists($ipn, 'createInvoice'))
			{
				$ipn->createInvoice();
			}
		}

		$testMode = $params->get('paypal_testmode', $input->get('paypal_testmode', false));
		$url = $testMode == 1 ? 'https://www.sandbox.paypal.com/us/cgi-bin/webscr?' : 'https://www.paypal.com/cgi-bin/webscr?';

		$opts = array();
		$opts['cmd'] = $params->get('paypal_cmd', "_xclick");

		$email = $testMode ? 'paypal_accountemail_testmode' : 'paypal_accountemail';
		$email = $params->get($email);

		if (trim($email) == '')
		{
			$email = $this->data[FabrikString::safeColNameToArrayKey($params->get('paypal_accountemail_element'))];

			if (is_array($email))
			{
				$email = array_shift($email);
			}
		}

		$opts['business'] = $email;
		$amount = $params->get('paypal_cost');
		$amount = $w->parseMessageForPlaceHolder($amount, $this->data);

		/**
		 * Adding eval option on cost field
		 * Useful if you use a cart system which will calculate on total shipping or tax fee and apply it. You can return it in the Cost field.
		 * Returning false will log an error and bang out with a runtime exception.
		 */
		if ($params->get('paypal_cost_eval', 0) == 1)
		{
			$amount = @eval($amount);

			if ($amount === false)
			{
				$log->message_type = 'fabrik.paypal.onAfterProcess';
				$msg = new stdClass;
				$msg->opt = $opts;
				$msg->data = $this->data;
				$msg->msg = "Eval amount code returned false.";
				$log->message = json_encode($msg);
				$log->store();
				throw new RuntimeException(FText::_('PLG_FORM_PAYPAL_COST_ELEMENT_ERROR'), 500);
			}
		}

		if (trim($amount) == '')
		{
			// Priority to raw data.
			$amountKey = FabrikString::safeColNameToArrayKey($params->get('paypal_cost_element'));
			$amount = FArrayHelper::getValue($this->data, $amountKey);
			$amount = FArrayHelper::getValue($this->data, $amountKey . '_raw', $amount);

			if (is_array($amount))
			{
				$amount = array_shift($amount);
			}
		}

		$opts['amount'] = $amount;

		// $$$tom added Shipping Cost params
		$shippingAmount = $params->get('paypal_shipping_cost');

		if ($params->get('paypal_shipping_cost_eval', 0) == 1)
		{
			$shippingAmount = @eval($shippingAmount);
		}

		if (trim($shippingAmount) == '')
		{
			$shippingAmount = FArrayHelper::getValue($this->data, FabrikString::safeColNameToArrayKey($params->get('paypal_shipping_cost_element')));

			if (is_array($shippingAmount))
			{
				$shippingAmount = array_shift($shippingAmount);
			}
		}

		$opts['shipping'] = "$shippingAmount";
		$item = $params->get('paypal_item');
		$item = $w->parseMessageForPlaceHolder($item, $this->data);

		if ($params->get('paypal_item_eval', 0) == 1)
		{
			$item = @eval($item);
			$itemRaw = $item;
		}

		if (trim($item) == '')
		{
			$itemRaw = FArrayHelper::getValue($this->data, FabrikString::safeColNameToArrayKey($params->get('paypal_item_element') . '_raw'));
			$item = $this->data[FabrikString::safeColNameToArrayKey($params->get('paypal_item_element'))];

			if (is_array($item))
			{
				$item = array_shift($item);
			}
		}

		// $$$ hugh - strip any HTML tags from the item name, as PayPal doesn't like them.
		$opts['item_name'] = strip_tags($item);

		// $$$ rob add in subscription variables
		if ($this->isSubscription($params))
		{
			$subTable = JModelLegacy::getInstance('List', 'FabrikFEModel');
			$subTable->setId((int) $params->get('paypal_subs_table'));

			$idEl = FabrikString::safeColName($params->get('paypal_subs_id', ''));
			$durationEl = FabrikString::safeColName($params->get('paypal_subs_duration', ''));
			$durationPerEl = FabrikString::safeColName($params->get('paypal_subs_duration_period', ''));
			$name = $params->get('paypal_subs_name', '');

			$subDb = $subTable->getDb();
			$query = $subDb->getQuery(true);
			$query->select('*, ' . $durationEl . ' AS p3, ' . $durationPerEl . ' AS t3, ' . $subDb->q($itemRaw) . ' AS item_number')
			->from($subTable->getTable()->db_table_name)
			->where($idEl . ' = ' . $subDb->quote($itemRaw));
			$subDb->setQuery($query);
			$sub = $subDb->loadObject();

			if (is_object($sub))
			{
				$opts['p3'] = $sub->p3;
				$opts['t3'] = $sub->t3;
				$opts['a3'] = $amount;
				$opts['no_note'] = 1;
				$opts['custom'] = '';

				$filter = JFilterInput::getInstance();
				$post = $filter->clean($_POST, 'array');
				$tmp = array_merge($post, JArrayHelper::fromObject($sub));

				// 'http://fabrikar.com/ '.$sub->item_name.' - User: subtest26012010 (subtest26012010)';
				$opts['item_name'] = $w->parseMessageForPlaceHolder($name, $tmp);
				$opts['invoice'] = $w->parseMessageForPlaceHolder($params->get('paypal_subs_invoice'), $tmp, false);

				if ($opts['invoice'] == '')
				{
					$opts['invoice'] = uniqid('', true);
				}

				$opts['src'] = $w->parseMessageForPlaceHolder($params->get('paypal_subs_recurring'), $tmp);
				$amount = $opts['amount'];
				unset($opts['amount']);
			}
			else
			{
				throw new RuntimeException('Could not determine subscription period, please check your settings', 500);
			}
		}

		if (!$this->isSubscription($params))
		{
			// Reset the amount which was unset during subscription code
			$opts['amount'] = $amount;
			$opts['cmd'] = '_xclick';

			// Unset any subscription options we may have set
			unset($opts['p3']);
			unset($opts['t3']);
			unset($opts['a3']);
			unset($opts['no_note']);
		}

		$shipping_table = $this->shippingTable();

		if ($shipping_table !== false)
		{
			$thisTable = $formModel->getListModel()->getTable()->db_table_name;
			$shippingUserId = $userId;

			/*
			 * If the shipping table is the same as the form's table, and no user logged in
			 * then use the shipping data entered into the form:
			 * see http://fabrikar.com/forums/index.php?threads/paypal-shipping-address-without-joomla-userid.33229/
			 */
			if ($shippingUserId === 0 && $thisTable === $shipping_table)
			{
				$shippingUserId = $formModel->formData['id'];
			}

			if ($shippingUserId > 0)
			{
				$shippingSelect = array();

				$db = FabrikWorker::getDbo();
				$query = $db->getQuery(true);

				if ($params->get('paypal_shippingdata_firstname'))
				{
					$shippingFirstName = FabrikString::shortColName($params->get('paypal_shippingdata_firstname'));
					$shippingSelect['first_name'] = $shippingFirstName;
				}

				if ($params->get('paypal_shippingdata_lastname'))
				{
					$shippingLastName = FabrikString::shortColName($params->get('paypal_shippingdata_lastname'));
					$shippingSelect['last_name'] = $shippingLastName;
				}

				if ($params->get('paypal_shippingdata_address1'))
				{
					$shippingAddress1 = FabrikString::shortColName($params->get('paypal_shippingdata_address1'));
					$shippingSelect['address1'] = $shippingAddress1;
				}

				if ($params->get('paypal_shippingdata_address2'))
				{
					$shippingAddress2 = FabrikString::shortColName($params->get('paypal_shippingdata_address2'));
					$shippingSelect['address2'] = $shippingAddress2;
				}

				if ($params->get('paypal_shippingdata_zip'))
				{
					$shippingZip = FabrikString::shortColName($params->get('paypal_shippingdata_zip'));
					$shippingSelect['zip'] = $shippingZip;
				}

				if ($params->get('paypal_shippingdata_state'))
				{
					$shippingState = FabrikString::shortColName($params->get('paypal_shippingdata_state'));
					$shippingSelect['state'] = $shippingState;
				}

				if ($params->get('paypal_shippingdata_city'))
				{
					$shippingCity = FabrikString::shortColName($params->get('paypal_shippingdata_city'));
					$shippingSelect['city'] = $shippingCity;
				}

				if ($params->get('paypal_shippingdata_country'))
				{
					$shippingCountry = FabrikString::shortColName($params->get('paypal_shippingdata_country'));
					$shippingSelect['country'] = $shippingCountry;
				}

				$query->clear();

				if (empty($shippingSelect) || $shipping_table == '')
				{
					$this->app->enqueueMessage('No shipping lookup table or shipping fields selected');
				}
				else
				{
					$query->select($shippingSelect)->from($shipping_table)
					->where(FabrikString::shortColName($params->get('paypal_shippingdata_id')) . ' = ' . $db->q($shippingUserId));

					$db->setQuery($query);
					$userShippingData = $db->loadObject();

					foreach ($shippingSelect as $opt => $val)
					{
						// $$$tom Since we test on the current userid, it always adds the &name=&street=....
						// Even if those vars are empty...
						if ($val)
						{
							$opts[$opt] = $userShippingData->$val;
						}
					}
				}
			}
		}

		if ($params->get('paypal_shipping_address_override', 0))
		{
			$opts['address_override'] = 1;
		}

		$currencyCode = $params->get('paypal_currencycode', 'USD');
		$currencyCode = $w->parseMessageForPlaceHolder($currencyCode, $this->data);
		$opts['currency_code'] = $currencyCode;

		$testSite = $params->get('paypal_test_site', '');
		$testSite = rtrim($testSite, '/');

		if ($testMode == 1 && !empty($testSite))
		{
			$ppurl = $testSite . '/index.php?option=com_' . $this->package . '&c=plugin&task=plugin.pluginAjax&formid=' . $formModel->get('id')
				. '&g=form&plugin=paypal&method=ipn';
		}
		else
		{
			$ppurl = COM_FABRIK_LIVESITE . 'index.php?option=com_' . $this->package . '&c=plugin&task=plugin.pluginAjax&formid=' . $formModel->get('id')
				. '&g=form&plugin=paypal&method=ipn';
		}

		$testSite_qs = $params->get('paypal_test_site_qs', '');

		if ($testMode == 1 && !empty($testSite_qs))
		{
			$ppurl .= $testSite_qs;
		}

		$ppurl .= '&renderOrder=' . $this->renderOrder;
		$ppurl = urlencode($ppurl);
		$opts['notify_url'] = "$ppurl";
		$paypal_return_url = $params->get('paypal_return_url', '');
		$paypal_return_url = $w->parseMessageForPlaceHolder($paypal_return_url, $this->data);

		if ($testMode == 1 && !empty($paypal_return_url))
		{
			if (preg_match('#^http:\/\/#', $paypal_return_url))
			{
				$opts['return'] = $paypal_return_url;
			}
			else
			{
				if (!empty($testSite))
				{
					$opts['return'] = $testSite . '/' . $paypal_return_url;
				}
				else
				{
					$opts['return'] = COM_FABRIK_LIVESITE . $paypal_return_url;
				}
			}

			if (!empty($testSite_qs))
			{
				$opts['return'] .= $testSite_qs;
			}
		}
		elseif (!empty($paypal_return_url))
		{
			if (preg_match('#^http:\/\/#', $paypal_return_url))
			{
				$opts['return'] = $paypal_return_url;
			}
			else
			{
				$opts['return'] = COM_FABRIK_LIVESITE . $paypal_return_url;
			}
		}
		else
		{
			// Using default thanks() method so don't forget to add renderOrder
			if ($testMode == '1' && !empty($testSite))
			{
				$opts['return'] = $testSite . '/index.php?option=com_' . $this->package . '&task=plugin.pluginAjax&formid=' . $formModel->get('id')
					. '&g=form&plugin=paypal&method=thanks&rowid=' . $this->data['rowid'] . '&renderOrder=' . $this->renderOrder;
			}
			else
			{
				$opts['return'] = COM_FABRIK_LIVESITE . 'index.php?option=com_' . $this->package . '&task=plugin.pluginAjax&formid=' . $formModel->get('id')
					. '&g=form&plugin=paypal&method=thanks&rowid=' . $this->data['rowid'] . '&renderOrder=' . $this->renderOrder;
			}
		}

		$opts['return'] = urlencode($opts['return']);

		$ipnValue = $params->get('paypal_ipn_value', '');
		$ipnValue = $w->parseMessageForPlaceHolder($ipnValue, $this->data);

		// Extra :'s will break parsing during IPN notify phase
		$ipnValue = str_replace(':', ';', $ipnValue);

		// $$$ hugh - thinking about putting in a call to a generic method in custom script
		// here and passing it a reference to $opts.

		if ($ipn !== false)
		{
			if (method_exists($ipn, 'checkOpts'))
			{
				if ($ipn->checkOpts($opts, $formModel) === false)
				{
					// Log the info
					$log->message_type = 'fabrik.paypal.onAfterProcess';
					$msg = new stdClass;
					$msg->opt = $opts;
					$msg->data = $this->data;
					$msg->msg = "Submission cancelled by checkOpts!";
					$log->message = json_encode($msg);
					$log->store();

					return true;
				}
			}
		}

		$opts['custom'] = $this->data['formid'] . ':' . $this->data['rowid'] . ':' . $ipnValue;
		$qs = array();

		foreach ($opts as $k => $v)
		{
			$qs[] = "$k=$v";
		}

		$url .= implode('&', $qs);

		/* $$$ rob 04/02/2011 no longer doing redirect from ANY plugin EXCEPT the redirect plugin
		 * - instead a session var is set (com_fabrik.form.X.redirect.url)
		 * as the preferred redirect url
		 */

		$context = $formModel->getRedirectContext();

		/* $$$ hugh - fixing issue with new redirect, which now needs to be an array.
		 * Not sure if we need to preserve existing session data, or just create a new surl array,
		 * to force ONLY redirect to PayPal?
		 */
		$surl = (array) $this->session->get($context . 'url', array());
		$surl[$this->renderOrder] = $url;
		$this->session->set($context . 'url', $surl);
		$this->session->set($context . 'redirect_content_how', 'samepage');

		// Log the info
		$log->message_type = 'fabrik.paypal.onAfterProcess';
		$msg = new stdClass;
		$msg->opt = $opts;
		$msg->data = $this->data;
		$log->message = json_encode($msg);
		$log->store();

		return true;
	}

	/**
	 * Check if we have a gateway subscription switch set up. This is for sites where
	 * you can toggle between a subscription or a single payment. E.g. fabrikar com
	 * if 'paypal_subscription_switch' is blank then use the $opts['cmd'] setting
	 * if not empty it should be some eval'd PHP which needs to return true for the payment
	 * to be treated as a subscription
	 * We want to do this so that single payments can make use of Paypals option to pay via credit card
	 * without a paypal account (subscriptions require a Paypal account)
	 * We do this after the subscription code has been run as this code is still needed to look up the correct item_name
	 *
	 * @param   JParameters  $params  Params
	 *
	 * @since 3.0.10
	 *
	 * @return boolean
	 */
	protected function isSubscription($params)
	{
		$data = $this->data;
		$subSwitch = $params->get('paypal_subscription_switch');

		if (trim($subSwitch) !== '')
		{
			$w = new FabrikWorker;
			$subSwitch = $w->parseMessageForPlaceHolder($subSwitch, $data);

			return @eval($subSwitch);
		}
		else
		{
			return $params->get('paypal_cmd') === '_xclick-subscriptions';
		}
	}

	/**
	 * Get the Shipping table name
	 *
	 * @return  string  db table name
	 */
	protected function shippingTable()
	{
		$params = $this->getParams();
		$shipping_table = (int) $params->get('paypal_shippingdata_table', '');

		if (empty($shipping_table))
		{
			return false;
		}

		$db = FabrikWorker::getDbo();
		$query = $db->getQuery(true);
		$query->select('db_table_name')->from('#__{package}_lists')->where('id = ' . (int) $params->get('paypal_shippingdata_table'));
		$db->setQuery($query);
		$db_table_name = $db->loadResult();

		if (!isset($db_table_name))
		{
			return false;
		}

		return $db_table_name;
	}

	/**
	 * Show thanks page
	 *
	 * @return  void
	 */
	public function onThanks()
	{
		/* @TODO - really need to work out how to get the plugin params at this point,
		 * so we don't have to pass the teg_msg around as a QS arg between us and PayPal,
		 * and just grab it from params directly.
		 */
		$input = $this->app->input;
		$formId = $input->getInt('formid');
		$rowId = $input->getString('rowid', '', 'string');
		JModelLegacy::addIncludePath(COM_FABRIK_FRONTEND . '/models');

		/** @var FabrikFEModelForm $formModel */
		$formModel = JModelLegacy::getInstance('Form', 'FabrikFEModel');
		$formModel->setId($formId);
		$params = $formModel->getParams();
		$retMsg = (array) $params->get('paypal_return_msg', array());
		$retMsg = $retMsg[$input->getInt('renderOrder')];

		if ($retMsg)
		{
			$w = new FabrikWorker;
			$listModel = $formModel->getlistModel();
			$row = $listModel->getRow($rowId);
			$retMsg = $w->parseMessageForPlaceHolder($retMsg, $row);

			if (JString::stristr($retMsg, '[show_all]'))
			{
				$all_data = array();

				foreach ($_REQUEST as $key => $val)
				{
					if (is_array($val))
					{
						$val = json_encode($val);
					}
					$all_data[] = "$key: $val";
				}

				$input->set('show_all', implode('<br />', $all_data));
			}

			$retMsg = str_replace('[', '{', $retMsg);
			$retMsg = str_replace(']', '}', $retMsg);
			$retMsg = $w->parseMessageForPlaceHolder($retMsg, $_REQUEST);
			echo $retMsg;
		}
		else
		{
			echo FText::_("thanks");
		}
	}

	/**
	 * Called from paypal at the end of the transaction
	 *
	 * @return  void
	 */
	public function onIpn()
	{
		$input = $this->app->input;
		$mail = JFactory::getMailer();
		JTable::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_fabrik/tables');
		$log = FabTable::getInstance('log', 'FabrikTable');
		$log->referring_url = $_SERVER['REQUEST_URI'];
		$log->message_type = 'fabrik.ipn.start';
		$log->message = json_encode($_REQUEST);
		$log->store();

		// Lets try to load in the custom returned value so we can load up the form and its parameters
		$custom = $input->get('custom', '', 'string');
		list($formId, $rowId, $ipnValue) = explode(":", $custom);

		// Pretty sure they are added but double add
		JModelLegacy::addIncludePath(COM_FABRIK_FRONTEND . '/models');

		/** @var FabrikFEModelForm $formModel */
		$formModel = JModelLegacy::getInstance('Form', 'FabrikFEModel');
		$formModel->setId($formId);
		$listModel = $formModel->getlistModel();
		$params = $formModel->getParams();
		$table = $listModel->getTable();
		$db = $listModel->getDb();
		$query = $db->getQuery(true);

		$testMode = $params->get('paypal_testmode', false);

		/* $$$ hugh
		 * @TODO shortColName won't handle joined data, need to fix this to use safeColName
		 * (don't forget to change quoteName stuff later on as well)
		 */
		$renderOrder = $input->getInt('renderOrder');
		$ipnTxnField = (array) $params->get('paypal_ipn_txn_id_element', array());
		$ipnTxnField = FabrikString::shortColName($ipnTxnField[$renderOrder]);

		$ipnPaymentField = (array) $params->get('paypal_ipn_payment_element', array());
		$ipnPaymentField = FabrikString::shortColName($ipnPaymentField[$renderOrder]);

		$ipnField = (array) $params->get('paypal_ipn_element', array());
		$ipnField = FabrikString::shortColName($ipnField[$renderOrder]);

		$ipnStatusField = (array) $params->get('paypal_ipn_status_element', array());
		$ipnStatusField = FabrikString::shortColName($ipnStatusField[$renderOrder]);

		$ipnAddressField = (array) $params->get('paypal_ipn_address_element', array());
		$ipnAddressField = FabrikString::shortColName($ipnAddressField[$renderOrder]);

		$w = new FabrikWorker;
		$ipnValue = str_replace('[', '{', $ipnValue);
		$ipnValue = str_replace(']', '}', $ipnValue);
		$ipnValue = $w->parseMessageForPlaceHolder($ipnValue, $_POST);

		$emailFrom = $admin_email = $this->config->get('mailfrom');

		// Read the post from PayPal system and add 'cmd'
		$req = 'cmd=_notify-validate';

		foreach ($_POST as $key => $value)
		{
			$value = urlencode(stripslashes($value));
			$req .= "&$key=$value";
		}

		// Post back to PayPal system to validate
		$header = "POST /cgi-bin/webscr HTTP/1.0\r\n";
		$header .= "Host: www.paypal.com:443\r\n";
		$header .= "Content-Type: application/x-www-form-urlencoded\r\n";
		$header .= "Content-Length: " . JString::strlen($req) . "\r\n\r\n";

		if ($_POST['test_ipn'] == 1)
		{
			$paypalUrl = 'ssl://www.sandbox.paypal.com';
		}
		else
		{
			$paypalUrl = 'ssl://www.paypal.com';
		}

		// Assign posted variables to local variables
		$item_name = $input->get('item_name', '', 'string');
		$item_number = $input->get('item_number', '', 'string');
		$payment_status = $input->get('payment_status', '', 'string');
		$payment_amount = $input->get('mc_gross', '', 'string');
		$payment_currency = $input->get('mc_currency', '', 'string');
		$txn_id = $input->get('txn_id', '', 'string');
		$txn_type = $input->get('txn_type', '', 'string');
		$receiver_email = $input->get('receiver_email', '', 'string');
		$payer_email = $input->get('payer_email', '', 'string');
		$buyer_address = $input->get('address_status', '', 'string') . ' - ' . $input->get('address_street', '', 'string')
			. ' ' . $input->get('address_zip', '', 'string')
			. ' ' . $input->get('address_state', '', 'string') . ' '
			. $input->get('address_city', '', 'string') . ' ' . $input->get('address_country_code', '', 'string');

		$status = 'ok';
		$errMsg = '';

		if (empty($formId))
		{
			$status = 'form.paypal.ipnfailure.custom_error';
			$errMsg = "formid or rowid empty in custom: $custom";
		}
		else
		{
			// @TODO implement a curl alternative as fsockopen is not always available
			$fp = fsockopen($paypalUrl, 443, $errno, $errstr, 30);

			if (!$fp)
			{
				$status = 'form.paypal.ipnfailure.fsock_error';
				$errMsg = "fsock error: $errno;$errstr";
			}
			else
			{
				fputs($fp, $header . $req);

				while (!feof($fp))
				{
					$res = fgets($fp, 1024);
					/* paypal steps (from their docs):
					 * check the payment_status is Completed
					 * check that txn_id has not been previously processed
					 * check that receiver_email is your Primary PayPal email
					 * check that payment_amount/payment_currency are correct
					 * process payment
					 */
					if (JString::strcmp($res, "VERIFIED") == 0)
					{
						// $$tom This block Paypal from updating the IPN field if the payment status evolves (e.g. from Pending to Completed)
						// $$$ hugh - added check of status, so only barf if there is a status field, and it is Completed for this txn_id
						if (!empty($ipnTxnField) && !empty($ipnStatusField))
						{
							$query->clear();
							$query->select($ipnStatusField)->from($table->db_table_name)
							->where($db->qn($ipnTxnField) . ' = ' . $db->q($txn_id));
							$db->setQuery($query);
							$txn_result = $db->loadResult();

							if (!empty($txn_result))
							{
								if ($txn_result == 'Completed')
								{
									if ($payment_status != 'Reversed' && $payment_status != 'Refunded')
									{
										$status = 'form.paypal.ipnfailure.txn_seen';
										$errMsg = "transaction id already seen as Completed, new payment status makes no sense: $txn_id, $payment_status";
									}
								}
								elseif ($txn_result == 'Reversed')
								{
									if ($payment_status != 'Canceled_Reversal')
									{
										$status = 'form.paypal.ipnfailure.txn_seen';
										$errMsg = "transaction id already seen as Reversed, new payment status makes no sense: $txn_id, $payment_status";
									}
								}
							}
						}

						if ($status == 'ok')
						{
							$set_list = array();

							if (!empty($ipnField))
							{
								if (empty($ipnValue))
								{
									$ipnValue = $txn_id;
								}

								$set_list[$ipnField] = $ipnValue;
							}

							if (!empty($ipnTxnField))
							{
								$set_list[$ipnTxnField] = $txn_id;
							}

							if (!empty($ipnPaymentField))
							{
								$set_list[$ipnPaymentField] = $payment_amount;
							}

							if (!empty($ipnStatusField))
							{
								$set_list[$ipnStatusField] = $payment_status;
							}

							if (!empty($ipnAddressField))
							{
								$set_list[$ipnAddressField] = $buyer_address;
							}

							$ipn = $this->getIPNHandler($params, $renderOrder);

							if ($ipn !== false)
							{
								$request = $_REQUEST;
								$ipnFunction = 'payment_status_' . $payment_status;

								if (method_exists($ipn, $ipnFunction))
								{
									$status = $ipn->$ipnFunction($listModel, $request, $set_list, $errMsg);

									if ($status != 'ok')
									{
										break;
									}
								}

								$txnTypeFunction = "txn_type_" . $txn_type;

								if (method_exists($ipn, $txnTypeFunction))
								{
									$status = $ipn->$txnTypeFunction($listModel, $request, $set_list, $errMsg);

									if ($status != 'ok')
									{
										break;
									}
								}
							}

							if (!empty($set_list))
							{
								$setArray = array();

								foreach ($set_list as $setField => $setValue)
								{
									$setValue = $db->q($setValue);
									$setField = $db->qn($setField);
									$setArray[] = "$setField = $setValue";
								}

								$query->clear();
								$query->update($table->db_table_name)
								->set(implode(',', $setArray))
								->where($table->db_primary_key . ' = ' . $db->q($rowId));
								$db->setQuery($query);

								if (!$db->execute())
								{
									$status = 'form.paypal.ipnfailure.query_error';
									$errMsg = 'sql query error: ' . $db->getErrorMsg();
								}
								else
								{
									if ($testMode == 1)
									{
										$log->message_type = 'form.paypal.ipndebug.ipn_query';
										$log->message = "IPN query: " . $query;
										$log->store();
									}
								}
							}
							else
							{
								$status = 'form.paypal.ipnfailure.set_list_empty';
								$errMsg = 'no IPN status fields found on form for rowid: ' . $rowId;
							}
						}
					}
					elseif (JString::strcmp($res, "INVALID") == 0)
					{
						$status = 'form.paypal.ipnfailure.invalid';
						$errMsg = 'paypal postback failed with INVALID';
					}
				}

				fclose($fp);
			}
		}

		$receive_debug_emails = (array) $params->get('paypal_receive_debug_emails');
		$receive_debug_emails = $receive_debug_emails[$renderOrder];
		$send_default_email = (array) $params->get('paypal_send_default_email');
		$send_default_email = $send_default_email[$renderOrder];
		$emailText = '';

		if ($status != 'ok')
		{
			if ($receive_debug_emails == '1')
			{
				foreach ($_POST as $key => $value)
				{
					$emailText .= $key . " = " . $value . "\n\n";
				}

				$subject = $this->config->get('sitename') . ": Error with PayPal IPN from Fabrik";
				$mail->sendMail($emailFrom, $emailFrom, $admin_email, $subject, $emailText, false);
			}

			$log->message_type = $status;
			$log->message = $emailText . "\n//////////////\n" . $res . "\n//////////////\n" . $req . "\n//////////////\n" . $errMsg;

			if ($send_default_email == '1')
			{
				$subject = $this->config->get('sitename') . ": Error with PayPal IPN from Fabrik";
				$payerEmailText = FText::_('PLG_FORM_PAYPAL_ERR_PROCESSING_PAYMENT');
				$mail->sendMail($emailFrom, $emailFrom, $payer_email, $subject, $payerEmailText, false);
			}
		}
		else
		{
			if ($receive_debug_emails == '1')
			{
				foreach ($_POST as $key => $value)
				{
					$emailText .= $key . " = " . $value . "\n\n";
				}

				$subject = $this->config->get('sitename') . ': IPN ' . $payment_status;
				$mail->sendMail($emailFrom, $emailFrom, $admin_email, $subject, $emailText, false);
			}

			$log->message_type = 'form.paypal.ipn.' . $payment_status;
			$query = $db->getQuery();
			$log->message = $emailText . "\n//////////////\n" . $res . "\n//////////////\n" . $req . "\n//////////////\n" . $query;

			if ($send_default_email == '1')
			{
				$payer_subject = "PayPal success";
				$payerEmailText = "Your PayPal payment was succesfully processed.  The PayPal transaction id was $txn_id";
				$mail->sendMail($emailFrom, $emailFrom, $payer_email, $payer_subject, $payerEmailText, false);
			}
		}

		$log->message .= "\n IPN custom function = $ipnFunction";
		$log->message .= "\n IPN custom transaction function = $txnTypeFunction";
		$log->store();
		jexit();
	}

	/**
	 * Get the custom IPN class
	 *
	 * @param   object  $params       plugin params
	 * @param   int     $renderOrder  plugin render order
	 *
	 * @return  mixed	false or class instance
	 */
	protected function getIPNHandler($params, $renderOrder = 0)
	{
		$php_file = (array) $params->get('paypal_run_php_file');
		$f = JFilterInput::getInstance();
		$php_file = $f->clean($php_file[$renderOrder], 'CMD');
		$php_file = empty($php_file) ? '' : 'plugins/fabrik_form/paypal/scripts/' . $php_file;

		if (!empty($php_file) && file_exists($php_file))
		{
			$request = $_REQUEST;
			require_once $php_file;
			$ipn = new fabrikPayPalIPN;

			return $ipn;
		}
		else
		{
			return false;
		}
	}
}
