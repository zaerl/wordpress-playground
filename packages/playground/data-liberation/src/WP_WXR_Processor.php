<?php
/**
 * @TODO:
 * – Should this extend WP_XML_Processor? Or should it keep track of its own paused
 *   state independently of the underlying WP_XML_Processor? Would anything change
 *   if the XML processor was receiving data from a HTTP -> unzip stream?
 * - Ensure we can pause in the middle of an item node, crash, and then resume later
 *   on. This would require setting bookmarks before/after each major parsed entity.
 */

class WP_WXR_Processor {

	private $xml;

	private $token_to_process = self::PROCESS_NEXT_TOKEN;

	const PROCESS_NEXT_TOKEN    = 'PROCESS_NEXT_TOKEN';
	const PROCESS_CURRENT_TOKEN = 'PROCESS_CURRENT_TOKEN';

	public function __construct( WP_XML_Processor $xml ) {
		$this->xml = $xml;
	}

	public function next_object() {
		// @TODO: Can we avoid checking this on every call?
		//        And only get inside the <channel> tag once?
		$breadcrumbs = $this->xml->get_breadcrumbs();
		if ( count( $breadcrumbs ) < 2 ||
			$breadcrumbs[0] !== 'rss' ||
			$breadcrumbs[1] !== 'channel'
		) {
			// @TODO: Reflect the WXR importer's paused state based
			//        on the underlying XML processor's paused state.
			if ( false === $this->xml->next_tag( 'channel' ) ) {
				return false;
			}
		}

		while ( true ) {
			if ( self::PROCESS_NEXT_TOKEN === $this->token_to_process ) {
				if ( false === $this->xml->next_token() ) {
					break;
				}
			} else {
				$this->token_to_process = self::PROCESS_NEXT_TOKEN;
			}

			if ( '#tag' !== $this->xml->get_token_type() || $this->xml->is_tag_closer() ) {
				continue;
			}

			switch ( $this->xml->get_tag() ) {
				case 'title':
					return new WXR_Action( 'update_option', array( 'blogname', $this->get_text_until_matching_closer_tag() ) );
				case 'link':
				case 'description':
				case 'pubDate':
				case 'language':
				case 'wp:wxr_version':
				case 'generator':
					// ignore this metadata
					break;
				case 'wp:base_site_url':
					return new WXR_Action( 'update_option', array( 'siteurl', $this->get_text_until_matching_closer_tag() ) );
				case 'wp:base_blog_url':
					return new WXR_Action( 'update_option', array( 'home', $this->get_text_until_matching_closer_tag() ) );
				case 'wp:author':
					return new WXR_Action( 'create_user', $this->parse_author_node() );
				case 'item':
					return new WXR_Action( 'create_post', $this->parse_item_node() );
				case 'wp:postmeta':
					return new WXR_Action( 'create_post_meta', $this->parse_post_meta_node() );
				case 'wp:comment':
					return new WXR_Action( 'create_comment', $this->parse_comment_node() );
				default:
					throw new \Exception( 'Unknown tag: ' . $this->xml->get_tag() );
					break;
			}
		}
	}

	protected function parse_item_node() {
		$item = array();

		$depth = $this->xml->get_current_depth();
		while ( $this->xml->next_tag() ) {
			if ( $this->xml->get_current_depth() <= $depth ) {
				break;
			}

			switch ( $this->xml->get_tag() ) {
				case 'title':
					$item['post_title'] = $this->get_text_until_matching_closer_tag();
					break;
				case 'link':
					$item['guid'] = $this->get_text_until_matching_closer_tag();
					break;
				case 'guid':
					$item['guid'] = $this->get_text_until_matching_closer_tag();
					break;
				case 'description':
					$item['post_excerpt'] = $this->get_text_until_matching_closer_tag();
					break;
				case 'pubDate':
					$item['post_date'] = $this->get_text_until_matching_closer_tag();
					break;
				case 'dc:creator':
					$item['post_author'] = $this->get_text_until_matching_closer_tag();
					break;
				case 'content:encoded':
					$item['post_content'] = $this->get_text_until_matching_closer_tag();
					break;
				case 'excerpt:encoded':
					$item['post_excerpt'] = $this->get_text_until_matching_closer_tag();
					break;
				case 'wp:post_id':
					$item['ID'] = $this->get_text_until_matching_closer_tag();
					break;
				case 'wp:post_date':
					$item['post_date'] = $this->get_text_until_matching_closer_tag();
					break;
				case 'wp:post_date_gmt':
					$item['post_date_gmt'] = $this->get_text_until_matching_closer_tag();
					break;
				case 'wp:post_modified':
					$item['post_modified'] = $this->get_text_until_matching_closer_tag();
					break;
				case 'wp:post_modified_gmt':
					$item['post_modified_gmt'] = $this->get_text_until_matching_closer_tag();
					break;
				case 'wp:comment_status':
					$item['comment_status'] = $this->get_text_until_matching_closer_tag();
					break;
				case 'wp:ping_status':
					$item['ping_status'] = $this->get_text_until_matching_closer_tag();
					break;
				case 'wp:post_name':
					$item['post_name'] = $this->get_text_until_matching_closer_tag();
					break;
				case 'wp:status':
					$item['post_status'] = $this->get_text_until_matching_closer_tag();
					break;
				case 'wp:post_parent':
					$item['post_parent'] = $this->get_text_until_matching_closer_tag();
					break;
				case 'wp:menu_order':
					$item['menu_order'] = $this->get_text_until_matching_closer_tag();
					break;
				case 'wp:post_type':
					$item['post_type'] = $this->get_text_until_matching_closer_tag();
					break;
				case 'wp:post_password':
					$item['post_password'] = $this->get_text_until_matching_closer_tag();
					break;
				case 'wp:is_sticky':
					$item['is_sticky'] = $this->get_text_until_matching_closer_tag();
					break;
				case 'category':
					$item['terms']['category'][] = $this->get_text_until_matching_closer_tag();
					break;
				case 'wp:postmeta':
				case 'wp:comment':
					// Stop processing the item node, emit the post, only continue afterwards
					$this->token_to_process = self::PROCESS_CURRENT_TOKEN;
					break 2;
				default:
					throw new \Exception( 'Unknown tag: ' . $this->xml->get_tag() );
					break;
			}
		}
		return $item;
	}

	protected function parse_post_meta_node() {
		$post_meta = array();

		while ( $this->xml->next_tag() ) {
			if ( ! $this->xml->matches_breadcrumbs( array( 'rss', 'channel', 'item', 'wp:postmeta', '*' ) ) ) {
				$this->token_to_process = self::PROCESS_CURRENT_TOKEN;
				break;
			}

			switch ( $this->xml->get_tag() ) {
				case 'wp:meta_key':
					$post_meta['meta_key'] = $this->get_text_until_matching_closer_tag();
					break;
				case 'wp:meta_value':
					$post_meta['meta_value'] = $this->get_text_until_matching_closer_tag();
					break;
				default:
					throw new \Exception( 'Unknown tag: ' . $this->xml->get_tag() );
					break;
			}
		}

		return $post_meta;
	}

	protected function parse_comment_node() {
		$comment = array();

		$depth = $this->xml->get_current_depth();
		while ( $this->xml->next_token() ) {
			if ( $this->xml->get_current_depth() <= $depth ) {
				break;
			}

			switch ( $this->xml->get_tag() ) {
				case 'wp:comment_id':
					$comment['comment_ID'] = $this->get_text_until_matching_closer_tag();
					break;
				case 'wp:comment_author':
					$comment['comment_author'] = $this->get_text_until_matching_closer_tag();
					break;
				case 'wp:comment_author_email':
					$comment['comment_author_email'] = $this->get_text_until_matching_closer_tag();
					break;
				case 'wp:comment_author_url':
					$comment['comment_author_url'] = $this->get_text_until_matching_closer_tag();
					break;
				case 'wp:comment_author_IP':
					$comment['comment_author_IP'] = $this->get_text_until_matching_closer_tag();
					break;
				case 'wp:comment_date':
					$comment['comment_date'] = $this->get_text_until_matching_closer_tag();
					break;
				case 'wp:comment_date_gmt':
					$comment['comment_date_gmt'] = $this->get_text_until_matching_closer_tag();
					break;
				case 'wp:comment_content':
					$comment['comment_content'] = $this->get_text_until_matching_closer_tag();
					break;
				case 'wp:comment_approved':
					$comment['comment_approved'] = $this->get_text_until_matching_closer_tag();
					break;
				default:
					throw new \Exception( 'Unknown tag: ' . $this->xml->get_tag() );
					break;
			}
		}
	}

	protected function parse_author_node() {
		$author = array();

		$depth = $this->xml->get_current_depth();
		while ( $this->xml->next_tag() ) {
			if ( $this->xml->get_current_depth() <= $depth ) {
				break;
			}

			switch ( $this->xml->get_tag() ) {
				case 'wp:author_id':
					$author['ID'] = $this->get_text_until_matching_closer_tag();
					break;
				case 'wp:author_login':
					$author['user_login'] = $this->get_text_until_matching_closer_tag();
					break;
				case 'wp:author_email':
					$author['user_email'] = $this->get_text_until_matching_closer_tag();
					break;
				case 'wp:author_display_name':
					$author['display_name'] = $this->get_text_until_matching_closer_tag();
					break;
				case 'wp:author_first_name':
					$author['first_name'] = $this->get_text_until_matching_closer_tag();
					break;
				case 'wp:author_last_name':
					$author['last_name'] = $this->get_text_until_matching_closer_tag();
					break;
				default:
					throw new \Exception( 'Unknown tag: ' . $this->xml->get_tag() );
					break;
			}
		}

		return $author;
	}

	protected function get_text_until_matching_closer_tag() {
		$text            = '';
		$encountered_tag = false;
		while ( $this->xml->next_token() ) {
			switch ( $this->xml->get_token_type() ) {
				case '#text':
				case '#cdata-section':
					$text .= $this->xml->get_modifiable_text();
					break;
				case '#tag':
					if ( $this->xml->is_tag_closer() ) {
						break 2;
					} else {
						$encountered_tag = true;
						_doing_it_wrong( __METHOD__, 'Encountered a tag opener when collecting the text contents of another tag.', 'WP_VERSION' );
					}
					break;
				default:
					throw new \Exception( 'Unknown token type: ' . $this->xml->get_token_type() );
					break;
			}
		}

		if ( $encountered_tag ) {
			return false;
		}

		return $text;
	}
}

class WXR_Action {
	public $action_type;
	public $data;

	public function __construct( $action_type, $data ) {
		$this->action_type = $action_type;
		$this->data        = $data;
	}
}
