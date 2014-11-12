<?php
/**
 * ||GEISSWEB| EU-VAT-GROUPER
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the GEISSWEB End User License Agreement
 * that is available through the world-wide-web at this URL:
 * http://www.geissweb.de/eula.html
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@geissweb.de so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade the extension
 * to newer versions in the future. If you wish to customize the extension
 * for your needs please refer to http://www.geissweb.de/ for more information
 * or send an email to support@geissweb.de or visit our customer forum at
 * http://forum.geissweb.de to make a feature request.
 *
 * @category                           Mage
 * @package                            Geissweb_Euvatgrouper
 * @copyright                          Copyright (c) 2013 GEISS Weblösungen (http://www.geissweb.de)
 * @license                            http://www.geissweb.de/eula.html GEISSWEB End User License Agreement
 */
class Geissweb_Euvatgrouper_Model_Observer extends Mage_Checkout_Model_Observer
{
    var $_debug = false;

    /*
    * Constructor sets debug mode
    */
    public function __construct()
    {
        $this->_debug = Mage::helper('euvatgrouper')->isDebugMode();
    }

	/*
	 * Observer for making VAT validation data available in session
	 * @var Varien_Event_Observer
	 */
	public function customer_login(Varien_Event_Observer $observer)
	{
		if($this->_debug) Mage::log("----------[EUVAT] EVENT RUNNING: customer_login----------");

		$session = Mage::getSingleton('customer/session');
		$customer = $session->getCustomer();
		$billing_address = $customer->getPrimaryBillingAddress();
		$shipping_address = $customer->getPrimaryShippingAddress();

		try {

			$this->_setVatSessionData($billing_address, 'billing');
			$this->_setVatSessionData($shipping_address, 'shipping');

		} catch (Exception $e) {
			Mage::logException($e);
		}
		if($this->_debug) Mage::log("----------[EUVAT] EVENT END: customer_login----------");
	}

	/*
	 * observer for the customer save event
	 * @var Varien_Event_Observer
	 */
	public function customer_save_before(Varien_Event_Observer $observer)
	{
		if($this->_debug) Mage::log("----------[EUVAT] EVENT RUNNING: customer_save_before----------");
		/* @var $customer Mage_Customer_Model_Customer */
		$customer = $observer->getCustomer();
		//if($this->_debug) Mage::log("[EUVAT] Customer Information: ".var_export($customer->debug(),true));
		$billing_cc = $this->_getBillingCountry($customer);

		// Just return when admin tries manually to change the customer to another group, group is in excluded list or unable to get customer_cc
		if (Mage::getSingleton('admin/session')->getUser()
			|| in_array($customer->getGroupId(), $this->_getExcludedGroups())
			|| $billing_cc === false
			|| $customer->getDisableAutoGroupChange() == 1
			|| !Mage::helper('euvatgrouper')->isValidationEnabled()) {
			return;
		}


		if(Mage::getSingleton('customer/session')->hasData('_vatgrouper'))
		{
			$vatdata = Mage::getSingleton('customer/session')->getData('_vatgrouper');
		} else {
			$vatdata = false;
		}

		if(array_key_exists('billing_validation', $vatdata))
		{
			$billing_vatdata = $vatdata['billing_validation'];
		} else {
			$billing_vatdata = false;
		}

		if ($this->_debug) Mage::log("[EUVAT] Customer Group Assignment: VAT-DATA (using billing): ".var_export($vatdata, true));


		try {
			// Set customer group
			$group = $this->_getBestCustomerGroup($billing_vatdata, $billing_cc);
			if ($this->_debug) Mage::log("[EUVAT] Customer Group Assignment: Assigning Group $group ($billing_cc)");
			$customer->setGroupId($group);

			if($billing_vatdata != false)
			{
				if ($this->_debug) Mage::log("[EUVAT] Customer has billing vatdata, setting account based VAT field to: ".$billing_vatdata['vat_id']);
				$customer->setData('taxvat', $billing_vatdata['vat_id']);
			}

			// Update address
			foreach($customer->getAddressesCollection() as $address)
			{
				if($address->getVatId() == $billing_vatdata['vat_id'] && $billing_vatdata['vat_request_success'] == 1 )
				{
					$address->setVatId($billing_vatdata['vat_id'])
						->setVatIsValid($billing_vatdata['validation_result'])
						->setVatRequestId($billing_vatdata['request_identifier'])
						->setVatRequestDate($billing_vatdata['request_date'])
						->setVatRequestSuccess($billing_vatdata['vat_request_success'])
						->setVatTraderName($billing_vatdata['trader_name'])
						->setVatTraderAddress($billing_vatdata['trader_address'])
						->setVatTraderCompanyType($billing_vatdata['trader_company_type']);
				}
			}

		} catch (Exception $e) {
			Mage::logException($e);
		}

		if($this->_debug) Mage::log("----------[EUVAT] EVENT END: customer_save_before----------");
	}

	/*
	 * FixFunction to avoid doubling the quote totals after
	 * admin assigns a customer to a new group
	 * @var Varien_Event_Observer
	 */
	public function adminhtml_customer_save_after(Varien_Event_Observer $observer)
	{
		$customer = $observer->getEvent()->getCustomer();
		$customer_quote = Mage::getModel('sales/quote')->getCollection()
			->addFieldToFilter('customer_id', array('eq'=>$customer->getId()))
			->addOrder('updated_at', 'desc');

		if($customer_quote->getSize() > 0)
		{
			foreach($customer_quote as $quote)
			{
				$quote->removeAllAddresses()->setTotalsCollectedFlag(false)->collectTotals()->save();
			}
		}
	}

	/*
	 * Set validation data for tax calculation
	 */
	private function _setVatSessionData($address, $address_type)
	{
		if($this->_debug) Mage::log("----------[EUVAT] Function RUNNING: _setVatSessionData(".$address_type.")----------");

		if($address instanceof Mage_Customer_Model_Address)
		{
			$shop_cc = Mage::helper('euvatgrouper')->getStoreCountryCode();

			$new_session_data = array(
				'validation_result' 	=> $address->getVatIsValid(),
				'vat_request_success'	=> $address->getVatRequestSuccess(),
				'vat_id' 				=> $address->getVatId(),
				'is_vat_free'			=> (substr($address->getVatId(), 0, 2) != $shop_cc) ? 1 : 0,
				'country_code'		 	=> substr($address->getVatId(), 0, 2),
				'address_type' 			=> $address_type,
				'trader_name'			=> $address->getVatTraderName(),
				'trader_address'		=> $address->getVatTraderAddress(),
				'trader_company_type'	=> $address->getVatTraderCompanyType(),
				'request_identifier'	=> $address->getVatRequestId(),
				'request_date'			=> $address->getVatRequestDate(),
				'address_id'			=> $address->getId()
			);


			// Set session data for tax calculation
			if( Mage::getSingleton('customer/session')->hasData('_vatgrouper') )
			{
				// Update shipping address
				if( array_key_exists('billing_validation', Mage::getSingleton('customer/session')->getData('_vatgrouper')) && $address_type == 'shipping' )
				{
					$existing_validation = Mage::getSingleton('customer/session')->getData('_vatgrouper');

					Mage::getSingleton('customer/session')->setData('_vatgrouper', array(
						'billing_validation' => $existing_validation['billing_validation'],
						'shipping_validation' => $new_session_data
					));
					if($this->_debug) Mage::log('billing exists. data: '.var_export(Mage::getSingleton('customer/session')->getData('_vatgrouper'),true));

				// Update billing address
				} elseif( array_key_exists('shipping_validation', Mage::getSingleton('customer/session')->getData('_vatgrouper')) && $address_type == 'billing') {

					$existing_validation = Mage::getSingleton('customer/session')->getData('_vatgrouper');

					Mage::getSingleton('customer/session')->setData('_vatgrouper', array(
						'billing_validation' => $new_session_data,
						'shipping_validation' => $existing_validation['shipping_validation']
					));

					if($this->_debug) Mage::log('shipping exists. '.var_export(Mage::getSingleton('customer/session')->getData('_vatgrouper'),true));

				// Update single validation address
				} else {
					Mage::getSingleton('customer/session')->setData('_vatgrouper', array(
						$address_type.'_validation' => $new_session_data
					));
				}

			// Set validation data to address
			} elseif( !Mage::getSingleton('customer/session')->hasData('_vatgrouper') ) {

				Mage::getSingleton('customer/session')->setData('_vatgrouper', array(
					$address_type.'_validation' => $new_session_data
				));

			}
		}
	}

    /*
    * Observer for Taxvat-field onchange
    * @var Varien_Event_Observer
    */
    public function vat_check_after(Varien_Event_Observer $observer)
    {
		if($this->_debug) Mage::log("--------------[EUVAT] EVENT RUNNING: vat_check_after (address_id: ".$observer->getEvent()->getAddressId().")------------");

        try {
			$validation_result = $observer->getEvent()->getValidationResult();
			$address_id = $observer->getEvent()->getAddressId();
			$customer = $observer->getEvent()->getCustomer();

			if(is_object($validation_result))
			{
				// Save validation information to address
				if($address_id != 0 && !empty($address_id)) {
					$address = Mage::getModel('customer/address')->load($address_id);
				} elseif( (empty($address_id) || $address_id == 0)) {
					$address = Mage::getModel('customer/address');
				}

				$address->setVatId($validation_result->countryCode.$validation_result->vatNumber)
					->setVatIsValid((int)$validation_result->valid_vat)
					->setVatRequestId($validation_result->requestIdentifier)
					->setVatRequestDate($validation_result->requestDate)
					->setVatRequestSuccess((int) !(isset($validation_result->faultstring) && strlen($validation_result->faultstring) > 0))
					->setVatTraderName($validation_result->traderName)
					->setVatTraderAddress($validation_result->traderAddress)
					->setVatTraderCompanyType($validation_result->traderCompanyType);

				// Set validation data to session for tax calculation
				$this->_setVatSessionData($address, $validation_result->address_type);

				// Update validation data on existing address
				if($address_id != 0 && !empty($address_id))
				{
					$address->save();

					// Update account based VAT-ID field
					if($validation_result->address_type = 'billing' && Mage::getSingleton('customer/session')->isLoggedIn())
					{
						Mage::getSingleton('customer/session')->getCustomer()->setTaxvat($address->getVatId())->save();
					}
				}

				if($customer != false && $customer instanceof Mage_Customer_Model_Customer)
				{
					if($validation_result->getVatRemoved() == true)
					{
						$customer->setTaxvat("");
						$customer->save();
					}
				}

			}

			if($this->_debug) Mage::log("---------------[EUVAT] EVENT END: vat_check_after-------------------");
        } catch (Exception $e) {
			Mage::logException($e);
        }
    }

    /*
     * Observer for tax calculation
     * @var Varien_Event_Observer
     */
    public function tax_rate_data_fetch(Varien_Event_Observer $observer)
    {
		if($this->_debug) Mage::log("------------------[EUVAT] EVENT RUNNING: tax_rate_data_fetch--------------------");

        if (!Mage::helper('euvatgrouper')->isValidationEnabled()) {
            return;
        }

		// tax rate request
		$request = $observer->getEvent()->getRequest();

        try {
            $shop_cc = Mage::helper('euvatgrouper')->getShopVatCc();
            $session = Mage::getSingleton('customer/session');
			$customer = $session->getCustomer();
            $vatdata = $session->getData('_vatgrouper');

			if($this->_debug) Mage::log("[EUVAT] Tax calculation: VAT-Data: ".var_export($vatdata,true));

			if(!isset($vatdata) || is_null($vatdata)) {
				return;
			}

			// Get Country and Validation Data
			$billing_cc = $this->_getBillingCountry($customer);
			$shipping_cc = $this->_getShippingCountry($customer);

			// When no shipping address is used (virtual/downloadable order only)
			if(!$shipping_cc || $shipping_cc == "" || $shipping_cc == false)
			{
				$shipping_cc = $billing_cc;
			}

			if( array_key_exists('billing_validation', $vatdata)) {
				$billing_vatdata = $vatdata['billing_validation'];
				$customer_vat_cc = $billing_vatdata['country_code'];
			} else {
				$billing_vatdata = NULL;
				$customer_vat_cc = $billing_cc;
			}
			if( array_key_exists('shipping_validation', $vatdata)) {
				$shipping_vatdata = $vatdata['shipping_validation'];
				$customer_vat_cc = $shipping_vatdata['country_code'];
			} else {
				$shipping_vatdata = NULL;
				$customer_vat_cc = $billing_cc;
			}

            // Use customers account VAT-ID or the VAT-ID from the billing validation
            if( is_array($billing_vatdata) && $billing_vatdata['vat_id'] != "") {
				$customer_vat_id = $billing_vatdata['vat_id'];
				$customer_vat_cc = substr($billing_vatdata['vat_id'], 0, 2);
            }


			// Use Billing Country from VAT-ID if not set
            if (!$billing_cc || $billing_cc == "")
			{
                if ($customer_vat_id != "") {
                    $billing_cc = substr($customer_vat_id, 0, 2);
                } else {
					$billing_cc = $shop_cc;
				}
            }

			// Fix for Greece Country Prefix
			if( $customer_vat_cc == "EL" ) $customer_vat_cc = "GR";

			// Prevent using a (billing) VAT-ID not matching the billing country
			$precheck = false;
			if($shipping_cc == $billing_cc && ($customer_vat_cc != null && $customer_vat_cc != ""))	{
				if( $customer_vat_cc != $billing_cc ) {
					$precheck = true;
					if($this->_debug) Mage::log("[EUVAT] Wrong VAT-ID prevention: shipping_cc[$shipping_cc] billing_cc[$billing_cc] billing_vat_cc[".$billing_vatdata['country_code']."]");
				}
			}

			if($billing_cc == $shipping_cc)
			{
				if ($this->_debug) Mage::log("[EUVAT] Shipping and billing countries are same: billing_cc: $billing_cc ** shipping_cc: $shipping_cc");

				//Customer has valid VAT-ID and comes not from the same country as the shop
				if( ($billing_vatdata['validation_result'] == 1) && ($shop_cc != $billing_cc) )
				{
					if ($this->_debug) Mage::log("[EUVAT] validation_result: ".$billing_vatdata['validation_result']." *** shop_cc: $shop_cc *** billing_cc: $billing_cc");

					// Handle only the Magento internal request for the shipping address country
					if ($request->getCountryId() == $billing_cc) {
						if ($this->_debug) Mage::log("[EUVAT] REQ-CC[" . $request->getCountryId() . "] ** billing_cc[$billing_cc] ** shipping_cc[$shipping_cc] *** GRP[" . $this->_getValidEuVatGroupId() . "] TXCLS[" . $this->_getTaxClassIdForGroup($this->_getValidEuVatGroupId()) . "]");
						$request->setCustomerClassId($this->_getTaxClassIdForGroup($this->_getValidEuVatGroupId()));
					}
					//If delivery to shops country or country mismatch between VAT-ID and billing country
					if ( ($shipping_cc == $shop_cc) || $precheck ) {
						if ($this->_debug) Mage::log("[EUVAT] Delivery to shop country or wrong VAT-ID in comparsion to billing_cc[".$billing_cc."] ** vat_cc[".$billing_vatdata['country_code']."] ** shipping_cc[".$shipping_cc."] ** shop_cc[".$shop_cc."]");
						$request->setCustomerClassId($this->_getTaxClassIdForGroup($this->_getDefaultGroupId()));
					}

				//Customer has valid VAT-ID and comes from the same country as the shop
				} elseif (($billing_vatdata['validation_result'] == 1) && ($shop_cc == $billing_cc)) {

					if ($this->_debug) Mage::log("[EUVAT] Same billing and shop country -> validation_result: ".$billing_vatdata['validation_result']." | $shop_cc , $billing_cc");
					if ($this->_debug) Mage::log("[EUVAT] B2B-OwnCountry -> GRP[" . $this->_getOutsideEuGroupId() . "] TXCLS[" . $this->_getTaxClassIdForGroup($this->_getOutsideEuGroupId()) . "]");
					$request->setCustomerClassId($this->_getTaxClassIdForGroup($this->_getSameCountryGroupId()));

				//Customer from outside EU
				} elseif (!Mage::helper('euvatgrouper')->isEuCountry($billing_cc)) {
					if ($this->_debug) Mage::log("[EUVAT] NON EU REQUEST -> $shop_cc , $billing_cc GRP[" . $this->_getOutsideEuGroupId() . "] TXCLS[" . $this->_getTaxClassIdForGroup($this->_getOutsideEuGroupId()) . "]");

					if(Mage::helper('euvatgrouper')->isEuCountry($shipping_cc))
					{
						if ($request->getCountryId() == $billing_cc) {
							if ($this->_debug) Mage::log("[EUVAT] NON EU REQUEST WITH SHIPPING-CC IN EU! BillingCC[$billing_cc] ShippingCC[$shipping_cc] GRP[" . $this->_getOutsideEuGroupId() . "] TXCLS[" . $this->_getTaxClassIdForGroup($this->_getDefaultGroupId()) . "]");
							$request->setCustomerClassId($this->_getTaxClassIdForGroup($this->_getDefaultGroupId()));
						}

					} else {
						if ($request->getCountryId() == $billing_cc) {
							$request->setCustomerClassId($this->_getTaxClassIdForGroup($this->_getOutsideEuGroupId()));
						}
					}

				//Customer is Enduser
				} else {
					if ($this->_debug) Mage::log("[EUVAT] Default -> $shop_cc, $billing_cc GRP[" . $this->_getDefaultGroupId() . "] TXCLS[" . $this->_getTaxClassIdForGroup($this->_getDefaultGroupId()) . "]");
					$request->setCustomerClassId($this->_getTaxClassIdForGroup($this->_getDefaultGroupId()));
				}
				//endif


			} else {// If billing_cc != shipping_cc

				if ($this->_debug) Mage::log("[EUVAT] Shipping and billing countries are NOT same: billing_cc: $billing_cc ** shipping_cc: $shipping_cc");
				if(!is_null($shipping_vatdata))
				{
					// Prevent using a (billing) VAT-ID not matching the billing country
					$shipping_precheck = false;
					if( $shipping_vatdata['country_code'] != $shipping_cc && ($billing_vatdata['country_code'] != "EL")) {
						$shipping_precheck = true;
						if($this->_debug) Mage::log("[EUVAT] Wrong shipping VAT-ID prevention: shipping_cc[$shipping_cc] billing_cc[$billing_cc] billing_vat_cc[".$billing_vatdata['country_code']."]");
					}

					if( ($shipping_vatdata['validation_result'] == 1) && ($shop_cc != $shipping_cc) && Mage::helper('euvatgrouper')->isEuCountry($shipping_cc) )
					{
						// Handle only the Magento internal request for the address country
						if ($request->getCountryId() == $shipping_cc && !$shipping_precheck) {
							if ($this->_debug) Mage::log("[EUVAT] REQ-CC[" . $request->getCountryId() . "] ** billing_cc[$billing_cc] ** shipping_cc[$shipping_cc] *** GRP[" . $this->_getValidEuVatGroupId() . "] TXCLS[" . $this->_getTaxClassIdForGroup($this->_getValidEuVatGroupId()) . "]");
							$request->setCustomerClassId($this->_getTaxClassIdForGroup($this->_getValidEuVatGroupId()));
						} elseif($shipping_precheck) {
							$request->setCustomerClassId($this->_getTaxClassIdForGroup($this->_getDefaultGroupId()));
						}

					} elseif($shop_cc == $shipping_cc) {
						if ($this->_debug) Mage::log("[EUVAT] Shipping to shop country, apply VAT");
						$request->setCustomerClassId($this->_getTaxClassIdForGroup($this->_getDefaultGroupId()));
					} elseif( !Mage::helper('euvatgrouper')->isEuCountry($shipping_cc) ) {
						if ($this->_debug) Mage::log("[EUVAT] Shipping to NON-EU country, remove VAT");
						$request->setCustomerClassId($this->_getTaxClassIdForGroup($this->_getOutsideEuGroupId()));
					}


				} else {
					if ($this->_debug) Mage::log("[EUVAT] Shipping Validation is NULL");
					if($shop_cc == $shipping_cc) {
						if ($this->_debug) Mage::log("[EUVAT] Shipping to shop country, apply VAT");
						$request->setCustomerClassId($this->_getTaxClassIdForGroup($this->_getDefaultGroupId()));
					}
				}

			}

            //if ($this->_debug) Mage::log("[EUVAT] REQ DEBUG: product[$product_class] reqcountry[" . $request->getCountryId() . "] user_cc[" . $billing_cc . "] \n" . var_export($request->debug(), true));
        } catch (Exception $e) {
            Mage::logException($e);
        }

		if($this->_debug) Mage::log("------------------[EUVAT] EVENT END: tax_rate_data_fetch--------------------");
    }

	private function quickValidate($address)
	{
		try {
			$validator = Mage::getSingleton('euvatgrouper/vatvalidation');
			$validator->setUserCc(strtoupper(substr($address->getVatId(), 0, 2)));
			$validator->setUserNr(substr($address->getVatId(), 2));
			$validator->setOpMode('MULTI');
			$validator->setAddressType($address->getAddressType());
			$validator->validate();

		} catch (SoapFault $sf) {
			Mage::logException($sf);
		}
	}



	public function sales_quote_address_collect_totals_before(Varien_Event_Observer $observer)
	{
		if($this->_debug) Mage::log("------------------[EUVAT] EVENT RUNNING: sales_quote_address_collect_totals_before--------------------");
		if( Mage::app()->getRequest()->getActionName() == 'saveShipping' )
		{
			$address = $observer->getEvent()->getQuoteAddress();

			if(Mage::getSingleton('customer/session')->hasData('_vatgrouper'))
			{
				$vatdata = Mage::getSingleton('customer/session')->getData('_vatgrouper');

				if( array_key_exists('shipping_validation', $vatdata) )
				{
					$shipping_vatdata = $vatdata['shipping_validation'];
					if( ($address->getAddressType() == 'shipping') && ($address->getVatId() != "") && ($address->getVatId() != $shipping_vatdata['vat_id']) ) {
						$this->quickValidate($address);
					}
				}

			} else {
				if($address->getAddressType() == 'shipping' && $address->getVatId() != "") {
					$this->quickValidate($address);
				}
			}

		}
		if($this->_debug) Mage::log("------------------[EUVAT] EVENT END: sales_quote_address_collect_totals_before--------------------");
	}



    /*
     * Observer for sending validation result to shop owner
     * @var Varien_Event_Observer
     */
    public function send_success_mail(Varien_Event_Observer $observer)
    {
		if($this->_debug) Mage::log("------------------[EUVAT] EVENT RUNNING: send_success_mail--------------------");
        $customer = $observer->getEvent()->getCustomer();
        $results = (array)$observer->getEvent()->getValidationResult();
        foreach ($results as $id => $res) {
            $customer->setData("vies_" . $id, $res);
        }

        $sender = array('name' => Mage::getStoreConfig('trans_email/ident_' . Mage::getStoreConfig('euvatgrouper/vat_settings/mail_sender') . '/name'),
            'email' => Mage::getStoreConfig('trans_email/ident_' . Mage::getStoreConfig('euvatgrouper/vat_settings/mail_sender') . '/email'));

        $vars = array('customer' => $customer);

        $translate = Mage::getSingleton('core/translate');
        Mage::getModel('core/email_template')->sendTransactional(
            Mage::helper('euvatgrouper')->getMailTemplate(), $sender, Mage::helper('euvatgrouper')->getMailRecipient(), Mage::helper('euvatgrouper')->getMailRecipient(), //Recipient Name
            $vars, Mage::app()->getStore()->getId()
        );
        $translate->setTranslateInline(true);
		if($this->_debug) Mage::log("------------------[EUVAT] EVENT END: send_success_mail--------------------");
        return $this;
    }


    /*
     * Observer to add the own shop vat-id to the order object
     * and to set VAT validation data on NOT LOGGED IN customer addresses
     * @var Varien_Event_Observer
     */
    public function sales_convert_quote_to_order(Varien_Event_Observer $observer)
    {
		if($this->_debug) Mage::log("------------------[EUVAT] EVENT RUNNING: sales_convert_quote_to_order--------------------");
        if (Mage::getSingleton('admin/session')->getUser()) return;

        $observer->getEvent()->getOrder()->setShopTaxvat(Mage::getStoreConfig('euvatgrouper/vat_settings/own_vatid'));
        $observer->getEvent()->getOrder()->setCustomerTaxvat($observer->getEvent()->getQuote()->getCustomerTaxvat());

        $session = Mage::getSingleton('customer/session');
        if (!$session->isLoggedIn())
		{
            if (!$session->hasData('_vatgrouper')) {
				if($this->_debug) Mage::log("[EUVAT] sales_convert_quote_to_order - no _vatgrouper data exists.");
                $vatdata = NULL;

				$customer_cc = $this->_getBillingCountry($observer->getEvent()->getCustomer());
				$group_id = $this->_getBestCustomerGroup($vatdata, $customer_cc);
				$observer->getEvent()->getOrder()->setCustomerGroupId($group_id);
				$observer->getEvent()->getOrder()->setCustomerTaxClassId($this->_getTaxClassIdForGroup($group_id));
				if ($this->_debug) Mage::log("[EUVAT] sales_convert_quote_to_order, order is now: " . var_export($observer->getEvent()->getOrder()->debug(), true));

            } else {
				if($this->_debug) Mage::log("[EUVAT] sales_convert_quote_to_order - _vatgrouper data exists!");
                $vatdata = $session->getData('_vatgrouper');

				if(isset($vatdata['billing_validation']))
				{
					$billing_vatdata = $vatdata['billing_validation'];
					$customer_cc = $this->_getBillingCountry($observer->getEvent()->getCustomer());
					$group_id = $this->_getBestCustomerGroup($billing_vatdata, $customer_cc);
					$observer->getEvent()->getOrder()->setCustomerGroupId($group_id);
					$observer->getEvent()->getOrder()->setCustomerTaxClassId($this->_getTaxClassIdForGroup($group_id));
					if ($this->_debug) Mage::log("[EUVAT] sales_convert_quote_to_order, order is now: " . var_export($observer->getEvent()->getOrder()->debug(), true));
				}

				/* @var $quote Mage_Sales_Model_Quote */
				$quote = $observer->getEvent()->getQuote();
				$billing_address = $quote->getBillingAddress();
				$shipping_address = $quote->getShippingAddress();

				if( array_key_exists($billing_address->getAddressType().'_validation', $vatdata) )
				{
					$address_vatdata = $vatdata[$billing_address->getAddressType().'_validation'];
					if($billing_address->getCustomerAddress() instanceof Mage_Customer_Model_Address )
					{
						$customer_address = $billing_address->getCustomerAddress();
						$customer_address->setVatId($address_vatdata['vat_id'])
							->setVatIsValid((int)$address_vatdata['validation_result'])
							->setVatRequestId($address_vatdata['request_identifier'])
							->setVatRequestDate($address_vatdata['request_date'])
							->setVatRequestSuccess((int) !(isset($address_vatdata['faultstring']) && strlen($address_vatdata['faultstring']) > 0))
							->setVatTraderName($address_vatdata['trader_name'])
							->setVatTraderAddress($address_vatdata['trader_address'])
							->setVatTraderCompanyType($address_vatdata['trader_company_type']);
						$billing_address->setCustomerAddress($customer_address);
					}
				}

				if( array_key_exists($shipping_address->getAddressType().'_validation', $vatdata) )
				{
					$address_vatdata = $vatdata[$shipping_address->getAddressType().'_validation'];
					if($shipping_address->getCustomerAddress() instanceof Mage_Customer_Model_Address )
					{
						$customer_address = $shipping_address->getCustomerAddress();
						$customer_address->setVatId($address_vatdata['vat_id'])
							->setVatIsValid((int)$address_vatdata['validation_result'])
							->setVatRequestId($address_vatdata['request_identifier'])
							->setVatRequestDate($address_vatdata['request_date'])
							->setVatRequestSuccess((int) !(isset($address_vatdata['faultstring']) && strlen($address_vatdata['faultstring']) > 0))
							->setVatTraderName($address_vatdata['trader_name'])
							->setVatTraderAddress($address_vatdata['trader_address'])
							->setVatTraderCompanyType($address_vatdata['trader_company_type']);
						$shipping_address->setCustomerAddress($customer_address);
					}
				}
				if($this->_debug) Mage::log("[EUVAT] sales_convert_quote_to_order billing address data: ".var_export($billing_address->debug(),true));
				if($this->_debug) Mage::log("[EUVAT] sales_convert_quote_to_order shipping address data: ".var_export($shipping_address->debug(),true));
            }
        } else {
			if($this->_debug) Mage::log("[EUVAT] sales_convert_quote_to_order Customer is already logged in. Nothing to do.");
		}

		if($this->_debug) Mage::log("------------------[EUVAT] EVENT END: sales_convert_quote_to_order--------------------");
	}



	/**
	 * Removes the customers VAT Validation data after guest checkout
	 * @param Varien_Event_Observer $observer
	 */
	public function sales_order_place_after(Varien_Event_Observer $observer)
	{
		if($this->_debug) Mage::log("------------------[EUVAT] EVENT RUNNING: sales_order_place_after--------------------");

		try {
			/* @var $order Mage_Sales_Model_Order */
			$order = $observer->getEvent()->getOrder();
			/* @var $quote Mage_Sales_Model_Quote */
			$quote = Mage::getModel('sales/quote')->getCollection()->addFieldToFilter("entity_id", $order->getQuoteId())->getFirstItem();
			$addresses = $order->getAddressesCollection();
			$vatdata = Mage::getSingleton('customer/session')->getData('_vatgrouper');

			if ($this->_debug) Mage::log("[EUVAT] sales_order_place_after, assigning VatData to Address: ".var_export($vatdata, true));
			foreach ($addresses as $address)
			{
				/* @var $address Mage_Customer_Model_Address */
				if(isset($vatdata[$address->getAddressType().'_validation']) && !is_null($vatdata[$address->getAddressType().'_validation']['vat_request_success']))
				{
					if ($this->_debug) Mage::log("[EUVAT] sales_order_place_after, assigning to: ".$address->getAddressType()." (addr_id: ".$address->getId().")");
					$address_vatdata = $vatdata[$address->getAddressType().'_validation'];
					$address->setVatId($address_vatdata['vat_id'])
						->setVatIsValid((int)$address_vatdata['validation_result'])
						->setVatRequestId($address_vatdata['request_identifier'])
						->setVatRequestDate($address_vatdata['request_date'])
						->setVatRequestSuccess((int) !(isset($address_vatdata['faultstring']) && strlen($address_vatdata['faultstring']) > 0))
						->setVatTraderName($address_vatdata['trader_name'])
						->setVatTraderAddress($address_vatdata['trader_address'])
						->setVatTraderCompanyType($address_vatdata['trader_company_type'])
						->save();
				}
			}

			if($quote->getCheckoutMethod(true) == "guest" && Mage::getSingleton('customer/session')->hasData('_vatgrouper'))
			{
				Mage::getSingleton('customer/session')->unsetData('_vatgrouper');
			}

		} catch(Exception $e) {
			Mage::logException($e);
		}

		if($this->_debug) Mage::log("------------------[EUVAT] EVENT END: sales_order_place_after--------------------");
	}


    /*
     * Observer to add the own shop vat-id and customers vat-id to the invoice object
     * @param Varien_Event_Observer $observer
     */
    public function geissweb_sales_convert_order_to_invoice(Varien_Event_Observer $observer)
    {
        $observer->getEvent()->getTarget()->setShopTaxvat(Mage::getStoreConfig('euvatgrouper/vat_settings/own_vatid'));
        $observer->getEvent()->getTarget()->setCustomerTaxvat($observer->getEvent()->getSource()->getCustomerTaxvat());
    }



	/*
	 * Try to return the best valid country code of customer
	 * @var Mage_Customer_Mocdel_Customer
	 */
	private function _getBillingCountry($customer)
	{
		$customer_ccs = array();

		if(Mage::getSingleton('checkout/session')->hasQuote())
		{
			$customer_ccs[] = Mage::getSingleton('checkout/session')->getQuote()->getBillingAddress()->getCountryId();
		}

		$customer_ccs[] = Mage::getSingleton('sales/quote')->getBillingAddress()->getCountryId();

		if (Mage::getSingleton('customer/session')->isLoggedIn() && ($customer->getDefaultBillingAddress() instanceof Mage_Customer_Model_Address)) {
			$customer_ccs[] = $customer->getDefaultBillingAddress()->getCountryId();
		}

		// For customer group assignment at register form
		if(Mage::getSingleton('customer/session')->hasData('_vatgrouper') && array_key_exists('billing_validation', Mage::getSingleton('customer/session')->getData('_vatgrouper')))
		{
			$vatdata = Mage::getSingleton('customer/session')->getData('_vatgrouper');
			$billing_vatdata = $vatdata['billing_validation'];
			$customer_ccs[] = $billing_vatdata['country_code'];
		}

		if ($this->_debug) { $_debug_msg = '|'; foreach ($customer_ccs as $cc) { $_debug_msg .= $cc.'|'; } Mage::log("[EUVAT] Billing-CCs: ".$_debug_msg);	}

		foreach ($customer_ccs as $cc) {
			if ($cc != NULL)
				return $cc;
		}

		return false;
	}
	/*
     * Returns the Quote Shipping Country or Customers default Shipping Country
     */
	private function _getShippingCountry($customer)
	{
		/* @var $customer Mage_Customer_Model_Customer */
		$customer_ccs = array();

		if(Mage::getSingleton('checkout/session')->hasQuote())
		{
			$customer_ccs[] = Mage::getSingleton('checkout/session')->getQuote()->getShippingAddress()->getCountryId();
		}

		$customer_ccs[] = Mage::getSingleton('sales/quote')->getShippingAddress()->getCountryId();

		if (Mage::getSingleton('customer/session')->isLoggedIn() && ($customer->getDefaultShippingAddress() instanceof Mage_Customer_Model_Address)) {
			$customer_ccs[] = $customer->getDefaultShippingAddress()->getCountryId();
		}

		if ($this->_debug) { $_debug_msg = '|'; foreach ($customer_ccs as $cc) { $_debug_msg .= $cc.'|'; } Mage::log("[EUVAT] Shipping-CCs: ".$_debug_msg);	}

		foreach ($customer_ccs as $cc) {
			if ($cc != NULL)
				return $cc;
		}
		return false;
	}

	/*
	 * Try to return the best fitting group for actual customer data
	 * @array vatdata (billing validation)
	 * @string customer_cc
	 */
	private function _getBestCustomerGroup($vatdata, $customer_cc)
	{
		if($this->_debug) Mage::log("----------------[EUVAT] Private Function RUNNING: _getBestCustomerGroup----------------");

		$shop_cc = Mage::helper('euvatgrouper')->getShopVatCc();
		if ($this->_debug) Mage::log("[EUVAT] GETTING GROUP FOR: $customer_cc");

		if (is_array($vatdata)) {

			if($shop_cc == $customer_cc && $vatdata['validation_result'] == 1)
			{
				if ($this->_debug) Mage::log("[EUVAT] Valid own Country -> GRP[" . $this->_getSameCountryGroupId() . "] TXCLS[" . $this->_getTaxClassIdForGroup($this->_getSameCountryGroupId()) . "]");
				return $this->_getSameCountryGroupId();
			}

			if ($vatdata['validation_result'] == 1 && $vatdata['is_vat_free'] == 1) {
				if ($this->_debug) Mage::log("[EUVAT] Valid VAT-exempt -> GRP[" . $this->_getValidEuVatGroupId() . "] TXCLS[" . $this->_getTaxClassIdForGroup($this->_getValidEuVatGroupId()) . "]");
				return $this->_getValidEuVatGroupId();

			} elseif ($vatdata['validation_result'] == 1 && $vatdata['is_vat_free'] == 0) {
				if ($this->_debug) Mage::log("[EUVAT] Valid own Country -> GRP[" . $this->_getSameCountryGroupId() . "] TXCLS[" . $this->_getTaxClassIdForGroup($this->_getSameCountryGroupId()) . "]");
				return $this->_getSameCountryGroupId();

			} else {
				if (!Mage::helper('euvatgrouper')->isEuCountry($customer_cc)) {
					if ($this->_debug) Mage::log("[EUVAT] OUTSIDE EU -> GRP[" . $this->_getOutsideEuGroupId() . "] TXCLS[" . $this->_getTaxClassIdForGroup($this->_getOutsideEuGroupId()) . "]");
					return $this->_getOutsideEuGroupId();

				} else {
					if ($this->_debug) Mage::log("[EUVAT] DEFAULT -> GRP[" . $this->_getDefaultGroupId() . "] TXCLS[" . $this->_getTaxClassIdForGroup($this->_getDefaultGroupId()) . "]");
					return $this->_getDefaultGroupId();
				}
			}

		} else {

			if (!Mage::helper('euvatgrouper')->isEuCountry($customer_cc)) {
				if ($this->_debug) Mage::log("[EUVAT] OUTSIDE EU2 -> GRP[" . $this->_getOutsideEuGroupId() . "] TXCLS[" . $this->_getTaxClassIdForGroup($this->_getOutsideEuGroupId()) . "]");
				return $this->_getOutsideEuGroupId();
			} else {
				if ($this->_debug) Mage::log("[EUVAT] DEFAULT2 -> GRP[" . $this->_getDefaultGroupId() . "] TXCLS[" . $this->_getTaxClassIdForGroup($this->_getDefaultGroupId()) . "]");
				return $this->_getDefaultGroupId();
			}
		}

	}


	/*
	 * Observer to check for the latest version of EU VAT Enhanced and support informations
	 * @var Varien_Event_Observer
	 */
	public function geissweb_check_for_updates()
	{
		$feed = Mage::getSingleton("euvatgrouper/feed");
		$feed->checkUpdate();
	}

	public function customer_logout($observer)
	{
		if( Mage::getSingleton('customer/session')->hasData('_vatgrouper'))
		{
			Mage::getSingleton('customer/session')->unsetData('_vatgrouper');
		}
	}

    /*
     * Helper functions to keep the code a bit more readable
     */
	private function _getExcludedGroups()
	{
		return explode(",", Mage::getStoreConfig('euvatgrouper/grouping_settings/excluded_groups', Mage::app()->getStore()->getId()));
	}

    private function _getValidEuVatGroupId()
    {
        return Mage::getStoreConfig('euvatgrouper/grouping_settings/target_group', Mage::app()->getStore()->getId());
    }

    private function _getSameCountryGroupId()
    {
        return Mage::getStoreConfig('euvatgrouper/grouping_settings/target_group_same_cc', Mage::app()->getStore()->getId());
    }

    private function _getOutsideEuGroupId()
    {
        return Mage::getStoreConfig('euvatgrouper/grouping_settings/target_group_outside', Mage::app()->getStore()->getId());
    }

    private function _getDefaultGroupId()
    {
        return Mage::getStoreConfig('customer/create_account/default_group', Mage::app()->getStore()->getId());
    }

    private function _getTaxClassIdForGroup($group_id)
    {
        return Mage::getSingleton('customer/group')->load($group_id)->getTaxClassId();
    }

}