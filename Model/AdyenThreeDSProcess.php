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

namespace Adyen\Payment\Model;

use \Adyen\Payment\Api\AdyenThreeDS2ProcessInterface;

class AdyenThreeDSProcess implements AdyenThreeDSProcessInterface
{
    /**
     * @var \Magento\Checkout\Model\Session
     */
    private $checkoutSession;

    /**
     * @var \Adyen\Payment\Helper\Data
     */
    private $adyenHelper;

    /**
     * @var \Adyen\Payment\Gateway\Validator\ThreeDSResponseValidator
     */
    private $threeDSResponseValidator;

    /**
     * @var \Magento\Quote\Model\QuoteRepository
     */
    private $quoteRepo;

    /**
     * @var \Magento\Quote\Model\QuoteIdMaskFactory
     */
    private $quoteMaskFactory;

    /**
     * AdyenThreeDS2Process constructor.
     *
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Adyen\Payment\Helper\Data $adyenHelper
     */
    public function __construct(
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Quote\Model\QuoteIdMaskFactory $quoteMaskFactory,
        \Magento\Quote\Model\QuoteRepository $quoteRepo,
        \Adyen\Payment\Helper\Data $adyenHelper,
        \Adyen\Payment\Gateway\Validator\ThreeDSResponseValidator $threeDSResponseValidator
    )
    {
        $this->checkoutSession = $checkoutSession;
        $this->adyenHelper = $adyenHelper;
        $this->quoteRepo = $quoteRepo;
        $this->quoteMaskFactory = $quoteMaskFactory;
        $this->threeDSResponseValidator = $threeDSResponseValidator;
    }

    /**
     * @api
     * @param string $payload
     * @return string
     */
    public function initiate($payload)
    {
        // When payload is not an array then why assume its a jsonstring so we try to decode 
        if(!is_array($payload)){
            $payload = json_decode($payload, true);
            // Validate JSON that has just been parsed if it was in a valid format
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Magento\Framework\Exception\LocalizedException('Error with payment method please select different payment method.');
            }
        }

        // If payload contains a customer Id, then get the cart by the customer_id
        if(isset($payload['customer_id'])){
            $quote = $this->checkoutSession->getQuote()->loadByCustomer($payload['customer_id']);
        } else {
            $quoteId = $payload['quote_id'];
            //if the quoteId is not an nummeric value then we assume that its a maked quote id from a guest card 
            if(!is_numeric($quoteId)){
                $maskedQuote = $this->quoteMaskFactory->create()->load($quoteId, 'masked_id');
                $quoteId =  $maskedQuote->getQuoteId();
            } 
            $quote = $this->quoteRepo->get($quoteId);
        }

        $payment = $quote->getPayment();
        $paymentResponse = $payload['response'];
        // Validate response 
        if( $paymentResponse['resultCode'] == "Authorized"){
            if($this->threeDSResponseValidator->validate([
                'payment'=>$payment,
                'response'=>$paymentResponse
            ])->isValid()){
                // Save the payments response because we are going to need it during the place order flow
                $payment->setAdditionalInformation("paymentsResponse", $paymentResponse);

                // Setting the placeOrder to true enables the process to skip the payments call because the paymentsResponse
                // is already in place - only set placeOrder to true when you have the paymentsResponse
                $payment->setAdditionalInformation('placeOrder', true);

                // To actually save the additional info changes into the quote
                $quote->save();

                return json_encode(['resultCode' => 'OK']);
            }
        }

        // 3DS2 flow is done, original place order flow can continue from frontend
        return json_encode([
            'resultCode' => 'KO',
            'response' => $paymentResponse
        ]);
    }
}
