<?php
/**
 *                       ######
 *                       ######
 * ############    ####( ######  #####. ######  ############   ############
 * #############  #####( ######  #####. ######  #############  #############
 *        ######  #####( ######  #####. ######  #####  ######  #####  ######
 * ###### ######  #####( ######  #####. ######  #####  #####   #####  ######
 * ###### ######  #####( ######  #####. ######  #####          #####  ######
 * #############  #############  #############  #############  #####  ######
 *  ############   ############  #############   ############  #####  ######
 *                                      ######
 *                               #############
 *                               ############
 *
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2019 Adyen BV (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Gateway\Validator;

use Magento\Payment\Gateway\Validator\AbstractValidator;

class ThreeDSResponseValidator extends AbstractValidator
{
    /**
     * GeneralResponseValidator constructor.
     *
     * @param \Magento\Payment\Gateway\Validator\ResultInterfaceFactory $resultFactory
     */
    public function __construct(
        \Magento\Payment\Gateway\Validator\ResultInterfaceFactory $resultFactory,
        \Adyen\Payment\Logger\AdyenLogger $adyenLogger,
        \Adyen\Payment\Helper\Data $adyenHelper
    ) {
        $this->adyenLogger = $adyenLogger;
        $this->adyenHelper = $adyenHelper;
        parent::__construct($resultFactory);
    }

    /**
     * @param array $validationSubject
     * @return \Magento\Payment\Gateway\Validator\ResultInterface
     */
    public function validate(array $validationSubject)
    {
        $response = \Magento\Payment\Gateway\Helper\SubjectReader::readResponse($validationSubject);
        if (!empty($validationSubject['payment'])) {
            $payment = $validationSubject['payment'];
        } else {
            $errorMsg = __('Error with payment method during validation please select different payment method.');
            throw new \Magento\Framework\Exception\LocalizedException(__($errorMsg));
        }

        $isValid = true;
        $errorMessages = [];

        // validate result
        if (!empty($response['resultCode'])) {
            switch($response['resultCode']){

                case "RedirectShopper":

                    $redirectUrl = null;
                    $paymentData = null;

                    if (!empty($response['redirect']['url'])) {
                        $redirectUrl = $response['redirect']['url'];
                    }

                    if (!empty($response['redirect']['method'])) {
                        $redirectMethod = $response['redirect']['method'];
                    }

                    if (!empty($response['paymentData'])) {
                        $paymentData = $response['paymentData'];
                    }

                    // If the redirect data is there then the payment is a card payment with 3d secure
                    if (isset($response['redirect']['data']['PaReq']) && isset($response['redirect']['data']['MD'])) {

                        $paReq = null;
                        $md = null;

                        $payment->setAdditionalInformation('3dActive', true);

                        if (!empty($response['redirect']['data']['PaReq'])) {
                            $paReq = $response['redirect']['data']['PaReq'];
                        }

                        if (!empty($response['redirect']['data']['MD'])) {
                            $md = $response['redirect']['data']['MD'];
                        }

                        if ($paReq && $md && $redirectUrl && $paymentData && $redirectMethod) {
                            $payment->setAdditionalInformation('redirectUrl', $redirectUrl);
                            $payment->setAdditionalInformation('redirectMethod', $redirectMethod);
                            $payment->setAdditionalInformation('paRequest', $paReq);
                            $payment->setAdditionalInformation('md', $md);
                            $payment->setAdditionalInformation('paymentData', $paymentData);
                        } else {
                            $isValid = false;
                            $errorMsg = __('3D secure is not valid.');
                            $this->adyenLogger->error($errorMsg);
                            $errorMessages[] = $errorMsg;
                        }
                        // otherwise it is an alternative payment method which only requires the redirect url to be present
                    } else {
                        // Flag to show we are in the checkoutAPM flow
                        $payment->setAdditionalInformation('checkoutAPM', true);

                        if ($redirectUrl && $paymentData && $redirectMethod) {
                            $payment->setAdditionalInformation('redirectUrl', $redirectUrl);
                            $payment->setAdditionalInformation('redirectMethod', $redirectMethod);
                            $payment->setAdditionalInformation('paymentData', $paymentData);
                        } else {
                            $isValid = false;
                            $errorMsg = __('Payment method is not valid.');
                            $this->adyenLogger->error($errorMsg);;
                            $errorMessages[] = $errorMsg;
                        }
                    }

                    break;
                case "Authorized":
                    if (!empty($response['additionalData']['bankTransfer.owner'])) {
                        foreach ($response['additionalData'] as $key => $value) {
                            if (strpos($key, 'bankTransfer') === 0) {
                                $payment->setAdditionalInformation($key, $value);
                            }
                        }
                    } elseif (!empty($response['additionalData']['comprafacil.entity'])) {
                        foreach ($response['additionalData'] as $key => $value) {
                            if (strpos($key, 'comprafacil') === 0) {
                                $payment->setAdditionalInformation($key, $value);
                            }
                        }
                    }

                    // Save cc_type if available in the response
                    if (!empty($response['additionalData']['paymentMethod'])) {
                        $ccType = $this->adyenHelper->getMagentoCreditCartType($response['additionalData']['paymentMethod']);
                        $payment->setAdditionalInformation('cc_type', $ccType);
                        $payment->setCcType($ccType);
                    }

                    $payment->setAdditionalInformation('pspReference', $response['pspReference']);
                break; 
                default:
                    $errorMsg = __('Error with payment method please select different payment method.');
                    throw new \Magento\Framework\Exception\LocalizedException(__($errorMsg));
            }
            
        } else {
            $errorMsg = __('Error with payment method please select different payment method.');
            throw new \Magento\Framework\Exception\LocalizedException(__($errorMsg));
        }

        return $this->createResult($isValid, $errorMessages);
    }
}
