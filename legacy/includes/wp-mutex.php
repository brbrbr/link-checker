<?php

if ( ! class_exists( 'WPMutex' ) ) :

	class WPMutex {
		/**
		 * Get an exclusive named lock.
		 *
		 * @param string $name
		 * @param integer $timeout
		 * @param bool $network_wide
		 * @return bool
		 */
		static function acquire( $name, $timeout = 0, $dbWide = true ) {
			global $wpdb; /* @var wpdb $wpdb */
			$dbWide = apply_filters('broken-link-checker-acquire-lock', $dbWide,$name);
			if ( ! $dbWide ) {
				$name = self::get_private_name( $name );
			}
			$state = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, $timeout ) );
			return 1 == $state;
		}

		/**
		 * Release a named lock.
		 *
		 * @param string $name
		 * @param bool $network_wide
		 * @return bool
		 */
		static function release( $name, $network_wide = false ) {
			global $wpdb; /* @var wpdb $wpdb */
			if ( ! $network_wide ) {
				$name = self::get_private_name( $name );
			}
			$released = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
			return 1 == $released;
		}

		/**
		 * Given a generic lock name, create a new one that's unique to the current blog.
		 *
		 * @access private
		 *
		 * @param string $name
		 * @return string
		 */
		private static function get_private_name( $name ) {
			return $name . home_url();
		}
	}

endif;

