<?php
require __DIR__ . '/vendor/autoload.php';

// Load environment variables from .env file
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$stripe = new \Stripe\StripeClient([
  "api_key" => $_ENV['STRIPE_SECRET_KEY'],
]);

session_start();

// Set CORS headers for API endpoints
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
  http_response_code(200);
  exit;
}

// Create a sample product and return a price for it
if ($_SERVER['REQUEST_URI'] === '/api/create-product' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $data = parseRequestBody();
  $productName = $data['productName'];
  $productDescription = $data['productDescription'];
  $productPrice = $data['productPrice'];
  $accountId = $data['accountId'];

  try {
    // Create the product on the connected account
    $product = $stripe->products->create([
      'name' => $productName,
      'description' => $productDescription,
    ], ['stripe_account' => $accountId]);

    // Create a price for the product on the connected account
    $price = $stripe->prices->create([
      'product' => $product->id,
      'unit_amount' => $productPrice,
      'currency' => 'usd',
    ], ['stripe_account' => $accountId]);

    echo json_encode([
      'productName' => $productName,
      'productDescription' => $productDescription,
      'productPrice' => $productPrice,
      'priceId' => $price->id,
    ]);
  } catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
  }
  exit;
}

// Create a Connected Account
if ($_SERVER['REQUEST_URI'] === '/api/create-connect-account' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $data = parseRequestBody();

  try {
    $account = $stripe->v2->core->accounts->create([
      'display_name' => $data['email'],
      'contact_email' => $data['email'],
      'dashboard' => 'full',
      'defaults' => [
        'responsibilities' => [
          'fees_collector' => 'stripe',
          'losses_collector' => 'stripe',
        ],
      ],
      'identity' => [
        'country' => 'US',
        'entity_type' => 'company',
      ],
      'configuration' => [
        'merchant' => [
          'capabilities' => [
            'card_payments' => ['requested' => true],
          ],
        ],
      ],
    ]);

    echo json_encode(['accountId' => $account->id]);
  } catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
  }
  exit;
}

// Create Account Link for onboarding
if ($_SERVER['REQUEST_URI'] === '/api/create-account-link' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $data = parseRequestBody();
  $accountId = $data['accountId'];

  try {
    $accountLink = $stripe->v2->core->accountLinks->create([
      'account' => $accountId,
      'use_case' => [
        'type' => 'account_onboarding',
        'account_onboarding' => [
          'configurations' => ['merchant'
],
          'refresh_url' => 'https://example.com',
          'return_url' => 'https://example.com?accountId=' . $accountId,
        ],
      ],
    ]);

    echo json_encode(['url' => $accountLink->url]);
  } catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
  }
  exit;
}

// Get Connected Account Status
if (preg_match('/^\/api\/account-status\/(.+)$/', $_SERVER['REQUEST_URI'], $matches) && $_SERVER['REQUEST_METHOD'] === 'GET') {
  $accountId = $matches[1];

  try {
    $account = $stripe->v2->core->accounts->retrieve($accountId, [
      'include' => ['requirements', 'configuration.merchant']
    ]);

    $payoutsEnabled = ($account->configuration->merchant->capabilities->stripe_balance->payouts->status ?? null) === 'active';
    $chargesEnabled = ($account->configuration->merchant->capabilities->card_payments->status ?? null) === 'active';
    $summaryStatus = $account->requirements->summary->minimum_deadline->status ?? null;
    $detailsSubmitted = !$summaryStatus || $summaryStatus === 'eventually_due';

    echo json_encode([
      'id' => $account->id,
      'payoutsEnabled' => $payoutsEnabled,
      'chargesEnabled' => $chargesEnabled,
      'detailsSubmitted' => $detailsSubmitted,
      'requirements' => $account->requirements->entries ?? null,
    ]);
  } catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
  }
  exit;
}

// Fetch products for a specific account
if (preg_match('/^\/api\/products\/(.+)$/', $_SERVER['REQUEST_URI'], $matches) && $_SERVER['REQUEST_METHOD'] === 'GET') {
  $accountId = $matches[1];

  try {
    $options = [];
    if ($accountId !== 'platform') {
      $options['stripe_account'] = $accountId;
    }

    $prices = $stripe->prices->all([
      'expand' => ['data.product'],
      'active' => true,
      'limit' => 100,
    ], $options);

    $products = [];
    foreach ($prices->data as $price) {
      $products[] = [
        'id' => $price->product->id,
        'name' => $price->product->name,
        'description' => $price->product->description,
        'price' => $price->unit_amount,
        'priceId' => $price->id,
        'image' => 'https://i.imgur.com/6Mvijcm.png',
      ];
    }

    echo json_encode($products);
  } catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
  }
  exit;
}

// Create checkout session
if ($_SERVER['REQUEST_URI'] === '/api/create-checkout-session' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $data = parseRequestBody();
  $accountId = $data['accountId'];
  $priceId = $data['priceId'];
  // Get the price's type from Stripe
  $price = $stripe->prices->retrieve($priceId, [], ['stripe_account' => $accountId]);
  $priceType = $price->type;
  $mode = $priceType === 'recurring' ? 'subscription' : 'payment';

  $checkout_params = [
    'line_items' => [[
      'price' => $priceId,
      'quantity' => 1,
    ]],
    'mode' => $mode,
    // Defines where Stripe will redirect a customer after successful payment
    'success_url' => $_ENV['DOMAIN'] . '/done?session_id={CHECKOUT_SESSION_ID}',
    // Defines where Stripe will redirect if a customer cancels payment
    'cancel_url' => $_ENV['DOMAIN'],
  ];

  // Add Connect-specific parameters based on payment mode

  $checkout_session = $stripe->checkout->sessions->create(
    $checkout_params,
    ['stripe_account' => $accountId]
  );

  // Redirect to the Stripe hosted checkout URL
  header('Location: ' . $checkout_session->url, true, 303);
  exit;
}



if ($_SERVER['REQUEST_URI'] === '/api/webhook' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $payload = @file_get_contents('php://input');
  $event = null;

  // Replace this endpoint secret with your endpoint's unique secret
  // If you are testing with the CLI, find the secret by running 'stripe listen'
  // If you are using an endpoint defined with the API or dashboard, look in your webhook settings
  // at https://dashboard.stripe.com/webhooks
  $endpoint_secret = '';

  // Only verify the event if you have an endpoint secret defined.
  // Otherwise use the basic event deserialized with json_decode
  if ($endpoint_secret) {
    try {
      $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
      $event = $stripe->webhooks->constructEvent(
        $payload, $sig_header, $endpoint_secret
      );
    } catch(\UnexpectedValueException $e) {
      http_response_code(400);
      exit();
    } catch(\Stripe\Exception\SignatureVerificationException $e) {
      http_response_code(400);
      exit();
    }
  } else {
    $event = json_decode($payload);
  }

  $stripeObject = null;
  $status = null;

  // Handle the event
  switch ($event->type) {
    case 'checkout.session.completed':
      $stripeObject = $event->data->object;
      $status = $stripeObject->status;
      error_log("Checkout Session status is " . $status);
      // Then define and call a method to handle the checkout session completed.
      // handleCheckoutSessionCompleted($stripeObject);
      break;
    case 'checkout.session.async_payment_failed':
      $stripeObject = $event->data->object;
      $status = $stripeObject->status;
      error_log("Checkout Session status is " . $status);
      // Then define and call a method to handle the checkout session failed.
      // handleCheckoutSessionFailed($stripeObject);
      break;
    default:
      error_log('Unhandled event type ' . $event->type);
  }

  // Return a 200 response to acknowledge receipt of the event
  http_response_code(200);
  exit();
}
if ($_SERVER['REQUEST_URI'] === '/api/thin-webhook' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $payload = @file_get_contents('php://input');
  $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

  // Replace this endpoint secret with your endpoint's unique secret
  // If you are testing with the CLI, find the secret by running 'stripe listen'
  // If you are using an endpoint defined with the API or dashboard, look in your webhook settings
  // at https://dashboard.stripe.com/webhooks
  $thin_endpoint_secret = '';
  try {
    $event_notification = $stripe->parseEventNotification($payload, $sig_header, $thin_endpoint_secret);
  } catch (\Exception $e) {
    error_log('Error parsing event notification: ' . $e->getMessage());
    http_response_code(400);
    exit();
  }

  if ($event_notification->type === 'v2.account.created') {
    $event_notification->fetchRelatedObject();
    $event_notification->fetchEvent();
  } else {
    error_log('Unhandled event type ' . $event_notification->type);
  }

  http_response_code(200);
  exit();
}


// Helper method to parse request body (JSON or form data)
function parseRequestBody() {
  if (!empty($_POST)) {
    return $_POST;
  }

  $input = file_get_contents('php://input');
  $jsonData = json_decode($input, true);

  return $jsonData ?: [];
}