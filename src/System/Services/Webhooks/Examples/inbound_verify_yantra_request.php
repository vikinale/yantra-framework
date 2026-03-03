<?php
declare(strict_types=1);

use System\Services\\Webhooks\Security\WebhookVerifier;
use System\Services\\Webhooks\Security\SignatureConfig;

// $request is System\Http\Request
$endpointId = 'partner-abc';

$verifier = new WebhookVerifier(
  secretProvider: fn(string $endpointId) => 'my_shared_secret',
  config: SignatureConfig::defaults()->withToleranceSeconds(300),
  replayProtector: null
);

$rawBody = (string)$request->getPsrRequest()->getBody();

$verifier->verify(
  endpointId: $endpointId,
  headers: $request->headers(),
  rawBody: $rawBody
);

// If we reach here, signature ok
