<?php
/*
Plugin Name: Drafts Sharer
Plugin URI:
Description: Share a draft post with a secret URL
Author:
Version:
Author URI:
*/

class DraftsSharer {
	function __construct() {
		add_action( 'init', [ &$this, 'init' ] );
	}

	function init() {
		global $current_user;
		add_action( 'admin_menu', [ $this, 'add_admin_pages' ] );
		add_filter( 'the_posts', [ $this, 'the_posts_intercept' ] );
		add_filter( 'posts_results', [ $this, 'posts_results_intercept' ] );
		$this->admin_options = $this->get_admin_options();
		$this->user_options  = ( $current_user->id > 0 && isset( $this->admin_options[ $current_user->id ] ) ) ? $this->admin_options[ $current_user->id ] : [];
		$this->save_admin_options();
		$this->admin_page_init();
	}

	function admin_page_init() {
		wp_enqueue_script( 'jquery' );
		add_action( 'admin_head', [ $this, 'print_admin_css' ] );
		add_action( 'admin_head', [ $this, 'print_admin_js' ] );
	}

	function get_admin_options() {
		$saved_options = get_option( 'shared' );

		return is_array( $saved_options ) ? $saved_options : [];
	}

	function save_admin_options() {
		global $current_user;
		if ( $current_user->id > 0 ) {
			$this->admin_options[ $current_user->id ] = $this->user_options;
		}
		update_option( 'shared', $this->admin_options );
	}

	function add_admin_pages() {
		add_submenu_page( "edit.php", __( 'Drafts Sharer', 'draftssharer' ), __( 'Drafts Sharer', 'draftssharer' ),
			1, __FILE__, [ $this, 'output_existing_menu_sub_admin_page' ] );
	}

	function calc( $params ) {
		$exp      = 60;
		$multiply = 60;
		if ( isset( $params['expires'] ) && ( $e = intval( $params['expires'] ) ) ) {
			$exp = $e;
		}
		$mults = [ 's' => 1, 'm' => 60, 'h' => 3600, 'd' => 24 * 3600 ];
		if ( $params['measure'] && $mults[ $params['measure'] ] ) {
			$multiply = $mults[ $params['measure'] ];
		}

		return $exp * $multiply;
	}

	function process_post_options( $params ) {
		global $current_user;
		if ( $params['post_id'] ) {
			$p = get_post( $params['post_id'] );
			if ( ! $p ) {
				return __( 'There is no such post!', 'draftssharer' );
			}
			if ( 'publish' == get_post_status( $p ) ) {
				return __( 'The post is published!', 'draftssharer' );
			}
			$this->user_options['shared'][] = [
				'id'      => $p->ID,
				'expires' => time() + $this->calc( $params ),
				'key'     => 'baba_' . wp_generate_password( 8 )
			];
			$this->save_admin_options();
		}
	}

	function process_delete( $params ) {
		$shared = [];
		foreach ( $this->user_options['shared'] as $share ) {
			if ( $share['key'] == $params['key'] ) {
				continue;
			}
			$shared[] = $share;
		}
		$this->user_options['shared'] = $shared;
		$this->save_admin_options();
	}

	function process_extend( $params ) {
		$shared = [];
		foreach ( $this->user_options['shared'] as $share ) {
			if ( $share['key'] == $params['key'] ) {
				$share['expires'] += $this->calc( $params );
			}
			$shared[] = $share;
		}
		$this->user_options['shared'] = $shared;
		$this->save_admin_options();
	}

	function get_drafts() {
		global $current_user;
		$my_drafts    = get_users_drafts( $current_user->id );
		$my_scheduled = $this->get_users_future( $current_user->id );
		$pending      = get_others_pending( $current_user->id );
		$ds           = [
			[
				__( 'Your Drafts:', 'draftssharer' ),
				count( $my_drafts ),
				$my_drafts,
			],
			[
				__( 'Your Scheduled Posts:', 'draftssharer' ),
				count( $my_scheduled ),
				$my_scheduled,
			],
			[
				__( 'Pending Review:', 'draftssharer' ),
				count( $pending ),
				$pending,
			],
		];

		return $ds;
	}

	function get_users_future( $user_id ) {
		global $wpdb;

		return $wpdb->get_results( "SELECT ID, post_title FROM $wpdb->posts WHERE post_type = 'post' AND post_status = 'future' AND post_author = $user_id ORDER BY post_modified DESC" );
	}

	function get_shared() {
		return $this->user_options['shared'];
	}

	function output_existing_menu_sub_admin_page() {
		if ( $_POST['draftssharer_submit'] ) {
			$t = $this->process_post_options( $_POST );
		} elseif ( $_POST['action'] == 'extend' ) {
			$t = $this->process_extend( $_POST );
		} elseif ( $_GET['action'] == 'delete' ) {
			$t = $this->process_delete( $_GET );
		}
		$ds = $this->get_drafts();
		?>
		<div class="wrap">
			<h2><? _e( 'Drafts Sharer', 'draftssharer' ); ?></h2>
			<?php if ( $t ): ?>
				<div id="message" class="updated fade"><?php echo $t; ?></div>
			<?php endif; ?>
			<h3><?php _e( 'Currently shared drafts', 'draftssharer' ); ?></h3>
			<table class="widefat">
				<thead>
				<tr>
					<th><?php _e( 'ID', 'draftssharer' ); ?></th>
					<th><?php _e( 'Title', 'draftssharer' ); ?></th>
					<th><?php _e( 'Link', 'draftssharer' ); ?></th>
					<th colspan="2" class="actions"><?php _e( 'Actions', 'draftssharer' ); ?></th>
				</tr>
				</thead>
				<tbody>
				<?php
				$s       = $this->get_shared();
				foreach ( $s as $share ):
					$p = get_post( $share['id'] );
					$url = get_bloginfo( 'url' ) . '/?p=' . $p->ID . '&draftssharer=' . $share['key'];
					?>
					<tr>
						<td><?php echo $p->ID; ?></td>
						<td><?php echo $p->post_title; ?></td>
						<!-- TODO: make the draft link selecatble -->
						<td><a href="<?php echo $url; ?>"><?php echo esc_html( $url ); ?></a></td>
						<td class="actions">
							<a class="draftssharer-extend edit" id="draftssharer-extend-link-<?php echo $share['key']; ?>"
							   href="javascript:draftssharer.toggle_extend('<?php echo $share['key']; ?>');">
								<?php _e( 'Extend', 'draftssharer' ); ?>
							</a>
							<form class="draftssharer-extend" id="draftssharer-extend-form-<?php echo $share['key']; ?>"
							      action="" method="post">
								<input type="hidden" name="action" value="extend"/>
								<input type="hidden" name="key" value="<?php echo $share['key']; ?>"/>
								<input type="submit" class="button" name="draftssharer_extend_submit"
								       value="<?php _e( 'Extend', 'draftssharer' ); ?>"/>
								<?php _e( 'by', 'draftssharer' ); ?>
								<?php echo $this->tmpl_measure_select(); ?>
								<a class="draftssharer-extend-cancel"
								   href="javascript:draftssharer.cancel_extend('<?php echo $share['key']; ?>');">
									<?php _e( 'Cancel', 'draftssharer' ); ?>
								</a>
							</form>
						</td>
						<td class="actions">
							<a class="delete" href="edit.php?page=<?php echo plugin_basename( __FILE__ ); ?>&amp;action=delete&amp;key=<?php echo $share['key']; ?>"><?php _e( 'Delete', 'draftssharer' ); ?></a>
						</td>
					</tr>
				<?php
				endforeach;
				if ( empty( $s ) ):
					?>
					<tr>
						<td colspan="5"><?php _e( 'No shared drafts!', 'draftssharer' ); ?></td>
					</tr>
				<?php
				endif;
				?>
				</tbody>
			</table>
			<h3><?php _e( 'Drafts Sharer', 'draftssharer' ); ?></h3>
			<form id="draftssharer-share" action="" method="post">
				<p>
					<select id="draftssharer-postid" name="post_id">
						<option value=""><?php _e( 'Choose a draft', 'draftssharer' ); ?></option>
						<?php
						foreach ( $ds as $dt ):
							if ( $dt[1] ):
								?>
								<option value="" disabled="disabled"></option>
								<option value="" disabled="disabled"><?php echo $dt[0]; ?></option>
								<?php
								foreach ( $dt[2] as $d ):
									if ( empty( $d->post_title ) ) {
										continue;
									}
									?>
									<option value="<?php echo $d->ID ?>"><?php echo wp_specialchars( $d->post_title ); ?></option>
								<?php
								endforeach;
							endif;
						endforeach;
						?>
					</select>
				</p>
				<p>
					<input type="submit" class="button" name="draftssharer_submit"
					       value="<?php _e( 'Share it', 'draftssharer' ); ?>"/>
					<?php _e( 'for', 'draftssharer' ); ?>
					<?php echo $this->tmpl_measure_select(); ?>.
				</p>
			</form>
		</div>
		<?php
	}

	function can_view( $pid ) {
		foreach ( $this->admin_options as $option ) {
			$shares = $option['shared'];
			foreach ( $shares as $share ) {
				if ( $share['key'] == $_GET['draftssharer'] && $pid ) {
					return true;
				}
			}
		}

		return false;
	}

	function posts_results_intercept( $pp ) {
		if ( 1 != count( $pp ) ) {
			return $pp;
		}
		$p      = $pp[0];
		$status = get_post_status( $p );
		if ( 'publish' != $status && $this->can_view( $p->ID ) ) {
			$this->shared_post = $p;
		}

		return $pp;
	}

	function the_posts_intercept( $pp ) {
		if ( empty( $pp ) && ! is_null( $this->shared_post ) ) {
			return [ $this->shared_post ];
		} else {
			$this->shared_post = null;

			return $pp;
		}
	}

	function tmpl_measure_select() {
		$secs  = __( 'seconds', 'draftssharer' );
		$mins  = __( 'minutes', 'draftssharer' );
		$hours = __( 'hours', 'draftssharer' );
		$days  = __( 'days', 'draftssharer' );

		return <<<SELECT
                        <input name="expires" type="text" value="2" size="4"/>
                        <select name="measure">
                                <option value="s">$secs</option>
                                <option value="m">$mins</option>
                                <option value="h" selected="selected">$hours</option>
                                <option value="d">$days</option>
                        </select>
SELECT;
	}

	function print_admin_css() {
		?>
		<style type="text/css">
			a.draftssharer-extend, a.draftssharer-extend-cancel {
				display: none;
			}

			form.draftssharer-extend {
				white-space: nowrap;
			}

			form.draftssharer-extend, form.draftssharer-extend input, form.draftssharer-extend select {
				font-size: 11px;
			}

			th.actions, td.actions {
				text-align: center;
			}
		</style>
		<?php
	}

	function print_admin_js() {
		?>
		<script type="text/javascript">
			jQuery( function() {
				jQuery( 'form.draftssharer-extend' ).hide();
				jQuery( 'a.draftssharer-extend' ).show();
				jQuery( 'a.draftssharer-extend-cancel' ).show();
				jQuery( 'a.draftssharer-extend-cancel' ).css( 'display', 'inline' );
			} );
			window.draftssharer = {
				toggle_extend: function( key ) {
					jQuery( '#draftssharer-extend-form-' + key ).show();
					jQuery( '#draftssharer-extend-link-' + key ).hide();
					jQuery( '#draftssharer-extend-form-' + key + ' input[name="expires"]' ).focus();
				},
				cancel_extend: function( key ) {
					jQuery( '#draftssharer-extend-form-' + key ).hide();
					jQuery( '#draftssharer-extend-link-' + key ).show();
				}
			};
		</script>
		<?php
	}
}

new DraftsSharer();
