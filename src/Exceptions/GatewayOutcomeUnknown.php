<?php

declare(strict_types=1);

namespace Ivanange\Billing\Exceptions;

/**
 * The request may have reached the provider, but no readable answer came
 * back (timeout, dropped connection, 5xx, unreadable body). Whether it
 * took effect is unknown, so it must never be read as a refusal.
 */
class GatewayOutcomeUnknown extends GatewayError {}
