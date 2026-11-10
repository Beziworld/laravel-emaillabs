<?php

declare(strict_types=1);

namespace Beziworld\EmailLabs\Exceptions;

use RuntimeException;

/**
 * Base exception of the package; every exception thrown by the package extends it.
 */
abstract class EmailLabsException extends RuntimeException {}
