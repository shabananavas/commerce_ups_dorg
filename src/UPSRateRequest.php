<?php

namespace Drupal\commerce_ups;

use const COMMERCE_UPS_LOGGER_CHANNEL;
use Drupal\commerce_price\Price;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\commerce_shipping\Plugin\Commerce\ShippingMethod\ShippingMethodInterface;
use Drupal\commerce_shipping\ShippingRate;
use Drupal\commerce_shipping\ShippingService;
use Drupal\Core\Datetime\DrupalDateTime;
use Psr\Container\ContainerInterface;
use Ups\Rate;
use Ups\Entity\RateInformation;

/**
 * Class UPSRateRequest.
 *
 * @package Drupal\commerce_ups
 */
class UPSRateRequest extends UPSRequest implements UPSRateRequestInterface {

  /**
   * A shipping method configuration array.
   *
   * @var array
   */
  protected $configuration;

  /**
   * The UPS Shipment object.
   *
   * @var \Drupal\commerce_ups\UPSShipmentInterface
   */
  protected $upsShipment;

  /**
   * UPSRateRequest constructor.
   *
   * @param \Drupal\commerce_ups\UPSShipmentInterface $ups_shipment
   *   The UPS shipment object.
   */
  public function __construct(UPSShipmentInterface $ups_shipment) {
    $this->upsShipment = $ups_shipment;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('commerce_ups.ups_shipment')
    );
  }

  /**
   * Fetch rates from the UPS API.
   *
   * @param \Drupal\commerce_shipping\Entity\ShipmentInterface $commerce_shipment
   *   The commerce shipment.
   * @param \Drupal\commerce_shipping\Plugin\Commerce\ShippingMethod\ShippingMethodInterface $shipping_method
   *   The shipping method.
   *
   * @throws \Exception
   *   Exception when required properties are missing.
   *
   * @return array
   *   An array of ShippingRate objects.
   */
  public function getRates(ShipmentInterface $commerce_shipment, ShippingMethodInterface $shipping_method) {
    $rates = [];

    try {
      $auth = $this->getAuth();
    }
    catch (\Exception $exception) {
      \Drupal::logger(COMMERCE_UPS_LOGGER_CHANNEL)->error(
        dt(
          'Unable to fetch authentication config for UPS. Please check your shipping method configuration.'
        )
      );
      return [];
    }

    $request = new Rate(
      $auth['access_key'],
      $auth['user_id'],
      $auth['password'],
      $this->useIntegrationMode()
    );

    try {
      $shipment = $this->upsShipment->getShipment($commerce_shipment, $shipping_method);

      // Enable negotiated rates, if enabled.
      if ($this->getRateType()) {
        $rate_information = new RateInformation();
        $rate_information->setNegotiatedRatesIndicator(TRUE);
        $rate_information->setRateChartIndicator(FALSE);
        $shipment->setRateInformation($rate_information);
      }

      // Shop Rates.
      $ups_rates = $request->shopRates($shipment);

      // Create a time in transit object so we can get the transit time for this
      // shipment.
      $time_in_transit = new UPSTransitRequest(
        $this->configuration,
        $commerce_shipment,
        $shipment
      );
    }
    catch (\Exception $ex) {
      \Drupal::logger(COMMERCE_UPS_LOGGER_CHANNEL)->error($ex->getMessage());
      $ups_rates = [];
    }

    if (!empty($ups_rates->RatedShipment)) {
      foreach ($ups_rates->RatedShipment as $ups_rate) {
        $service_code = $ups_rate->Service->getCode();

        // Only add the rate if this service is enabled.
        if (!in_array($service_code, $this->configuration['services'])) {
          continue;
        }

        // Use negotiated rates if they were returned.
        if ($this->getRateType() && !empty($ups_rate->NegotiatedRates->NetSummaryCharges->GrandTotal->MonetaryValue)) {
          $cost = $ups_rate->NegotiatedRates->NetSummaryCharges->GrandTotal->MonetaryValue;
          $currency = $ups_rate->NegotiatedRates->NetSummaryCharges->GrandTotal->CurrencyCode;
        }

        // Otherwise, use the default rates.
        else {
          $cost = $ups_rate->TotalCharges->MonetaryValue;
          $currency = $ups_rate->TotalCharges->CurrencyCode;
        }

        $price = new Price((string) $cost, $currency);
        $service_name = $ups_rate->Service->getName();
        $date = new DrupalDateTime();
        $date->format('Y-m-d');

        $shipping_service = new ShippingService(
          $service_code,
          $service_name
        );

        if (isset($time_in_transit)) {
          $times = $time_in_transit->getTransitTime();
          foreach ($times->ServiceSummary as $serviceSummary) {
            $rates[] = new ShippingRate(
              $service_code,
              $shipping_service,
              $price,
              $date::createFromFormat('Y-m-d', $serviceSummary->EstimatedArrival->getDate())
            );
          }
        }
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
