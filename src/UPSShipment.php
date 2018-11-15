<?php

namespace Drupal\commerce_ups;

use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Ups\Entity\Package as UPSPackage;
use Ups\Entity\Address;
use Ups\Entity\ShipFrom;
use Ups\Entity\Shipment as APIShipment;
use Ups\Entity\Dimensions;

/**
 * Constructs and extends the UPS shipment entity.
 *
 * @package Drupal\commerce_ups
 */
class UPSShipment extends UPSEntity {

  /**
   * The commerce shipment interface.
   *
   * @var \Drupal\commerce_shipping\Entity\ShipmentInterface
   */
  protected $shipment;

  /**
   * UPSShipment constructor.
   *
   * @param \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment
   *   A commerce shipping shipment object.
   */
  public function __construct(ShipmentInterface $shipment) {
    parent::__construct();

    $this->shipment = $shipment;
  }

  /**
   * Creates and returns a Ups API shipment object.
   *
   * @return \Ups\Entity\Shipment
   *   A Ups API shipment object.
   */
  public function getShipment() {
    $api_shipment = new APIShipment();
    $this->setShipTo($api_shipment);
    $this->setShipFrom($api_shipment);
    $this->setPackage($api_shipment);
    return $api_shipment;
  }

  /**
   * Sets the ship to for a given shipment.
   *
   * @param \Ups\Entity\Shipment $api_shipment
   *   A Ups API shipment object.
   */
  public function setShipTo(APIShipment $api_shipment) {
    $address = $this->shipment->getShippingProfile()->address;
    $to_address = new Address();
    $to_address->setAttentionName($address->given_name . ' ' . $address->family_name);
    $to_address->setAddressLine1($address->address_line1);
    $to_address->setAddressLine2($address->address_line2);
    $to_address->setCity($address->locality);
    $to_address->setStateProvinceCode($address->administrative_area);
    $to_address->setPostalCode($address->postal_code);
    $to_address->setCountryCode($address->country_code);
    $api_shipment->getShipTo()->setAddress($to_address);
  }

  /**
   * Sets the ship from for a given shipment.
   *
   * @param \Ups\Entity\Shipment $api_shipment
   *   A Ups API shipment object.
   */
  public function setShipFrom(APIShipment $api_shipment) {
    $address = $this->shipment->getOrder()->getStore()->getAddress();
    $from_address = new Address();
    $from_address->setAddressLine1($address->getAddressLine1());
    $from_address->setAddressLine2($address->getAddressLine2());
    $from_address->setCity($address->getDependentLocality());
    $from_address->setStateProvinceCode($address->getAdministrativeArea());
    $from_address->setPostalCode($address->getPostalCode());
    $from_address->setCountryCode($address->getCountryCode());
    $ship_from = new ShipFrom();
    $ship_from->setAddress($from_address);
    $api_shipment->setShipFrom($ship_from);
  }

  /**
   * Sets the package for a given shipment.
   *
   * @param \Ups\Entity\Shipment $api_shipment
   *   A Ups API shipment object.
   */
  public function setPackage(APIShipment $api_shipment) {
    $package = new UPSPackage();
    $this->setDimensions($package);
    $this->setWeight($package);
    $api_shipment->addPackage($package);
  }

  /**
   * Package dimension setter.
   *
   * @param \Ups\Entity\Package $ups_package
   *   A Ups API package object.
   */
  public function setDimensions(UPSPackage $ups_package) {
    $dimensions = new Dimensions();

    // UPS only takes the dimensions in certain units, so we need to convert it
    // into cm if in m/mm/ft which the Drupal physical module does.
    $height = $this->shipment->getPackageType()->getHeight()->getNumber();
    $length = $this->shipment->getPackageType()->getLength()->getNumber();
    $width = $this->shipment->getPackageType()->getWidth()->getNumber();
    $unit = $this->shipment->getPackageType()->getLength()->getUnit();
    if ($unit == 'm' || $unit == 'mm' || $unit == 'ft') {
      $height = $this->convertDimensionToCentimeters($unit, $height);
      $length = $this->convertDimensionToCentimeters($unit, $length);
      $width = $this->convertDimensionToCentimeters($unit, $width);

      // Change the unit to 'cm' now.
      $unit = 'cm';
    }

    $dimensions->setHeight($height);
    $dimensions->setWidth($length);
    $dimensions->setLength($width);
    $unit = $this->getUnitOfMeasure($unit);
    $dimensions->setUnitOfMeasurement($this->setUnitOfMeasurement($unit));
    $ups_package->setDimensions($dimensions);
  }

  /**
   * Define the package weight.
   *
   * @param \Ups\Entity\Package $ups_package
   *   A package object from the Ups API.
   */
  public function setWeight(UPSPackage $ups_package) {
    $ups_package_weight = $ups_package->getPackageWeight();

    // UPS only takes certain weights, so we need to convert it into kg if in
    // g/oz which the Drupal physical module does.
    $weight = $this->shipment->getPackageType()->getWeight()->getNumber();
    $unit = $this->shipment->getPackageType()->getWeight()->getUnit();
    if ($unit == 'oz' || $unit == 'g') {
      $weight = $this->convertWeightToKilograms($unit, $weight);

      // Change the unit to 'kg' now.
      $unit = 'kg';
    }

    $ups_package_weight->setWeight($weight);
    $unit = $this->getUnitOfMeasure($unit);
    $ups_package_weight->setUnitOfMeasurement($this->setUnitOfMeasurement($unit));
  }

  /**
   * Converts a height/length/width dimension from m/mm/ft to centimeters.
   *
   * @param string $unit
   *   The unit we are converting from.
   * @param int $dimension
   *   The height/length/width dimension in m/mm/ft.
   *
   * @return float|int
   *   The height/length/weight in centimeters.
   */
  protected function convertDimensionToCentimeters($unit, $dimension) {
    switch ($unit) {
      // Meters.
      case 'm':
        return ($dimension * 100);

      // Inches.
      case 'mm':
        return ($dimension * 0.1);

      // Feet.
      case 'ft':
        return ($dimension * 30.48);
    }
  }

  /**
   * Converts a weight from g/oz to kilograms.
   *
   * @param string $unit
   *   The unit we are converting from.
   * @param int $weight
   *   The weight in g/oz.
   *
   * @return float|int
   *   The weight in kilograms.
   */
  protected function convertWeightToKilograms($unit, $weight) {
    switch ($unit) {
      // Ounces.
      case 'oz':
        return ($weight / 35.274);

      // Grams.
      case 'g':
        return ($weight / 1000);
    }
  }

}
