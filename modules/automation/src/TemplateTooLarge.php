<?php
/**
 * Template size failure.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation;

use RuntimeException;

/**
 * Thrown by Templates::render() when a template with an `{{#each}}` block renders past Templates::MAX_BYTES.
 * The step that rendered it fails with `template_too_large` (no retry).
 */
final class TemplateTooLarge extends RuntimeException {
}
