<?php

namespace Drupal\commerce_ups;

use const COMMERCE_UPS_LOGGER_CHANNEL;
use DateInterval;
use DateTime;
use Drupal;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\physical\WeightUnit;
use Ups\Entity\AddressArtifactFormat;
use Ups\Entity\InvoiceLineTotal;
use Ups\Entity\Shipment;
use Ups\Entity\ShipmentWeight;
use Ups\Entity\TimeInTransitRequest;
use Ups\Entity\UnitOfMeasurement;
use Ups\TimeInTransit;

/**
 * Class to fetch the transit time for a shipment.
 *
 * @package Drupal\commerce_ups
 */
class UPSTransitRequest extends UPSRateRequest {

  /**
   * The configuration array from a CommerceShippingMethod.
   *
   * @var array
   */
  protected $configuration;

  /**
   * The commerce shipment entity.
   *
   * @var \Drupal\commerce_shipping\Entity\ShipmentInterface
   */
  protected $shipment;

  /**
   * The UPS shipment entity.
   *
   * @var \Ups\Entity\Shipment
   */
  protected $upsShipment;

  /**
   * The UPS time transit request object.
   *
   * @var \Ups\Entity\TimeInTransitRequest
   */
  protected $request;

  /**
   * UPSTransitRequest constructor().
   *
   * @param array $configuration
   *   array of authentication information for UPS.
   * @param \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment
   *   Commerce shipment object.
   * @param \Ups\Entity\Shipment $api_shipment
   *   UPS Shipment Object.
   */
  public function __construct(
    array $configuration,
    ShipmentInterface $shipment,
    Shipment $api_shipment
  ) {
    $this->configuration = $configuration;
    $this->shipment = $shipment;
    $this->upsShipment = $api_shipment;
    $this->request = new TimeInTransitRequest();
  }

  /**
   * Builds a time in transit object.
   *
   * @return \Ups\Entity\TimeInTransitRequest
   *   A time in transit request response object for a shipment.
   */
  public function getTransitTime() {
    $time_in_transit = new TimeInTransit(
      $this->configuration['api_information']['access_key'],
      $this->configuration['api_information']['user_id'],
      $this->configuration['api_information']['password'],
      $this->useIntegrationMode(),
      NULL,
      Drupal::logger(COMMERCE_UPS_LOGGER_CHANNEL)
    );

    $this->setAddressArtifacts();
    $this->setInvoiceLines();
    $this->setWeight();
    $this->setPickup();
    $this->setPackageCount();

    return $time_in_transit->getTimeInTransit($this->request);
  }

  /**
   * Set the shipment to and from address artifacts.
   */
  protected function setAddressArtifacts() {
    $ship_from_artifact = new AddressArtifactFormat();
    $ship_to_artifact = new AddressArtifactFormat();

    $ship_from_address = $this->shipment->getOrder()->getStore()->getAddress();
    $ship_from_artifact->setPoliticalDivision3($ship_from_address->getLocality());
    $ship_from_artifact->setPostcodePrimaryLow($ship_from_address->getPostalCode());
    $ship_from_artifact->setCountryCode($ship_from_address->getCountryCode());

    $ship_to_address = $this->shipment->getOrder()->getStore()->getAddress();
    $ship_to_artifact->setPoliticalDivision3($ship_to_address->getLocality());
    $ship_to_artifact->setPostcodePrimaryLow($ship_to_address->getPostalCode());
    $ship_to_artifact->setCountryCode($ship_to_address->getCountryCode());

    $artifacts = [
      'ship_from' => $ship_from_artifact,
      'ship_to' => $ship_to_artifact,
    ];

    $this->request->setTransitFrom($artifacts['ship_from']);
    $this->request->setTransitTo($artifacts['ship_to']);
  }

  /**
   * Set the invoice lines for the shipment.
   */
  protected function setInvoiceLines() {
    $invoiceLineTotal = new InvoiceLineTotal();

    $subtotal_price = $this->shipment->getOrder()->getSubtotalPrice();
    $invoiceLineTotal->setMonetaryValue($subtotal_price->getNumber());
    $invoiceLineTotal->setCurrencyCode($subtotal_price->getCurrencyCode());

    $this->request->setInvoiceLineTotal($invoiceLineTotal);
  }

  /**
   * Set the shipment weight.
   */
  protected function setWeight() {
    $shipWeight = new ShipmentWeight();

    // The package and shipment weights are both derived from the commerce
    // shipment entity, so let's just get the weight from the package because
    // the weight has already been converted to the correct units there.
    $packages = $this->upsShipment->getPackages();
    $package = reset($packages);

    $shipWeight->setWeight($package->getPackageWeight()->getWeight());
    $shipWeight->setUnitOfMeasurement($package->getPackageWeight()->getUnitOfMeasurement());

    $this->request->setShipmentWeight($shipWeight);
  }

  /**
   * Set the pickup date.
   */
  protected function setPickup() {
    $date = new DateTime();
    // Set statically for now.
    // @todo: There should be a "production days" value somewhere.
    $date->add(new DateInterval('P8D'));

    $this->request->setPickupDate($date);
  }

  /**
   * Set the number of packages.
   */
  protected function setPackageCount() {
    $packages = $this->upsShipment->getPackages();

    $this->request->setTotalPackagesInShipment(count($packages));
  }

}
