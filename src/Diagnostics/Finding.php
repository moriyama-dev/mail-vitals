<?php
/**
 * A single diagnostic result.
 *
 * @package MailVitals
 */

namespace MailVitals\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One thing the plugin checked, and what it concluded.
 *
 * Kept independent of how the result is displayed, so the same finding can be
 * rendered into Site Health, returned from an ability, or written to a log.
 */
final class Finding {

	/**
	 * Nothing to do.
	 *
	 * @var string
	 */
	const STATUS_GOOD = 'good';

	/**
	 * Works, but something is worth improving.
	 *
	 * @var string
	 */
	const STATUS_WARNING = 'warning';

	/**
	 * Actively causing mail to be rejected or filtered.
	 *
	 * @var string
	 */
	const STATUS_CRITICAL = 'critical';

	/**
	 * The check could not run, so its outcome is unknown.
	 *
	 * @var string
	 */
	const STATUS_UNKNOWN = 'unknown';

	/**
	 * Stable machine-readable identifier for the check.
	 *
	 * @var string
	 */
	private $id;

	/**
	 * Short human-readable name of the check.
	 *
	 * @var string
	 */
	private $label;

	/**
	 * One of the STATUS_* constants.
	 *
	 * @var string
	 */
	private $status;

	/**
	 * Sentence explaining the conclusion.
	 *
	 * @var string
	 */
	private $summary;

	/**
	 * Supporting values, such as the record that was found.
	 *
	 * @var array
	 */
	private $detail;

	/**
	 * Build a finding.
	 *
	 * @param string $id      Stable machine-readable identifier.
	 * @param string $label   Short human-readable name.
	 * @param string $status  One of the STATUS_* constants.
	 * @param string $summary Sentence explaining the conclusion.
	 * @param array  $detail  Optional supporting values.
	 */
	public function __construct( $id, $label, $status, $summary, array $detail = array() ) {
		$this->id      = $id;
		$this->label   = $label;
		$this->status  = $status;
		$this->summary = $summary;
		$this->detail  = $detail;
	}

	/**
	 * Stable machine-readable identifier for the check.
	 *
	 * @return string
	 */
	public function id() {
		return $this->id;
	}

	/**
	 * Short human-readable name of the check.
	 *
	 * @return string
	 */
	public function label() {
		return $this->label;
	}

	/**
	 * One of the STATUS_* constants.
	 *
	 * @return string
	 */
	public function status() {
		return $this->status;
	}

	/**
	 * Sentence explaining the conclusion.
	 *
	 * @return string
	 */
	public function summary() {
		return $this->summary;
	}

	/**
	 * Supporting values, such as the record that was found.
	 *
	 * @return array
	 */
	public function detail() {
		return $this->detail;
	}

	/**
	 * Whether this finding describes a problem worth acting on.
	 *
	 * @return bool
	 */
	public function is_problem() {
		return self::STATUS_WARNING === $this->status || self::STATUS_CRITICAL === $this->status;
	}

	/**
	 * Flatten the finding for transport, storage, or an ability response.
	 *
	 * @return array
	 */
	public function to_array() {
		return array(
			'id'      => $this->id,
			'label'   => $this->label,
			'status'  => $this->status,
			'summary' => $this->summary,
			'detail'  => $this->detail,
		);
	}
}
