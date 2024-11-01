<?php
/**
 * Plugin Name: WP FetLife Importer
 * Plugin URI: http://maybemaimed.com/2013/03/05/ready-to-ditch-fetlife-tools-to-make-the-transition-easier/
 * Description: Import your FetLife Writings and Pictures to your WordPress blog as posts.
 * Author: maymay
 * Author URI: http://maybemaimed.com/cyberbusking/
 * Version: 0.2.3
 * Text Domain: wp-fetlife-importer
 * Domain Path: /languages
 * License: GPL version 2 or later - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 */

if ( ! defined( 'WP_LOAD_IMPORTERS' ) )
	return;

/** Display verbose errors */
define( 'IMPORT_DEBUG', false );

// Load Importer API
require_once ABSPATH . 'wp-admin/includes/import.php';

if ( ! class_exists( 'WP_Importer' ) ) {
	$class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
	if ( file_exists( $class_wp_importer ) )
		require $class_wp_importer;
}

// include our required libraries
require dirname( __FILE__ ) . '/lib/FetLife/FetLife.php';

/**
 * WP FetLife Importer class for managing the import process of a FetLife user.
 *
 * @package WordPress
 * @subpackage Importer
 */
if ( class_exists( 'WP_Importer' ) ) {
class WP_FetLife_Import extends WP_Importer {
	var $FL; // the FetLifeUser object we'll use to interface with FetLife.
	// information to import from FetLife
	var $authors = array();
	var $posts = array(); // actually, an array of FetLifeWriting objects
	var $terms = array();
	var $categories = array();
	var $base_url = '';

	// mappings from old information to new
	var $processed_authors = array();
	var $author_mapping = array();
	var $processed_terms = array();
	var $processed_posts = array();

	var $fetch_attachments = true; // Just do it.
	var $url_remap = array();

	function WP_FetLife_Import() { /* nothing */ }

	/**
	 * Registered callback function for the WP FetLife Importer
	 *
	 * Manages the three separate stages of the FetLife import process
	 */
	function dispatch() {
		$this->header();

		$step = empty( $_GET['step'] ) ? 0 : (int) $_GET['step'];
		switch ( $step ) {
			case 0:
				$this->greet();
				break;
			case 1:
				check_admin_referer( 'wp-fetlife-importer' );
				if ( $this->handle_connect() )
					$this->import_options();
				break;
			case 2:
				check_admin_referer( 'wp-fetlife-importer' );
				$this->fetch_attachments = ( ! empty( $_POST['fetch_attachments'] ) && $this->allow_fetch_attachments() );
				set_time_limit(0);
				$this->import();
				break;
		}

		$this->footer();
	}

	/**
	 * The main controller for the actual import stage.
	 */
	function import () {
		add_filter( 'import_post_meta_key', array( $this, 'is_valid_meta_key' ) );
		add_filter( 'http_request_timeout', array( &$this, 'bump_request_timeout' ) );

		$this->import_start();

		$this->get_author_mapping();

		wp_suspend_cache_invalidation( true );
		$this->process_categories();
		$this->process_posts();
		wp_suspend_cache_invalidation( false );

		$this->import_end();
	}

	/**
	 * Fetches data from FetLife.com and prepares us for starting the import.
	 */
	function import_start () {
		// There's only ever gonna be one author.
		$this->FL = new FetLifeUser($_POST['imported_authors'][0], $_POST['fl_password']);
		if ($_POST['fl_proxyurl']) {
			$p = parse_url($_POST['fl_proxyurl']);
			$this->FL->connection->setProxy(
				"{$p['host']}:{$p['port']}",
				('socks' === $p['scheme']) ? CURLPROXY_SOCKS5 : CURLPROXY_HTTP
			);
		}
		if (!$this->FL->logIn()) {
			if ( defined('IMPORT_DEBUG') && IMPORT_DEBUG ) {
				var_dump($this->FL);
			}
			die(__('Failed to log in to FetLife. Wait a bit and try again.', 'wp-fetlife-importer'));
		}
		$fl_writings = $this->FL->getWritingsOf($_POST['imported_authors'][0]);
		// Prepare FetLife Writings as WordPress posts.
		foreach ($fl_writings as $writing) {
			// Extract all the categories we found
			if ($writing->category && (false === in_array($writing->category, $this->categories)) ) {
				$description = '';
				switch ($writing->category) {
					case 'Journal Entry':
						$description = __('about my life and journey', 'wp-fetlife-importer');
						break;
					case 'Erotica':
						$description = __('that I have written', 'wp-fetlife-importer');
						break;
					case 'Note':
						$description = __('about random stuff', 'wp-fetlife-importer');
						break;
				}

				$this->categories[$writing->category] = array(
					'cat_name' => $writing->category,
					'category_nicename' => strtolower(str_replace(' ', '-', $writing->category)),
					'category_description' => $description,
				);
			}

			// and extract the posts, too.
			$writing->populate(); // Fetch ALL the things!
			// Prepare the comments. We'll attach these to the post in just a moment.
			$comments = array();
			foreach ($writing->comments as $comment) {
				$comments[] = array(
					'comment_post_ID' => $comment->id,
					'comment_author' => $comment->creator->nickname,
					'comment_author_email' => '',
					'comment_author_url' => $comment->creator->getPermalink(),
					'comment_author_IP' => '',
					'comment_date' => date('Y-m-d H:i:s', strtotime($comment->dt_published)),
					'comment_date_gmt' => date('Y-m-d H:i:s', strtotime($comment->dt_published)),
					'comment_content' => $comment->getContentHtml(),
					'comment_approved' => 1, // Comments are always approved.
					'comment_type' => '',
					'comment_parent' => 0,
					'comment_user_id' => 0
				);
			}
			$this->posts[] = array(
				'post_type' => 'post',
				'post_id' => $writing->id,
				'post_title' => $writing->title,
				'post_date' => date('Y-m-d H:i:s', strtotime($writing->dt_published)),
				'post_author' => $this->FL->nickname, // NOTE: This is the user doing the import.
				'post_content' => $writing->getContentHtml(),
				'status' => 'publish',
				'terms' => array(array( // Should be one item in a 2D array.
					'domain' => 'category',
					'slug' => $this->categories[$writing->category]['category_nicename'],
					'name' => $this->categories[$writing->category]['cat_name']
				)),
				'comments' => $comments
			);
		}
		// Prepare FetLife Pictures as WordPress attachment pages.
		$fl_pictures = $this->FL->getPicturesOf($_POST['imported_authors'][0]);
		foreach ($fl_pictures as $picture) {
			$picture->populate(); // Fetch ALL the things!
			$comments = array();
			foreach ($picture->comments as $comment) {
				$comments[] = array(
					'comment_post_ID' => $comment->id,
					'comment_author' => $comment->creator->nickname,
					'comment_author_email' => '',
					'comment_author_url' => $comment->creator->getPermalink(),
					'comment_author_IP' => '',
					'comment_date' => date('Y-m-d H:i:s', strtotime($comment->dt_published)),
					'comment_date_gmt' => date('Y-m-d H:i:s', strtotime($comment->dt_published)),
					'comment_content' => $comment->getContentHtml(),
					'comment_approved' => 1, // Comments are always approved.
					'comment_type' => '',
					'comment_parent' => 0,
					'comment_user_id' => 0
				);
			}
			$this->posts[] = array(
				'post_type' => 'attachment',
				'post_id' => $picture->id,
				'post_title' => strip_tags($picture->getContentHtml()),
				'post_date' => date('Y-m-d H:i:s', strtotime($picture->dt_published)),
				'post_author' => $this->FL->nickname, // NOTE: This is the user doing the import.
				'post_content' => $picture->getContentHtml(),
				'attachment_url' => $picture->src,
				'status' => 'publish',
				'comments' => $comments
			);
		}

		$this->base_url = esc_url( $this->FL->base_url . "/users/{$this->FL->id}/posts" );

		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );

		do_action( 'import_start' );
	}

	/**
	 * Performs post-import cleanup of files and the cache
	 */
	function import_end() {
		wp_cache_flush();
		foreach ( get_taxonomies() as $tax ) {
			delete_option( "{$tax}_children" );
			_get_term_hierarchy( $tax );
		}

		wp_defer_term_counting( false );
		wp_defer_comment_counting( false );

		echo '<p>' . __( 'All done.', 'wp-fetlife-importer' ) . ' <a href="' . admin_url() . '">' . __( 'Have fun!', 'wp-fetlife-importer' ) . '</a>' . '</p>';
		echo '<p>' . __( 'Remember to update the passwords and roles of imported users.', 'wp-fetlife-importer' ) . '</p>';
		echo '<p>' . __( 'If you need more help <a href="http://maybemaimed.com/escape-from-fetlife/">escaping FetLife</a>, <a href="http://maybemaimed.com/seminars/#booking-inquiry">just ask</a>!', 'wp-fetlife-importer' ) . '</p>';

		do_action( 'import_end' );
	}

	/**
	 * Handles the FetLife connection setup and fetches the user's Writings.
	 *
	 * @return bool False if error connecting or logging in, true otherwise
	 */
	function handle_connect() {
		$this->FL = new FetLifeUser(trim(strip_tags($_POST['fl_nickname'])), $_POST['fl_password']);
		if ($_POST['fl_proxyurl']) {
			$p = parse_url($_POST['fl_proxyurl']);
			$this->FL->connection->setProxy(
				"{$p['host']}:{$p['port']}",
				('socks' === $p['scheme']) ? CURLPROXY_SOCKS5 : CURLPROXY_HTTP
			);
		}

		// Do an actual log in so we can verify these details.
		$this->FL->logIn() or die('Failed to log in to FetLife. Go back and try again.');

		$this->authors[] = array(
			'author_display_name' => $_POST['fl_nickname'],
			'author_login' => $_POST['fl_nickname']
		);

		return true;
	}

	/**
	 * Display pre-import options, author importing/mapping.
	 */
	function import_options() {
		$j = 0;
?>
<form action="<?php echo admin_url( 'admin.php?import=wp-fetlife-importer&amp;step=2' ); ?>" method="post">
	<?php wp_nonce_field( 'wp-fetlife-importer' ); ?>

<?php if ( ! empty( $this->authors ) ) : ?>
	<h3><?php _e( 'Assign Authors', 'wp-fetlife-importer' ); ?></h3>
	<p><?php _e( 'To make it easier for you to edit and save the imported content, you may want to reassign the author of the imported item to an existing user of this site. For example, you may want to import all the entries as <code>admin</code>s entries.', 'wp-fetlife-importer' ); ?></p>
<?php if ( $this->allow_create_users() ) : ?>
	<p><?php printf( __( 'If a new user is created by WordPress, a new password will be randomly generated and the new user&#8217;s role will be set as %s. Manually changing the new user&#8217;s details will be necessary.', 'wp-fetlife-importer' ), esc_html( get_option('default_role') ) ); ?></p>
<?php endif; ?>
	<ol id="authors">
<?php foreach ( $this->authors as $author ) : ?>
		<li><?php $this->author_select( $j++, $author ); ?></li>
<?php endforeach; ?>
	</ol>
<?php endif; ?>

<?php if ( $this->allow_fetch_attachments() ) : ?>
	<h3><?php _e( 'Import Pictures', 'wp-fetlife-importer' ); ?></h3>
	<p>
		<input type="checkbox" value="1" name="fetch_attachments" id="import-attachments" checked="checked" />
		<label for="import-attachments"><?php _e( 'Download and import Pictures from FetLife', 'wp-fetlife-importer' ); ?></label>
	</p>
<?php endif; ?>

	<!-- Bring the password and proxy configuration over from the previous step. -->
	<input type="hidden" name="fl_password" value="<?php echo esc_attr( $_POST['fl_password'] );?>" />
	<input type="hidden" name="fl_proxyurl" value="<?php echo esc_attr( $_POST['fl_proxyurl'] );?>" />
	<p class="submit"><input type="submit" class="button" value="<?php esc_attr_e( 'Submit', 'wp-fetlife-importer' ); ?>" /></p>
</form>
<?php
	}

	/**
	 * Display import options for an individual author. That is, either create
	 * a new user based on import info or map to an existing user
	 *
	 * @param int $n Index for each author in the form
	 * @param array $author Author information, e.g. login, display name, email
	 */
	function author_select( $n, $author ) {
		_e( 'Import author:', 'wp-fetlife-importer' );
		echo ' <strong>' . esc_html( $author['author_display_name'] );
		if ( $this->version != '1.0' ) echo ' (' . esc_html( $author['author_login'] ) . ')';
		echo '</strong><br />';

		if ( $this->version != '1.0' )
			echo '<div style="margin-left:18px">';

		$create_users = $this->allow_create_users();
		if ( $create_users ) {
			if ( $this->version != '1.0' ) {
				_e( 'or create new user with login name:', 'wordpress-importer' );
				$value = '';
			} else {
				_e( 'as a new user:', 'wordpress-importer' );
				$value = esc_attr( sanitize_user( $author['author_login'], true ) );
			}

			echo ' <input type="text" name="user_new['.$n.']" value="'. $value .'" /><br />';
		}

		if ( ! $create_users && $this->version == '1.0' )
			_e( 'assign posts to an existing user:', 'wordpress-importer' );
		else
			_e( 'or assign posts to an existing user:', 'wordpress-importer' );
		wp_dropdown_users( array( 'name' => "user_map[$n]", 'multi' => true, 'show_option_all' => __( '- Select -', 'wordpress-importer' ) ) );
		echo '<input type="hidden" name="imported_authors['.$n.']" value="' . esc_attr( $author['author_login'] ) . '" />';

		if ( $this->version != '1.0' )
			echo '</div>';
	}

	/**
	 * Map old author logins to local user IDs based on decisions made
	 * in import options form. Can map to an existing user, create a new user
	 * or falls back to the current user in case of error with either of the previous
	 */
	function get_author_mapping() {
		if ( ! isset( $_POST['imported_authors'] ) )
			return;

		$create_users = $this->allow_create_users();

		foreach ( (array) $_POST['imported_authors'] as $i => $old_login ) {
			// Multisite adds strtolower to sanitize_user. Need to sanitize here to stop breakage in process_posts.
			$santized_old_login = sanitize_user( $old_login, true );
			$old_id = isset( $this->authors[$old_login]['author_id'] ) ? intval($this->authors[$old_login]['author_id']) : false;

			if ( ! empty( $_POST['user_map'][$i] ) ) {
				$user = get_userdata( intval($_POST['user_map'][$i]) );
				if ( isset( $user->ID ) ) {
					if ( $old_id )
						$this->processed_authors[$old_id] = $user->ID;
					$this->author_mapping[$santized_old_login] = $user->ID;
				}
			} else if ( $create_users ) {
				if ( ! empty($_POST['user_new'][$i]) ) {
					$user_id = wp_create_user( $_POST['user_new'][$i], wp_generate_password() );
				}

				if ( ! is_wp_error( $user_id ) ) {
					if ( $old_id )
						$this->processed_authors[$old_id] = $user_id;
					$this->author_mapping[$santized_old_login] = $user_id;
				} else {
					printf( __( 'Failed to create new user for %s. Their posts will be attributed to the current user.', 'wp-fetlife-importer' ), esc_html($this->authors[$old_login]['author_display_name']) );
					if ( defined('IMPORT_DEBUG') && IMPORT_DEBUG )
						echo ' ' . $user_id->get_error_message();
					echo '<br />';
				}
			}

			// failsafe: if the user_id was invalid, default to the current user
			if ( ! isset( $this->author_mapping[$santized_old_login] ) ) {
				if ( $old_id )
					$this->processed_authors[$old_id] = (int) get_current_user_id();
				$this->author_mapping[$santized_old_login] = (int) get_current_user_id();
			}
		}
	}

	/**
	 * Create new categories based on import information
	 *
	 * Doesn't create a new category if its slug already exists
	 */
	function process_categories() {
		if ( empty( $this->categories ) )
			return;

		foreach ( $this->categories as $cat ) {
			// if the category already exists leave it alone
			$term_id = term_exists( $cat['category_nicename'], 'category' );
			if ( $term_id ) {
				if ( is_array($term_id) ) $term_id = $term_id['term_id'];
				if ( isset($cat['term_id']) )
					$this->processed_terms[intval($cat['term_id'])] = (int) $term_id;
				continue;
			}

			$category_parent = empty( $cat['category_parent'] ) ? 0 : category_exists( $cat['category_parent'] );
			$category_description = isset( $cat['category_description'] ) ? $cat['category_description'] : '';
			$catarr = array(
				'category_nicename' => $cat['category_nicename'],
				'category_parent' => $category_parent,
				'cat_name' => $cat['cat_name'],
				'category_description' => $category_description
			);

			$id = wp_insert_category( $catarr );
			if ( ! is_wp_error( $id ) ) {
				if ( isset($cat['term_id']) )
					$this->processed_terms[intval($cat['term_id'])] = $id;
			} else {
				printf( __( 'Failed to import category %s', 'wp-fetlife-importer' ), esc_html($cat['category_nicename']) );
				if ( defined('IMPORT_DEBUG') && IMPORT_DEBUG )
					echo ': ' . $id->get_error_message();
				echo '<br />';
				continue;
			}
		}

		unset( $this->categories );
	}

	/**
	 * Create new posts based on import information
	 *
	 * Posts marked as having a parent which doesn't exist will become top level items.
	 * Doesn't create a new post if: the post type doesn't exist, the given post ID
	 * is already noted as imported or a post with the same title and date already exists.
	 * Note that new/updated terms, comments and meta are imported for the last of the above.
	 */
	function process_posts() {
		foreach ( $this->posts as $post ) {
			if ( ! post_type_exists( $post['post_type'] ) ) {
				printf( __( 'Failed to import &#8220;%s&#8221;: Invalid post type %s', 'wp-fetlife-importer' ),
					esc_html($post['post_title']), esc_html($post['post_type']) );
				echo '<br />';
				continue;
			}

			if ( isset( $this->processed_posts[$post['post_id']] ) && ! empty( $post['post_id'] ) )
				continue;

			if ( $post['status'] == 'auto-draft' )
				continue;

			if ( 'nav_menu_item' == $post['post_type'] ) {
				$this->process_menu_item( $post );
				continue;
			}

			$post_type_object = get_post_type_object( $post['post_type'] );

			$post_exists = post_exists( $post['post_title'], '', $post['post_date'] );
			if ( $post_exists && get_post_type( $post_exists ) == $post['post_type'] ) {
				printf( __('%s &#8220;%s&#8221; already exists.', 'wp-fetlife-importer'), $post_type_object->labels->singular_name, esc_html($post['post_title']) );
				echo '<br />';
				$comment_post_ID = $post_id = $post_exists;
			} else {
				$post_parent = (int) $post['post_parent'];
				if ( $post_parent ) {
					// if we already know the parent, map it to the new local ID
					if ( isset( $this->processed_posts[$post_parent] ) ) {
						$post_parent = $this->processed_posts[$post_parent];
					// otherwise record the parent for later
					} else {
						$this->post_orphans[intval($post['post_id'])] = $post_parent;
						$post_parent = 0;
					}
				}

				// map the post author
				$author = sanitize_user( $post['post_author'], true );
				if ( isset( $this->author_mapping[$author] ) )
					$author = $this->author_mapping[$author];
				else
					$author = (int) get_current_user_id();

				$postdata = array(
					'import_id' => $post['post_id'], 'post_author' => $author, 'post_date' => $post['post_date'],
					'post_date_gmt' => $post['post_date_gmt'], 'post_content' => $post['post_content'],
					'post_excerpt' => $post['post_excerpt'], 'post_title' => $post['post_title'],
					'post_status' => $post['status'], 'post_name' => $post['post_name'],
					'comment_status' => $post['comment_status'], 'ping_status' => $post['ping_status'],
					'guid' => $post['guid'], 'post_parent' => $post_parent, 'menu_order' => $post['menu_order'],
					'post_type' => $post['post_type'], 'post_password' => $post['post_password']
				);

				if ( 'attachment' == $postdata['post_type'] ) {
					$remote_url = ! empty($post['attachment_url']) ? $post['attachment_url'] : $post['guid'];

					// try to use _wp_attached file for upload folder placement to ensure the same location as the export site
					// e.g. location is 2003/05/image.jpg but the attachment post_date is 2010/09, see media_handle_upload()
					$postdata['upload_date'] = $post['post_date'];
					if ( isset( $post['postmeta'] ) ) {
						foreach( $post['postmeta'] as $meta ) {
							if ( $meta['key'] == '_wp_attached_file' ) {
								if ( preg_match( '%^[0-9]{4}/[0-9]{2}%', $meta['value'], $matches ) )
									$postdata['upload_date'] = $matches[0];
								break;
							}
						}
					}

					$comment_post_ID = $post_id = $this->process_attachment( $postdata, $remote_url );
				} else {
					$comment_post_ID = $post_id = wp_insert_post( $postdata, true );
				}

				if ( is_wp_error( $post_id ) ) {
					printf( __( 'Failed to import %s &#8220;%s&#8221;', 'wp-fetlife-importer' ),
						$post_type_object->labels->singular_name, esc_html($post['post_title']) );
					if ( defined('IMPORT_DEBUG') && IMPORT_DEBUG )
						echo ': ' . $post_id->get_error_message();
					echo '<br />';
					continue;
				}

				if ( $post['is_sticky'] == 1 )
					stick_post( $post_id );
			}

			// map pre-import ID to local ID
			$this->processed_posts[intval($post['post_id'])] = (int) $post_id;

			// add categories, tags and other terms
			if ( ! empty( $post['terms'] ) ) {
				$terms_to_set = array();
				foreach ( $post['terms'] as $term ) {
					// back compat with WXR 1.0 map 'tag' to 'post_tag'
					$taxonomy = ( 'tag' == $term['domain'] ) ? 'post_tag' : $term['domain'];
					$term_exists = term_exists( $term['slug'], $taxonomy );
					$term_id = is_array( $term_exists ) ? $term_exists['term_id'] : $term_exists;
					if ( ! $term_id ) {
						$t = wp_insert_term( $term['name'], $taxonomy, array( 'slug' => $term['slug'] ) );
						if ( ! is_wp_error( $t ) ) {
							$term_id = $t['term_id'];
						} else {
							printf( __( 'Failed to import %s %s', 'wp-fetlife-importer' ), esc_html($taxonomy), esc_html($term['name']) );
							if ( defined('IMPORT_DEBUG') && IMPORT_DEBUG )
								echo ': ' . $t->get_error_message();
							echo '<br />';
							continue;
						}
					}
					$terms_to_set[$taxonomy][] = intval( $term_id );
				}

				foreach ( $terms_to_set as $tax => $ids ) {
					$tt_ids = wp_set_post_terms( $post_id, $ids, $tax );
				}
				unset( $post['terms'], $terms_to_set );
			}

			// add/update comments
			if ( ! empty( $post['comments'] ) ) {
				$num_comments = 0;
				$inserted_comments = array();
				foreach ( $post['comments'] as $comment ) {
					$comment_id	= $comment['comment_id'];
					$newcomments[$comment_id]['comment_post_ID']	  = $comment_post_ID;
					$newcomments[$comment_id]['comment_author']	   = $comment['comment_author'];
					$newcomments[$comment_id]['comment_author_email'] = $comment['comment_author_email'];
					$newcomments[$comment_id]['comment_author_IP']	= $comment['comment_author_IP'];
					$newcomments[$comment_id]['comment_author_url']   = $comment['comment_author_url'];
					$newcomments[$comment_id]['comment_date']		 = $comment['comment_date'];
					$newcomments[$comment_id]['comment_date_gmt']	 = $comment['comment_date_gmt'];
					$newcomments[$comment_id]['comment_content']	  = $comment['comment_content'];
					$newcomments[$comment_id]['comment_approved']	 = $comment['comment_approved'];
					$newcomments[$comment_id]['comment_type']		 = $comment['comment_type'];
					$newcomments[$comment_id]['comment_parent'] 	  = $comment['comment_parent'];
					$newcomments[$comment_id]['commentmeta']		  = isset( $comment['commentmeta'] ) ? $comment['commentmeta'] : array();
					if ( isset( $this->processed_authors[$comment['comment_user_id']] ) )
						$newcomments[$comment_id]['user_id'] = $this->processed_authors[$comment['comment_user_id']];
				}
				ksort( $newcomments );

				foreach ( $newcomments as $key => $comment ) {
					// if this is a new post we can skip the comment_exists() check
					if ( ! $post_exists || ! comment_exists( $comment['comment_author'], $comment['comment_date'] ) ) {
						if ( isset( $inserted_comments[$comment['comment_parent']] ) )
							$comment['comment_parent'] = $inserted_comments[$comment['comment_parent']];
						$comment = wp_filter_comment( $comment );
						$inserted_comments[$key] = wp_insert_comment( $comment );

						foreach( $comment['commentmeta'] as $meta ) {
							$value = maybe_unserialize( $meta['value'] );
							add_comment_meta( $inserted_comments[$key], $meta['key'], $value );
						}

						$num_comments++;
					}
				}
				unset( $newcomments, $inserted_comments, $post['comments'] );
			}

			// add/update post meta
			if ( isset( $post['postmeta'] ) ) {
				foreach ( $post['postmeta'] as $meta ) {
					$key = apply_filters( 'import_post_meta_key', $meta['key'] );
					$value = false;

					if ( '_edit_last' == $key ) {
						if ( isset( $this->processed_authors[intval($meta['value'])] ) )
							$value = $this->processed_authors[intval($meta['value'])];
						else
							$key = false;
					}

					if ( $key ) {
						// export gets meta straight from the DB so could have a serialized string
						if ( ! $value )
							$value = maybe_unserialize( $meta['value'] );

						add_post_meta( $post_id, $key, $value );
						do_action( 'import_post_meta', $post_id, $key, $value );

					}
				}
			}
		}

		unset( $this->posts );
	}

	/**
	 * If fetching attachments is enabled then attempt to create a new attachment
	 *
	 * @param array $post Attachment post details from WXR
	 * @param string $url URL to fetch attachment from
	 * @return int|WP_Error Post ID on success, WP_Error otherwise
	 */
	function process_attachment( $post, $url ) {
		if ( ! $this->fetch_attachments )
			return new WP_Error( 'attachment_processing_error',
				__( 'Fetching attachments is not enabled', 'wordpress-importer' ) );

		// if the URL is absolute, but does not contain address, then upload it assuming base_site_url
		if ( preg_match( '|^/[\w\W]+$|', $url ) )
			$url = rtrim( $this->base_url, '/' ) . $url;

		$upload = $this->fetch_remote_file( $url, $post );
		if ( is_wp_error( $upload ) )
			return $upload;

		if ( $info = wp_check_filetype( $upload['file'] ) )
			$post['post_mime_type'] = $info['type'];
		else
			return new WP_Error( 'attachment_processing_error', __('Invalid file type', 'wordpress-importer') );

		$post['guid'] = $upload['url'];

		// as per wp-admin/includes/upload.php
		$post_id = wp_insert_attachment( $post, $upload['file'] );
		wp_update_attachment_metadata( $post_id, wp_generate_attachment_metadata( $post_id, $upload['file'] ) );

		// remap resized image URLs, works by stripping the extension and remapping the URL stub.
		if ( preg_match( '!^image/!', $info['type'] ) ) {
			$parts = pathinfo( $url );
			$name = basename( $parts['basename'], ".{$parts['extension']}" ); // PATHINFO_FILENAME in PHP 5.2

			$parts_new = pathinfo( $upload['url'] );
			$name_new = basename( $parts_new['basename'], ".{$parts_new['extension']}" );

			$this->url_remap[$parts['dirname'] . '/' . $name] = $parts_new['dirname'] . '/' . $name_new;
		}

		return $post_id;
	}

	/**
	 * Attempt to download a remote file attachment
	 *
	 * @param string $url URL of item to fetch
	 * @param array $post Attachment details
	 * @return array|WP_Error Local file location details on success, WP_Error otherwise
	 */
	function fetch_remote_file( $url, $post ) {
		// extract the file name and extension from the url
		$file_name = basename( $url );

		// get placeholder file in the upload dir with a unique, sanitized filename
		$upload = wp_upload_bits( $file_name, 0, '', $post['upload_date'] );
		if ( $upload['error'] )
			return new WP_Error( 'upload_dir_error', $upload['error'] );

		// fetch the remote url and write it to the placeholder file
		$headers = wp_get_http( $url, $upload['file'] );

		// request failed
		if ( ! $headers ) {
			@unlink( $upload['file'] );
			return new WP_Error( 'import_file_error', __('Remote server did not respond', 'wordpress-importer') );
		}

		// make sure the fetch was successful
		if ( $headers['response'] != '200' ) {
			@unlink( $upload['file'] );
			return new WP_Error( 'import_file_error', sprintf( __('Remote server returned error response %1$d %2$s', 'wordpress-importer'), esc_html($headers['response']), get_status_header_desc($headers['response']) ) );
		}

		$filesize = filesize( $upload['file'] );

		if ( isset( $headers['content-length'] ) && $filesize != $headers['content-length'] ) {
			@unlink( $upload['file'] );
			return new WP_Error( 'import_file_error', __('Remote file is incorrect size', 'wordpress-importer') );
		}

		if ( 0 == $filesize ) {
			@unlink( $upload['file'] );
			return new WP_Error( 'import_file_error', __('Zero size file downloaded', 'wordpress-importer') );
		}

		$max_size = (int) $this->max_attachment_size();
		if ( ! empty( $max_size ) && $filesize > $max_size ) {
			@unlink( $upload['file'] );
			return new WP_Error( 'import_file_error', sprintf(__('Remote file is too large, limit is %s', 'wordpress-importer'), size_format($max_size) ) );
		}

		// keep track of the old and new urls so we can substitute them later
		$this->url_remap[$url] = $upload['url'];
		$this->url_remap[$post['guid']] = $upload['url']; // r13735, really needed?
		// keep track of the destination if the remote url is redirected somewhere else
		if ( isset($headers['x-final-location']) && $headers['x-final-location'] != $url )
			$this->url_remap[$headers['x-final-location']] = $upload['url'];

		return $upload;
	}


	// Display import page title
	function header() {
		echo '<div class="wrap">';
		screen_icon();
		echo '<h2>' . __( 'WP FetLife Importer', 'wp-fetlife-importer' ) . '</h2>';

		$updates = get_plugin_updates();
		$basename = plugin_basename(__FILE__);
		if ( isset( $updates[$basename] ) ) {
			$update = $updates[$basename];
			echo '<div class="error"><p><strong>';
			printf( __( 'A new version of this importer is available. Please update to version %s to ensure compatibility with newer export files.', 'wp-fetlife-importer' ), $update->update->new_version );
			echo '</strong></p></div>';
		}
	}

	// Close div.wrap
	function footer() {
        echo '<p>' . sprintf(
            __('This tool is brought to you courtesy of %1$smaymay&rsquo;s foresight%2$s. Please consider %3$smaking a donation%2$s. If you want to keep a backup of your entire FetLife account history, try %4$sthis free FetLife Exporter%2$s.', 'wp-fetlife-importer'),
            '<a href="http://maybemaimed.com/2011/03/20/fetlife-considered-harmful/">', '</a>',
            '<a href="http://maybemaimed.com/cyberbusking/">',
            '<a href="http://fetlife.maybemaimed.com/">'
        ) . '</p>';
		echo '</div>';
	}

	/**
	 * Display introductory text and file upload form
	 */
	function greet() {
		echo '<div class="narrow">';
		echo '<p>'.__( 'Howdy! Enter your FetLife connection details and I&rsquo;ll import your Writings as WordPress Posts.', 'wp-fetlife-importer' ).'</p>';
?>
<form id="wp-fetlife-importer-connect-form" method="post" action="<?php echo esc_attr(wp_nonce_url('admin.php?import=wp-fetlife-importer&amp;step=1', 'wp-fetlife-importer'));?>">
	<p>
        <label for="fl_nickname"><?php _e('FetLife nickname', 'wp-fetlife-importer');?></label>
        <input id="fl_nickname" name="fl_nickname" placeholder="<?php _e('Enter your FetLife nickname', 'wp-fetlife-importer');?>" />
	</p>
	<p>
        <label for="fl_password"><?php _e('FetLife password', 'wp-fetlife-importer');?></label>
		<input type="password" id="fl_password" name="fl_password" placeholder="<?php _e('Enter your FetLife password', 'wp-fetlife-importer');?>" />
	</p>
	<p>
		<label for="fl_proxyurl"><?php _e('Proxy URL', 'wp-fetlife-importer');?></label>
		<input id="fl_proxyurl" name="fl_proxyurl" placeholder="http://proxy.example.com:8080" />
		<br /><span class="description"><?php _e('If you need to use a proxy to connect to FetLife, enter its URL here. Otherwise, leave this blank to make a direct connection.', 'wp-fetlife-importer');?></span>
	</p>
	<?php submit_button(__('Connect to FetLife and import', 'wp-fetlife-importer'));?>
</form>
<?php
		echo '</div>';
	}

	/**
	 * Decide if the given meta key maps to information we will want to import
	 *
	 * @param string $key The meta key to check
	 * @return string|bool The key if we do want to import, false if not
	 */
	function is_valid_meta_key( $key ) {
		// skip attachment metadata since we'll regenerate it from scratch
		// skip _edit_lock as not relevant for import
		if ( in_array( $key, array( '_wp_attached_file', '_wp_attachment_metadata', '_edit_lock' ) ) )
			return false;
		return $key;
	}

	/**
	 * Decide whether or not the importer is allowed to create users.
	 * Default is true, can be filtered via import_allow_create_users
	 *
	 * @return bool True if creating users is allowed
	 */
	function allow_create_users() {
		return apply_filters( 'import_allow_create_users', true );
	}

	/**
	 * Decide whether or not the importer should attempt to download attachment files.
	 * Default is true, can be filtered via import_allow_fetch_attachments. The choice
	 * made at the import options screen must also be true, false here hides that checkbox.
	 *
	 * @return bool True if downloading attachments is allowed
	 */
	function allow_fetch_attachments() {
		return apply_filters( 'import_allow_fetch_attachments', true );
	}

	/**
	 * Decide what the maximum file size for downloaded attachments is.
	 * Default is 0 (unlimited), can be filtered via import_attachment_size_limit
	 *
	 * @return int Maximum attachment file size to import
	 */
	function max_attachment_size() {
		return apply_filters( 'import_attachment_size_limit', 0 );
	}

	/**
	 * Added to http_request_timeout filter to force timeout at 60 seconds during import
	 * @return int 60
	 */
	function bump_request_timeout($val) {
		return 60;
	}

	// return the difference in length between two strings
	function cmpr_strlen( $a, $b ) {
		return strlen($b) - strlen($a);
	}
}

} // class_exists( 'WP_Importer' )

function wp_fetlife_importer_init() {
	load_plugin_textdomain( 'wp-fetlife-importer', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	/**
	 * WP FetLife Importer object for registering the import callback
	 * @global WP_FetLife_Import $wp_fetlife_import
	 */
	$GLOBALS['wp_fetlife_import'] = new WP_FetLife_Import();
	register_importer( 'wp-fetlife-importer', 'FetLife', __('Import your FetLife Writings to your WordPress blog as posts.', 'wp-fetlife-importer'), array( $GLOBALS['wp_fetlife_import'], 'dispatch' ) );
}
add_action( 'admin_init', 'wp_fetlife_importer_init' );
