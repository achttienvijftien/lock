<?php
/**
 * This file contains implementation of a lock mechanism.
 *
 * @package AchttienVijftien\Lock
 */

namespace AchttienVijftien\Lock;

/**
 * Class Lock
 */
class Lock {

	/**
	 * Name of the lock.
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * Whether lock has been acquired.
	 *
	 * @var bool
	 */
	private bool $have_lock = false;

	/**
	 * Lock constructor.
	 *
	 * @param string $name         Name of the lock.
	 * @param int    $max_lock_age Max age (in seconds) after which the lock is considered dead.
	 * @param int    $max_tries    How many times should there be tried to acquire a lock.
	 */
	public function __construct( string $name, private int $max_lock_age = 60, private int $max_tries = 10 ) {
		$this->name = "achttienvijftien_importer_lock_$name";
	}

	/**
	 * Lock destructor.
	 */
	public function __destruct() {
		$this->release();
	}

	/**
	 * Tries to acquire the lock.
	 *
	 * @param int $wait Time in seconds to keep trying to acquire the lock.
	 *
	 * @return bool
	 */
	public function acquire( int $wait = 60 ): bool {
		global $wpdb;

		$suppress_errors = $wpdb->suppress_errors();

		$time_wait  = 1;
		$acquired   = false;
		$time_start = time();
		$tries      = 0;

		do {
			$now = $tries > 0 ? time() : $time_start;

			$lock_time = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_value FROM $wpdb->options WHERE option_name = %s",
					$this->name
				)
			);

			$lock_age = $lock_time ? $now - $lock_time : null;

			if ( null !== $lock_age && $lock_age > $this->max_lock_age ) {
				$this->release( $lock_time, true );
			}

			$lock_result = $wpdb->insert(
				$wpdb->options,
				[
					'option_name'  => $this->name,
					'option_value' => time(),
					'autoload'     => 'no',
				]
			);

			if ( false !== $lock_result ) {
				$acquired = true;
				break;
			}

			if ( ( $now + $time_wait ) - $time_start >= $wait ) {
				break;
			}

			sleep( $time_wait );

			$time_wait *= 2;
			$tries ++;
		} while ( ( $now - $time_start < $wait ) && $tries < $this->max_tries );

		$wpdb->suppress_errors( $suppress_errors );

		$this->have_lock = $acquired;

		return $acquired;
	}

	/**
	 * Releases the lock.
	 *
	 * @param int|null $lock_time If given, lock will only be released if its timestamp matches.
	 * @param bool     $force     Force release when locked.
	 *
	 * @return void
	 */
	public function release( int $lock_time = null, bool $force = false ): void {
		global $wpdb;

		if ( $this->have_lock || $force ) {
			$where = [ 'option_name' => $this->name ];

			if ( $lock_time ) {
				$where['option_value'] = (string) $lock_time;
			}

			$wpdb->delete( $wpdb->options, $where );

			$this->have_lock = false;
		}
	}
}
