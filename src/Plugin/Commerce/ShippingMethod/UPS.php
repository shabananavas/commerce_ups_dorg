<?php

namespace Drupal\commerce_ups\Plugin\Commerce\ShippingMethod;

use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\commerce_shipping\PackageTypeManagerInterface;
use Drupal\commerce_shipping\Plugin\Commerce\ShippingMethod\ShippingMethodBase;
use Drupal\commerce_ups\UPSRequestInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use function substr;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Creates a UPS shipping method.
 *
 * @todo: Fix the annotation bug so we don't have to add a underscore in the
 * services keys.
 *
 * @CommerceShippingMethod(
 *  id = "ups",
 *  label = @Translation("UPS"),
 *  services = {
 *   "_01" = @translation("UPS Next Day Air"),
 *   "_02" = @translation("UPS Second Day Air"),
 *   "_03" = @translation("UPS Ground"),
 *   "_07" = @translation("UPS Worldwide Express"),
 *   "_08" = @translation("UPS Worldwide Expedited"),
 *   "_11" = @translation("UPS Standard"),
 *   "_12" = @translation("UPS Three-Day Select"),
 *   "_13" = @translation("Next Day Air Saver"),
 *   "_14" = @translation("UPS Next Day Air Early AM"),
 *   "_54" = @translation("UPS Worldwide Express Plus"),
 *   "_59" = @translation("UPS Second Day Air AM"),
 *   "_65" = @translation("UPS Saver"),
 *   "_70" = @translation("UPS Access Point Economy"),
 *   },
 * )
 */
class UPS extends ShippingMethodBase {

  /**
   * The UPSRateRequest class.
   *
   * @var \Drupal\commerce_ups\UPSRateRequest
   */
  protected $upsRateService;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new ShippingMethodBase object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\commerce_shipping\PackageTypeManagerInterface $packageTypeManager
   *   The package type manager.
   * @param \Drupal\commerce_ups\UPSRequestInterface $ups_rate_request
   *   The rate request service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    PackageTypeManagerInterface $packageTypeManager,
    UPSRequestInterface $ups_rate_request,
    EntityTypeManagerInterface $entity_type_manager
  ) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $packageTypeManager
    );

    $this->upsRateService = $ups_rate_request;
    $this->upsRateService->setConfig($configuration);

    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('plugin.manager.commerce_package_type'),
      $container->get('commerce_ups.ups_rate_request'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'api_information' => [
        'access_key' => '',
        'user_id' => '',
        'password' => '',
        'mode' => 'test',
      ],
      'rate_options' => [
        'rate_type' => 0,
      ] ,
      'options' => [
        'log' => [],
      ],
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    // Select all services by default.
    if (empty($this->configuration['services'])) {
      $service_ids = array_keys($this->services);
      $this->configuration['services'] = array_combine($service_ids, $service_ids);
    }

    $form['api_information'] = [
      '#type' => 'details',
      '#title' => $this->t('API information'),
      '#description' => $this->isConfigured()
      ? $this->t('Update your UPS API information.')
      : $this->t('Fill in your UPS API information.'),
      '#weight' => $this->isConfigured() ? 10 : -10,
      '#open' => !$this->isConfigured(),
    ];

    $form['api_information']['access_key'] = [
      '#type' => 'textfield',
      '#title' => t('Access Key'),
      '#default_value' => $this->configuration['api_information']['access_key'],
      '#required' => TRUE,
    ];
    $form['api_information']['user_id'] = [
      '#type' => 'textfield',
      '#title' => t('User ID'),
      '#default_value' => $this->configuration['api_information']['user_id'],
      '#required' => TRUE,
    ];

    $form['api_information']['password'] = [
      '#type' => 'textfield',
      '#title' => t('Password'),
      '#default_value' => $this->configuration['api_information']['password'],
      '#required' => TRUE,
    ];

    $form['api_information']['mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Mode'),
      '#description' => $this->t('Choose whether to use the test or live mode.'),
      '#options' => [
        'test' => $this->t('Test'),
        'live' => $this->t('Live'),
      ],
      '#default_value' => $this->configuration['api_information']['mode'],
    ];

    $form['rate_options'] = [
      '#type' => 'details',
      '#title' => $this->t('Rate options'),
      '#description' => $this->t('Options to pass during rate requests.'),
    ];

    $form['rate_options']['rate_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Rate Type'),
      '#description' => $this->t('Choose between negotiated and standard rates.'),
      '#options' => [
        0 => $this->t('Standard Rates'),
        1 => $this->t('Negotiated Rates'),
      ],
      '#default_value' => $this->configuration['rate_options']['rate_type'],
    ];

    $form['options'] = [
      '#type' => 'details',
      '#title' => $this->t('UPS Options'),
      '#description' => $this->t('Additional options for UPS'),
    ];
    $form['options']['log'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Log the following messages for debugging'),
      '#options' => [
        'request' => $this->t('API request messages'),
        'response' => $this->t('API response messages'),
      ],
      '#default_value' => $this->configuration['options']['log'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);

    // Ensure the package type selected for this method doesn't have an empty
    // weight as the UPS API will throw an error when calculating rates.
    $values = $form_state->getValue($form['#parents']);
    $package_type = $this->entityTypeManager->getStorage('commerce_package_type')->loadByProperties([
      'uuid' => substr($values['default_package_type'], 22)
    ]);
    /** @var \Drupal\commerce_shipping\Entity\PackageTypeInterface $package_type */
    $package_type = reset($package_type);

    // Validate the weight.
    $actual_weight = $package_type->getWeight();
    if ($actual_weight['number'] == 0) {
      $form_state->setError($form['default_package_type'], $this->t(
        'The weight for a package type cannot be 0 for UPS.
         Please select another package or change the weight for this package and
          resave.'
      ));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);

      $this->configuration['api_information']['access_key'] = $values['api_information']['access_key'];
      $this->configuration['api_information']['user_id'] = $values['api_information']['user_id'];
      $this->configuration['api_information']['password'] = $values['api_information']['password'];
      $this->configuration['api_information']['mode'] = $values['api_information']['mode'];
      $this->configuration['rate_options']['rate_type'] = $values['rate_options']['rate_type'];
      $this->configuration['options']['log'] = $values['options']['log'];

    }
    parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * Calculates rates for the given shipment.
   *
   * @param \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment
   *   The shipment.
   *
   * @return \Drupal\commerce_shipping\ShippingRate[]
   *   The rates.
   */
  public function calculateRates(ShipmentInterface $shipment) {
    // Only attempt to collect rates if an address exists on the shipment.
    if ($shipment->getShippingProfile()->get('address')->isEmpty()) {
      return [];
    }

    $this->upsRateService->setShipment($shipment);

    return $this->upsRateService->getRates();
  }

  /**
   * Determine if we have the minimum information to connect to UPS.
   *
   * @return bool
   *   TRUE if there is enough information to connect, FALSE otherwise.
   */
  protected function isConfigured() {
    $api_information = $this->configuration['api_information'];

    return (
      !empty($api_information['access_key'])
      && !empty($api_information['user_id'])
      && !empty($api_information['password'])
    );
  }

}
