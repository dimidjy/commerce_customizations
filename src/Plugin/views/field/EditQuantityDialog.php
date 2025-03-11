<?php

namespace Drupal\app_core\Plugin\views\field;

use Drupal\commerce_cart\CartManagerInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\OpenModalDialogCommand;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Url;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\Plugin\views\field\UncacheableFieldHandlerTrait;
use Drupal\views\ResultRow;
use Psy\Util\Json;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a form element for editing the order item quantity.
 *
 * @ViewsField("commerce_order_item_edit_quantity_dialog")
 */
class EditQuantityDialog extends FieldPluginBase {

  use UncacheableFieldHandlerTrait;

  /**
   * The cart manager.
   *
   * @var \Drupal\commerce_cart\CartManagerInterface
   */
  protected $cartManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The form builder.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;

  /**
   * Constructs a new EditQuantity object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\commerce_cart\CartManagerInterface $cart_manager
   *   The cart manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   * @param \Drupal\Core\Form\FormBuilderInterface $form_builder
   *   The form builder.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, CartManagerInterface $cart_manager, EntityTypeManagerInterface $entity_type_manager, MessengerInterface $messenger, FormBuilderInterface $form_builder) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->cartManager = $cart_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->messenger = $messenger;
    $this->formBuilder = $form_builder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('commerce_cart.cart_manager'),
      $container->get('entity_type.manager'),
      $container->get('messenger'),
      $container->get('form_builder')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function clickSortable() {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['allow_decimal'] = ['default' => FALSE];

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    $form['allow_decimal'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow decimal quantities'),
      '#default_value' => $this->options['allow_decimal'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getValue(ResultRow $row, $field = NULL) {
    return '<!--form-item-' . $this->options['id'] . '--' . $row->index . '-->';
  }

  /**
   * Form constructor for the views form.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function viewsForm(array &$form, FormStateInterface $form_state) {
    // Make sure we do not accidentally cache this form.
    $form['#cache']['max-age'] = 0;
    // The view is empty, abort.
    if (empty($this->view->result)) {
      unset($form['actions']);
      return;
    }

    $form['#attached'] = [
      'library' => ['commerce_cart/cart_form'],
    ];

    $form[$this->options['id']]['#tree'] = TRUE;
    foreach ($this->view->result as $row_index => $row) {
      /** @var \Drupal\commerce_order\Entity\OrderItemInterface $order_item */
      $order_item = $this->getEntity($row);
      if ($this->options['allow_decimal']) {
        $form_display = commerce_get_entity_display('commerce_order_item', $order_item->bundle(), 'form');
        $quantity_component = $form_display->getComponent('quantity');
        $step = $quantity_component['settings']['step'];
        $precision = $step >= '1' ? 0 : strlen($step) - 2;
      }
      else {
        $step = 1;
        $precision = 0;
      }

      if (!$order_item->isLocked()) {
        $form[$this->options['id']][$row_index] = [
          '#type' => 'link',
          '#title' => $this->t('Change quantity'),
          '#url' => Url::fromRoute('app_core.quantity_edit', ['order_item' => $order_item->id()]),
          '#options' => [
            'attributes' => [
              'class' => ['use-ajax', 'button'],
              'data-dialog-type' => 'modal',
              'data-dialog-options' => Json::encode([
                'width' => 700,
              ]),
            ],
          ],
        ];

        // Dialog box dependency.
        $form['#attached']['library'][] = 'core/drupal.dialog.ajax';
      }
      else {
        $form[$this->options['id']][$row_index] = [
          '#type' => 'item',
          '#plain_text' => round($order_item->getQuantity(), $precision),
        ];
      }
    }

    $form['actions']['submit']['#update_cart'] = TRUE;
    $form['actions']['submit']['#show_update_message'] = TRUE;
    // Replace the form submit button label.
    $form['actions']['submit']['#value'] = $this->t('Update cart');
  }

  /**
   * Callback for opening the modal form.
   */
  public function openQuantityDialog(array $form, FormStateInterface $form_state) {
    $response = new AjaxResponse();

    // Get the modal form using the form builder.
    $modal_form = $this->formBuilder->getForm('Drupal\app_core\Form\EditQuantityForm');

    // Add an AJAX command to open a modal dialog with the form as the content.
    $dialog_options = [
      'width' => 'calc(100% - 20px)',
      'dialogClass' => 'quantity-modal',
    ];
    $response->addCommand(new OpenModalDialogCommand($this->t('Test'), $modal_form, $dialog_options));

    return $response;
  }

  /**
   * Validate handler for the views form.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function viewsFormValidate(array &$form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    if (empty($triggering_element['#update_cart'])) {
      // Don't run when the "Remove" or "Empty cart" buttons are pressed.
      return;
    }
    $quantities = $form_state->getValue($this->options['id'], []);
    foreach ($quantities as $row_index => $quantity) {
      if (!is_numeric($quantity) || $quantity <= 0) {
        continue;
      }
      /** @var \Drupal\commerce_order\Entity\OrderItemInterface $order_item */
      $order_item = $this->getEntity($this->view->result[$row_index]);
      if ($order_item->getQuantity() == $quantity) {
        // The quantity hasn't changed.
        continue;
      }
      // Save the original quantity so it can be restored after validation.
      $original_quantity = $order_item->getQuantity();
      // Set the new quantity so the right quantity is validated.
      $order_item->setQuantity($quantity);
      $violations = $order_item
        ->validate()
        ->filterByFields(array_diff(array_keys($order_item->getFieldDefinitions()), [
          'purchased_entity',
          'quantity',
        ]));

      foreach ($violations as $violation) {
        /** @var \Symfony\Component\Validator\ConstraintViolationInterface $violation */
        $form_state->setError($form[$this->options['id']][$row_index], $violation->getMessage());
      }

      // Restore the quantity so the logic in viewsFormSubmit() proceeds with
      // the quantity update.
      $order_item->setQuantity($original_quantity);
    }
  }

  /**
   * Submit handler for the views form.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function viewsFormSubmit(array &$form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    if (empty($triggering_element['#update_cart'])) {
      // Don't run when the "Remove" or "Empty cart" buttons are pressed.
      return;
    }

    $order_storage = $this->entityTypeManager->getStorage('commerce_order');
    /** @var \Drupal\commerce_order\Entity\OrderInterface $cart */
    $cart = $order_storage->load($this->view->argument['order_id']->getValue());
    $quantities = $form_state->getValue($this->options['id'], []);
    $save_cart = FALSE;
    foreach ($quantities as $row_index => $quantity) {
      if (!is_numeric($quantity) || $quantity < 0) {
        // The input might be invalid if the #required or #min attributes
        // were removed by an alter hook.
        continue;
      }
      /** @var \Drupal\commerce_order\Entity\OrderItemInterface $order_item */
      $order_item = $this->getEntity($this->view->result[$row_index]);
      if ($order_item->getQuantity() == $quantity) {
        // The quantity hasn't changed.
        continue;
      }

      if ($quantity > 0) {
        $order_item->setQuantity($quantity);
        $this->cartManager->updateOrderItem($cart, $order_item, FALSE);
      }
      else {
        // Treat quantity "0" as a request for deletion.
        $this->cartManager->removeOrderItem($cart, $order_item, FALSE);
      }
      $save_cart = TRUE;
    }

    if ($save_cart) {
      $cart->save();
      if (!empty($triggering_element['#show_update_message'])) {
        $this->messenger->addMessage($this->t('Your shopping cart has been updated.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    // Do nothing.
  }

}
