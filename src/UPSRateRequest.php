<?php

namespace Drupal\commerce_ups;

use const COMMERCE_UPS_LOGGER_CHANNEL;
use Drupal\commerce_price\Price;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\commerce_shipping\ShippingRate;
use Drupal\commerce_shipping\ShippingService;
use Ups\Rate;
use Ups\Entity\RateInformation;

/**
 * Class UPSRateRequest.
 *
 * @package Drupal\commerce_ups
 */
class UPSRateRequest extends UPSRequest {

  /**
   * The commerce shipment entity.
   *
   * @var \Drupal\commerce_shipping\Entity\ShipmentInterface
   */
  protected $commerceShipment;

  /**
   * The configuration array from a CommerceShippingMethod.
   *
   * @var array
   */
  protected $configuration;

  /**
   * Set the shipment for rate requests.
   *
   * @param \Drupal\commerce_shipping\Entity\ShipmentInterface $commerce_shipment
   *   A Drupal Commerce shipment entity.
   */
  public function setShipment(ShipmentInterface $commerce_shipment) {
    $this->commerceShipment = $commerce_shipment;
  }

  /**
   * Fetch rates from the UPS API.
   */
  public function getRates() {
    // Validate a commerce shipment has been provided.
    if (empty($this->commerceShipment)) {
      throw new \Exception('Shipment not provided');
    }

    $rates = [];
    $auth = $this->getAuth();

    $request = new Rate(
      $auth['access_key'],
      $auth['user_id'],
      $auth['password'],
      $this->useIntegrationMode()
    );

    try {
      $ups_shipment = new UPSShipment($this->commerceShipment);
      $shipment = $ups_shipment->getShipment();

      // Enable negotiated rates, if enabled.
      if ($this->getRateType()) {
        $rate_information = new RateInformation();
        $rate_information->setNegotiatedRatesIndicator(TRUE);
        $rate_information->setRateChartIndicator(FALSE);
        $shipment->setRateInformation($rate_information);
      }

      // Shop Rates.
      $ups_rates = $request->shopRates($shipment);
    }
    catch (\Exception $ex) {
      \Drupal::logger(COMMERCE_UPS_LOGGER_CHANNEL)->error($ex->getMessage());
      $ups_rates = [];
    }

    if (!empty($ups_rates->RatedShipment)) {
      foreach ($ups_rates->RatedShipment as $ups_rate) {
        // Let's add an underscore as prefix to the code we get from UPS as the
        // keys for the UPS shipping services start with an underscore due to a
        // bug in the Drupal Core annotation system.
        $service_code = '_' . $ups_rate->Service->getCode();

        // Only add the rate if this service is enabled.
        if (!in_array($service_code, $this->configuration['services'])) {
          continue;
        }

        $cost = $ups_rate->TotalCharges->MonetaryValue;
        $currency = $ups_rate->TotalCharges->CurrencyCode;
        $price = new Price((string) $cost, $currency);
        $service_name = $ups_rate->Service->getName();

        $shipping_service = new ShippingService(
          $service_name,
          $service_name
        );
        $rates[] = new ShippingRate(
          $service_code,
          $shipping_service,
          $price
        );
      }
    }
    return $rates;
  }

  /**
   * Gets the rate type: whether we will use negotiated rates or standard rates.
   *
   * @return bool
   *   Returns true if negotiated rates should be requested.
   */
  public function getRateType() {
    return boolval($this->configuration['rate_options']['rate_type']);
  }

}
