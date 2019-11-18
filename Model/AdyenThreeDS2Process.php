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

class AdyenThreeDS2Process implements AdyenThreeDS2ProcessInterface
{
    /**
     * @var \Magento\Checkout\Model\Session
     */
    private $checkoutSession;

    /**
     * @var \Adyen\Payment\Helper\Data
     */
    private $adyenHelper;

    private $quoteRepo;
    private $qutoeMaskFactory;

    /**
     * AdyenThreeDS2Process constructor.
     *
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Adyen\Payment\Helper\Data $adyenHelper
     */
    public function __construct(
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Quote\Model\QuoteIdMaskFactory $qutoeMaskFactory,
        \Magento\Quote\Model\QuoteRepository $quoteRepo,
        \Adyen\Payment\Helper\Data $adyenHelper
    )
    {
        $this->checkoutSession = $checkoutSession;
        $this->adyenHelper = $adyenHelper;
        $this->quoteRepo = $quoteRepo;
        $this->quoteMaskFactory = $qutoeMaskFactory;
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

        // Init payments/details request
        $result = [];

        if ($paymentData = $payment->getAdditionalInformation("threeDS2PaymentData")) {
            // Add payment data into the request object
            $request = [
                "paymentData" => $payment->getAdditionalInformation("threeDS2PaymentData")
            ];

            // unset payment data from additional information
            $payment->unsAdditionalInformation("threeDS2PaymentData");
        } else {
            throw new \Magento\Framework\Exception\LocalizedException(__('3D secure 2.0 failed, payment data not found'));
        }

        // Depends on the component's response we send a fingerprint or the challenge result
        if (!empty($payload['details']['threeds2.fingerprint'])) {
            $request['details']['threeds2.fingerprint'] = $payload['details']['threeds2.fingerprint'];
        } elseif (!empty($payload['details']['threeds2.challengeResult'])) {
            $request['details']['threeds2.challengeResult'] = $payload['details']['threeds2.challengeResult'];
        }

        // Send the request
        try {
            $client = $this->adyenHelper->initializeAdyenClient($quote->getStoreId());
            $service = $this->adyenHelper->createAdyenCheckoutService($client);
            $requestOptions['idempotencyKey'] = $quote->reserveOrderId()->getReservedOrderId();

            $result = $service->paymentsDetails($request);
        } catch (\Adyen\AdyenException $e) {
            throw new \Magento\Framework\Exception\LocalizedException(__(json_encode($e)));
        }

        // Check if result is challenge shopper, if yes return the token
        if (!empty($result['resultCode']) &&
            $result['resultCode'] === 'ChallengeShopper' &&
            !empty($result['authentication']['threeds2.challengeToken'])
        ) {
            return $this->adyenHelper->buildThreeDS2ProcessResponseJson($result['resultCode'], $result['authentication']['threeds2.challengeToken']);
        }

        // Payment can get back to the original flow

        // Save the payments response because we are going to need it during the place order flow
        $payment->setAdditionalInformation("paymentsResponse", $result);

        // Setting the placeOrder to true enables the process to skip the payments call because the paymentsResponse
        // is already in place - only set placeOrder to true when you have the paymentsResponse
        $payment->setAdditionalInformation('placeOrder', true);

        // To actually save the additional info changes into the quote
        $quote->save();

        // 3DS2 flow is done, original place order flow can continue from frontend
        return $this->adyenHelper->buildThreeDS2ProcessResponseJson();
    }
}
