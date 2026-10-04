<?php
/**
 * Companies, locations and departments, and which employees work where.
 *
 *   Company    — an employer / payroll entity (e.g. Carolina Sports & Spine).
 *   Location   — a physical office with its network addresses (e.g. Raleigh).
 *   Department — a unit inside one location that belongs to one company
 *                (e.g. Raleigh → CSS, owned by Carolina Sports & Spine).
 *
 * Employees are assigned to one or more departments; one is their home.
 * Shifts store the department, location and company they were worked in.
 *
 * Everything lives in one option. A small office has a handful of rows, so
 * an option is simpler than custom tables and is included in site backups.
 *
 * @package CssTimeclockAddon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Organization store, employee assignments, and shift stamping.
 */
class Css_Tc_Organization {

	const OPTION        = 'css_tc_org';
	const USER_ASSIGNED = 'css_tc_departments';
	const USER_HOME     = 'css_tc_home_department';
	const ADMIN_ACTION  = 'css_tc_org';
	const PROFILE_NONCE = 'css_tc_assignments';
	const NO_HOME       = -1;

	const META_DEPARTMENT = 'css_tc_department_id';
	const META_LOCATION   = 'css_tc_location_id';
	const META_COMPANY    = 'css_tc_company_id';
	const META_LABEL      = 'css_tc_assignment_label';

	/**
	 * @var array<string,mixed>|null
	 */
	private $cache = null;

	/**
	 * Locations for a brand-new site. Existing locations are left alone.
	 *
	 * @return void
	 */
	public function seed_default_locations() {
		$data = $this->data();
		if ( ! empty( $data['locations'] ) ) {
			return;
		}
		$defaults = array(
			'Raleigh'     => '76.195.93.124',
			'Rocky Mount' => '66.76.190.146',
			'Wilson'      => 'csswilson.ddns.net',
		);
		foreach ( $defaults as $name => $ips ) {
			$id                 = (int) $data['next_id'];
			$data['next_id']   = $id + 1;
			$data['locations'][ $id ] = array(
				'id'   => $id,
				'name' => $name,
				'ips'  => $ips,
			);
		}
		$this->save( $data );
	}

	public function register_hooks() {
		add_action( 'admin_post_' . self::ADMIN_ACTION, array( $this, 'handle_admin_post' ) );
		add_action( 'show_user_profile', array( $this, 'render_profile' ) );
		add_action( 'edit_user_profile', array( $this, 'render_profile' ) );
		add_action( 'personal_options_update', array( $this, 'save_profile' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_profile' ) );
		add_action( 'admin_init', array( $this, 'maybe_hide_aio_profile_department' ) );
	}

	/**
	 * With SMOTC departments on, AIO's own single-choice "Department" list on
	 * the user profile is redundant (and its save path is what crashed on
	 * AIO's missing count function). Remove AIO's profile department hooks so
	 * only "Time clock departments" shows. AIO department terms already set
	 * on users are left as they are.
	 *
	 * @return void
	 */
	public function maybe_hide_aio_profile_department() {
		global $pagenow, $wp_filter;
		if ( ! in_array( $pagenow, array( 'profile.php', 'user-edit.php' ), true ) || ! $this->enabled() ) {
			return;
		}
		foreach ( array( 'show_user_profile', 'edit_user_profile', 'personal_options_update', 'edit_user_profile_update' ) as $hook ) {
			if ( empty( $wp_filter[ $hook ] ) || ! ( $wp_filter[ $hook ] instanceof WP_Hook ) ) {
				continue;
			}
			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$fn = $callback['function'];
					if ( is_array( $fn ) && is_object( $fn[0] ) && 0 === stripos( get_class( $fn[0] ), 'AIO_' ) && false !== stripos( (string) $fn[1], 'department' ) ) {
						remove_action( $hook, $fn, $priority );
					}
				}
			}
		}
	}

	/**
	 * Whether the kiosk asks for a department and shifts are stamped.
	 *
	 * @return bool
	 */
	public function enabled() {
		$settings = css_tc_addon()->get_settings();
		return ! empty( $settings['assignments_enabled'] ) && ! empty( $this->departments() );
	}

	/**
	 * Whether clocked-in employees may Switch departments at the kiosk.
	 * Needs departments at clock-in. Missing setting (older installs) = on.
	 *
	 * @return bool
	 */
	public function switch_enabled() {
		$settings = css_tc_addon()->get_settings();
		return $this->enabled() && ( ! isset( $settings['switch_enabled'] ) || ! empty( $settings['switch_enabled'] ) );
	}

	// ------------------------------------------------------------------
	// Store
	// ------------------------------------------------------------------

	/**
	 * @return array{companies:array<int,array<string,mixed>>,locations:array<int,array<string,mixed>>,departments:array<int,array<string,mixed>>,next_id:int}
	 */
	public function data() {
		if ( null !== $this->cache ) {
			return $this->cache;
		}
		$raw = get_option( self::OPTION, array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		$data = array(
			'companies'   => array(),
			'locations'   => array(),
			'departments' => array(),
			'next_id'     => isset( $raw['next_id'] ) ? max( 1, (int) $raw['next_id'] ) : 1,
		);
		foreach ( array( 'companies', 'locations', 'departments' ) as $kind ) {
			if ( empty( $raw[ $kind ] ) || ! is_array( $raw[ $kind ] ) ) {
				continue;
			}
			foreach ( $raw[ $kind ] as $row ) {
				if ( is_array( $row ) && ! empty( $row['id'] ) ) {
					$data[ $kind ][ (int) $row['id'] ] = $row;
				}
			}
		}
		$this->cache = $data;
		return $data;
	}

	/**
	 * @param array<string,mixed> $data Full store.
	 * @return void
	 */
	private function save( $data ) {
		update_option( self::OPTION, $data, false );
		$this->cache = null;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function companies() {
		return $this->sorted( $this->data()['companies'] );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function locations() {
		return $this->sorted( $this->data()['locations'] );
	}

	/**
	 * Departments sorted by location name, then department name.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function departments() {
		$rows = $this->data()['departments'];
		$self = $this;
		uasort(
			$rows,
			static function ( $a, $b ) use ( $self ) {
				$la  = $self->location_name( (int) $a['location_id'] );
				$lb  = $self->location_name( (int) $b['location_id'] );
				$cmp = strcasecmp( $la, $lb );
				return 0 !== $cmp ? $cmp : strcasecmp( (string) $a['name'], (string) $b['name'] );
			}
		);
		return $rows;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows Rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function sorted( $rows ) {
		uasort(
			$rows,
			static function ( $a, $b ) {
				return strcasecmp( (string) $a['name'], (string) $b['name'] );
			}
		);
		return $rows;
	}

	/**
	 * @param int $id Department ID.
	 * @return array<string,mixed>|null
	 */
	public function department( $id ) {
		$data = $this->data();
		return isset( $data['departments'][ (int) $id ] ) ? $data['departments'][ (int) $id ] : null;
	}

	/**
	 * @param int $id Location ID.
	 * @return array<string,mixed>|null
	 */
	public function location( $id ) {
		$data = $this->data();
		return isset( $data['locations'][ (int) $id ] ) ? $data['locations'][ (int) $id ] : null;
	}

	/**
	 * @param int $id Company ID.
	 * @return array<string,mixed>|null
	 */
	public function company( $id ) {
		$data = $this->data();
		return isset( $data['companies'][ (int) $id ] ) ? $data['companies'][ (int) $id ] : null;
	}

	/**
	 * @param int $id Location ID.
	 * @return string
	 */
	public function location_name( $id ) {
		$row = $this->location( $id );
		return $row ? (string) $row['name'] : '';
	}

	/**
	 * @param int $id Company ID.
	 * @return string
	 */
	public function company_name( $id ) {
		$row = $this->company( $id );
		return $row ? (string) $row['name'] : '';
	}

	/**
	 * "Department · Location", e.g. "CSS · Raleigh".
	 *
	 * @param int $department_id Department.
	 * @return string
	 */
	public function label( $department_id ) {
		$dept = $this->department( $department_id );
		if ( ! $dept ) {
			return '';
		}
		$location = $this->location_name( (int) $dept['location_id'] );
		return '' !== $location ? $dept['name'] . ' · ' . $location : (string) $dept['name'];
	}

	/**
	 * Add or update a row. Returns the ID.
	 *
	 * @param string              $kind companies|locations|departments.
	 * @param array<string,mixed> $row  Fields; include id to update.
	 * @return int|WP_Error
	 */
	public function upsert( $kind, $row ) {
		if ( ! in_array( $kind, array( 'companies', 'locations', 'departments' ), true ) ) {
			return new WP_Error( 'css_tc_org_kind', 'Unknown kind.' );
		}
		$data = $this->data();
		$name = isset( $row['name'] ) ? trim( sanitize_text_field( (string) $row['name'] ) ) : '';
		if ( '' === $name || strlen( $name ) > 80 ) {
			return new WP_Error( 'css_tc_org_name', __( 'Enter a name up to 80 characters.', 'css-timeclock-addon' ) );
		}

		$id    = isset( $row['id'] ) ? (int) $row['id'] : 0;
		$clean = array( 'name' => $name );

		if ( 'locations' === $kind ) {
			$ips    = isset( $row['ips'] ) ? str_replace( array( "\r\n", "\r" ), "\n", (string) $row['ips'] ) : '';
			$ips    = sanitize_textarea_field( $ips );
			$parsed = css_tc_addon()->pins->parse_allowlist( $ips );
			if ( ! empty( $parsed['invalid'] ) ) {
				return new WP_Error(
					'css_tc_org_ips',
					sprintf(
						/* translators: %s: invalid lines */
						__( 'These lines are not IPv4, IPv6, CIDR ranges, or hostnames: %s', 'css-timeclock-addon' ),
						implode( ', ', array_slice( $parsed['invalid'], 0, 5 ) )
					)
				);
			}
			$clean['ips'] = $ips;
			css_tc_addon()->pins->warm_hostnames( $ips );
		}

		if ( 'departments' === $kind ) {
			$location_id = isset( $row['location_id'] ) ? (int) $row['location_id'] : 0;
			$company_id  = isset( $row['company_id'] ) ? (int) $row['company_id'] : 0;
			if ( ! isset( $data['locations'][ $location_id ] ) ) {
				return new WP_Error( 'css_tc_org_location', __( 'Choose a location for the department.', 'css-timeclock-addon' ) );
			}
			if ( ! isset( $data['companies'][ $company_id ] ) ) {
				return new WP_Error( 'css_tc_org_company', __( 'Choose the company the department belongs to.', 'css-timeclock-addon' ) );
			}
			$clean['location_id'] = $location_id;
			$clean['company_id']  = $company_id;
		}

		foreach ( $data[ $kind ] as $other ) {
			if ( (int) $other['id'] === $id ) {
				continue;
			}
			$same_scope = ( 'departments' !== $kind ) || ( (int) $other['location_id'] === $clean['location_id'] );
			if ( $same_scope && 0 === strcasecmp( (string) $other['name'], $name ) ) {
				return new WP_Error( 'css_tc_org_dupe', __( 'That name is already used here.', 'css-timeclock-addon' ) );
			}
		}

		if ( $id > 0 ) {
			if ( ! isset( $data[ $kind ][ $id ] ) ) {
				return new WP_Error( 'css_tc_org_missing', __( 'That item no longer exists.', 'css-timeclock-addon' ) );
			}
			$data[ $kind ][ $id ] = array_merge( $data[ $kind ][ $id ], $clean );
		} else {
			$id                   = (int) $data['next_id'];
			$data['next_id']      = $id + 1;
			$data[ $kind ][ $id ] = array_merge( array( 'id' => $id ), $clean );
		}

		$this->save( $data );
		return $id;
	}

	/**
	 * Delete a row that nothing depends on. Worked shifts keep their name
	 * snapshot, so a department with past shifts can still be removed.
	 *
	 * @param string $kind companies|locations|departments.
	 * @param int    $id   Row.
	 * @return true|WP_Error
	 */
	public function delete( $kind, $id ) {
		$data = $this->data();
		$id   = (int) $id;
		if ( ! isset( $data[ $kind ][ $id ] ) ) {
			return new WP_Error( 'css_tc_org_missing', __( 'That item no longer exists.', 'css-timeclock-addon' ) );
		}
		if ( 'companies' === $kind || 'locations' === $kind ) {
			$key = 'companies' === $kind ? 'company_id' : 'location_id';
			foreach ( $data['departments'] as $dept ) {
				if ( (int) $dept[ $key ] === $id ) {
					return new WP_Error( 'css_tc_org_in_use', __( 'Remove its departments first.', 'css-timeclock-addon' ) );
				}
			}
		}
		if ( 'departments' === $kind && $this->assigned_user_count( $id ) > 0 ) {
			return new WP_Error( 'css_tc_org_in_use', __( 'Employees are still assigned to this department. Unassign them first.', 'css-timeclock-addon' ) );
		}
		unset( $data[ $kind ][ $id ] );
		$this->save( $data );
		return true;
	}

	// ------------------------------------------------------------------
	// Employees
	// ------------------------------------------------------------------

	/**
	 * Department IDs the employee may clock into.
	 *
	 * @param int $user_id Employee.
	 * @return int[]
	 */
	public function assigned( $user_id ) {
		$raw = get_user_meta( (int) $user_id, self::USER_ASSIGNED, true );
		$out = array();
		foreach ( (array) $raw as $id ) {
			$id = (int) $id;
			if ( $id > 0 && $this->department( $id ) ) {
				$out[] = $id;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * @param int $user_id Employee.
	 * @return int Home department ID, or 0.
	 */
	public function home( $user_id ) {
		$home     = (int) get_user_meta( (int) $user_id, self::USER_HOME, true );
		if ( self::NO_HOME === $home ) {
			return 0;
		}
		$assigned = $this->assigned( $user_id );
		if ( in_array( $home, $assigned, true ) ) {
			return $home;
		}
		return empty( $assigned ) ? 0 : $assigned[0];
	}

	/**
	 * Whether a manager chose "No home department" for this employee.
	 *
	 * @param int $user_id Employee.
	 * @return bool
	 */
	public function has_no_home( $user_id ) {
		return self::NO_HOME === (int) get_user_meta( (int) $user_id, self::USER_HOME, true );
	}

	/**
	 * @param int   $user_id  Employee.
	 * @param int[] $assigned Department IDs.
	 * @param int   $home     Home department ID.
	 * @return void
	 */
	public function set_assignments( $user_id, $assigned, $home ) {
		$clean = array();
		foreach ( (array) $assigned as $id ) {
			$id = (int) $id;
			if ( $id > 0 && $this->department( $id ) ) {
				$clean[] = $id;
			}
		}
		$clean = array_values( array_unique( $clean ) );
		$home  = (int) $home;
		if ( self::NO_HOME === $home ) {
			$home = empty( $clean ) ? 0 : self::NO_HOME;
		} elseif ( ! in_array( $home, $clean, true ) ) {
			$home = empty( $clean ) ? 0 : $clean[0];
		}
		update_user_meta( (int) $user_id, self::USER_ASSIGNED, $clean );
		update_user_meta( (int) $user_id, self::USER_HOME, $home );
	}

	/**
	 * @param int $department_id Department.
	 * @return int
	 */
	public function assigned_user_count( $department_id ) {
		$count = 0;
		foreach ( css_tc_addon()->employees->list_for_admin() as $user ) {
			if ( in_array( (int) $department_id, $this->assigned( (int) $user->ID ), true ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Location whose office network contains this address.
	 *
	 * @param string $ip Client address.
	 * @return int Location ID or 0.
	 */
	public function location_for_ip( $ip ) {
		$pins = css_tc_addon()->pins;
		if ( '' === $pins->canonical_ip( (string) $ip ) ) {
			return 0;
		}
		foreach ( $this->locations() as $location ) {
			$parsed = $pins->parse_allowlist( isset( $location['ips'] ) ? (string) $location['ips'] : '' );
			foreach ( $parsed['entries'] as $entry ) {
				if ( $pins->ip_in_entry( $ip, $entry ) ) {
					return (int) $location['id'];
				}
			}
		}
		return 0;
	}

	/**
	 * Where this kiosk request is: the kiosk page's location, else the
	 * office network, else unknown (the employee then picks).
	 *
	 * @param int $page_location Location ID from the kiosk page, or 0.
	 * @return array{id:int,name:string,source:string}
	 */
	public function resolve_location( $page_location = 0 ) {
		$page_location = (int) $page_location;
		if ( $page_location > 0 && $this->location( $page_location ) ) {
			return array(
				'id'     => $page_location,
				'name'   => $this->location_name( $page_location ),
				'source' => 'page',
			);
		}
		$by_ip = $this->location_for_ip( css_tc_addon()->pins->client_ip() );
		if ( $by_ip > 0 ) {
			return array(
				'id'     => $by_ip,
				'name'   => $this->location_name( $by_ip ),
				'source' => 'network',
			);
		}
		return array(
			'id'     => 0,
			'name'   => '',
			'source' => 'unknown',
		);
	}

	/**
	 * Clock-in choices for an employee. With a known location, only that
	 * location's departments; otherwise all assigned departments. Home first.
	 *
	 * @param int $user_id     Employee.
	 * @param int $location_id Location, or 0 for any.
	 * @param int $exclude     Department to leave out (the one they are in now).
	 * @return array<int,array<string,mixed>>
	 */
	public function choices( $user_id, $location_id = 0, $exclude = 0 ) {
		$home = $this->home( $user_id );
		$out  = array();
		foreach ( $this->assigned( $user_id ) as $id ) {
			$dept = $this->department( $id );
			if ( ! $dept || $id === (int) $exclude ) {
				continue;
			}
			if ( $location_id > 0 && (int) $dept['location_id'] !== (int) $location_id ) {
				continue;
			}
			$out[] = array(
				'department_id' => $id,
				'department'    => (string) $dept['name'],
				'location_id'   => (int) $dept['location_id'],
				'location'      => $this->location_name( (int) $dept['location_id'] ),
				'company_id'    => (int) $dept['company_id'],
				'company'       => $this->company_name( (int) $dept['company_id'] ),
				'is_home'       => ( $id === $home ),
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				if ( $a['is_home'] !== $b['is_home'] ) {
					return $a['is_home'] ? -1 : 1;
				}
				$cmp = strcasecmp( $a['location'], $b['location'] );
				return 0 !== $cmp ? $cmp : strcasecmp( $a['company'] . $a['department'], $b['company'] . $b['department'] );
			}
		);
		return $out;
	}

	/**
	 * Check a kiosk department choice against what the employee may use here.
	 *
	 * @param int $user_id       Employee.
	 * @param int $department_id Chosen department.
	 * @param int $location_id   Resolved location, or 0.
	 * @return true|WP_Error
	 */
	public function assert_choice( $user_id, $department_id, $location_id ) {
		foreach ( $this->choices( $user_id, $location_id ) as $choice ) {
			if ( (int) $choice['department_id'] === (int) $department_id ) {
				return true;
			}
		}
		return new WP_Error( 'css_tc_bad_department', __( 'Choose one of your departments.', 'css-timeclock-addon' ) );
	}

	/**
	 * Write the department, location and company onto a shift.
	 *
	 * @param int $shift_id      Shift post.
	 * @param int $department_id Department, or 0 to clear.
	 * @return void
	 */
	public function stamp_shift( $shift_id, $department_id ) {
		$dept = $this->department( $department_id );
		if ( ! $dept ) {
			foreach ( array( self::META_DEPARTMENT, self::META_LOCATION, self::META_COMPANY, self::META_LABEL ) as $key ) {
				delete_post_meta( (int) $shift_id, $key );
			}
			return;
		}
		update_post_meta( (int) $shift_id, self::META_DEPARTMENT, (int) $dept['id'] );
		update_post_meta( (int) $shift_id, self::META_LOCATION, (int) $dept['location_id'] );
		update_post_meta( (int) $shift_id, self::META_COMPANY, (int) $dept['company_id'] );
		update_post_meta(
			(int) $shift_id,
			self::META_LABEL,
			$this->company_name( (int) $dept['company_id'] ) . ' · ' . $this->label( (int) $dept['id'] )
		);
	}

	/**
	 * Assignment stored on a shift. Names come from the current setup, or
	 * from the snapshot label when the department was since removed.
	 *
	 * @param int $shift_id Shift post.
	 * @return array{department_id:int,location_id:int,company_id:int,department:string,location:string,company:string,label:string}
	 */
	public function shift_assignment( $shift_id ) {
		$dept_id = (int) get_post_meta( (int) $shift_id, self::META_DEPARTMENT, true );
		$loc_id  = (int) get_post_meta( (int) $shift_id, self::META_LOCATION, true );
		$co_id   = (int) get_post_meta( (int) $shift_id, self::META_COMPANY, true );
		$dept    = $this->department( $dept_id );
		$label   = $dept
			? $this->company_name( $co_id ) . ' · ' . $this->label( $dept_id )
			: (string) get_post_meta( (int) $shift_id, self::META_LABEL, true );
		return array(
			'department_id' => $dept_id,
			'location_id'   => $loc_id,
			'company_id'    => $co_id,
			'department'    => $dept ? (string) $dept['name'] : '',
			'location'      => $this->location_name( $loc_id ),
			'company'       => $this->company_name( $co_id ),
			'label'         => $label,
		);
	}

	// ------------------------------------------------------------------
	// Import from AIO departments
	// ------------------------------------------------------------------

	/**
	 * Build locations/departments from AIO "department" terms named
	 * "Location-Department" (e.g. Raleigh-CSS, Wilson-BFM/TRM). The part
	 * after the first hyphen is also the company name. Terms without a
	 * hyphen become a department of that name at location "Main".
	 * Employees with a term are assigned to the matching department as
	 * home. Existing shifts are stamped from their stored department name.
	 *
	 * Safe to run more than once: existing names are reused.
	 *
	 * @return array{departments:int,employees:int,shifts:int}
	 */
	public function import_from_aio() {
		$result = array(
			'departments' => 0,
			'employees'   => 0,
			'shifts'      => 0,
		);
		if ( ! taxonomy_exists( 'department' ) ) {
			return $result;
		}
		$terms = get_terms(
			array(
				'taxonomy'   => 'department',
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) ) {
			return $result;
		}

		$map = array(); // term name => department ID.
		foreach ( $terms as $term ) {
			$name  = trim( (string) $term->name );
			$parts = preg_split( '/\s*-\s*/', $name, 2 );
			if ( 2 === count( $parts ) && '' !== $parts[0] && '' !== $parts[1] ) {
				$location_name = $parts[0];
				$dept_name     = $parts[1];
			} else {
				$location_name = __( 'Main', 'css-timeclock-addon' );
				$dept_name     = $name;
			}
			$location_id = $this->find_or_create( 'locations', $location_name );
			$company_id  = $this->find_or_create( 'companies', $dept_name );
			$dept_id     = $this->find_department( $location_id, $dept_name );
			if ( ! $dept_id ) {
				$dept_id = $this->upsert(
					'departments',
					array(
						'name'        => $dept_name,
						'location_id' => $location_id,
						'company_id'  => $company_id,
					)
				);
				if ( is_wp_error( $dept_id ) ) {
					continue;
				}
				++$result['departments'];
			}
			$map[ $name ] = (int) $dept_id;
		}

		foreach ( css_tc_addon()->employees->list_for_admin() as $user ) {
			$names = wp_get_object_terms( (int) $user->ID, 'department', array( 'fields' => 'names' ) );
			if ( is_wp_error( $names ) || empty( $names ) ) {
				continue;
			}
			$assigned = $this->assigned( (int) $user->ID );
			$home     = (int) get_user_meta( (int) $user->ID, self::USER_HOME, true );
			foreach ( $names as $term_name ) {
				if ( isset( $map[ $term_name ] ) ) {
					$assigned[] = $map[ $term_name ];
					if ( ! $home ) {
						$home = $map[ $term_name ];
					}
				}
			}
			$this->set_assignments( (int) $user->ID, $assigned, $home );
			++$result['employees'];
		}

		$query = new WP_Query(
			array(
				'post_type'      => Css_Tc_Punches::POST_TYPE,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'     => self::META_DEPARTMENT,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		foreach ( $query->posts as $shift_id ) {
			$stored = (string) get_post_meta( (int) $shift_id, 'department', true );
			if ( '' !== $stored && isset( $map[ $stored ] ) ) {
				$this->stamp_shift( (int) $shift_id, $map[ $stored ] );
				++$result['shifts'];
			}
		}

		return $result;
	}

	/**
	 * @param string $kind companies|locations.
	 * @param string $name Name.
	 * @return int
	 */
	private function find_or_create( $kind, $name ) {
		foreach ( $this->data()[ $kind ] as $row ) {
			if ( 0 === strcasecmp( (string) $row['name'], $name ) ) {
				return (int) $row['id'];
			}
		}
		$id = $this->upsert( $kind, array( 'name' => $name ) );
		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	/**
	 * @param int    $location_id Location.
	 * @param string $name        Department name.
	 * @return int
	 */
	private function find_department( $location_id, $name ) {
		foreach ( $this->data()['departments'] as $row ) {
			if ( (int) $row['location_id'] === (int) $location_id && 0 === strcasecmp( (string) $row['name'], $name ) ) {
				return (int) $row['id'];
			}
		}
		return 0;
	}

	// ------------------------------------------------------------------
	// Admin
	// ------------------------------------------------------------------

	/**
	 * Setup form posts (add, rename, delete, import).
	 *
	 * @return void
	 */
	public function handle_admin_post() {
		if ( ! Css_Tc_Plugin::user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to change locations and departments.', 'css-timeclock-addon' ), 403 );
		}
		check_admin_referer( self::ADMIN_ACTION );

		$op   = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$kind = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';
		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		$message = '';
		$error   = '';
		if ( 'save' === $op ) {
			$saved = $this->upsert(
				$kind,
				array(
					'id'          => $id,
					'name'        => isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '',
					'ips'         => isset( $_POST['ips'] ) ? wp_unslash( $_POST['ips'] ) : '',
					'location_id' => isset( $_POST['location_id'] ) ? absint( $_POST['location_id'] ) : 0,
					'company_id'  => isset( $_POST['company_id'] ) ? absint( $_POST['company_id'] ) : 0,
				)
			);
			if ( is_wp_error( $saved ) ) {
				$error = $saved->get_error_message();
			} else {
				$message = __( 'Saved.', 'css-timeclock-addon' );
			}
		} elseif ( 'delete' === $op ) {
			$deleted = $this->delete( $kind, $id );
			if ( is_wp_error( $deleted ) ) {
				$error = $deleted->get_error_message();
			} else {
				$message = __( 'Removed.', 'css-timeclock-addon' );
			}
		} elseif ( 'import' === $op ) {
			$result  = $this->import_from_aio();
			$message = sprintf(
				/* translators: 1: departments created, 2: employees assigned, 3: shifts stamped */
				__( 'Imported: %1$d departments created, %2$d employees assigned, %3$d past shifts labeled.', 'css-timeclock-addon' ),
				$result['departments'],
				$result['employees'],
				$result['shifts']
			);
		}

		$back = isset( $_POST['_wp_http_referer'] ) ? esc_url_raw( wp_unslash( $_POST['_wp_http_referer'] ) ) : admin_url();
		$back = remove_query_arg( array( 'css_tc_org_msg', 'css_tc_org_err' ), $back );
		if ( '' !== $error ) {
			$back = add_query_arg( 'css_tc_org_err', rawurlencode( $error ), $back );
		} elseif ( '' !== $message ) {
			$back = add_query_arg( 'css_tc_org_msg', rawurlencode( $message ), $back );
		}
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Assignment checkboxes on the user profile (managers only).
	 *
	 * @param WP_User $user Profile user.
	 * @return void
	 */
	public function render_profile( $user ) {
		if ( ! Css_Tc_Plugin::user_can_manage() || ! css_tc_addon()->employees->is_employee( (int) $user->ID ) ) {
			return;
		}
		$departments = $this->departments();
		$assigned    = $this->assigned( (int) $user->ID );
		$home        = $this->home( (int) $user->ID );
		include CSS_TC_ADDON_DIR . 'admin/views/profile-assignments.php';
	}

	/**
	 * @param int $user_id Profile user.
	 * @return void
	 */
	public function save_profile( $user_id ) {
		if ( ! Css_Tc_Plugin::user_can_manage() || ! isset( $_POST['css_tc_assign_present'] ) ) {
			return;
		}
		check_admin_referer( self::PROFILE_NONCE, 'css_tc_assign_nonce' );
		$assigned = isset( $_POST['css_tc_departments'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['css_tc_departments'] ) ) : array();
		$home     = isset( $_POST['css_tc_home_department'] ) ? (int) $_POST['css_tc_home_department'] : 0;
		$home     = ( self::NO_HOME === $home ) ? self::NO_HOME : max( 0, $home );
		$this->set_assignments( (int) $user_id, $assigned, $home );
	}
}
