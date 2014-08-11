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
 * @category   Mage
 * @package    Geissweb_Euvatgrouper
 * @copyright  Copyright (c) 2011 GEISS Weblösungen (http://www.geissweb.de)
 * @license    http://www.geissweb.de/eula.html GEISSWEB End User License Agreement
 */
class Geissweb_Euvatgrouper_IndexController extends Mage_Core_Controller_Front_Action
{

    public function indexAction()
    {
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

		switch($op_mode)
		{
			case 'MULTI':
				$customer_vat_id = Mage::helper('euvatgrouper')->cleanCustomerVatId( $this->getRequest()->getParam('taxvat') );

				if($customer_vat_id != false) {
					$validator->setUserTaxvat($customer_vat_id);
					$do_validation = true;
				}
				break;

			break;

			case 'SINGLE':
			default:
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
			break;
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
?>