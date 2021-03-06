<?php
/**
 *  Get Customer Feedback Module
 *  
 *  Copyright (C) 2013 paj@gaiterjones.com
 *
 *  v0.0.12 - 25.11.2011 - dev BETA release
 *  v0.0.13 - 25.11.2011 - bug fixes - store name and image links
 *  v0.0.15 - 25.11.2011 - implemented detection for invisible simple products
 *						   that are children of a grouped product.
 *  v0.0.16 - 09.12.2011 - implemented change to allow removal of duplicates
 *						   products from generated cart array and force UTF8
 *						   conversion of description text.
 *  v0.0.17 - 16.12.2011 - Improved customer name handling
 *                         added multistore functionality to allow for for multi language
 *  v0.0.18 - 04.01.2012 - Improved email formatting and options for translations
 *  v0.0.19 - 05.01.2012 - Added max feedback item control
 *  v0.0.19a - 05.01.2012 - Bug Fix
 *	v0.0.20	- 20.02.2012 - Added locale file for translations
 *	v0.0.23	- 21.03.2012 - Added controls to prevent empty customer name from breaking code.
 * 	v0.0.3 - 28.02.2013  - Zuiko enhanced code to validate cache files against orders before sending.
 * 	v0.0.4 - 16.04.2013  - Code tidy up, enhancements to order checking, fraud check etc.
 * 	v0.0.5 - 29.04.2013  - Added option to use Magento mail system by default to try and avoid customer mail going to spam using custom mail class.
 * 	v0.0.6 - 29.04.2013  - Added detection of configurable parent for invisible child products in cart.
 * 	v0.0.61 - 01.05.2013  - bug fix.
 * 	v0.0.62 - 02.05.2013  - bug fix.
 * 	v0.0.63 - 10.05.2013  - changed alert emails to use mage or php class
 * 	v0.0.65 - 17.05.2013  - bug fix/s.
 * 	v0.0.7  - 21.05.2013  - Add new timestamp to order data when order is complete to make waiting period for feedback email more accurate.
 *                         
 *
 *	This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 *  @category   PAJ
 *  @package    GetCustomerFeedback
 *  @license    http://www.gnu.org/licenses/ GNU General Public License
 * 
 *
 */

// php mail class 
require_once Mage::getModuleDir('', 'PAJ_GetCustomerFeedback') . DS . 'classes'. DS . 'class.GetCustomerFeedback.Email.php';

class PAJ_GetCustomerFeedback_Model_Observer
{

	public function GetCustomerFeedback()
	{
		// initialise variables
		$cacheFolder  = Mage::getModuleDir('', 'PAJ_GetCustomerFeedback') . DS . 'cache'. DS;
		$orderId = Mage::getSingleton('checkout/session')->getLastOrderId();
		$order = Mage::getModel('sales/order')->load($orderId);
		$customerEmail = $order->getCustomerEmail();
		$realOrderId = $order->getRealOrderId();
		$orderStatus = $order->getStatus();
		$newline="\n";
		
		// clean customer name, First + LAST from session
		$customerFirstName = strtolower(utf8_decode(Mage::getSingleton('customer/session')->getCustomer()->getFirstname()));
		$customerLastName = strtolower(utf8_decode(Mage::getSingleton('customer/session')->getCustomer()->getLastname()));
		$customerName=ucfirst($customerFirstName). " ". ucfirst($customerLastName);
		$customerName=trim($customerName);
		
		// if no session i.e. guest checkout get customer name from billing address
		if (empty($customerName)) { $customerName=$order->getBillingAddress()->getName();}
		// if no customer name use default to stop blank name from breaking things
		if (empty($customerName)) { $customerName="Customer";}
		
		// store id
		$orderStoreID=$order->getStoreId();
		
		// items
		$items = $order->getAllItems();
		$ids=array();
		$name=array();
		$unitPrice=array();
		$sku=array();
		$qty=array();
		
		// item counters
		$itemCount=1;
		$feedbackItemCount=0;
		
		// email
		$emailFeedbackIconURL=trim(Mage::getStoreConfig('getcustomerfeedback_section1/general/email_feedback_icon',(int)$orderStoreID));
		$urlTrackingTags = trim(Mage::getStoreConfig('getcustomerfeedback_section1/general/url_tracking_tags',(int)$orderStoreID));
		
		$feedbackProductIDs=array();

		try {
		
				// parse cart for saleable/visible order items
				foreach ($items as $itemId => $item)
				{
					$cartProduct = Mage::getModel('catalog/product')->load($item->getProductId());
				
					if ($cartProduct->getVisibility()!= "4") // product not visible in catalog
					{
					
						// check if the *invisible* product is a child of a grouped or configurable product
						
						if (Mage::getVersion() >= 1.4)
						{
							// Magento v1.42 +
							$parentIdGrouped = Mage::getModel('catalog/product_type_grouped')
								->getParentIdsByChild( $cartProduct->getId() );
							$parentIdConfigurable = Mage::getModel('catalog/product_type_configurable')
								->getParentIdsByChild( $cartProduct->getId() );							
						} else {
							// pre 1.42
							$parentIdGrouped = $cartProduct->loadParentProductIds()->getData('parent_product_ids');
							$parentIdConfigurable = $cartProduct->loadParentProductIds()->getData('parent_product_ids');
						}
						
						// use parent product if parent is grouped or configurable otherwise move on, these are not the products you are looking for...
						
						if (!empty($parentIdGrouped[0])) // check for grouped product parent
						{
							$cartProduct = Mage::getModel('catalog/product')->load($parentIdGrouped[0]);
						
							if($cartProduct->getTypeId() != "grouped") { continue; } // paranoia
							
						} else if (!empty($parentIdConfigurable[0])) { // check for configurable product parent

							$cartProduct = Mage::getModel('catalog/product')->load($parentIdConfigurable[0]);
						
							if($cartProduct->getTypeId() != "configurable") { continue; } // paranoia
		
						}			

					}
					
					// load the visible cart products into array
					if ($cartProduct->getVisibility()=== "4")
					{
						$feedbackProductIDs[]=$cartProduct->getId();
					}
					
				}

				// clean up feedback product array to avoid duplicate products
				$feedbackProductIDs = array_unique($feedbackProductIDs);
				
				// get max feedback items
				$maxFeedbackItems=(int)Mage::getStoreConfig('getcustomerfeedback_section1/general/max_feedback_items',$orderStoreID);
				
				$cartHTML=null;
				$cartHTML=$cartHTML. '<table cellspacing="0" cellpadding="0" border="0" width="650" style="border:1px solid #EAEAEA;">'. $newline;	
				$cartHTML=$cartHTML. '<tbody bgcolor="#F6F6F6">'. $newline;	;
				
				if (!is_numeric($maxFeedbackItems)) {
					$maxFeedbackItems=0;
				}
				
	
				// create the product html
				foreach ($feedbackProductIDs as $key => $item)
				{
				
					$cartProduct = Mage::getModel('catalog/product')->load($item);
					
					// get product attributes
					$cartProductID=$cartProduct->getId();
					$cartProductName=utf8_decode($cartProduct->getName());
					$cartProductImageURL=$cartProduct->getImageUrl();
					$cartProductImageURL=str_replace("https","http",$cartProductImageURL);
					$cartProductVisibility=$cartProduct->getVisibility();
					
					if ($cartProduct->getVisibility()=== "4") // products must be visible in search and catalogue
					{
						$cartHTML=$cartHTML. '<tr>'. $newline;
						$cartHTML=$cartHTML. '<td align="left" valign="top" style="font-size:11px; padding:3px 9px; border-bottom:1px dotted #CCCCCC;">'. $itemCount. '</td>'. $newline;
						$cartHTML=$cartHTML. '<td align="center" valign="top" style="font-size:11px; padding:3px 9px; border-bottom:1px dotted #CCCCCC;"><img height="64" width="64" src="'. $cartProductImageURL. '"></td>'. $newline;
						$cartHTML=$cartHTML. '<td align="left" valign="top" style="font-size:11px; padding:3px 9px; border-bottom:1px dotted #CCCCCC;">'. htmlentities($cartProductName). '</td>'. $newline;
						
						if (empty($emailFeedbackIconURL))
						{
							$cartHTML=$cartHTML. '<td align="center" valign="top" style="font-size:11px; padding:3px 9px; border-bottom:1px dotted #CCCCCC;"><a href="'. Mage::getBaseUrl(Mage_Core_Model_Store:: URL_TYPE_WEB). 'review/product/list/id/'. $cartProductID. '/#review-form'. $urlTrackingTags. '">Leave Feedback</a></td>'. $newline;
						} else {
							$cartHTML=$cartHTML. '<td align="center" valign="top" style="font-size:11px; padding:3px 9px; border-bottom:1px dotted #CCCCCC;"><a href="'. Mage::getBaseUrl(Mage_Core_Model_Store:: URL_TYPE_WEB). 'review/product/list/id/'. $cartProductID. '/#review-form'. $urlTrackingTags. '"><img src="'. $emailFeedbackIconURL. '"></a></td>'. $newline;
						}
						
						$cartHTML=$cartHTML. '</tr>'. $newline;
						
						// increment counters
						$itemCount ++;
						$feedbackItemCount ++;
					}
					
					
					if ($maxFeedbackItems > 1) 	// check max feedback item control, must be greater than 1 or feedback is empty.
					{
						
						if ($feedbackItemCount >= $maxFeedbackItems)
						{
							break;
						}
						
					}
				}

				$cartHTML=$cartHTML. '</tbody>'. $newline;
				$cartHTML=$cartHTML. '</table>'. $newline;

			
			if ($feedbackItemCount===0) { throw new Exception('No valid products to use for customer feedback could be found in the cart for order '. $orderId. '.'); }
			
			
			// dump cart to html and dat files
			if (is_writable($cacheFolder)) 
			{
				$timeStamp=time();
				$fp2 = fopen($cacheFolder. 'GetCustomerFeedback'. $timeStamp. '.dat', 'w');		
				// customer data in fp2
				// line 0 Order ID
				fwrite($fp2, $realOrderId. $newline);
				// line 1 Customer Name
				fwrite($fp2, $customerName. $newline);
				// line 2 Customer Email
				fwrite($fp2, $customerEmail. $newline);
				// line 3 HTML File
				fwrite($fp2, 'GetCustomerFeedback'. $timeStamp. '.html'. $newline);
				// line 4 Date/time
				fwrite($fp2, $timeStamp. $newline);
				// line 5 Internal Order ID
				fwrite($fp2, $orderId. $newline);
				// line 6 Internal Store ID
				fwrite($fp2, $orderStoreID. $newline);
				// line 7 Order Status at time of Order with timestamp
				fwrite($fp2, $order->getStatus(). '|'. $timeStamp. $newline);				
				// LAST line Magento Version and debugging
				fwrite($fp2, "Magento Version: ". Mage::getVersion(). $newline);
				fclose($fp2);
				
				
				$fp1 = fopen($cacheFolder. 'GetCustomerFeedback'. $timeStamp. '.html', 'w');						
				// product html in fp1
				fwrite($fp1, $cartHTML);
				fclose($fp1);
				

			} else {
				throw new Exception('The GetCustomerFeedback module cache folder - '. $cacheFolder. ' is not writable, please check the folder permissions.');
			}
			
		} catch (Exception $e) {
			
		   if (empty($e))
			{
				$this->sendAlertEmail("An undefined error occurred preparing the customer feedback html cache file.");
			} else {
		   		$this->sendAlertEmail($e->getMessage());
			}
		}
		

	}

	// function called by Magento scheduling system to format and send feedback emails
	//
	public function GetCustomerFeedbackCron()
	{
	
	try {
			$cacheFolder  = Mage::getModuleDir('', 'PAJ_GetCustomerFeedback') . DS . 'cache'. DS;
			
			if (is_writable($cacheFolder)) 
			{
				// get list of files in cache folder
				$files=$this->getDirectoryList(Mage::getModuleDir('', 'PAJ_GetCustomerFeedback') . DS . 'cache'. DS);

				if (count($files) > 0) // if files exist in cache folder then go get 'em...
				{
					
					$newline="\n";
					sort($files);
					
					foreach ($files as $file)
					{
						
						// get first file contents
						$orderDatFile=Mage::getModuleDir('', 'PAJ_GetCustomerFeedback') . DS . 'cache'. DS. $file;
						
						// load dat file into array
						$orderData=file($orderDatFile);
						
						// load order
						$order = Mage::getModel('sales/order')->load(trim($orderData[5]));
						$orderStatus=$order->getStatus();
						
						// validate order check for cancelled, fraud, valid order etc etc.
						if (!$this->validateOrder($orderData,$orderStatus,$orderDatFile)) { continue; } // keep on loopin baby
					
						// get timestamp from dat file
						$orderDatTimeStamp=$orderData[4];
						
						// compare order status with actual status, reset timestamp if complete
						if (Mage::getStoreConfig('getcustomerfeedback_section1/general/check_order_status'))
						{
							$orderDatStatusArray=explode('|',$orderData[7]);
							$orderDatStatus=$orderDatStatusArray[0];
							
							if ($orderStatus==='complete')
							{
								
								if ($orderDatStatus === 'complete')
								{
									// use timestamp from order completion date
									
									if (isset ($orderDatStatusArray[1])) { $orderDatTimeStamp=$orderDatStatusArray[1];}
									
								} else {
									// if order status has changed to complete, update timestamp
									$this->UpdateDatFile($orderDatFile,$orderData,7,'complete|'.time());
									$orderDatTimeStamp=time();
								}
							}
							
						}

						// get store id from .dat file
						$storeID=trim($orderData[6]);
						
						// get customer name from .dat file
						$customerName=trim($orderData[1]);
						
						// catch empty customer name as this will break email
						if (empty($customerName)){ $customerName=$this->getTranslation('Customer',$storeID); }
						
						// get customer email address from .dat file
						$customerEmail=trim($orderData[2]);
						
							// determine time (in hours) elapsed since order
							$elaspedHoursSinceOrder= floor((time()-(int)$orderDatTimeStamp)/3600);
							// waiting period in days from config
							$emailNotificationWaitingPeriod=(int)Mage::getStoreConfig('getcustomerfeedback_section1/general/elapsed_time_from_order');
							// change from days to hours
							$emailNotificationWaitingPeriod=$emailNotificationWaitingPeriod*24;
							
							if ($elaspedHoursSinceOrder >= $emailNotificationWaitingPeriod)
							{
								// construct email
								// check for test mode
								if (Mage::getStoreConfig('getcustomerfeedback_section1/general/test_mode_enabled')) {
									$toName=Mage::getStoreConfig('trans_email/ident_general/name');
									$to = Mage::getStoreConfig('trans_email/ident_general/email');
								} else {
									$to = $customerEmail;
									$toName = $customerName;
								}
								
								// check for bcc mode
								$bcc=null;
								if (Mage::getStoreConfig('getcustomerfeedback_section1/general/bcc_emails_enabled')) {
									$bcc = Mage::getStoreConfig('trans_email/ident_general/email');
								}
								
								// email from address uses store sales address
								$from = Mage::getStoreConfig('trans_email/ident_sales/email',$storeID);
								$fromName = Mage::getStoreConfig('trans_email/ident_sales/name',$storeID);
	
								// set email subject text
								$subject=Mage::getStoreConfig('getcustomerfeedback_section1/general/email_subject',$storeID);
								if (empty($subject)) {
									$subject = Mage::getStoreConfig('getcustomerfeedback_section1/general/email_footer_link',$storeID). ' : '. $this->getTranslation('Your Order',$storeID). ' # '. trim($orderData[0]);
								}
								
								$style='<style type="text/css">
	body,td { color:#2f2f2f; font:11px/1.35em Verdana, Arial, Helvetica, sans-serif; }
	a:visited {color: #000000}
	</style>';
								
								$header='<body style="background:#F6F6F6; font-family:Verdana, Arial, Helvetica, sans-serif; font-size:12px; margin:0; padding:0;">
	<div style="background:#F6F6F6; font-family:Verdana, Arial, Helvetica, sans-serif; font-size:12px; margin:0; padding:0;">
	<table cellspacing="0" cellpadding="0" border="0" width="100%">
	<tr>
	<td align="center" valign="top" style="padding:20px 0 20px 0">
	<table bgcolor="#FFFFFF" cellspacing="0" cellpadding="10" border="0" width="650" style="border:1px solid #E0E0E0;">';
										
								$greeting = '<tr><td valign="top"><h1 style="font-size:22px; font-weight:normal; line-height:22px; margin:0 0 11px 0;"">'. $this->getTranslation('Hello',$storeID). ' '. $customerName. ',</h1>';
								$orderInfo = '<tr><td><h2 style="font-size:18px; font-weight:normal; margin:0;">'. $this->getTranslation('Your Order',$storeID). ' #'. trim($orderData[0]). '<small> ('. (date("j.n.Y h:i:s A",(int)$orderData[4])). ')</small></h2></td></tr>';
								$intro = '<p style="font-size:12px; line-height:16px; margin:0;">'. Mage::getStoreConfig('getcustomerfeedback_section1/general/email_text1',$storeID). '</p></tr></td>';
								$products = '<tr><td>'. file_get_contents (Mage::getModuleDir('', 'PAJ_GetCustomerFeedback') . DS . 'cache'. DS. trim($orderData[3])). '</td></tr>';
								
								$footerText=Mage::getStoreConfig('getcustomerfeedback_section1/general/email_footer_link',$storeID);
								$footerTextHtml='<strong><a href="'. Mage::getBaseUrl(Mage_Core_Model_Store:: URL_TYPE_WEB).'">'. $footerText .'</a></strong>';
								
								if (strtolower(substr($footerText, -4)) === ".png") // check for image
								{
									$footerTextHtml='<a href="'. Mage::getBaseUrl(Mage_Core_Model_Store:: URL_TYPE_WEB).'"><img style="border: 0px;" src="'. $footerText .'"></a>';
								}
								
								$footer = '<tr><td><p>'. Mage::getStoreConfig('getcustomerfeedback_section1/general/email_text2',$storeID). '</p><p style="font-size:12px; margin:0 0 10px 0"></p></td></tr>
	<tr>
	<td bgcolor="#EAEAEA" align="center" style="background:#EAEAEA; text-align:center;"><p style="font-size:12px; text-align:center; margin:0;">'. $footerTextHtml. '</p></td>
	</tr>
	</tr>
	</table>
	</div>
	</body>';
								
								// construct email html
								$body=$style.$header.$greeting.$intro.$orderInfo.$products.$footer;
								
								// if check status is set to yes
								if (Mage::getStoreConfig('getcustomerfeedback_section1/general/check_order_status'))
								{
									
									// determine order status
									if ($orderStatus==='complete')
									{
										// send get feedback email
										if (Mage::getStoreConfig('getcustomerfeedback_section1/general/use_php_mail')) // use php mail
										{
											$oMail = new GetCustomerFeedbackMail($toName. ' <'. $to. '>',$fromName. ' <'. $from. '>',$subject,$body,$bcc);
											$_sendMail=$oMail->send();
										} else { // use magento mail
											$_sendMail=$this->magentoMail($toName,$to,$body,$subject,$fromName,$from,$bcc);
										
										}
										
										if (!$_sendMail) { // mail send error
											// clean up
											unlink($orderDatFile);
											unlink(Mage::getModuleDir('', 'PAJ_GetCustomerFeedback') . DS . 'cache'. DS. trim($orderData[3]));
											throw new Exception('An error occurred trying to send customer feedback email, command : '.$orderData[0].' erasing file: '.$orderDatFile.' and associated .html file'); 
										}
										
										if (Mage::getStoreConfig('getcustomerfeedback_section1/general/test_mode_enabled')) {
											// dont update order if in test mode								
										} else {
											// add order note
											$order->addStatusToHistory($order->getStatus(), '<i>GetCustomerFeedback</i><br/>Customer feedback email sent to <strong>'. $customerEmail. '</strong>.', true);
											$order->save();
										}
										
										// clean up
										unlink($orderDatFile);
										unlink(Mage::getModuleDir('', 'PAJ_GetCustomerFeedback') . DS . 'cache'. DS. trim($orderData[3]));
									
									}
									
								} else { // check status not set, send email
								
									// send get feedback email
									if (Mage::getStoreConfig('getcustomerfeedback_section1/general/use_php_mail')) // use php mail
									{
										$oMail = new GetCustomerFeedbackMail($toName. ' <'. $to. '>',$fromName. ' <'. $from. '>',$subject,$body,$bcc);
										$_sendMail=$oMail->send();
									} else { // use magento mail
										$_sendMail=$this->magentoMail($toName,$to,$body,$subject,$fromName,$fromEmail,$bcc);
									
									}
									
									if (!$_sendMail) { // mail send error
										// clean up
										unlink($orderDatFile);
										unlink(Mage::getModuleDir('', 'PAJ_GetCustomerFeedback') . DS . 'cache'. DS. trim($orderData[3]));
										throw new Exception('An error occurred trying to send customer feedback email, command : '.$orderData[0].' erasing file: '.$orderDatFile.' and associated .html file'); 
									}
									
									if (Mage::getStoreConfig('getcustomerfeedback_section1/general/test_mode_enabled')) {
										// dont update order if in test mode
									} else {
										// add order note
										$order = Mage::getModel('sales/order')->load(trim($orderData[5]));
										$order->addStatusToHistory($order->getStatus(), '<i>GetCustomerFeedback</i><br/>Customer feedback email sent to <strong>'. $customerEmail. '</strong>.', true);
										$order->save();
									}
									
									// clean up
									unlink($orderDatFile);
									unlink(Mage::getModuleDir('', 'PAJ_GetCustomerFeedback') . DS . 'cache'. DS. trim($orderData[3]));
								
								} // check order status
								
							} // elapsed time check
					
					} // file array loop
				
				} // file array contains files
				
			} else { // folder permissions check failed
			    throw new Exception('The GetCustomerFeedback module cache folder -'. $cacheFolder. ' is not writable, please check the folder permissions.');
			}
							
		} catch (Exception $e) {
			
		   if (empty($e))
			{
				$this->sendAlertEmail("An undefined error occurred preparing the customer feedback html cache file.");
			} else {
		   		$this->sendAlertEmail($e->getMessage());
			}
		}
	}
	
	private function validateOrder($orderData,$orderStatus,$orderDatFile)
	{
	
		// determine order status
		$order = Mage::getModel('sales/order')->load(trim($orderData[5]));
		
		if (!$order->getEntityId()) 
		{
			if (file_exists($orderDatFile)) {
				unlink($orderDatFile);
				unlink(Mage::getModuleDir('', 'PAJ_GetCustomerFeedback') . DS . 'cache'. DS. trim($orderData[3]));
			}
			return false;				
		} 
			
		if (empty($orderStatus))
		{
			if (file_exists($orderDatFile)) {
				unlink($orderDatFile);
				unlink(Mage::getModuleDir('', 'PAJ_GetCustomerFeedback') . DS . 'cache'. DS. trim($orderData[3]));
				//throw new Exception('Empty order status????');
			}
			return false;
		}										
			
		if ($orderStatus==="canceled" || $orderStatus==="cancelled") // which spelling is correct?
		{
			if (file_exists($orderDatFile)) {
				unlink($orderDatFile);
				unlink(Mage::getModuleDir('', 'PAJ_GetCustomerFeedback') . DS . 'cache'. DS. trim($orderData[3]));
			}
			return false;
		}
		
		if ($orderStatus==="fraud")
		{
			if (file_exists($orderDatFile)) {
				unlink($orderDatFile);
				unlink(Mage::getModuleDir('', 'PAJ_GetCustomerFeedback') . DS . 'cache'. DS. trim($orderData[3]));
			}
			return false;
		}	
	
			return true;
	}
	
	// function to return filenames in a directory as an array
	//
	private function getDirectoryList ($directory) 
	{
	  // create an array to hold directory list
	  $results = array();
	  // create a handler for the directory
	  $handler = opendir($directory);
	  // open directory and walk through the filenames
	  while ($file = readdir($handler)) {
	  
	    // read files and match against the files we are interested in
	    if (substr($file, 0, 19) === "GetCustomerFeedback") {
			
			if (substr($file, -4) === ".dat") {
				$results[] = $file;
			}
			
			if (substr($file, -10) === ".emailtest") {
				$this->sendAlertEmail('Test email from GetCustomerFeedback Module!');
			}
						
	    }
		
	  }
	  // tidy up: close the handler
	  closedir($handler);
	  // return array containing filenames
	  return $results;
	}
	

	// function to return translations from locale file
	//
	private function getTranslation($word,$storeID=1) 
	{
	  $translationFile=Mage::getModuleDir('', 'PAJ_GetCustomerFeedback') . DS. 'translate_store_id_'. (string)$storeID. '.txt';
	  
		if (file_exists($translationFile))
		{
	  
		  $file_handle = fopen($translationFile, "rb");

			while (!feof($file_handle) ) {
			$line_of_text = fgets($file_handle);
			$parts = explode('=', $line_of_text);
			
				if ($parts[0]===$word)
				{
					$word=trim($parts[1]);
					break;
				}
			}
			fclose($file_handle);
		}
		
		return $word;
	}

	// function to update dat order file
	//
	private function UpdateDatFile($_file,$_data,$_lineNumberToReplace,$_replacementData) 
	{

		$_newline="\n";
		$_fileHandle = fopen($_file, 'w');
		
		foreach ($_data as $_lineNumber=>$_line)
		{
			if ($_lineNumber==$_lineNumberToReplace)
			{
				fwrite($_fileHandle,$_replacementData.$_newline);
				continue;
			}
			
			fwrite($_fileHandle,$_line);
		}
		
		fclose($_fileHandle);
	}	

	private function magentoMail($_toName,$_toEmail,$_body,$_subject,$_fromName,$_fromEmail,$_bcc='')
	{
		$_mail = Mage::getModel('core/email');
		$_mail->setToName($_toName);
		$_mail->setToEmail($_toEmail);
		$_mail->setBody($_body);
		$_mail->setSubject($_subject);
		$_mail->setFromEmail($_fromEmail);
		$_mail->setFromName($_fromName);
		//if (!empty($_bcc)) {$_mail->addBcc($_bcc);}
		$_mail->setType('html'); // use html or text as mail format

		try {
			$_mail->send();
			return true;
		}
		catch (Exception $e) {
			// could not send
			return false;
		}	
	
	}
	
	private function sendAlertEmail($message)
    {
		if (Mage::getStoreConfig('getcustomerfeedback_section1/general/send_alert_email')) {
		
			$_body = wordwrap($message, 70);
						
			$_fromEmail = Mage::getStoreConfig('trans_email/ident_general/email');
			//$_fromEmail = 'your @ email address here.com'; // edit for debugging
			
			$_fromName='Get Customer Feedback Module';
			
			$_toEmail=Mage::getStoreConfig('trans_email/ident_general/email');
			//$_toEmail = 'your @ email address here.com'; // edit for debugging
			
			$_toName='Module Support';

			if (Mage::getStoreConfig('getcustomerfeedback_section1/general/use_php_mail')) // use php mail
			{
				$_subject='Alert from Get Customer Feedback Module (PHP)';
				$oMail = new GetCustomerFeedbackMail($_toName. ' <'. $_toEmail. '>',$_fromName. ' <'. $_fromEmail. '>',$_subject,$_body);
				$_sendMail=$oMail->send();
				
			} else { // use magento mail
				$_subject='Alert from Get Customer Feedback Module (MAGE)';
				$this->magentoMail($_toName,$_toEmail,$_body,$_subject,$_fromName,$_fromEmail);
			}
		}
	}
	
// class
}

?>