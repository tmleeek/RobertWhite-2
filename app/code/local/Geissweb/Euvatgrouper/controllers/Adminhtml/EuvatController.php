<?php
/**
 * ||GEISSWEB| EU VAT Enhanced
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the GEISSWEB End User License Agreement
 * that is available through the world-wide-web at this URL:
 * http://www.geissweb.de/eula.html
 *
 * DISCLAIMER
 *
 * Do not edit this file if you wish to update the extension
 * to newer versions in the future. If you wish to customize the extension
 * for your needs please refer to our support for more information: support@geissweb.de
 *
 * @category    Mage
 * @package     Geissweb_Euvatgrouper
 * @copyright   Copyright (c) 2011 GEISS Weblösungen (http://www.geissweb.de)
 * @license     http://www.geissweb.de/eula.html GEISSWEB End User License Agreement
 */
class Geissweb_Euvatgrouper_Adminhtml_EuvatController extends Mage_Adminhtml_Controller_Action
{
	public function validateAccountAction()
	{
		Mage::register('euvatenhanced_is_admin_validation', true);
		$customerIds = $this->getRequest()->getParam('customer');
		$validated = 0;

		if(!is_array($customerIds))
		{
			Mage::getSingleton('adminhtml/session')->addError(Mage::helper('euvatgrouper')->__('No customers were selected!'));

		} else {

			try {
				$validator = Mage::getModel('euvatgrouper/vatvalidation');

					foreach($customerIds as $customerId)
					{

						$customer = Mage::getModel('customer/customer')->load($customerId);
						if($customer->getTaxvat() != "")
						{
							$vat_nr = Mage::helper('euvatgrouper')->cleanCustomerVatId($customer->getTaxvat());
							$old_group_id = $customer->getGroupId();
							$old_group_name = Mage::getModel('customer/group')->load($customer->getGroupId())->getCode();

							$validator->setUserCc(strtoupper(substr($vat_nr, 0, 2)));
							$validator->setUserNr(substr($vat_nr, 2));
							$validator->setIsCronValidation(true);
							$validator->setAddressId($customer->getDefaultBillingAddress()->getId());
							$validator->validate();
							$result = $validator->getResult();
							$validated++;

							$new_group_id = Mage::helper('euvatgrouper')->getBestCustomerGroup((array)$result, $validator->getUserCc());
							if($old_group_id != $new_group_id)
							{
								$new_group_name = Mage::getModel('customer/group')->load($new_group_id)->getCode();
								$customer->setGroupId($new_group_id);
								$customer->save();
								Mage::getSingleton('adminhtml/session')->addNotice(Mage::helper('euvatgrouper')->__('Moved customer '.$customer->getId().' from '.$old_group_name.' to '.$new_group_name));
							}
						}

					}

					Mage::getSingleton('adminhtml/session')->addSuccess(Mage::helper('euvatgrouper')->__('Successfully validated '.$validated.'/'.count($customerIds).' customers.'));

			} catch(Exception $e) {
				Mage::getSingleton('adminhtml/session')->addError($e->getMessage());
				//Mage::throwException($e);
			}
		}

		$this->_redirectReferer();
	}





	public function validateSingleAddressAction()
	{
		Mage::register('euvatenhanced_is_admin_validation', true);

		$do_validation = false;
		$customer_vat_id = false;
		$validator = Mage::getSingleton("euvatgrouper/vatvalidation");
		$op_mode = $this->getRequest()->getParam('op_mode');
		$validator->setOpMode($op_mode);

		$address_type = $this->getRequest()->getParam('address_type');
		if(!$address_type || $address_type == '') $address_type = 'billing';
		$validator->setAddressType($address_type);

		$address_id = $this->getRequest()->getParam('address_id');
		$validator->setAddressId($address_id);

		if ($this->getRequest()->getParam('taxvat') != "")
		{
			$customer_vat_id = Mage::helper('euvatgrouper')->cleanCustomerVatId($this->getRequest()->getParam('taxvat'));

		} elseif (is_array($this->getRequest()->getParam('billing'))) {

			$param = $this->getRequest()->getParam('billing');
			$customer_vat_id = Mage::helper('euvatgrouper')->cleanCustomerVatId($param['taxvat']);
		}

		if($customer_vat_id != false) {
			$validator->setUserTaxvat($customer_vat_id);
			$do_validation = true;
		}


		if ($do_validation)
		{
			$validator->setUserCc(strtoupper(substr($validator->getUserTaxvat(), 0, 2)));
			$validator->setUserNr(substr($validator->getUserTaxvat(), 2));

			try {
				$validator->validate();
				$result = $validator->getResult();
				$this->getResponse()->setBody(Mage::helper('core')->jsonEncode($result));
			} catch(SoapFault $e) {
				$result = new StdClass();
				$result->faultstring = strtoupper($e->getMessage());
				$this->getResponse()->setBody(Mage::helper('core')->jsonEncode($result));
			}
		}


		if ($this->getRequest()->getParam('taxvat') == "" && $this->getRequest()->getParam('vatid') == "removed")
		{
			$validator->assignDefault();
			$result = $validator->getResult();
			$this->getResponse()->setBody(Mage::helper('core')->jsonEncode($result));
		}


	}

	public function getLoaderSrcAction()
	{
		$skin_path = $this->getLayout()->createBlock('core/text')->getSkinUrl('images/opc-ajax-loader.gif', array('_secure'=>true));
		$this->getResponse()->setBody(Mage::helper('core')->jsonEncode( $skin_path ));
	}

}