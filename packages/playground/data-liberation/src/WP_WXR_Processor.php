<?php
/**
 * @TODO:
 * – Should this extend WP_XML_Processor? Or should it keep track of its own paused
 *   state independently of the underlying WP_XML_Processor? Would anything change
 *   if the XML processor was receiving data from a HTTP -> unzip stream?
 * - Ensure we can pause in the middle of an item node, crash, and then resume later
 *   on. This would require setting bookmarks before/after each major parsed entity.
 * - Decide: Should rewriting site URLs be done here? Or should it be done later on
 *   in an importer-agnostic way that we could also apply to markdown files, site
 *   transfers etc.? Fetching assets should not happen in this class for sure.
 * - Explicitly define and test failure modes. Provide useful error messages with clear
 *   instructions on how to fix the problem.
 */

class WP_WXR_Processor {

	/**
	 * @var WP_XML_Processor
	 */
	private $xml;

	private $entity_tag;
	private $entity_type;
	private $entity_data;
	private $entity_finished        = false;
	private $last_opener_attributes = array();
	private $last_post_id           = null;
	private $last_comment_id        = null;
	private $text_buffer            = '';

	const KNOWN_ENITIES = array(
		'wp:comment' => array(
			'type' => 'comment',
			'fields' => array(
				'wp:comment_id' => 'ID',
				'wp:comment_author' => 'comment_author',
				'wp:comment_author_email' => 'comment_author_email',
				'wp:comment_author_url' => 'comment_author_url',
				'wp:comment_author_IP' => 'comment_author_IP',
				'wp:comment_date' => 'comment_date',
				'wp:comment_date_gmt' => 'comment_date_gmt',
				'wp:comment_content' => 'comment_content',
				'wp:comment_approved' => 'comment_approved',
				'wp:comment_type' => 'comment_type',
				'wp:comment_parent' => 'comment_parent',
				'wp:comment_user_id' => 'comment_user_id',
			),
		),
		'wp:commentmeta' => array(
			'type' => 'comment_meta',
			'fields' => array(
				'wp:meta_key' => 'meta_key',
				'wp:meta_value' => 'meta_value',
			),
		),
		'wp:author' => array(
			'type' => 'user',
			'fields' => array(
				'wp:author_id' => 'ID',
				'wp:author_login' => 'user_login',
				'wp:author_email' => 'user_email',
				'wp:author_display_name' => 'display_name',
				'wp:author_first_name' => 'first_name',
				'wp:author_last_name' => 'last_name',
			),
		),
		'item' => array(
			'type' => 'post',
			'fields' => array(
				'title' => 'post_title',
				'link' => 'link',
				'guid' => 'guid',
				'description' => 'post_excerpt',
				'pubDate' => 'post_published_at',
				'dc:creator' => 'post_author',
				'content:encoded' => 'post_content',
				'excerpt:encoded' => 'post_excerpt',
				'wp:post_id' => 'ID',
				'wp:status' => 'post_status',
				'wp:post_date' => 'post_date',
				'wp:post_date_gmt' => 'post_date_gmt',
				'wp:post_modified' => 'post_modified',
				'wp:post_modified_gmt' => 'post_modified_gmt',
				'wp:comment_status' => 'comment_status',
				'wp:ping_status' => 'ping_status',
				'wp:post_name' => 'post_name',
				'wp:post_parent' => 'post_parent',
				'wp:menu_order' => 'menu_order',
				'wp:post_type' => 'post_type',
				'wp:post_password' => 'post_password',
				'wp:is_sticky' => 'is_sticky',
				'wp:attachment_url' => 'attachment_url',
			),
		),
		'wp:postmeta' => array(
			'type' => 'post_meta',
			'fields' => array(
				'wp:meta_key' => 'meta_key',
				'wp:meta_value' => 'meta_value',
			),
		),
		'wp:term' => array(
			'type' => 'term',
			'fields' => array(
				'wp:term_id' => 'term_id',
				'wp:term_taxonomy' => 'taxonomy',
				'wp:term_slug' => 'slug',
				'wp:term_parent' => 'parent',
				'wp:term_name' => 'name',
			),
		),
		'wp:tag' => array(
			'type' => 'tag',
			'fields' => array(
				'wp:term_id' => 'term_id',
				'wp:tag_slug' => 'slug',
				'wp:tag_name' => 'name',
				'wp:tag_description' => 'description',
			),
		),
		'wp:category' => array(
			'type' => 'category',
			'fields' => array(
				'wp:category_nicename' => 'nicename',
				'wp:category_parent' => 'parent',
				'wp:cat_name' => 'name',
			),
		),
	);

	public static function from_string( $wxr_bytes = '' ) {
		return new WP_WXR_Processor( WP_XML_Processor::from_string( $wxr_bytes ) );
	}

	public static function from_stream( $wxr_bytes = '' ) {
		return new WP_WXR_Processor( WP_XML_Processor::from_stream( $wxr_bytes ) );
	}

	protected function __construct( $xml ) {
		$this->xml = $xml;
	}

	public function get_entity_type() {
		if ( null !== $this->entity_type ) {
			return $this->entity_type;
		}
		if ( null === $this->entity_tag ) {
			return false;
		}
		if ( ! array_key_exists( $this->entity_tag, static::KNOWN_ENITIES ) ) {
			return false;
		}
		return static::KNOWN_ENITIES[ $this->entity_tag ]['type'];
	}

	public function get_entity_data() {
		return $this->entity_data;
	}

	public function get_last_post_id() {
		return $this->last_post_id;
	}

	public function get_last_comment_id() {
		return $this->last_comment_id;
	}

	public function append_bytes( string $bytes ) {
		$this->xml->append_bytes( $bytes );
	}

	public function input_finished(): void {
		$this->xml->input_finished();
	}

	public function is_finished(): bool {
		return $this->xml->is_finished();
	}

	public function is_paused_at_incomplete_input(): bool {
		return $this->xml->is_paused_at_incomplete_input();
	}

	public function get_last_error(): ?string {
		return $this->xml->get_last_error();
	}

	public function next_entity() {
		if (
			$this->xml->is_finished() ||
			$this->xml->is_paused_at_incomplete_input()
		) {
			return false;
		}

		if ( $this->entity_type && $this->entity_finished ) {
			$this->after_entity();
			if ( $this->xml->is_tag_closer() ) {
				if ( false === $this->xml->next_token() ) {
					return false;
				}
			}
		}

		do {
			$breadcrumbs = $this->xml->get_breadcrumbs();
			if (
				count( $breadcrumbs ) < 2 ||
				$breadcrumbs[0] !== 'rss' ||
				$breadcrumbs[1] !== 'channel'
			) {
				continue;
			}

			if (
				$this->xml->get_token_type() === '#text' ||
				$this->xml->get_token_type() === '#cdata-section'
			) {
				$this->text_buffer .= $this->xml->get_modifiable_text();
				continue;
			}

			if ( $this->xml->get_token_type() !== '#tag' ) {
				continue;
			}

			$tag = $this->xml->get_tag();
			// The Accessibility WXR file uses a non-standard wp:wp_author tag.
			if ( $tag === 'wp:wp_author' ) {
				$tag = 'wp:author';
			}
			if ( array_key_exists( $tag, static::KNOWN_ENITIES ) ) {
				if ( $this->entity_type && ! $this->entity_finished ) {
					$this->emit_entity();
					return true;
				}
				$this->after_entity();
				if ( $this->xml->is_tag_opener() ) {
					$this->set_entity_tag( $tag );
				}
				continue;
			}

			if ( $this->xml->is_tag_opener() ) {
				$this->last_opener_attributes = array();
				$names                        = $this->xml->get_attribute_names_with_prefix( '' );
				foreach ( $names as $name ) {
					$this->last_opener_attributes[ $name ] = $this->xml->get_attribute( $name );
				}
			} elseif ( $this->xml->is_tag_closer() ) {
				/**
				 * Only process site options when they are at the top level.
				 */
				if (
					! $this->entity_finished &&
					$this->xml->get_breadcrumbs() === array( 'rss', 'channel' )
				) {
					switch ( $tag ) {
						case 'wp:base_blog_url':
							$this->entity_type = 'site_option';
							$this->entity_data = array(
								'option_name' => 'home',
								'option_value' => $this->text_buffer,
							);
							$this->emit_entity();
							return true;
						case 'wp:base_site_url':
							$this->entity_type = 'site_option';
							$this->entity_data = array(
								'option_name' => 'siteurl',
								'option_value' => $this->text_buffer,
							);
							$this->emit_entity();
							return true;
						case 'title':
							$this->entity_type = 'site_option';
							$this->entity_data = array(
								'option_name' => 'blogname',
								'option_value' => $this->text_buffer,
							);
							$this->emit_entity();
							return true;
					}
				} elseif ( $this->entity_type === 'post' ) {
					if ( $tag === 'category' ) {
						$term_name = $this->last_opener_attributes['domain'];
						if ( empty( $this->entity_data['terms'][ $term_name ] ) ) {
							$this->entity_data['terms'][ $term_name ] = array();
						}
						$this->entity_data['terms'][ $term_name ][] = $this->text_buffer;
						continue;
					}
				}

				if ( ! isset( static::KNOWN_ENITIES[ $this->entity_tag ]['fields'][ $tag ] ) ) {
					// @TODO: Log this?
					continue;
				}

				$key                       = static::KNOWN_ENITIES[ $this->entity_tag ]['fields'][ $tag ];
				$this->entity_data[ $key ] = $this->text_buffer;
			}
			$this->text_buffer = '';
		} while ( $this->xml->next_token() );

		if ( $this->is_paused_at_incomplete_input() ) {
			return false;
		}

		/**
		 * Emit the last unemitted entity after parsing all the data.
		 */
		if ( $this->is_finished() &&
			$this->entity_type &&
			! $this->entity_finished
		) {
			$this->emit_entity();
			return true;
		}

		return false;
	}

	private function emit_entity() {
		if ( $this->entity_type === 'post' ) {
			$this->last_post_id = $this->entity_data['ID'];
		} elseif ( $this->entity_type === 'comment' ) {
			$this->last_comment_id = $this->entity_data['ID'];
		}
		$this->entity_finished = true;
	}

	private function set_entity_tag( string $tag ) {
		$this->entity_tag = $tag;
		if ( array_key_exists( $tag, static::KNOWN_ENITIES ) ) {
			$this->entity_type = static::KNOWN_ENITIES[ $tag ]['type'];
		}
	}

	private function after_entity() {
		$this->entity_tag             = null;
		$this->entity_type            = null;
		$this->entity_data            = array();
		$this->entity_finished        = false;
		$this->text_buffer            = '';
		$this->last_opener_attributes = array();
	}
}
