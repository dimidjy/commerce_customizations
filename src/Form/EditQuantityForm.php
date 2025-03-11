<?php

namespace Drupal\app_core\Form;

use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\commerce_cart\CartManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\RendererInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;


class EditQuantityForm extends FormBase {

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
     * Renderer.
     *
     * @var \Drupal\Core\Render\RendererInterface
     */
    protected $renderer;
  
    /**
     * Constructs a new EditQuantity object.
     *
     * @param \Drupal\commerce_cart\CartManagerInterface $cart_manager
     *   The cart manager.
     * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
     *   The entity type manager.
     * @param \Drupal\Core\Messenger\MessengerInterface $messenger
     *   The messenger.
     */
    public function __construct(CartManagerInterface $cart_manager, EntityTypeManagerInterface $entity_type_manager, MessengerInterface $messenger, RendererInterface $renderer) {
      $this->cartManager = $cart_manager;
      $this->entityTypeManager = $entity_type_manager;
      $this->messenger = $messenger;
      $this->renderer = $renderer;
    }
  
    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container) {
      return new static(
        $container->get('commerce_cart.cart_manager'),
        $container->get('entity_type.manager'),
        $container->get('messenger'),
        $container->get('renderer')
      );
    }


    public function getFormId() {
        return "edit_quantity_form";
    }

    public function buildForm(array $form, FormStateInterface $form_state, $order_item = NULL) {
      $order_item_entity = $this->entityTypeManager->getStorage('commerce_order_item')->load($order_item);

      $form['system_messages'] = [
        '#markup' => '<div id="quantity-form-system-messages"></div>',
        '#weight' => -101,
      ];

      $form['order_item'] = [
        '#type' => 'hidden',
        '#value' => $order_item
      ];
      
      $form['quantity'] = [
        '#type' => 'number',
        '#title' => $this->t('Quantity'),
        '#title_display' => 'invisible',
        '#default_value' => round($order_item_entity->getQuantity()),
        '#size' => 4,
        '#min' => 0,
        '#max' => 999999999,
        '#step' => 1,
        '#required' => TRUE,
        '#attributes' => [
          'class' => [
            'quantity-edit-input',
          ],
        ],
      ];

      $form['submit'] = [
          '#type' => 'submit',
          '#value' => 'Save',
          '#ajax' => [
              'callback' => '::setAjaxSubmit',
          ],
      ];

      return $form;
    }

    public function setAjaxSubmit(array $form, FormStateInterface $form_state) {
      $response = new AjaxResponse();

      $message = [
        '#theme' => 'status_messages',
        '#message_list' => $this->messenger()->all(),
        '#status_headings' => [
          'status' => $this->t('Status message'),
          'error' => $this->t('Error message'),
          'warning' => $this->t('Warning message'),
        ],
      ];
  
      $errors = $this->messenger()->messagesByType('error');
      if (empty($errors)) {
        /* @var CartProviderInterface $cpi */
        $cartProvider = \Drupal::service('commerce_cart.cart_provider');
        /** @var \Drupal\commerce_order\Entity\OrderInterface[] $carts */
        $carts = $cartProvider->getCarts();
        $carts = array_filter($carts, function ($cart) {
          /** @var \Drupal\commerce_order\Entity\OrderInterface $cart */
          // There is a chance the cart may have converted from a draft order, but
          // is still in session. Such as just completing check out. So we verify
          // that the cart is still a cart.
          return $cart->cart->value;
        });

        $current_order_item_id = $form_state->getValue('order_item');
        $current_order_item = $this->entityTypeManager->getStorage('commerce_order_item')->load($current_order_item_id);
        $quantity = $form_state->getValue('quantity');

        $order = reset($carts);

        $current_order_item->setQuantity($quantity);
        $current_order_item->save();
        $order_item_total = $current_order_item->getTotalPrice();
        
        $order->recalculateTotalPrice();

        $order_total = $order->get('total_price')->view([
          'label' => 'hidden',
          'type' => 'commerce_order_total_summary',
        ]);
        $order_total['#prefix'] = '<div data-drupal-selector="order-total-summary">';
        $order_total['#suffix'] = '</div>';
    
        $rendered_total =  \Drupal::service('renderer')->render($order_total);
    
        if (isset($order_total)) {
          $response->addCommand(new ReplaceCommand('[data-drupal-selector="order-total-summary"]', $rendered_total));
        }
        if (isset($order_item_total)) {
          $response->addCommand(new HtmlCommand('.order-item-id-' . $current_order_item_id . ' .views-field-quantity', $quantity));
          $response->addCommand(new HtmlCommand('.order-item-id-' . $current_order_item_id . ' .views-field-total-price__number', $order_item_total->__toString()));
        }
      }

      $messages = $this->renderer->render($message);
      $response->addCommand(new HtmlCommand('#quantity-form-system-messages', $messages));
      $this->messenger()->deleteAll();
            
      return $response;
    }

    public function validateForm(array &$form, FormStateInterface $form_state) {
      $edit_value = $form_state->getValue('quantity');
      if (empty($edit_value) || $edit_value < 1) {
        $form_state->setErrorByName('quantity', $this->t('Quantity can\'t be less than one.'));
      }
    }

    public function submitForm(array &$form, FormStateInterface $form_state) {
      $this->messenger()->addMessage('Quantity was changed.');
    }
}