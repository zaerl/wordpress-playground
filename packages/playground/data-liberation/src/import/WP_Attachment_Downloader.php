<?php

use WordPress\AsyncHTTP\Client;
use WordPress\AsyncHTTP\Request;

class WP_Attachment_Downloader {
	private $client;
	private $fps = array();
	private $output_root;
	private $output_paths  = array();

	private $current_event;
	private $pending_events = array();

	public function __construct( $output_root ) {
		$this->client      = new Client();
		$this->output_root = $output_root;
	}

	public function has_pending_requests() {
		return count( $this->client->get_active_requests() ) > 0;
	}

	public function enqueue_if_not_exists( $url, $output_path ) {
		$output_path = $this->output_root . '/' . ltrim( $output_path, '/' );
		if ( file_exists( $output_path ) ) {
			// @TODO: Reconsider the return value. The enqueuing operation failed,
			//        but overall already having a file seems like a success.
			return true;
		}

		$output_dir = dirname( $output_path );
		if ( ! file_exists( $output_dir ) ) {
			// @TODO: think through the chmod of the created directory.
			mkdir( $output_dir, 0777, true );
		}

		$protocol = parse_url( $url, PHP_URL_SCHEME );
		if ( null === $protocol ) {
			return false;
		}

		switch ( $protocol ) {
			case 'file':
				$local_path = parse_url( $url, PHP_URL_PATH );
				if ( false === $local_path ) {
					return false;
				}
				// Just copy the file over.
				// @TODO: think through the chmod of the created file.
				$this->pending_events[] = new WP_Attachment_Downloader_Event(
					WP_Attachment_Downloader_Event::STARTED,
					$url,
					$output_path
				);
				$success                = copy( $local_path, $output_path );
				$this->pending_events[] = new WP_Attachment_Downloader_Event(
					$success ? WP_Attachment_Downloader_Event::SUCCESS : WP_Attachment_Downloader_Event::FAILURE,
					$url,
					$output_path
				);
				return true;
			case 'http':
			case 'https':
				$request                            = new Request( $url );
				$this->output_paths[ $request->id ] = $output_path;
				$this->pending_events[] = new WP_Attachment_Downloader_Event(
					WP_Attachment_Downloader_Event::STARTED,
					$url,
					$output_path
				);
				$this->client->enqueue( $request );
				return true;
		}
		return false;
	}

	public function queue_full() {
		return count( $this->client->get_active_requests() ) >= 10;
	}

	public function get_event() {
		return $this->current_event;
	}

	public function next_event() {
		$this->current_event = null;
		if ( count( $this->pending_events ) === 0 ) {
			return false;
		}

		$this->current_event = array_shift( $this->pending_events );
		return true;
	}

	public function poll() {
		if ( ! $this->client->await_next_event() ) {
			return false;
		}
		$event   = $this->client->get_event();
		$request = $this->client->get_request();
		// The request object we get from the client may be a redirect.
		// Let's keep referring to the original request.
		$original_request_id = $request->original_request()->id;

		while ( true ) {
			switch ( $event ) {
				case Client::EVENT_GOT_HEADERS:
					if ( ! $request->is_redirected() ) {
						if ( file_exists( $this->output_paths[ $original_request_id ] . '.partial' ) ) {
							unlink( $this->output_paths[ $original_request_id ] . '.partial' );
						}
						$this->fps[ $original_request_id ] = fopen( $this->output_paths[ $original_request_id ] . '.partial', 'wb' );
						if ( false === $this->fps[ $original_request_id ] ) {
							// @TODO: Log an error.
						}
					}
					break;
				case Client::EVENT_BODY_CHUNK_AVAILABLE:
					$chunk = $this->client->get_response_body_chunk();
					if ( false === fwrite( $this->fps[ $original_request_id ], $chunk ) ) {
						// @TODO: Log an error.
					}
					break;
				case Client::EVENT_FAILED:
					if ( isset( $this->fps[ $original_request_id ] ) ) {
						fclose( $this->fps[ $original_request_id ] );
					}
					if ( isset( $this->output_paths[ $original_request_id ] ) ) {
						$partial_file = $this->output_root . '/' . $this->output_paths[ $original_request_id ] . '.partial';
						if ( file_exists( $partial_file ) ) {
							unlink( $partial_file );
						}
					}
					$this->pending_events[] = new WP_Attachment_Downloader_Event(
						WP_Attachment_Downloader_Event::FAILURE,
						$request->original_request()->url,
						$this->output_paths[ $original_request_id ]
					);
					unset( $this->output_paths[ $original_request_id ] );
					break;
				case Client::EVENT_FINISHED:
					if ( ! $request->is_redirected() ) {
						// Only clean up if this was the last request in the chain.
						if ( isset( $this->fps[ $original_request_id ] ) ) {
							fclose( $this->fps[ $original_request_id ] );
						}
						if ( isset( $this->output_paths[ $original_request_id ] ) ) {
							if ( false === rename(
								$this->output_paths[ $original_request_id ] . '.partial',
								$this->output_paths[ $original_request_id ]
							) ) {
								// @TODO: Log an error.
							}
						}
						$this->pending_events[] = new WP_Attachment_Downloader_Event(
							WP_Attachment_Downloader_Event::SUCCESS,
							$request->original_request()->url,
							$this->output_paths[ $original_request_id ]
						);
						unset( $this->output_paths[ $original_request_id ] );
					}
					break;
			}
		}

		return true;
	}
}

class WP_Attachment_Downloader_Event {

	const STARTED = '#started';
	const SUCCESS = '#success';
	const FAILURE = '#failure';

	public $type;
	public $url;
	public $target_path;

	public function __construct( $type, $url, $target_path = null ) {
		$this->type         = $type;
		$this->url          = $url;
		$this->target_path  = $target_path;
	}
}
