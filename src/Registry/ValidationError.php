<?php
/**
 * One schema validation error.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Registry;

/**
 * An error with its machine code, readable message, source file and JSON pointer.
 */
final class ValidationError {

	/**
	 * Creates the error.
	 *
	 * @param string $code    Stable machine-readable code, such as `invalid_key`.
	 * @param string $message Readable, translated message.
	 * @param string $file    Source file path, or `option:pegwork_schema` for the DB source.
	 * @param string $pointer JSON pointer into the source, empty for the whole document.
	 * @param string $field   Pointer of the required value that is missing or empty, or ''. A missing
	 *                        property is reported at its object (`pointer`, as JSON Schema does);
	 *                        editors that show errors at controls use this instead.
	 */
	public function __construct(
		public readonly string $code,
		public readonly string $message,
		public readonly string $file,
		public readonly string $pointer = '',
		public readonly string $field = ''
	) {
	}

	/**
	 * Formats the error as `file#/pointer: message`.
	 */
	public function __toString(): string {
		$where = '' === $this->pointer ? $this->file : $this->file . '#' . $this->pointer;

		return $where . ': ' . $this->message;
	}
}
