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

    private $object_type;
    private $object_data;
    private $object_depth;
    private $object_finished = true;
	private $last_post_id = null;
	private $last_comment_id = null;

	static public function from_string( $wxr_bytes = '' ) {
		return new WP_WXR_Processor( WP_XML_Processor::from_string( $wxr_bytes ) );
	}
	
	static public function from_stream( $wxr_bytes = '' ) {
		return new WP_WXR_Processor( WP_XML_Processor::from_stream( $wxr_bytes ) );
	}

	protected function __construct($xml)
	{
		$this->xml = $xml;		
	}

	public function get_object_type() {
		return $this->object_type;
	}

	public function get_object_data() {
		return $this->object_data;
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

	public function next_object() {
		if ( 
			$this->xml->is_finished() ||
			$this->xml->is_paused_at_incomplete_input()
		) {
			return false;
		}

		if ( $this->object_finished ) {
			$this->after_object();
		}

		if ( null === $this->object_type ) {
			if ( false === $this->find_next_object() ) {
				return false;
			}
			$this->object_depth = $this->xml->get_current_depth();
		}

		while ( ! $this->object_finished ) {
			if ( false === $this->xml->next_token() ) {
				return false;
			}

			if (
				$this->xml->is_tag_closer() &&
				$this->xml->get_current_depth() < $this->object_depth 
			) {
				// We've stepped out of the current object, let's emit it
				$this->object_finished = true;
				return true;
			} else if ( $this->xml->is_empty_element() ) {
				continue;
			}

			switch ( $this->object_type ) {
				case 'term':
					$success = $this->step_in_term();
					break;
				case 'user':
					$success = $this->step_in_user();
					break;
				case 'comment':
					$success = $this->step_in_comment();
					break;
				case 'category':
					$success = $this->step_in_category();
					break;
				case 'tag':
					$success = $this->step_in_tag();
					break;
				case 'post':
					$success = $this->step_in_post();
					break;
				case 'post_meta':
					$success = $this->step_in_post_meta();
					break;
				case 'comment_meta':
					$success = $this->step_in_comment_meta();
					break;
				default:
					throw new \Exception( 'Unknown object type: ' . $this->object_type );
					break;
			}
			if(true !== $success) {
				break;
			}
		}

		if ($this->xml->is_finished() ) {
			$this->object_finished = true;
		}

		return $this->object_finished;
	}

	protected function find_next_object() {
		/**
		 * The pattern matcher below assumes the XML processor is
		 * pointing to the first tag of the next object.
		 * 
		 * However, parsing the last object may have left the XML processor
		 * in one of two states:
		 * 
		 * * Pointing to the last tag of the previous object
		 * * Pointing to the first tag of the next object
		 * 
		 * The next few lines normalize this and make sure the XML processor
		 * always points to the first tag of the next object before we try
		 * to match it.
		 */
		$stopped_on_a_tag_opener = '#tag' === $this->xml->get_token_type() && !$this->xml->is_tag_closer();
		if ( !$stopped_on_a_tag_opener ) {
			if ( false === $this->xml->next_tag() ) {
				return false;
			}
		}
		do {
			// Skip the top-level "image" tag.
			if ( 
				'image' === $this->xml->get_tag() ||
				$this->xml->matches_breadcrumbs(['image', '*'])
			) {
				continue;
			}
			switch ( end( $this->xml->get_breadcrumbs() ) ) {
				case 'rss':
				case 'channel':
				case 'link':
				case 'description':
				case 'pubDate':
				case 'language':
				case 'wp:wxr_version':
				case 'generator':
					// ignore this metadata
					break;
				case 'title':
					$this->object_type = 'site_option';
					$this->object_data = array(
						'option_name' => 'blogname',
						'option_value' => $this->get_text_until_matching_closer_tag(),
					);
					$this->object_finished = true;
					return true;
				case 'wp:base_site_url':
                    $this->object_type = 'site_option';
					$this->object_data = array(
						'option_name' => 'siteurl',
						'option_value' => $this->get_text_until_matching_closer_tag(),
					);
					$this->object_finished = true;
					return true;
				case 'wp:base_blog_url':
					$this->object_type = 'site_option';
					$this->object_data = array(
						'option_name' => 'home',
						'option_value' => $this->get_text_until_matching_closer_tag(),
					);
					$this->object_finished = true;
					return true;
				case 'wp:author':
				case 'wp:wp_author':
					$this->object_type = 'user';
                    $this->object_depth = $this->xml->get_current_depth();
					return true;
				case 'wp:term':
					$this->object_type = 'term';
                    $this->object_depth = $this->xml->get_current_depth();
					return true;
				case 'item':
					$this->last_post_id = null;
					$this->object_type = 'post';
                    $this->object_depth = $this->xml->get_current_depth();
					return true;
				case 'wp:tag':
					$this->object_type = 'tag';
                    $this->object_depth = $this->xml->get_current_depth();
					return true;
				case 'wp:category':
					$this->object_type = 'category';
                    $this->object_depth = $this->xml->get_current_depth();
					return true;
				case 'wp:postmeta':
					$this->object_type = 'post_meta';
					$this->object_depth = $this->xml->get_current_depth();
					return true;
				case 'wp:commentmeta':
					$this->object_type = 'comment_meta';
					$this->object_depth = $this->xml->get_current_depth();
					return true;
				case 'wp:comment':
					$this->last_comment_id = null;
					$this->object_type = 'comment';
                    $this->object_depth = $this->xml->get_current_depth();
					return true;
				default:
					throw new \Exception( 'Unknown top-level tag: ' . $this->xml->get_tag() );
					break;
			}
		} while( $this->xml->next_tag() );
	}

	private function after_object() {
		$this->object_finished = false;
		$this->object_type = null;
		$this->object_depth = null;
		$this->object_data = array();
	}

	protected function step_in_post() {
		$closest_tag = end( $this->xml->get_breadcrumbs() );
		switch ( $closest_tag ) {
			case 'item':
				// Ignore the opener.
				break;
			case 'title':
				$this->object_data['post_title'] .= $this->get_text_until_matching_closer_tag();
				break;
			case 'link':
				$this->object_data['link'] .= $this->get_text_until_matching_closer_tag();
				break;
			case 'guid':
				$this->object_data['guid'] .= $this->get_text_until_matching_closer_tag();
				break;
			case 'description':
				$this->object_data['post_excerpt'] .= $this->get_text_until_matching_closer_tag();
				break;
			case 'pubDate':
				// @TODO: What would be the right key here?
				$this->object_data['post_published_at'] .= $this->get_text_until_matching_closer_tag();
				break;
			case 'dc:creator':
				$this->object_data['post_author'] .= $this->get_text_until_matching_closer_tag();
				break;
			case 'content:encoded':
				$this->object_data['post_content'] .= $this->get_text_until_matching_closer_tag();
				break;
			case 'excerpt:encoded':
				$this->object_data['post_excerpt'] .= $this->get_text_until_matching_closer_tag();
				break;
			case 'wp:post_id':
				$this->object_data['ID'] .= $this->get_text_until_matching_closer_tag();
				$this->last_post_id = $this->object_data['ID'];
				break;
			case 'wp:status':
				$this->object_data['post_status'] .= $this->get_text_until_matching_closer_tag();
				break;
			case 'wp:post_date':
			case 'wp:post_date_gmt':
			case 'wp:post_modified':
			case 'wp:post_modified_gmt':
			case 'wp:comment_status':
			case 'wp:ping_status':
			case 'wp:post_name':
			case 'wp:post_parent':
			case 'wp:menu_order':
			case 'wp:post_type':
			case 'wp:post_password':
			case 'wp:is_sticky':
			case 'wp:attachment_url':
				$key = substr($this->xml->get_tag(), 3);
				$this->object_data[$key] .= $this->get_text_until_matching_closer_tag();
				break;
			case 'category':
				$this->object_data['terms']['category'][] .= $this->get_text_until_matching_closer_tag();
				break;
			case 'wp:postmeta':
			case 'wp:comment':
				$this->object_finished = true;
				break;
			default:
				throw new \Exception( 'Unexpected tag inside post: ' . $closest_tag );
				break;
		}
		return true;
	}

	protected function step_in_post_meta() {
		$closest_tag = end( $this->xml->get_breadcrumbs() );
		switch ( $closest_tag ) {
			case 'wp:postmeta':
				// Ignore the opener.
				break;
			case 'wp:meta_key':
				$this->object_data['meta_key'] .= $this->get_text_until_matching_closer_tag();
				break;
			case 'wp:meta_value':
				$this->object_data['meta_value'] .= $this->get_text_until_matching_closer_tag();
				break;
			default:
				throw new \Exception( 'Unknown tag: ' . $closest_tag );
				break;
		}

		return true;
	}

	protected function step_in_comment_meta() {
		$closest_tag = end( $this->xml->get_breadcrumbs() );
		switch ( $closest_tag ) {
			case 'wp:commentmeta':
				// Ignore the opener.
				break;
			case 'wp:meta_key':
				$this->object_data['meta_key'] .= $this->get_text_until_matching_closer_tag();
				break;
			case 'wp:meta_value':
				$this->object_data['meta_value'] .= $this->get_text_until_matching_closer_tag();
				break;
			default:
				throw new \Exception( 'Unknown tag: ' . $closest_tag );
				break;
		}

		return true;
	}

	protected function step_in_tag() {
		if('#tag' === $this->xml->get_token_type() && !$this->xml->is_tag_opener()) {
			return true;
		}
		$closest_tag = end( $this->xml->get_breadcrumbs() );
		switch ( $closest_tag ) {
			case 'wp:tag':
				// Ignore the opener.
				break;
			case 'wp:term_id':
				$this->object_data['term_id'] .= $this->get_text_until_matching_closer_tag();
				break;
			case 'wp:tag_slug':
				$this->object_data['slug'] .= $this->get_text_until_matching_closer_tag();
				break;
			case 'wp:tag_name':
				$this->object_data['name'] .= $this->get_text_until_matching_closer_tag();
				break;
			case 'wp:tag_description':
				$this->object_data['description'] .= $this->get_text_until_matching_closer_tag();
				break;
			default:
				throw new \Exception( 'Unknown XML tag when processing wp:tag: ' . $closest_tag );
				break;
		}
		return true;
	}

	protected function step_in_category() {
		$closest_tag = end( $this->xml->get_breadcrumbs() );
		switch ( $closest_tag ) {
			case 'wp:category':
				// Ignore the opener.
				break;
			case 'wp:term_id':
				$this->object_data['term_id'] .= $this->get_text_until_matching_closer_tag();
				break;
			case 'wp:category_description':
				$this->object_data['description'] .= $this->get_text_until_matching_closer_tag();
				break;
			case 'wp:category_nicename':
				$this->object_data['nicename'] .= $this->get_text_until_matching_closer_tag();
				break;
			case 'wp:category_parent':
				$this->object_data['parent'] .= $this->get_text_until_matching_closer_tag();
				break;
			case 'wp:cat_name':
				$this->object_data['name'] .= $this->get_text_until_matching_closer_tag();
				break;
			default:
				throw new \Exception( 'Unexpected tag inside category: ' . $closest_tag );
				break;
		}
		return true;
	}

	protected function step_in_comment() {
		$closest_tag = end( $this->xml->get_breadcrumbs() );
		switch ( $closest_tag ) {
			case 'wp:comment':
				// Ignore the opener.
				break;
			case 'wp:comment_id':
				$this->object_data['ID'] .= $this->get_text_until_matching_closer_tag();
				$this->last_comment_id = $this->object_data['ID'];
				break;
			case 'wp:comment_author':
			case 'wp:comment_author_email':
			case 'wp:comment_author_url':
			case 'wp:comment_author_IP':
			case 'wp:comment_date':
			case 'wp:comment_parent':
			case 'wp:comment_date_gmt':
			case 'wp:comment_content':
			case 'wp:comment_type':
			case 'wp:comment_user_id':
			case 'wp:comment_approved':
				$key = substr($this->xml->get_tag(), strlen('wp:comment_'));
				$this->object_data[$key] .= $this->get_text_until_matching_closer_tag();
				break;
			case 'wp:commentmeta':
				$this->object_finished = true;
				break;
			default:
				throw new \Exception( 'Unexpected tag inside comment: ' . $closest_tag );
				break;
		}
		return true;
	}

	protected function step_in_user() {
		$closest_tag = end( $this->xml->get_breadcrumbs() );
		switch ( $closest_tag ) {
			case 'wp:author':
			case 'wp:wp_author':
				// Ignore the opener.
				break;
			case 'wp:author_id':
				$this->object_data['ID'] .= $this->get_text_until_matching_closer_tag();
				break;
			case 'wp:author_login':
				$this->object_data['user_login'] .= $this->get_text_until_matching_closer_tag();
				break;
			case 'wp:author_email':
				$this->object_data['user_email'] .= $this->get_text_until_matching_closer_tag();
				break;
			case 'wp:author_display_name':
				$this->object_data['display_name'] .= $this->get_text_until_matching_closer_tag();
				break;
			case 'wp:author_first_name':
				$this->object_data['first_name'] .= $this->get_text_until_matching_closer_tag();
				break;
			case 'wp:author_last_name':
				$this->object_data['last_name'] .= $this->get_text_until_matching_closer_tag();
				break;
			default:
				throw new \Exception( 'Unexpected tag inside user: ' . $closest_tag );
				break;
		}
		return true;
	}

	protected function step_in_term() {
		$closest_tag = end( $this->xml->get_breadcrumbs() );
		switch ( $closest_tag ) {
			case 'wp:term':
				// Ignore the opener.
				break;
			case 'wp:term_id':
				$this->object_data['term_id'] .= $this->get_text_until_matching_closer_tag();
				break;
			case 'wp:term_taxonomy':
				$this->object_data['taxonomy'] .= $this->get_text_until_matching_closer_tag();
				break;
			case 'wp:term_slug':
				$this->object_data['slug'] .= $this->get_text_until_matching_closer_tag();
				break;
			case 'wp:term_parent':
				$this->object_data['parent'] .= $this->get_text_until_matching_closer_tag();
				break;
			case 'wp:term_name':
				$this->object_data['name'] .= $this->get_text_until_matching_closer_tag();
				break;
			case 'wp:term_description':
				$this->object_data['description'] .= $this->get_text_until_matching_closer_tag();
				break;
			default:
				throw new \Exception( 'Unexpected tag inside term: ' . $closest_tag );
				break;
		}
		return true;
	}

	protected function get_text_until_matching_closer_tag() {
		if($this->xml->is_empty_element()) {
			return '';
		}

		$text            = '';
		do {
			switch ( $this->xml->get_token_type() ) {
				case '#text':
				case '#cdata-section':
					$text .= $this->xml->get_modifiable_text();
					break;
				case '#tag':
					/**
					 * @TODO: What to do if we get an XML tag where we only expect text?
					 *        Note this clause will likely also kick in the first time the
					 *        get_text_until_matching_closer_tag() method is called
					 *        because we're likely pointed to the tag which we want to
					 *        collect the text contents of.
					 */

					/**
					 * Assume that any tag closer indicates the end of the text node.
					 * This is a naive assumption. Let's revisit this if we find any
					 * expected tags mixed with text.
					 */
					if($this->xml->is_tag_closer()) {
						return $text;
					}

					break;
				default:
					throw new \Exception( 'Unknown token type: ' . $this->xml->get_token_type() );
					break;
			}
		} while( $this->xml->next_token() );

		return $text;
	}
}

class WXR_Object {
	public $object_type;
	public $data;

	public function __construct( $object_type, $data ) {
		$this->object_type = $object_type;
		$this->data        = $data;
	}
}
