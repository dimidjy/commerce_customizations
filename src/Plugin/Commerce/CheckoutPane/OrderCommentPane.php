<?php

namespace Drupal\app_core\Plugin\Commerce\CheckoutPane;

use Drupal\commerce_checkout\Plugin\Commerce\CheckoutPane\CheckoutPaneBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a custom message pane.
 *
 * @CommerceCheckoutPane(
 *   id = "order_comment_pane",
 *   label = @Translation("Order comment pane"),
 * )
 */
class OrderCommentPane extends CheckoutPaneBase {

  /**
   * {@inheritdoc}
   */
  public function buildPaneForm(array $pane_form, FormStateInterface $form_state, array &$complete_form) {
    $comment = $this->order->getData('order_comment');
    $pane_form['comment'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Order comment'),
      '#default_value' => $comment ? $comment : '',
      '#size' => 60,
    ];
    return $pane_form;
  }

  /**
   * {@inheritdoc}
   */
  public function buildPaneSummary() {
    if ($order_comment = $this->order->getData('order_comment')) {
      return [
        '#plain_text' => $order_comment,
      ];
    }
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function submitPaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form) {
    $values = $form_state->getValue($pane_form['#parents']);
    $this->order->set('field_order_comment', $values['comment']);
    $this->order->save();
  }

}
