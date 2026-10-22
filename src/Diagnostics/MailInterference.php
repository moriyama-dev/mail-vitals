<?php
/**
 * Notices other code that takes part in sending mail.
 *
 * @package MailVitals
 */

namespace MailVitals\Diagnostics;

use MailVitals\Mailer\Dispatcher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds out who else is involved when this site sends a message.
 *
 * The commonest way for mail to break quietly is two plugins that both want
 * to send it. One of them wins, the other's settings look perfectly fine, and
 * nothing tells the site owner which one is actually in charge.
 *
 * This does not look for known plugin names. Names change, and a plugin that
 * is not on a list is simply missed. It looks at what is in place instead:
 * whether wp_mail() is still WordPress's own, and which callbacks are attached
 * to the two hooks that decide how a message leaves, pre_wp_mail and
 * phpmailer_init. Each callback is traced to the file that defines it, and
 * that file to the plugin, theme or other code it belongs to. The plugin's
 * name is only a label; what the finding reports is which hook, at which
 * priority, from which file.
 *
 * The inspection sees the hooks as they are when it runs, before any message
 * is sent. Code that attaches its callbacks only at the moment of sending,
 * for example from inside its own wp_mail filter, is not visible to it.
 */
final class MailInterference {

	/**
	 * Hooks through which other code can take over or reshape a send.
	 *
	 * @var string[]
	 */
	const HOOKS = array( 'pre_wp_mail', 'phpmailer_init' );

	/**
	 * Callbacks WordPress itself attaches to those hooks.
	 *
	 * Only multisite does, to give each message a Message-ID on the network's
	 * domain. Matched by name rather than by file: a core helper such as
	 * __return_false attached to pre_wp_mail is in a core file too, but it was
	 * put there by some other code, and it stops every message.
	 *
	 * @var array
	 */
	const CORE_CALLBACKS = array(
		'phpmailer_init' => array( 'fix_phpmailer_messageid' ),
	);

	/**
	 * Priority Mail Vitals takes over the whole send at.
	 *
	 * @var int
	 */
	const DELIVERY_PRIORITY = 1;

	/**
	 * Priority Mail Vitals configures the mailer for SMTP at.
	 *
	 * @var int
	 */
	const SMTP_PRIORITY = 10;

	/**
	 * Examine what is attached to sending right now.
	 *
	 * @return Finding
	 */
	public static function check() {
		return self::build(
			self::wp_mail_file(),
			null !== Dispatcher::active(),
			self::foreign_callbacks()
		);
	}

	/**
	 * Turn what was found into a finding.
	 *
	 * Kept apart from reading the globals so that every combination can be
	 * tested, including a replaced wp_mail(), which cannot be arranged in a
	 * running site.
	 *
	 * @param string $wp_mail_file Absolute path of the file defining wp_mail(), or '' if unknown.
	 * @param bool   $route_active Whether a Mail Vitals route is in charge of sending.
	 * @param array  $callbacks    Callbacks from other code, as returned by foreign_callbacks().
	 * @return Finding
	 */
	public static function build( $wp_mail_file, $route_active, array $callbacks ) {
		$replaced = self::is_replaced( $wp_mail_file, ABSPATH . WPINC . '/pluggable.php' );
		$owner    = $replaced ? self::owner_of( $wp_mail_file ) : null;
		$status   = self::decide( $replaced, $route_active, $callbacks );
		$hooks    = array_unique( wp_list_pluck( $callbacks, 'hook' ) );

		$detail = array(
			'route_active' => (bool) $route_active,
			'wp_mail'      => array(
				'replaced' => $replaced,
				'file'     => '' === $wp_mail_file ? '' : self::relative( $wp_mail_file ),
				'owner'    => $owner,
			),
			'callbacks'    => array_values( $callbacks ),
		);

		$sentences = array();

		if ( $replaced ) {
			$who = sprintf(
				/* translators: 1: name of the plugin, theme or code, 2: file path. */
				__( 'wp_mail() is defined by %1$s (%2$s) instead of WordPress.', 'mail-vitals' ),
				$owner['name'],
				$owner['file']
			);

			$sentences[] = $route_active
				? $who . ' ' . __( 'A replacement does not have to run pre_wp_mail or phpmailer_init, so the route chosen in Mail Vitals may never be used, and failures may never reach the send log.', 'mail-vitals' )
				: $who . ' ' . __( 'A replacement does not have to report what happened, so messages it sends or fails to send may be missing from the send log.', 'mail-vitals' );
		}

		if ( in_array( 'pre_wp_mail', $hooks, true ) ) {
			$sentences[] = $route_active
				? sprintf(
					/* translators: %d: priority Mail Vitals takes over sending at. */
					__( 'Other code is attached to pre_wp_mail. A callback there that returns anything other than null decides what wp_mail() returns: at a priority below %d it stops the message before Mail Vitals sees it, and otherwise it also runs inside each of Mail Vitals\' own attempts, where it can take the message over in place of the route.', 'mail-vitals' ),
					self::DELIVERY_PRIORITY
				)
				: __( 'Other code is attached to pre_wp_mail. When a callback there returns anything other than null, wp_mail() stops and returns that value without reporting success or failure, so those messages do not appear in the send log.', 'mail-vitals' );
		}

		if ( in_array( 'phpmailer_init', $hooks, true ) ) {
			$sentences[] = $route_active
				? sprintf(
					/* translators: %d: priority Mail Vitals configures SMTP at. */
					__( 'Other code is attached to phpmailer_init. A callback that runs after Mail Vitals\' (priority %d) can overwrite the SMTP server, port and login it set, so mail leaves through a server other than the one configured here.', 'mail-vitals' ),
					self::SMTP_PRIORITY
				)
				: __( 'Other code configures the mailer through phpmailer_init. Mail Vitals is leaving sending to WordPress, so this is not a conflict; it is listed so you know what is shaping outgoing mail.', 'mail-vitals' );
		}

		if ( empty( $sentences ) ) {
			$sentences[] = __( 'No other code is attached to the hooks that send mail, and wp_mail() is WordPress\'s own.', 'mail-vitals' );
		}

		return new Finding(
			'mail_interference',
			__( 'Other code handling mail', 'mail-vitals' ),
			$status,
			implode( ' ', $sentences ),
			$detail
		);
	}

	/**
	 * How serious the situation is.
	 *
	 * A replaced wp_mail() is the worst case while a route is configured: the
	 * replacement need not fire either hook, so the route may never carry a
	 * single message. Without a route, Mail Vitals only records, and the
	 * concern is a record with gaps in it.
	 *
	 * Other callbacks matter while a route is configured, because they can
	 * undo it. Without one, a pre_wp_mail callback still matters because a
	 * message it short-circuits is never reported, but another plugin setting
	 * up the mailer is simply the site's chosen way of sending.
	 *
	 * @param bool  $wp_mail_replaced Whether wp_mail() is defined outside WordPress.
	 * @param bool  $route_active     Whether a Mail Vitals route is in charge of sending.
	 * @param array $callbacks        Callbacks from other code.
	 * @return string One of the Finding STATUS_* constants.
	 */
	public static function decide( $wp_mail_replaced, $route_active, array $callbacks ) {
		if ( $wp_mail_replaced ) {
			return $route_active ? Finding::STATUS_CRITICAL : Finding::STATUS_WARNING;
		}

		if ( empty( $callbacks ) ) {
			return Finding::STATUS_GOOD;
		}

		if ( $route_active || in_array( 'pre_wp_mail', wp_list_pluck( $callbacks, 'hook' ), true ) ) {
			return Finding::STATUS_WARNING;
		}

		return Finding::STATUS_GOOD;
	}

	/**
	 * Whether wp_mail() comes from somewhere other than WordPress.
	 *
	 * @param string $file      File defining wp_mail(), or '' if it could not be found.
	 * @param string $core_file Core's pluggable.php.
	 * @return bool
	 */
	public static function is_replaced( $file, $core_file ) {
		if ( '' === $file ) {
			return false;
		}

		return wp_normalize_path( $file ) !== wp_normalize_path( $core_file );
	}

	/**
	 * File that defines wp_mail() in this request.
	 *
	 * @return string Empty when the function is not defined yet.
	 */
	public static function wp_mail_file() {
		if ( ! function_exists( 'wp_mail' ) ) {
			return '';
		}

		try {
			$file = ( new \ReflectionFunction( 'wp_mail' ) )->getFileName();
		} catch ( \ReflectionException $e ) {
			return '';
		}

		return false === $file ? '' : $file;
	}

	/**
	 * Callbacks on the sending hooks that are neither Mail Vitals' nor core's.
	 *
	 * Listed in the order WordPress runs them, each marked as running before
	 * or after Mail Vitals' own callback on the same hook, since on these two
	 * hooks the order decides who wins.
	 *
	 * @return array[] Each with hook, priority, callback, owner_type, owner, slug, file, line and runs.
	 */
	public static function foreign_callbacks() {
		$found = array();

		foreach ( self::HOOKS as $hook ) {
			$seen_own = false;
			$has_own  = false;
			$entries  = array();

			foreach ( self::callbacks_on( $hook ) as $priority => $callbacks ) {
				foreach ( $callbacks as $registered ) {
					$callback = isset( $registered['function'] ) ? $registered['function'] : null;
					$location = self::locate( $callback );

					if ( self::is_own( $location['file'] ) ) {
						$seen_own = true;
						$has_own  = true;
						continue;
					}

					if ( self::is_core_callback( $hook, $callback ) ) {
						continue;
					}

					$owner     = self::owner_of( $location['file'] );
					$entries[] = array(
						'hook'       => $hook,
						'priority'   => (int) $priority,
						'callback'   => self::name_of( $callback ),
						'owner_type' => $owner['type'],
						'owner'      => $owner['name'],
						'slug'       => $owner['slug'],
						'file'       => $owner['file'],
						'line'       => $location['line'],
						'runs'       => $seen_own ? 'after' : 'before',
					);
				}
			}

			// Without a callback of ours on the hook, there is no order to report.
			foreach ( $entries as $entry ) {
				if ( ! $has_own ) {
					$entry['runs'] = '';
				}

				$found[] = $entry;
			}
		}

		return $found;
	}

	/**
	 * The callbacks attached to a hook, by priority, in the order they run.
	 *
	 * @param string $hook Hook name.
	 * @return array
	 */
	private static function callbacks_on( $hook ) {
		if ( ! isset( $GLOBALS['wp_filter'][ $hook ] ) ) {
			return array();
		}

		$hooked    = $GLOBALS['wp_filter'][ $hook ];
		$callbacks = $hooked instanceof \WP_Hook ? $hooked->callbacks : $hooked;

		if ( ! is_array( $callbacks ) ) {
			return array();
		}

		ksort( $callbacks, SORT_NUMERIC );

		return $callbacks;
	}

	/**
	 * Find where a callback is defined.
	 *
	 * Every shape WordPress accepts is handled: function names, "Class::method"
	 * strings, object and class method pairs, closures and invokable objects.
	 * Anything that cannot be traced, including a method that exists only
	 * through __call, comes back with an empty file and is reported as
	 * unidentified. Nothing here may stop the page from loading.
	 *
	 * @param mixed $callback Callback as registered.
	 * @return array With file (absolute, or '') and line (0 when unknown).
	 */
	public static function locate( $callback ) {
		$unknown = array(
			'file' => '',
			'line' => 0,
		);

		try {
			$reflection = self::reflect( $callback );
		} catch ( \Throwable $e ) {
			return $unknown;
		}

		if ( null === $reflection ) {
			return $unknown;
		}

		$file = $reflection->getFileName();

		if ( false === $file ) {
			return $unknown;
		}

		$line = $reflection->getStartLine();

		return array(
			'file' => $file,
			'line' => false === $line ? 0 : (int) $line,
		);
	}

	/**
	 * Reflection for a callback, or for its class when the method is not real.
	 *
	 * @param mixed $callback Callback as registered.
	 * @return \ReflectionFunctionAbstract|\ReflectionClass|null
	 * @throws \ReflectionException When a named class or method does not exist.
	 */
	private static function reflect( $callback ) {
		if ( $callback instanceof \Closure ) {
			return new \ReflectionFunction( $callback );
		}

		if ( is_string( $callback ) ) {
			if ( false !== strpos( $callback, '::' ) ) {
				list( $class, $method ) = explode( '::', $callback, 2 );

				return self::reflect_method( $class, $method );
			}

			return function_exists( $callback ) ? new \ReflectionFunction( $callback ) : null;
		}

		if ( is_array( $callback ) && 2 === count( $callback ) && isset( $callback[0], $callback[1] ) && is_string( $callback[1] ) ) {
			if ( is_object( $callback[0] ) || ( is_string( $callback[0] ) && class_exists( $callback[0] ) ) ) {
				return self::reflect_method( $callback[0], $callback[1] );
			}

			return null;
		}

		if ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
			return new \ReflectionMethod( $callback, '__invoke' );
		}

		return null;
	}

	/**
	 * Reflection for a method, falling back to its class.
	 *
	 * A method reached through __call, or written as "parent::method", has no
	 * definition of its own to point at, but its class still says which code
	 * registered it.
	 *
	 * @param object|string $target Object or class name.
	 * @param string        $method Method name.
	 * @return \ReflectionMethod|\ReflectionClass|null
	 * @throws \ReflectionException When the class does not exist.
	 */
	private static function reflect_method( $target, $method ) {
		if ( method_exists( $target, $method ) ) {
			return new \ReflectionMethod( $target, $method );
		}

		if ( is_object( $target ) || class_exists( $target ) ) {
			return new \ReflectionClass( $target );
		}

		return null;
	}

	/**
	 * A readable name for a callback.
	 *
	 * @param mixed $callback Callback as registered.
	 * @return string
	 */
	public static function name_of( $callback ) {
		if ( $callback instanceof \Closure ) {
			return 'Closure';
		}

		if ( is_string( $callback ) ) {
			return $callback;
		}

		if ( is_array( $callback ) && 2 === count( $callback ) && isset( $callback[0], $callback[1] ) && is_string( $callback[1] ) ) {
			if ( is_object( $callback[0] ) ) {
				return get_class( $callback[0] ) . '->' . $callback[1];
			}

			if ( is_string( $callback[0] ) ) {
				return $callback[0] . '::' . $callback[1];
			}
		}

		if ( is_object( $callback ) ) {
			return get_class( $callback ) . '::__invoke';
		}

		return __( 'Unrecognised callback', 'mail-vitals' );
	}

	/**
	 * Work out whose code a file is.
	 *
	 * Decided by where the file lives, not by what it calls itself. The name
	 * from the plugin or theme header is looked up as a label and falls back
	 * to the folder name, so a file that cannot be read still says where it
	 * came from.
	 *
	 * @param string $file Absolute path, or '' when the callback could not be traced.
	 * @return array With type (plugin, mu-plugin, theme, core, other or unknown), slug, name and file.
	 */
	public static function owner_of( $file ) {
		if ( '' === $file ) {
			return array(
				'type' => 'unknown',
				'slug' => '',
				'name' => __( 'Unidentified code', 'mail-vitals' ),
				'file' => '',
			);
		}

		$file     = self::through_symlinks( wp_normalize_path( $file ) );
		$relative = self::relative( $file );

		$inside = self::inside( $file, WPMU_PLUGIN_DIR );

		if ( null !== $inside ) {
			return self::owner( 'mu-plugin', $inside, self::mu_plugin_name( $inside ), $relative );
		}

		$inside = self::inside( $file, WP_PLUGIN_DIR );

		if ( null !== $inside ) {
			return self::owner( 'plugin', $inside, self::plugin_name( $inside ), $relative );
		}

		foreach ( self::theme_roots() as $root ) {
			$inside = self::inside( $file, $root );

			if ( null !== $inside ) {
				return self::owner( 'theme', $inside, self::theme_name( $inside, $root ), $relative );
			}
		}

		if ( null !== self::inside( $file, ABSPATH . WPINC ) || null !== self::inside( $file, ABSPATH . 'wp-admin' ) ) {
			return array(
				'type' => 'core',
				'slug' => '',
				'name' => __( 'A WordPress function, attached by code that cannot be identified from the callback', 'mail-vitals' ),
				'file' => $relative,
			);
		}

		return array(
			'type' => 'other',
			'slug' => '',
			'name' => $relative,
			'file' => $relative,
		);
	}

	/**
	 * Shape an owner whose folder is known.
	 *
	 * @param string $type     Owner type.
	 * @param string $inside   Path below the root the owner was found in.
	 * @param string $name     Display name.
	 * @param string $relative File relative to ABSPATH.
	 * @return array
	 */
	private static function owner( $type, $inside, $name, $relative ) {
		return array(
			'type' => $type,
			'slug' => self::first_segment( $inside ),
			'name' => $name,
			'file' => $relative,
		);
	}

	/**
	 * Display name of the plugin a file belongs to.
	 *
	 * @param string $inside Path below the plugins folder.
	 * @return string
	 */
	private static function plugin_name( $inside ) {
		$slug = self::first_segment( $inside );

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( get_plugins() as $basename => $data ) {
			$matches = false === strpos( $inside, '/' ) ? $basename === $inside : 0 === strpos( $basename, $slug . '/' );

			if ( $matches && ! empty( $data['Name'] ) ) {
				return $data['Name'];
			}
		}

		return $slug;
	}

	/**
	 * Display name of the must-use plugin a file belongs to.
	 *
	 * Only a file directly in the folder has a header WordPress reads. One in
	 * a subfolder was loaded by some other file there, so its folder is the
	 * best available name.
	 *
	 * @param string $inside Path below the must-use plugins folder.
	 * @return string
	 */
	private static function mu_plugin_name( $inside ) {
		if ( false === strpos( $inside, '/' ) ) {
			if ( ! function_exists( 'get_mu_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$plugins = get_mu_plugins();

			if ( ! empty( $plugins[ $inside ]['Name'] ) ) {
				return $plugins[ $inside ]['Name'];
			}
		}

		return self::first_segment( $inside );
	}

	/**
	 * Display name of the theme a file belongs to.
	 *
	 * @param string $inside Path below the theme root.
	 * @param string $root   Theme root.
	 * @return string
	 */
	private static function theme_name( $inside, $root ) {
		$slug  = self::first_segment( $inside );
		$theme = wp_get_theme( $slug, $root );

		return $theme->exists() ? $theme->get( 'Name' ) : $slug;
	}

	/**
	 * Every folder themes can live in.
	 *
	 * @return string[]
	 */
	private static function theme_roots() {
		$roots = array( get_theme_root() );

		if ( ! empty( $GLOBALS['wp_theme_directories'] ) && is_array( $GLOBALS['wp_theme_directories'] ) ) {
			$roots = array_merge( $roots, $GLOBALS['wp_theme_directories'] );
		}

		return array_unique( array_map( 'wp_normalize_path', $roots ) );
	}

	/**
	 * Translate a resolved path back to where WordPress loaded it from.
	 *
	 * PHP reports a symlinked plugin by its real location, which is outside
	 * the plugins folder. WordPress keeps the mapping, so use it to recognise
	 * the plugin anyway.
	 *
	 * @param string $file Normalised absolute path.
	 * @return string
	 */
	private static function through_symlinks( $file ) {
		if ( empty( $GLOBALS['wp_plugin_paths'] ) || ! is_array( $GLOBALS['wp_plugin_paths'] ) ) {
			return $file;
		}

		foreach ( $GLOBALS['wp_plugin_paths'] as $dir => $realdir ) {
			$realdir = trailingslashit( wp_normalize_path( $realdir ) );

			if ( 0 === strpos( $file, $realdir ) ) {
				return trailingslashit( wp_normalize_path( $dir ) ) . substr( $file, strlen( $realdir ) );
			}
		}

		return $file;
	}

	/**
	 * Whether a file is part of Mail Vitals.
	 *
	 * Measured against src/, where all of the plugin's code lives, rather than
	 * the whole plugin folder. The folder of a development copy also holds the
	 * tests, and their stand-in "other plugins" must count as other code.
	 *
	 * @param string $file Absolute path.
	 * @return bool
	 */
	private static function is_own( $file ) {
		return '' !== $file && null !== self::inside( wp_normalize_path( $file ), MAIL_VITALS_DIR . 'src' );
	}

	/**
	 * Whether a callback is one WordPress itself attaches to the hook.
	 *
	 * @param string $hook     Hook name.
	 * @param mixed  $callback Callback as registered.
	 * @return bool
	 */
	private static function is_core_callback( $hook, $callback ) {
		return is_string( $callback )
			&& isset( self::CORE_CALLBACKS[ $hook ] )
			&& in_array( $callback, self::CORE_CALLBACKS[ $hook ], true );
	}

	/**
	 * The part of a path below a folder.
	 *
	 * @param string $file Normalised absolute path.
	 * @param string $dir  Folder.
	 * @return string|null Null when the file is not inside the folder.
	 */
	private static function inside( $file, $dir ) {
		$dir = trailingslashit( wp_normalize_path( $dir ) );

		return 0 === strpos( $file, $dir ) ? substr( $file, strlen( $dir ) ) : null;
	}

	/**
	 * First folder, or the file name, of a relative path.
	 *
	 * @param string $path Relative path.
	 * @return string
	 */
	private static function first_segment( $path ) {
		$parts = explode( '/', $path, 2 );

		return $parts[0];
	}

	/**
	 * A path as the site owner would recognise it: relative to the WordPress
	 * folder when inside it, in full otherwise.
	 *
	 * @param string $file Absolute path.
	 * @return string
	 */
	private static function relative( $file ) {
		$file   = wp_normalize_path( $file );
		$inside = self::inside( $file, ABSPATH );

		return null === $inside ? $file : $inside;
	}
}
