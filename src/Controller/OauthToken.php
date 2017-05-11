<?php

namespace Drupal\commerce_square\Controller;

use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Provides a controller for Square access token retrieval via OAuth.
 */
class OauthToken extends ControllerBase {

  /**
   * Provides a route for square to redirect to when obtaining the oauth token.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   */
  public function obtain(Request $request) {
    $gateway_id = explode(' ', $request->query->get('state'))[1];
    $code = $request->query->get('code');
    $options = [
      'query' => [
        'code' => $code,
      ],
    ];
    $route_parameters = ['commerce_payment_gateway' => $gateway_id];
    return new RedirectResponse(Url::fromRoute('entity.commerce_payment_gateway.edit_form', $route_parameters, $options)->toString());
  }

  /**
   * Controller access method.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   The access result.
   */
  public function obtainAccess() {
    // $request is not passed in to _custom_access.
    // @see https://www.drupal.org/node/2786941
    $token = explode(' ', \Drupal::request()->query->get('state'))[0];
    if (\Drupal::csrfToken()->validate($token)) {
      return AccessResult::allowed();
    }
    return AccessResult::forbidden($this->t('Invalid token'));
  }

}
