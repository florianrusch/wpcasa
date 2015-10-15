<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * wpSight_Meta_Boxes class
 */
class WPSight_Meta_Boxes {

	/**
	 * Constructor
	 */
	public function __construct() {

		add_filter( 'cmb2_meta_box_url', array( $this, 'cmb2_meta_box_url' ) );

		// Enqueue scripts
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ), 20 );

		// Add custom meta boxes
		add_action( 'cmb2_admin_init', array( $this, 'admin_meta_boxes' ) );

		// Set default listing ID
		add_action( 'wp_insert_post', array( $this, 'admin_listing_id_default' ), null, 2 );

		// Fire action when a listing is saved
		add_action( 'save_post', array( $this, 'admin_save_post' ), 1, 2 );

		// Add action when a listing is saved
		//add_action( 'wpsight_save_listing', array( $this, 'admin_save_listing_data' ), 20, 2 );

		// Update geolocation data
		add_action( 'update_post_meta', array( $this, 'maybe_generate_geolocation_data' ), 10, 4 );

		// Update some listing post meta data
		add_action( 'add_meta_boxes_listing', array( $this, 'admin_post_meta_update' ) );

		// Set existing attachments as gallery meta data
		add_action( 'update_post_meta', array( $this, 'admin_gallery_default' ), 1, 4 );

	}

	/**
	 *  Make sure CMB2 works in unusual environments such as symlinking the plugin
	 *
	 *  @see https://github.com/WebDevStudios/CMB2/issues/432
	 *
	 *  @param   string  $url
	 *  @return  string
	 */
	public function cmb2_meta_box_url( $url ) {
		return plugins_url( 'cmb2/', $url );
	}

	/**
	 * admin_enqueue_scripts()
	 *
	 * @access public
	 * @uses get_current_screen()
	 * @uses wp_enqueue_style()
	 *
	 * @since 1.0.0
	 */
	public function admin_enqueue_scripts() {

		if ( in_array( get_current_screen()->id, array( 'edit-listing', 'listing' ) ) )
			wp_enqueue_style( 'wpsight-meta-boxes', WPSIGHT_PLUGIN_URL . '/assets/css/wpsight-meta-boxes.css' );

	}

	/**
	 * admin_meta_boxes()
	 *
	 * Merging arrays of all meta boxes to be
	 * sent to cmb2_admin_init filter of Custom Meta Box API.
	 *
	 * @access public
	 * @return array
	 * @see /functions/wpsight-meta-boxes.php
	 *
	 * @since 1.0.0
	 */
	public function admin_meta_boxes( ) {
		$meta_boxes = wpsight_meta_boxes();

		foreach ( $meta_boxes as $metabox ) {
			$cmb = new_cmb2_box( $metabox );
		    foreach ( $metabox['fields'] as $field ) {
		    	$cmb->add_field( $field );
		    }
		}	

	}

	/**
	 * admin_listing_id_default()
	 *
	 * Save a default listing ID when
	 * creating a new listing (post-new.php)
	 * by getting auto-draft post id.
	 *
	 * @access public
	 * @param integer $post_id
	 * @param mixed   $post
	 * @uses wpsight_post_type()
	 * @uses get_post_meta()
	 * @uses update_post_meta()
	 *
	 * @since 1.0.0
	 */
	public function admin_listing_id_default( $post_id, $post ) {

		if ( $post->post_status != 'auto-draft' || $post->post_type != wpsight_post_type() )
			return;

		$listing_id = get_post_meta( $post->ID, '_listing_id', true );

		if ( ! $listing_id )
			update_post_meta( $post->ID, '_listing_id', wpsight_get_listing_id( $post->ID ) );

	}

	/**
	 * admin_save_post()
	 *
	 * Fire action wpsight_save_listing when a listing
	 * is saved and meets some conditions.
	 *
	 * @access public
	 * @param integer $post_id
	 * @param mixed   $post
	 * @uses wp_is_post_revision()
	 * @uses wp_is_post_autosave()
	 * @uses current_user_can()
	 * @uses do_action()
	 *
	 * @since 1.0.0
	 */
	public function admin_save_post( $post_id, $post ) {

		// Stop when no post ID or object is given
		if ( empty( $post_id ) || empty( $post ) || empty( $_POST ) ) return;

		// Stop if this is only a revision
		if ( is_int( wp_is_post_revision( $post ) ) ) return;

		// Stop if this is only an autosave
		if ( is_int( wp_is_post_autosave( $post ) ) ) return;

		// Stop if current user is not allowed
		if ( ! current_user_can( 'edit_listing', $post_id ) ) return;

		// Stop if other post type
		if ( $post->post_type != wpsight_post_type() ) return;

		// Fire wpsight_save_listing action
		do_action( 'wpsight_save_listing', $post_id, $post );

	}

	/**
	 * admin_save_listing_data()
	 *
	 * Update listing data when saved.
	 *
	 * @access public
	 * @param integer $post_id
	 * @param mixed   $post
	 * @uses update_post_meta()
	 * @uses apply_filters()
	 * @uses wpSight_Geocode::has_location_data()
	 * @uses wpSight_Geocode::generate_location_data()
	 * @uses sanitize_text_field()
	 *
	 * @since 1.0.0
	 */
	public function admin_save_listing_data( $post_id, $post ) {
		
		if( ! is_admin() )
			return;

		// Update listing location data

		$value = array_values( $_POST[ '_map_address' ] );

		if ( update_post_meta( $post_id, '_map_address', sanitize_text_field( $value[0] ) ) ) {
			// Location data will be updated by maybe_generate_geolocation_data method
		} elseif ( apply_filters( 'wpsight_geolocation_enabled', true ) && ! wpSight_Geocode::has_location_data( $post_id ) ) {
			wpSight_Geocode::generate_location_data( $post_id, sanitize_text_field( $value[0] ) );
		}

		// Update listing agent logo URL

		$agent_logo_id = array_values( $_POST[ '_agent_logo_id' ] );

		if ( ! empty( $agent_logo_id[0] ) ) {
			
			$agent_logo = wp_get_attachment_url( absint( $agent_logo_id[0] ) );

			if ( $agent_logo != $post->_agent_logo )
				update_post_meta( $post_id, '_agent_logo', $agent_logo );

		} else {

			delete_post_meta( $post_id, '_agent_logo' );

		}

	}

	/**
	 * Generate location data if a post is saved
	 *
	 * @param int     $post_id
	 * @param array   $post
	 */
	public function maybe_generate_geolocation_data( $meta_id, $object_id, $meta_key, $_meta_value ) {
		if ( '_map_address' !== $meta_key || wpsight_post_type() !== get_post_type( $object_id ) ) {
			return;
		}
		do_action( 'wpsight_listing_location_edited', $object_id, $_meta_value );
	}

	/**
	 * admin_post_meta_update()
	 *
	 * Rename and update some post meta to
	 * ensure backwards compability with
	 * older wpCasa versions.
	 *
	 * @access public
	 * @uses get_the_id()
	 * @uses update_post_meta()
	 * @uses delete_post_meta()
	 * @uses wpsight_maybe_update_gallery()
	 *
	 * @since 1.0.0
	 */
	public function admin_post_meta_update( $post ) {

		// Post ID
		$post_id = $post->ID;

		// Rename _price_sold_rented post meta

		$sold_rented = $post->_price_sold_rented;

		if ( ! empty( $sold_rented ) ) {

			// Update new field with old field value
			update_post_meta( $post_id, '_listing_not_available', $sold_rented );

			// Remove old field
			delete_post_meta( $post_id, '_price_sold_rented' );

		}

		// Rename _price_status post meta

		// Get old _price_status value
		$status = $post->_price_status;

		if ( ! empty( $status ) ) {

			// Update new field with old field value
			update_post_meta( $post_id, '_price_offer', $status );

			// Remove old field
			delete_post_meta( $post_id, '_price_status' );

		}

		// Update gallery information
		wpsight_maybe_update_gallery( $post_id );
		
		// Update post meta title
		
		if( $post->post_title != $post->_listing_title )
			update_post_meta( $post_id, '_listing_title', $post->post_title );

	}

	/**
	 * Set available image attachments
	 * as default images.
	 *
	 * @access public
	 * @uses get_current_screen()
	 * @uses get_the_id()
	 * @uses get_post_meta()
	 * @uses add_post_meta()
	 * @uses get_posts()
	 *
	 * @since 1.0.0
	 */
	public function admin_gallery_default( $meta_id, $object_id, $meta_key, $_meta_value ) {
		global $post;

		if ( '_gallery' !== $meta_key || wpsight_post_type() !== get_post_type( $object_id ) )
			return;

		$post_id = $post->ID;

		// Check if gallery has already been imported
		$gallery_imported = $post->_gallery_imported;

		if ( ! $gallery_imported ) {

			// Check existing gallery
			$gallery = get_post_meta( $post_id, '_gallery' );

			// Get all image attachments

			$attachments = get_posts(
				array(
					'post_type'      => 'attachment',
					'posts_per_page' => -1,
					'post_parent'    => $post_id,
					'post_mime_type' => 'image',
					'orderby'        => 'menu_order'
				)
			);

			/**
			 * If still no gallery is available and it
			 * hasn't been imported yet, but there are
			 * attachments, create gallery custom fields
			 * with attachment IDs.
			 */

			if ( ! $gallery && $attachments ) {

				// Loop through attachments

				foreach ( $attachments as $attachment ) {

					// Create gallery post meta with attachment ID

					if ( $attachment->ID != absint( $post->_agent_logo_id ) )
						add_post_meta( $post_id, '_gallery', $attachment->ID );

				}

				// Mark gallery as imported
				add_post_meta( $post_id, '_gallery_imported', '1' );

			}

		}

	}

	/**
	 * meta_boxes()
	 *
	 * Merging arrays of all WPSight meta boxes
	 *
	 * @uses wpsight_meta_box_listing_*()
	 * @return array Array of all listing meta boxes
	 *
	 * @since 1.0.0
	 */

	public static function meta_boxes() {

		// Merge all meta box arrays

		$meta_boxes = array(
			'listing_attributes' => wpsight_meta_box_listing_attributes(),
			'listing_price'      => wpsight_meta_box_listing_price(),
			'listing_details'    => wpsight_meta_box_listing_details(),
			'listing_images'     => wpsight_meta_box_listing_images(),
			'listing_location'   => wpsight_meta_box_listing_location(),
			'listing_agent'      => wpsight_meta_box_listing_agent(),
			'user'      		 => wpsight_meta_box_user()
		);

		// Add custom spaces if any

		foreach ( wpsight_meta_box_spaces() as $key => $space )
			$meta_boxes[$key] = $space;

		return apply_filters( 'wpsight_meta_boxes', $meta_boxes );

	}

	/**
	 * meta_box_listing_attributes()
	 *
	 * Create listing attributes meta box
	 *
	 * @uses wpsight_sort_array_by_priority()
	 * @uses wpsight_post_type()
	 *
	 * @return array $meta_box Meta box array with fields
	 * @see wpsight_meta_boxes()
	 *
	 * @since 1.0.0
	 */

	public static function meta_box_listing_attributes() {

		// Set meta box fields

		$fields = array(
			'availability' => array(
				'name'      => __( 'Availability', 'wpsight' ),
				'id'        => '_listing_not_available',
				'type'      => 'checkbox',
				'label_cb'  => __( 'Item not available', 'wpsight' ),
				'desc'      => __( 'The item is currently not available as it has been sold or rented.', 'wpsight' ),
				'dashboard' => false,
				'priority'  => 10
			)
		);

		// Apply filter and order fields by priority
		$fields = wpsight_sort_array_by_priority( apply_filters( 'wpsight_meta_box_listing_attributes_fields', $fields ) );

		// Set meta box

		$meta_box = array(
			'id'       => 'listing_attributes',
			'title'    => __( 'Listing Attributes', 'wpsight' ),
			'object_types'    => array( wpsight_post_type() ),
			'context'  => 'side',
			'priority' => 'core',
			'fields'   => $fields
		);

		return apply_filters( 'wpsight_meta_box_listing_attributes', $meta_box );

	}

	/**
	 * meta_box_listing_images()
	 *
	 * Create listing images meta box
	 *
	 * @uses wpsight_sort_array_by_priority()
	 * @uses wpsight_post_type()
	 *
	 * @return array $meta_box Meta box array with fields
	 * @see wpsight_meta_boxes()
	 *
	 * @since 1.0.0
	 */

	public static function meta_box_listing_images() {

		// Set meta box fields

		$fields = array(
			'images' => array(
				'name'       => __( 'Images', 'wpsight' ),
				'id'         => '_gallery',
				'type'       => 'file_list',
				'sortable'   => true,
				'desc'       => false,
				'dashboard'  => false
			)
		);

		// Apply filter and order fields by priority
		$fields = wpsight_sort_array_by_priority( apply_filters( 'wpsight_meta_box_listing_images_fields', $fields ) );

		// Set meta box

		$meta_box = array(
			'id'       => 'listing_images',
			'title'    => __( 'Listing Images', 'wpsight' ),
			'object_types'    => array( wpsight_post_type() ),
			'context'  => 'normal',
			'priority' => 'high',
			'fields'   => $fields
		);

		return apply_filters( 'wpsight_meta_box_listing_images', $meta_box );

	}

	/**
	 * meta_box_listing_price()
	 *
	 * Create listing price meta box
	 *
	 * @uses wpsight_offers()
	 * @uses wpsight_rental_periods()
	 * @uses wpsight_sort_array_by_priority()
	 * @uses wpsight_post_type()
	 *
	 * @return array $meta_box Meta box array with fields
	 * @see wpsight_meta_boxes()
	 *
	 * @since 1.0.0
	 */

	public static function meta_box_listing_price() {

		// Set meta box fields

		$fields = array(
			'price' => array(
				'name'      => __( 'Price', 'wpsight' ) . ' (' . wpsight_get_currency() . ')',
				'id'        => '_price',
				'type'      => 'text',
				'desc'      => __( 'No currency symbols or thousands separators', 'wpsight' ),
				'dashboard' => true,
				'priority'  => 10
			),
			'offer' => array(
				'name'      => __( 'Offer', 'wpsight' ),
				'id'        => '_price_offer',
				'type'      => 'radio',
				'options'   => wpsight_offers(),
				'default'   => 'sale',
				'dashboard' => true,
				'priority'  => 20
			),
			'period' => array(
				'name'      => __( 'Period', 'wpsight' ),
				'id'        => '_price_period',
				'type'      => 'select',
				'options'   => array_merge( array( '' => __( 'None', 'wpsight' ) ), wpsight_rental_periods() ),
				'dashboard' => true,
				'priority'  => 30
			)
		);

		// Apply filter and order fields by priority
		$fields = wpsight_sort_array_by_priority( apply_filters( 'wpsight_meta_box_listing_price_fields', $fields ) );

		// Set meta box

		$meta_box = array(
			'id'       => 'listing_price',
			'title'    => __( 'Listing Price', 'wpsight' ),
			'object_types'    => array( wpsight_post_type() ),
			'context'  => 'normal',
			'priority' => 'high',
			'fields'   => $fields
		);

		return apply_filters( 'wpsight_meta_box_listing_price', $meta_box );

	}

	/**
	 * meta_box_listing_details()
	 *
	 * Create listing details meta box
	 *
	 * @uses wpsight_user_can_edit_listing_id()
	 * @uses wpsight_measurements()
	 * @uses wpsight_sort_array_by_priority()
	 * @uses wpsight_post_type()
	 *
	 * @return array $meta_box Meta box array with fields
	 * @see wpsight_meta_boxes()
	 *
	 * @since 1.0.0
	 */

	public static function meta_box_listing_details() {

		// Set meta box fields

		$fields = array(
			'id' => array(
				'name'      => __( 'Listing ID', 'wpsight' ),
				'id'        => '_listing_id',
				'type'      => 'text',
				'dashboard' => wpsight_user_can_edit_listing_id() ? true : 'disabled',
				'readonly'  => wpsight_user_can_edit_listing_id() ? false : true,
				'priority'  => 10
			)
		);

		/**
		 * Add listing details fields
		 */

		$units = wpsight_measurements();

		$prio = 20;

		foreach ( wpsight_details() as $detail => $value ) {

			if ( ! empty( $value['label'] ) ) {

				// Optionally add measurement label to title
				$unit  = '';

				if ( ! empty( $value['unit'] ) ) {
					$unit = $value['unit'];
					$unit = $units[$unit];
					$unit = ' (' . $unit . ')';
				}

				// If there is select data, create select fields else text

				if ( ! empty( $value['data'] ) ) {

					$fields[$detail] = array(
						'name'      => $value['label'] . $unit,
						'id'        => '_' . $detail,
						'type'      => 'select',
						'options'   => $value['data'],
						'desc'      => $value['description'],
						'priority'  => $prio
					);

				} else {

					$fields[$detail] = array(
						'name'      => $value['label'] . $unit,
						'id'        => '_' . $detail,
						'type'      => 'text',
						'desc'      => $value['description'],
						'priority'  => $prio
					);

				} // end if

			} // end if

			$prio +=10;

		} // end foreach

		// Apply filter and order fields by priority
		$fields = wpsight_sort_array_by_priority( apply_filters( 'wpsight_meta_box_listing_details_fields', $fields ) );

		// Set meta box

		$meta_box = array(
			'id'       => 'listing_details',
			'title'    => __( 'Listing Details', 'wpsight' ),
			'object_types'    => array( wpsight_post_type() ),
			'context'  => 'normal',
			'priority' => 'high',
			'fields'   => $fields
		);

		return apply_filters( 'wpsight_meta_box_listing_details', $meta_box );

	}

	/**
	 * meta_box_listing_location()
	 *
	 * Create listing location meta box
	 *
	 * @uses wpsight_sort_array_by_priority()
	 * @uses wpsight_post_type()
	 *
	 * @return array $meta_box Meta box array with fields
	 * @see wpsight_meta_boxes()
	 *
	 * @since 1.0.0
	 */

	public static function meta_box_listing_location() {

		// Create map fields

		$fields = array(
			'address' => array(
				'name'      => __( 'Address', 'wpsight' ),
				'id'        => '_map_address',
				'type'      => 'text',
				'desc'      => __( 'e.g. <code>Marbella, Spain</code> or <code>Platz der Republik 1, 10557 Berlin</code>', 'wpsight' ),
				'class'     => 'map-search',
				'priority'  => 10
			),
			'note' => array(
				'name'      => __( 'Public Note', 'wpsight' ),
				'id'        => '_map_note',
				'type'      => 'text',
				'desc'      => __( 'e.g. <code>Location is not the exact address of the listing</code>', 'wpsight' ),
				'priority'  => 40
			),
			'secret' => array(
				'name'      => __( 'Secret Note', 'wpsight' ),
				'id'        => '_map_secret',
				'type'      => 'textarea',
				'desc'      => __( 'Will not be displayed on the website (e.g. complete address)', 'wpsight' ),
				'priority'  => 50
			),
			'exclude' => array(
				'name'      => __( 'Listings Map', 'wpsight' ),
				'id'        => '_map_exclude',
				'type'      => 'checkbox',
				'label_cb'  => __( 'Exclude from general listings map', 'wpsight' ),
				'priority'  => 60
			)
		);

		// Apply filter and order fields by priority
		$fields = wpsight_sort_array_by_priority( apply_filters( 'wpsight_meta_box_listing_location_fields', $fields ) );

		// Create meta box

		$meta_box = array(
			'id'       => 'listing_location',
			'title'    => __( 'Listing Location', 'wpsight' ),
			'object_types'    => array( wpsight_post_type() ),
			'context'  => 'normal',
			'priority' => 'high',
			'fields'   => $fields
		);

		return apply_filters( 'wpsight_meta_box_listing_location', $meta_box );

	}

	/**
	 * meta_box_listing_agent()
	 *
	 * Create listing agent box
	 *
	 * @uses wpsight_sort_array_by_priority()
	 * @uses wpsight_post_type()
	 *
	 * @return array $meta_box Meta box array with fields
	 * @see wpsight_meta_boxes()
	 *
	 * @since 1.0.0
	 */

	public static function meta_box_listing_agent() {

		// Set meta box fields

		$fields = array(
			'name' => array(
				'name'      => __( 'Name', 'wpsight' ),
				'id'        => '_agent_name',
				'type'      => 'text',
				'desc'      => false,
				'default'   => wp_get_current_user()->display_name,
				'priority'  => 10
			),
			'company' => array(
				'name'      => __( 'Company', 'wpsight' ),
				'id'        => '_agent_company',
				'type'      => 'text',
				'desc'      => false,
				'default'   => get_user_meta( wp_get_current_user()->ID, 'company', true ),
				'priority'  => 20
			),
			'description' => array(
				'name'      => __( 'Description', 'wpsight' ),
				'id'        => '_agent_description',
				'type'      => 'textarea',
				'desc'      => false,
				'default'   => get_user_meta( wp_get_current_user()->ID, 'description', true ),
				'priority'  => 30
			),
			'website' => array(
				'name'      => __( 'Website', 'wpsight' ),
				'id'        => '_agent_website',
				'type'      => 'text_url',
				'desc'      => false,
				'default'   => wp_get_current_user()->user_url,
				'priority'  => 40
			),
			'twitter' => array(
				'name'      => __( 'Twitter', 'wpsight' ),
				'id'        => '_agent_twitter',
				'type'      => 'text',
				'desc'      => false,
				'default'   => get_user_meta( wp_get_current_user()->ID, 'twitter', true ),
				'priority'  => 50
			),
			'facebook' => array(
				'name'      => __( 'Facebook', 'wpsight' ),
				'id'        => '_agent_facebook',
				'type'      => 'text',
				'desc'      => false,
				'default'   => get_user_meta( wp_get_current_user()->ID, 'facebook', true ),
				'priority'  => 60
			),
			'logo' => array(
				'name'      => __( 'Logo', 'wpsight' ),
				'id'        => '_agent_logo',
				'type'      => 'file',
				'desc'      => false,
				'priority'  => 70
			)
		);

		// Apply filter and order fields by priority
		$fields = wpsight_sort_array_by_priority( apply_filters( 'wpsight_meta_box_listing_agent_fields', $fields ) );

		// Set meta box

		$meta_box = array(
			'id'       => 'listing_agent',
			'title'    => __( 'Listing Agent', 'wpsight' ),
			'object_types'    => array( wpsight_post_type() ),
			'context'  => 'normal',
			'priority' => 'high',
			'fields'   => $fields
		);

		return apply_filters( 'wpsight_meta_box_listing_agent', $meta_box );

	}

	/**
	 *  Register meta boxes on user profiles
	 *
	 *  @return  void
	 */
	public static function meta_box_user() {

		// Set meta box fields for users

		if ( ! current_user_can( 'edit_user' ) ) {
			return array();
		}

		$fields = array(
			array(
				'name'     => __( 'Agent Info', 'cmb2' ),
				'desc'     => __( 'These settings let you change how your agent profile will appear in listings.', 'cmb2' ),
				'id'       =>  'user_listing_agent_title',
				'type'     => 'title',
			),
			array(
				'name'      => __( 'Agent Image', 'wpsight' ),
				'id'        => 'agent_logo',
				'type'      => 'file',
				'desc'      => false,
				'priority'  => 10
			),
		);

		// Apply filter and order fields by priority
		$fields = wpsight_sort_array_by_priority( apply_filters( 'wpsight_meta_box_user_fields', $fields ) );

		// Set meta box

		$meta_box = array(
			'id'           => 'user_listing_agent',
			'title'        => __( 'User Listing Agent Settings', 'wpsight' ),
			'object_types' => array('user'),
			'fields'       => $fields
		);

		return apply_filters( 'wpsight_meta_box_user', $meta_box );

	}

	/**
	 * meta_box_spaces()
	 *
	 * Create listing spaces box(es)
	 *
	 * @uses wpsight_spaces()
	 * @uses wpsight_sort_array_by_priority()
	 * @uses wpsight_post_type()
	 *
	 * @return array $meta_box Meta box array with fields
	 * @see wpsight_meta_boxes()
	 * @see /functions/wpsight-general.php => L768
	 *
	 * @since 1.0.0
	 */

	public static function meta_box_spaces() {

		$meta_boxes = array();

		// Loop through existing spaces

		foreach ( wpsight_spaces() as $key => $space ) {

			// Check if multiple fields

			if ( ! isset( $space['fields'] ) || empty( $space['fields'] ) ) {

				// If not, set one field

				$fields = array(
					$key => array(
						'name' => $space['label'],
						'id'   => $space['key'],
						'type' => $space['type'],
						'desc' => $space['description'],
						'rows' => $space['rows']
					)
				);

			} else {

				// If yes, set meta box fields

				$fields = $space['fields'];

				// Set info field as description

				if ( isset( $space['description'] ) && ! empty( $space['description'] ) )
					$fields['description'] = array(
						'id'       => $space['key'] . '_desc',
						'name'     => $space['description'],
						'type'     => 'title',
						'priority' => 9999
					);

			}

			// Apply filter and order fields by priority
			$fields = wpsight_sort_array_by_priority( apply_filters( 'wpsight_meta_box_spaces_fields', $fields ) );

			// Set meta box

			$meta_boxes[$key] = array(
				'id'           => $key,
				'title'        => $space['title'],
				'object_types' => $space['post_type'],
				'context'      => 'normal',
				'priority'     => 'high',
				'fields'       => $fields

			);

		} // endforeach

		return apply_filters( 'wpsight_meta_box_spaces', $meta_boxes );

	}

}
