<?php

/**
 * Controller object manages tree retrieval, manipulation and publishing
 */
class WordPress_GitHub_Sync_Controller {

	/**
	 * Application container.
	 *
	 * @var WordPress_GitHub_Sync
	 */
	public $app;

	/**
	 * Instantiates a new Controller object
	 *
	 * @param WordPress_GitHub_Sync $app
	 */
	public function __construct( WordPress_GitHub_Sync $app ) {
		$this->app = $app;
	}

	/**
	 * Webhook callback as triggered from GitHub push.
	 *
	 * Reads the Webhook payload and syncs posts as necessary.
	 *
	 * @return boolean
	 */
	public function pull_posts() {
		if ( is_wp_error( $error = $this->app->semaphore()->is_open() ) ) {
			return $this->app->response()->error( $error );
		}

		$this->app->semaphore()->lock();

		if ( is_wp_error( $error = $this->app->request()->is_secret_valid() ) ) {
			$this->app->semaphore()->unlock();

			return $this->app->response()->error( $error );
		}

		$payload = $this->app->request()->payload();

		if ( is_wp_error( $error = $payload->should_import() ) ) {
			$this->app->semaphore()->unlock();

			return $this->app->response()->error( $error );
		}

		$result = $this->app->import()->payload( $payload );

		$this->app->semaphore()->unlock();

		if ( is_wp_error( $result ) ) {
			return $this->app->response()->error( $result );
		}

		return $this->app->response()->success( $result );
	}

	/**
	 * Imports posts from the current master branch.
	 *
	 * @return boolean
	 */
	public function import_master() {
		if ( is_wp_error( $error = $this->app->semaphore()->is_open() ) ) {
			return $this->app->response()->error( $error );
		}

		$this->app->semaphore()->lock();

		$commit = $this->app->api()->last_commit();

		if ( is_wp_error( $commit ) ) {
			$this->app->semaphore()->unlock();
			$this->app->response()->log( $commit );

			return false;
		}

		if ( $commit->already_synced() ) {
			$this->app->semaphore()->unlock();
			$this->app->response()->log(
				new WP_Error( 'commit_synced', __( 'Already synced this commit.', 'wordpress-github-sync' ) )
			);

			return false;
		}

		$result = $this->app->import()->commit( $commit );

		$this->app->semaphore()->unlock();
		$this->app->response()->log( $result );

		return is_wp_error( $result ) ? false : true;
	}

	/**
	 * Export all the posts in the database to GitHub.
	 *
	 * @return boolean
	 */
	public function export_all() {
		if ( is_wp_error( $error = $this->app->semaphore()->is_open() ) ) {
			$this->app->response()->log( $error );

			return false;
		}

		$this->app->semaphore()->lock();

		$result = $this->app->database()->all_supported();

		if ( is_wp_error( $result ) ) {
			$this->app->semaphore()->unlock();
			$this->app->response()->log( $result );

			return false;
		}

		// @todo sprintf this
		$msg = apply_filters( 'wpghs_commit_msg_full', 'Full export from WordPress at ' . site_url() . ' (' . get_bloginfo( 'name' ) . ')' ) . ' - wpghs';

		$result = $this->app->export()->posts( $result, $msg );

		$this->app->semaphore()->unlock();
		$this->app->response()->log( $result );

		return is_wp_error( $result ) ? false : true;
	}

	/**
	 * Exports a single post to GitHub by ID.
	 *
	 * Called on the save_post hook.
	 *
	 * @param int $post_id Post ID
	 * @return boolean
	 */
	public function export_post( $post_id ) {
		if ( is_wp_error( $error = $this->app->semaphore()->is_open() ) ) {
			$this->app->response()->log( $error );

			return false;
		}

		$this->app->semaphore()->lock();

		$post = $this->app->database()->id( $post_id );

		if ( is_wp_error( $post ) ) {
			$this->app->semaphore()->unlock();
			$this->app->response()->log( $post );

			return false;
		}

		// @todo sprintf this
		$msg = apply_filters( 'wpghs_commit_msg_single', 'Syncing ' . $post->github_path() . ' from WordPress at ' . site_url() . ' (' . get_bloginfo( 'name' ) . ')', $post ) . ' - wpghs';

		$result = $this->app->export()->post( $post, $msg );

		$this->app->semaphore()->unlock();
		$this->app->response()->log( $result );

		return is_wp_error( $result ) ? false : true;
	}

	/**
	 * Removes the post from the tree.
	 *
	 * Called the delete_post hook.
	 *
	 * @param int $post_id Post ID
	 * @return boolean
	 */
	public function delete_post( $post_id ) {
		if ( is_wp_error( $error = $this->app->semaphore()->is_open() ) ) {
			$this->app->response()->log( $error );

			return false;
		}

		$this->app->semaphore()->lock();

		$post = $this->app->database()->id( $post_id );

		if ( is_wp_error( $post ) ) {
			$this->app->semaphore()->unlock();
			$this->app->response()->log( $post );

			return false;
		}

		$msg  = apply_filters( 'wpghs_commit_msg_delete', 'Deleting ' . $post->github_path() . ' via WordPress at ' . site_url() . ' (' . get_bloginfo( 'name' ) . ')', $post ) . ' - wpghs';

		$result = $this->app->export()->delete( $post, $msg );

		$this->app->semaphore()->unlock();
		$this->app->response()->log( $result );

		return is_wp_error( $result ) ? false : true;
	}
}
