<?php

declare(strict_types=1);

namespace Ivanange\Billing\Contracts;

/**
 * Marker: a person records these payments and (with maker-checker on)
 * another person confirms them. No money moves through the app.
 */
interface ManualConfirmation {}
