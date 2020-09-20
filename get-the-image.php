<?php
/**
 * Plugin Name: Bestwebsite Get The Image
 * Plugin URI:  https://github.com/bestwebsite/get-the-image
 * Description: This is a highly intuitive script that can grab an image by custom field, featured image, post attachment, or extracting it from the post's content.
 * Version:     1.0
 * Author:      Bestwebsite
 * Author URI:  https://bestwebsite.com
 */

# Adds theme support for WordPress 'featured images'.
add_theme_support( 'post-thumbnails' );

# Delete the cache when a post or post metadata is updated.
add_action( 'save_post',         'get_the_image_delete_cache_by_post'        );
add_action( 'deleted_post_meta', 'get_the_image_delete_cache_by_meta', 10, 2 );
add_action( 'updated_post_meta', 'get_the_image_delete_cache_by_meta', 10, 2 );
add_action( 'added_post_meta',   'get_the_image_delete_cache_by_meta', 10, 2 );

function get_the_image( $args = array() ) {

	$image = new Get_The_Image( $args );

	return $image->get_image();
}

final class Get_The_Image {

	public $image_args  = array();

	
	public $image = '';

	
	public $original_image = '';

	
	public $srcsets = array();

	
	public function __construct( $args = array() ) {
		global $wp_embed;

		// Use WP's embed functionality to handle the [embed] shortcode and autoembeds. */
		add_filter( 'get_the_image_post_content', array( $wp_embed, 'run_shortcode' ) );
		add_filter( 'get_the_image_post_content', array( $wp_embed, 'autoembed'     ) );

		// Set the default arguments.
		$defaults = array(

			// Post the image is associated with.
			'post_id'            => get_the_ID(),

			// Method order (see methods below).
			'order'              => array( 'meta_key', 'featured', 'attachment', 'scan', 'scan_raw', 'callback', 'default' ),

			// Methods of getting an image (in order).
			'meta_key'           => array( 'Thumbnail', 'thumbnail' ), // array|string
			'featured'           => true,
			'attachment'         => true,
			'scan'               => false,
			'scan_raw'           => false, // Note: don't use the array format option with this.
			'callback'           => null,
			'default'            => false,

			// Split image from post content (by default, only used with the 'scan_raw' option).
			'split_content'      => false,

			// Attachment-specific arguments.
			'size'               => has_image_size( 'post-thumbnail' ) ? 'post-thumbnail' : 'thumbnail',

			// Key (image size) / Value ( width or px-density descriptor) pairs (e.g., 'large' => '2x' )
			'srcset_sizes'       => array(),

			// Format/display of image.
			'link'               => 'post', // string|bool - 'post' (true), 'file', 'attachment', false
			'link_class'         => '',
			'image_class'        => false,
			'image_attr'         => array(),
			'width'              => false,
			'height'             => false,
			'before'             => '',
			'after'              => '',

			// Minimum allowed sizes.
			'min_width'          => 0,
			'min_height'         => 0,

			// Captions.
			'caption'            => false, // Default WP [caption] requires a width.

			// Saving the image.
			'meta_key_save'      => false, // Save as metadata (string).
			'thumbnail_id_save'  => false, // Set 'featured image'.
			'cache'              => true,  // Cache the image.

			// Return/echo image.
			'format'             => 'img',
			'echo'               => true,

			// Deprecated arguments.
			'custom_key'         => null, // @deprecated 0.6.0 Use 'meta_key'.
			'default_size'       => null, // @deprecated 0.5.0 Use 'size'.
			'the_post_thumbnail' => null, // @deprecated 1.0.0 Use 'featured'.
			'image_scan'         => null, // @deprecated 1.0.0 Use 'scan' or 'scan_raw'.
			'default_image'      => null, // @deprecated 1.0.0 Use 'default'.
			'order_of_image'     => null, // @deprecated 1.0.0 No replacement.
			'link_to_post'       => null, // @deprecated 1.1.0 Use 'link'.
		);

		// Allow plugins/themes to filter the arguments.
		$this->args = apply_filters(
			'get_the_image_args',
			wp_parse_args( $args, $defaults )
		);

		// If no post ID, return.
		if ( empty( $this->args['post_id'] ) )
			return false;

		/* === Handle deprecated arguments. === */

		// If $default_size is given, overwrite $size.
		if ( !is_null( $this->args['default_size'] ) )
			$this->args['size'] = $this->args['default_size'];

		// If $custom_key is set, overwrite $meta_key.
		if ( !is_null( $this->args['custom_key'] ) )
			$this->args['meta_key'] = $this->args['custom_key'];

		// If 'the_post_thumbnail' is set, overwrite 'featured'.
		if ( !is_null( $this->args['the_post_thumbnail'] ) )
			$this->args['featured'] = $this->args['the_post_thumbnail'];

		// If 'image_scan' is set, overwrite 'scan'.
		if ( !is_null( $this->args['image_scan'] ) )
			$this->args['scan'] = $this->args['image_scan'];

		// If 'default_image' is set, overwrite 'default'.
		if ( !is_null( $this->args['default_image'] ) )
			$this->args['default'] = $this->args['default_image'];

		// If 'link_to_post' is set, overwrite 'link'.
		if ( !is_null( $this->args['link_to_post'] ) )
			$this->args['link'] = true === $this->args['link_to_post'] ? 'post' : false;

		/* === End deprecated arguments. === */

		// If $format is set to 'array', don't link to the post.
		if ( 'array' == $this->args['format'] )
			$this->args['link'] = false;

		// Find images.
		$this->find();

		// Only used if $original_image is set.
		if ( true === $this->args['split_content'] && !empty( $this->original_image ) )
			add_filter( 'the_content', array( $this, 'split_content' ), 9 );
	}

	public function get_image() {

		// Allow plugins/theme to override the final output.
		$image_html = apply_filters( 'get_the_image', $this->image );

		// If $format is set to 'array', return an array of image attributes.
		if ( 'array' === $this->args['format'] ) {

			// Set up a default empty array.
			$out = array();

			// Get the image attributes.
			$atts = wp_kses_hair( $image_html, array( 'http', 'https' ) );

			// Loop through the image attributes and add them in key/value pairs for the return array.
			foreach ( $atts as $att )
				$out[ $att['name'] ] = $att['value'];

			// Return the array of attributes.
			return $out;
		}

		// Or, if $echo is set to false, return the formatted image.
		elseif ( false === $this->args['echo'] ) {
			return !empty( $image_html ) ? $this->args['before'] . $image_html . $this->args['after'] : $image_html;
		}

		// If there is a $post_thumbnail_id, do the actions associated with get_the_post_thumbnail().
		if ( isset( $this->image_args['post_thumbnail_id'] ) )
			do_action( 'begin_fetch_post_thumbnail_html', $this->args['post_id'], $this->image_args['post_thumbnail_id'], $this->args['size'] );

		// Display the image if we get to this point.
		echo !empty( $image_html ) ? $this->args['before'] . $image_html . $this->args['after'] : $image_html;

		// If there is a $post_thumbnail_id, do the actions associated with get_the_post_thumbnail().
		if ( isset( $this->image_args['post_thumbnail_id'] ) )
			do_action( 'end_fetch_post_thumbnail_html', $this->args['post_id'], $this->image_args['post_thumbnail_id'], $this->args['size'] );
	}

	
	public function find() {

		// Get cache key based on $this->args.
		$key = md5( serialize( compact( array_keys( $this->args ) ) ) );

		// Check for a cached image.
		$image_cache = wp_cache_get( $this->args['post_id'], 'get_the_image' );

		if ( !is_array( $image_cache ) )
			$image_cache = array();

		// If there is no cached image, let's see if one exists.
		if ( !isset( $image_cache[ $key ] ) || empty( $cache ) ) {

			foreach ( $this->args['order'] as $method ) {

				if ( !empty( $this->image ) || !empty( $this->image_args ) )
					break;

				if ( 'meta_key' === $method && !empty( $this->args['meta_key'] ) )
					$this->get_meta_key_image();

				elseif ( 'featured' === $method && true === $this->args['featured'] )
					$this->get_featured_image();

				elseif ( 'attachment' === $method && true === $this->args['attachment'] )
					$this->get_attachment_image();

				elseif ( 'scan' === $method && true === $this->args['scan'] )
					$this->get_scan_image();

				elseif ( 'scan_raw' === $method && true === $this->args['scan_raw'])
					$this->get_scan_raw_image();

				elseif ( 'callback' === $method && !is_null( $this->args['callback'] ) )
					$this->get_callback_image();

				elseif ( 'default' === $method && !empty( $this->args['default'] ) )
					$this->get_default_image();
			}

			// Format the image HTML.
			if ( empty( $this->image ) && !empty( $this->image_args ) )
				$this->format_image();

			// If we have image HTML.
			if ( !empty( $this->image ) ) {

				// Save the image as metadata.
				if ( !empty( $this->args['meta_key_save'] ) )
					$this->meta_key_save();

				// Set the image cache for the specific post.
				$image_cache[ $key ] = $this->image;
				wp_cache_set( $this->args['post_id'], $image_cache, 'get_the_image' );
			}
		}

		// If an image was already cached for the post and arguments, use it.
		else {
			$this->image = $image_cache[ $key ];
		}
	}

	
	public function get_meta_key_image() {

		// If $meta_key is not an array.
		if ( !is_array( $this->args['meta_key'] ) )
			$this->args['meta_key'] = array( $this->args['meta_key'] );

		// Loop through each of the given meta keys.
		foreach ( $this->args['meta_key'] as $meta_key ) {

			// Get the image URL by the current meta key in the loop.
			$image = get_post_meta( $this->args['post_id'], $meta_key, true );

			// If an image was found, break out of the loop.
			if ( !empty( $image ) )
				break;
		}

		// If there's an image and it is numeric, assume it is an attachment ID.
		if ( !empty( $image ) && is_numeric( $image ) )
			$this->_get_image_attachment( absint( $image ) );

		// Else, assume the image is a file URL.
		elseif ( !empty( $image ) )
			$this->image_args = array( 'src' => $image );
	}

	public function get_featured_image() {

		// Check for a post image ID (set by WP as a custom field).
		$post_thumbnail_id = get_post_thumbnail_id( $this->args['post_id'] );

		// If no post image ID is found, return.
		if ( empty( $post_thumbnail_id ) )
			return;

		// Apply filters on post_thumbnail_size because this is a default WP filter used with its image feature.
		$this->args['size'] = apply_filters( 'post_thumbnail_size', $this->args['size'] );

		// Set the image args.
		$this->_get_image_attachment( $post_thumbnail_id );

		// Add the post thumbnail ID.
		if ( $this->image_args )
			$this->image_args['post_thumbnail_id'] = $post_thumbnail_id;
	}


	public function get_attachment_image() {

		// Check if the post itself is an image attachment.
		if ( wp_attachment_is_image( $this->args['post_id'] ) ) {
			$attachment_id = $this->args['post_id'];
		}

		// If the post is not an image attachment, check if it has any image attachments.
		else {

			// Get attachments for the inputted $post_id.
			$attachments = get_children(
				array(
					'numberposts'      => 1,
					'post_parent'      => $this->args['post_id'],
					'post_status'      => 'inherit',
					'post_type'        => 'attachment',
					'post_mime_type'   => 'image',
					'order'            => 'ASC',
					'orderby'          => 'menu_order ID',
					'fields'           => 'ids'
				)
			);

			// Check if any attachments were found.
			if ( !empty( $attachments ) )
				$attachment_id = array_shift( $attachments );
		}

		if ( !empty( $attachment_id ) )
			$this->_get_image_attachment( $attachment_id );
	}

	
	public function get_scan_image() {

		// Get the post content.
		$post_content = get_post_field( 'post_content', $this->args['post_id'] );

		// Apply filters to content.
		$post_content = apply_filters( 'get_the_image_post_content', $post_content );

		// Check the content for `id="wp-image-%d"`.
		preg_match( '/id=[\'"]wp-image-([\d]*)[\'"]/i', $post_content, $image_ids );

		// Loop through any found image IDs.
		if ( is_array( $image_ids ) ) {

			foreach ( $image_ids as $image_id ) {
				$this->_get_image_attachment( $image_id );

				if ( !empty( $this->image_args ) )
					return;
			}
		}

		// Search the post's content for the <img /> tag and get its URL.
		preg_match_all( '|<img.*?src=[\'"](.*?)[\'"].*?>|i', $post_content, $matches );

		// If there is a match for the image, set the image args.
		if ( isset( $matches ) && !empty( $matches[1][0] ) )
			$this->image_args = array( 'src' => $matches[1][0] );
	}

	
	public function get_scan_raw_image() {

		// Get the post content.
		$post_content = get_post_field( 'post_content', $this->args['post_id'] );

		// Apply filters to content.
		$post_content = apply_filters( 'get_the_image_post_content', $post_content );

		// Finds matches for shortcodes in the content.
		preg_match_all( '/' . get_shortcode_regex() . '/s', $post_content, $matches, PREG_SET_ORDER );

		if ( !empty( $matches ) ) {

			foreach ( $matches as $shortcode ) {

				if ( in_array( $shortcode[2], array( 'caption', 'wp_caption' ) ) ) {

					preg_match( '#id=[\'"]attachment_([\d]*)[\'"]|class=[\'"].*?wp-image-([\d]*).*?[\'"]#i', $shortcode[0], $matches );

					if ( !empty( $matches ) && isset( $matches[1] ) || isset( $matches[2] ) ) {

						$attachment_id = !empty( $matches[1] ) ? absint( $matches[1] ) : absint( $matches[2] );

						$image_src = wp_get_attachment_image_src( $attachment_id, $this->args['size'] );

						if ( !empty( $image_src ) ) {

							// Old-style captions.
							if ( preg_match( '#.*?[\s]caption=[\'"](.+?)[\'"]#i', $shortcode[0], $caption_matches ) )
								$image_caption = trim( $caption_matches[1] );

							$caption_args = array(
								'width'   => $image_src[1],
								'align'   => 'center'
							);

							if ( !empty( $image_caption ) )
								$caption_args['caption'] = $image_caption;

							// Set up the patterns for the 'src', 'width', and 'height' attributes.
							$patterns = array(
								'/(src=[\'"]).+?([\'"])/i',
								'/(width=[\'"]).+?([\'"])/i',
								'/(height=[\'"]).+?([\'"])/i',
							);

							// Set up the replacements for the 'src', 'width', and 'height' attributes.
							$replacements = array(
								'${1}' . $image_src[0] . '${2}',
								'${1}' . $image_src[1] . '${2}',
								'${1}' . $image_src[2] . '${2}',
							);

							// Filter the image attributes.
							$shortcode_content = preg_replace( $patterns, $replacements, $shortcode[5] );

							$this->image          = img_caption_shortcode( $caption_args, $shortcode_content );
							$this->original_image = $shortcode[0];
							return;
						}
						else {
							$this->image          = do_shortcode( $shortcode[0] );
							$this->original_image = $shortcode[0];
							return;
						}
					}
				}
			}
		}

		// Pull a raw HTML image + link if it exists.
		if ( preg_match( '#((?:<a [^>]+>\s*)?<img [^>]+>(?:\s*</a>)?)#is', $post_content, $matches ) )
			$this->image = $this->original_image = $matches[0];
	}

	
	public function get_callback_image() {
		$this->image_args = call_user_func( $this->args['callback'], $this->args );
	}

	
	public function get_default_image() {
		$this->image_args = array( 'src' => $this->args['default'] );
	}

	
	public function _get_image_attachment( $attachment_id ) {

		// Get the attachment image.
		$image = wp_get_attachment_image_src( $attachment_id, $this->args['size'] );

		// Get the attachment alt text.
		$alt = trim( strip_tags( get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ) );

		// Get the attachment caption.
		$caption = get_post_field( 'post_excerpt', $attachment_id );

		// Only use the image if we have an image and it meets the size requirements.
		if ( ! $image || ! $this->have_required_dimensions( $image[1], $image[2] ) )
			return;

		// Save the attachment as the 'featured image'.
		if ( true === $this->args['thumbnail_id_save'] )
			$this->thumbnail_id_save( $attachment_id );

		// Set the image args.
		$this->image_args = array(
			'id'      => $attachment_id,
			'src'     => $image[0],
			'width'   => $image[1],
			'height'  => $image[2],
			'alt'     => $alt,
			'caption' => $caption
		);

		// Get the image srcset sizes.
		$this->get_srcset( $attachment_id );
	}

	
	public function get_srcset( $attachment_id ) {

		// Bail if no sizes set.
		if ( empty( $this->args['srcset_sizes'] ) )
			return;

		foreach ( $this->args['srcset_sizes'] as $size => $descriptor ) {

			$image = wp_get_attachment_image_src( $attachment_id, $size );

			// Make sure image doesn't match the image used for the `src` attribute.
			// This will happen often if the particular image size doesn't exist.
			if ( $this->image_args['src'] !== $image[0] )
				$this->srcsets[] = sprintf( "%s %s", esc_url( $image[0] ), esc_attr( $descriptor ) );
		}
	}

	public function format_image() {

		// If there is no image URL, return false.
		if ( empty( $this->image_args['src'] ) )
			return;

		// Check against min. width and height. If the image is too small return.
		if ( isset( $this->image_args['width'] ) || isset( $this->image_args['height'] ) ) {

			$_w = isset( $this->image_args['width'] )  ? $this->image_args['width']  : false;
			$_h = isset( $this->image_args['height'] ) ? $this->image_args['height'] : false;

			if ( ! $this->have_required_dimensions( $_w, $_h ) )
				return;
		}

		// Set up a variable for the image attributes.
		$img_attr = '';

		// Loop through the image attributes and format them for display.
		foreach ( $this->get_image_attr() as $name => $value )
			$img_attr .= false !== $value ? sprintf( ' %s="%s"', esc_html( $name ), esc_attr( $value ) ) : esc_html( " {$name}" );

		// Add the image attributes to the <img /> element.
		$html = sprintf( '<img %s />', $img_attr );

		// If $link is set to true, link the image to its post.
		if ( false !== $this->args['link'] ) {

			if ( 'post' === $this->args['link'] || true === $this->args['link'] )
				$url = get_permalink( $this->args['post_id'] );

			elseif ( 'file' === $this->args['link'] )
				$url = $this->image_args['src'];

			elseif ( 'attachment' === $this->args['link'] && isset( $this->image_args['id'] ) )
				$url = get_permalink( $this->image_args['id'] );

			if ( ! empty( $url ) ) {

				$link_class = $this->args['link_class'] ? sprintf( ' class="%s"', esc_attr( $this->args['link_class'] ) ) : '';

				$html = sprintf( '<a href="%s"%s>%s</a>', esc_url( $url ), $link_class, $html );
			}
		}

		// If there is a $post_thumbnail_id, apply the WP filters normally associated with get_the_post_thumbnail().
		if ( ! empty( $this->image_args['post_thumbnail_id'] ) )
			$html = apply_filters( 'post_thumbnail_html', $html, $this->args['post_id'], $this->image_args['post_thumbnail_id'], $this->args['size'], '' );

		// If we're showing a caption.
		if ( true === $this->args['caption'] && ! empty( $this->image_args['caption'] ) )
			$html = img_caption_shortcode( array( 'caption' => $this->image_args['caption'], 'width' => $this->args['width'] ), $html );

		$this->image = $html;
	}


	public function get_image_attr() {

		$attr = array();

		// Add the image class.
		$attr['class'] = join( ' ', $this->get_image_class() );

		// If there's a width/height for the image.
		if ( isset( $this->image_args['width'] ) && isset( $this->image_args['height'] ) ) {

			// If an explicit width/height is not set, use the info from the image.
			if ( ! $this->args['width'] && ! $this->args['height'] ) {

				$this->args['width']  = $this->image_args['width'];
				$this->args['height'] = $this->image_args['height'];
			}
		}

		// If there is a width or height, set them.
		if ( $this->args['width'] )
			$attr['width'] = $this->args['width'];

		if ( $this->args['height'] )
			$attr['height'] = $this->args['height'];

		// If there is alt text, set it.  Otherwise, default to the post title.
		$attr['alt'] = ! empty( $this->image_args['alt'] ) ? $this->image_args['alt'] : get_post_field( 'post_title', $this->args['post_id'] );

		// Add the itemprop attribute.
		$attr['itemprop'] = 'image';

		// Parse the args with the user inputted args.
		$attr = wp_parse_args( $this->args['image_attr'], $attr );

		// Allow devs to filter the image attributes.
		$attr = apply_filters( 'get_the_image_attr', $attr, $this );

		// Add the image source after the filter so that it can't be overwritten.
		$attr['src'] = $this->image_args['src'];

		// Return attributes.
		return $attr;
	}


	public function get_image_class() {
		global $content_width;

		$classes = array();

		// Get true image height and width.
		$width  = isset( $this->image_args['width'] )  ? $this->image_args['width']  : false;
		$height = isset( $this->image_args['height'] ) ? $this->image_args['height'] : false;

		// If there's a width/height for the image.
		if ( $width && $height ) {

			// Set a class based on the orientation.
			$classes[] = $height > $width ? 'portrait' : 'landscape';

			// Set class based on the content width (defined by theme).
			if ( 0 < $content_width ) {

				if ( $content_width == $width )
					$classes[] = 'cw-equal';

				elseif ( $content_width <= $width )
					$classes[] = 'cw-lesser';

				elseif ( $content_width >= $width )
					$classes[] = 'cw-greater';
			}
		}

		// Add the meta key(s) to the classes array.
		if ( ! empty( $this->args['meta_key'] ) )
			$classes = array_merge( $classes, (array)$this->args['meta_key'] );

		// Add the $size to the class.
		$classes[] = $this->args['size'];

		// Get the custom image class.
		if ( ! empty( $this->args['image_class'] ) ) {

			if ( ! is_array( $this->args['image_class'] ) )
				$this->args['image_class'] = preg_split( '#\s+#', $this->args['image_class'] );

			$classes = array_merge( $classes, $this->args['image_class'] );
		}

		return apply_filters( 'get_the_image_class', $this->sanitize_class( $classes ), $this );
	}

	public function meta_key_save() {

		// If the $meta_key_save argument is empty or there is no image $url given, return.
		if ( empty( $this->args['meta_key_save'] ) || empty( $this->image_args['src'] ) )
			return;

		// Get the current value of the meta key.
		$meta = get_post_meta( $this->args['post_id'], $this->args['meta_key_save'], true );

		// If there is no value for the meta key, set a new value with the image $url.
		if ( empty( $meta ) )
			add_post_meta( $this->args['post_id'], $this->args['meta_key_save'], $this->image_args['src'] );

		// If the current value doesn't match the image $url, update it.
		elseif ( $meta !== $this->image_args['src'] )
			update_post_meta( $this->args['post_id'], $this->args['meta_key_save'], $this->image_args['src'], $meta );
	}

	
	public function thumbnail_id_save( $attachment_id ) {

		// Save the attachment as the 'featured image'.
		if ( true === $this->args['thumbnail_id_save'] )
			set_post_thumbnail( $this->args['post_id'], $attachment_id );
	}

	public function sanitize_class( $classes ) {

		$classes = array_map( 'strtolower',          $classes );
		$classes = array_map( 'sanitize_html_class', $classes );

		return array_unique( $classes );
	}

	public function split_content( $content ) {

		remove_filter( 'the_content', array( $this, 'split_content' ), 9 );

		return str_replace( $this->original_image, '', $content );
	}

	public function have_required_dimensions( $width = false, $height = false ) {

		// Check against min. width. If the image width is too small return.
		if ( 0 < $this->args['min_width'] && $width && $width < $this->args['min_width'] )
			return false;

		// Check against min. height. If the image height is too small return.
		if ( 0 < $this->args['min_height'] && $height && $height < $this->args['min_height'] )
			return false;

		return true;
	}
}

function get_the_image_delete_cache_by_post( $post_id ) {
	wp_cache_delete( $post_id, 'get_the_image' );
}

function get_the_image_delete_cache_by_meta( $meta_id, $post_id ) {
	wp_cache_delete( $post_id, 'get_the_image' );
}


function get_the_image_link() {
	_deprecated_function( __FUNCTION__, '0.3.0', 'get_the_image' );
	get_the_image( array( 'link_to_post' => true ) );
}

function image_by_custom_field() {}

function image_by_the_post_thumbnail() {}

function image_by_attachment() {}

function image_by_scan() {}

function image_by_default() {}

function display_the_image() {}

function get_the_image_delete_cache() {}

function get_the_image_by_meta_key() {}

function get_the_image_by_post_thumbnail() {}

function get_the_image_by_attachment() {}

function get_the_image_by_scan() {}

function get_the_image_by_default() {}

function get_the_image_format() {}

function get_the_image_meta_key_save() {}
