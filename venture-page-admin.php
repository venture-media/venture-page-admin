<?php
/**
 * Plugin Name:       Venture Page Admin
 * Description:       Adds a "No index" checkbox column to Pages in the admin list table and provides a simple redirect manager dashboard widget.
 * Version:           0.9.0
 * Author:            Leon de Klerk
 * Text Domain:       venture-page-admin
 * License:           MIT
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// === No Index Functionality ===

// Enqueue admin scripts only on the Pages list table
function venture_page_admin_enqueue_assets( $hook ) {
    $screen = get_current_screen();
    
    if ( ! $screen || $screen->post_type !== 'page' || $hook !== 'edit.php' ) {
        return;
    }

    wp_enqueue_script(
        'venture-page-admin-noindex-js',
        plugin_dir_url( __FILE__ ) . 'assets/js/noindex.js',
        array( 'jquery' ),
        '0.9.0',
        true
    );

    wp_localize_script(
        'venture-page-admin-noindex-js',
        'venturePageAdmin',
        array(
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
        )
    );
}
add_action( 'admin_enqueue_scripts', 'venture_page_admin_enqueue_assets' );

// --- 1. Add "No index" column ---
function venture_page_admin_add_column( $columns ) {
    $columns['noindex'] = __( 'No index', 'venture-page-admin' );
    return $columns;
}
add_filter( 'manage_page_posts_columns', 'venture_page_admin_add_column' );

// --- 2. Render checkbox ---
function venture_page_admin_render_column( $column, $post_id ) {
    if ( $column === 'noindex' ) {
        $checked = get_post_meta( $post_id, '_noindex', true ) ? 'checked' : '';
        $nonce   = wp_create_nonce( 'toggle_noindex_' . $post_id );
        echo '<input type="checkbox" class="noindex-toggle" data-post-id="' . esc_attr( $post_id ) . '" data-nonce="' . esc_attr( $nonce ) . '" ' . $checked . '>';
    }
}
add_action( 'manage_page_posts_custom_column', 'venture_page_admin_render_column', 10, 2 );

// --- 3. Save checkbox via AJAX ---
function venture_page_admin_ajax_save() {
    $post_id = intval( $_POST['post_id'] ?? 0 );
    $value   = ! empty( $_POST['value'] ) ? 1 : 0;
    $nonce   = $_POST['nonce'] ?? '';

    if ( ! $post_id || ! wp_verify_nonce( $nonce, 'toggle_noindex_' . $post_id ) ) {
        wp_send_json_error( 'Invalid nonce' );
    }

    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        wp_send_json_error( 'Insufficient permissions' );
    }

    update_post_meta( $post_id, '_noindex', $value );
    
    // Return fresh nonce
    $new_nonce = wp_create_nonce( 'toggle_noindex_' . $post_id );
    wp_send_json_success( array( 'nonce' => $new_nonce ) );
}
add_action( 'wp_ajax_toggle_noindex', 'venture_page_admin_ajax_save' );

// --- 4. Output <meta name="robots" content="noindex"> on front-end ---
function venture_page_admin_meta() {
    if ( is_singular( 'page' ) && get_post_meta( get_the_ID(), '_noindex', true ) ) {
        echo '<meta name="robots" content="noindex">' . "\n";
    }
}
add_action( 'wp_head', 'venture_page_admin_meta' );



// === Redirect Manager Functionality ===

// Handle redirects on the front-end
add_action('template_redirect', function() {
    $redirects = get_option('venture_redirects_list', []);

    if (!is_array($redirects)) return;

    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $path = trailingslashit($path);

    if (isset($redirects[$path])) {
        wp_redirect(home_url($redirects[$path]), 301);
        exit;
    }
});

// Add dashboard widget
add_action('wp_dashboard_setup', function() {
    wp_add_dashboard_widget(
        'venture_redirects_widget',
        'Venture Redirects',
        'venture_redirects_widget_display'
    );
});

function venture_redirects_widget_display() {
    $redirects = get_option('venture_redirects_list', []);
    if (!is_array($redirects)) $redirects = [];

    // Handle form submission
    if (!empty($_POST['venture_redirects_nonce']) &&
        wp_verify_nonce($_POST['venture_redirects_nonce'], 'venture_redirects_save')) {

        $source = trailingslashit(sanitize_text_field($_POST['redirect_from']));
        $target = trailingslashit(sanitize_text_field($_POST['redirect_to']));

        if (!empty($source) && !empty($target)) {
            $redirects[$source] = $target;
            update_option('venture_redirects_list', $redirects);
            echo '<div class="updated"><p>Redirect added successfully!</p></div>';
        }
    }

    // Handle deletion
    if (isset($_GET['delete_redirect'])) {
        $delete = sanitize_text_field($_GET['delete_redirect']);
        unset($redirects[$delete]);
        update_option('venture_redirects_list', $redirects);
        echo '<div class="updated"><p>Redirect deleted.</p></div>';
    }

    // Display form
    ?>
    <form method="post">
        <?php wp_nonce_field('venture_redirects_save', 'venture_redirects_nonce'); ?>
        <p>
            <label><strong>From:</strong> (e.g. /old-page/)</label><br>
            <input type="text" name="redirect_from" style="width:100%;" required>
        </p>
        <p>
            <label><strong>To:</strong> (e.g. /2025/old-page/)</label><br>
            <input type="text" name="redirect_to" style="width:100%;" required>
        </p>
        <p><button type="submit" class="button button-primary">Add Redirect</button></p>
    </form>

    <hr>
    <h3>Existing Redirects</h3>
    <table class="widefat striped">
        <thead><tr><th>From</th><th>To</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($redirects as $from => $to): ?>
            <tr>
                <td><?php echo esc_html($from); ?></td>
                <td><?php echo esc_html($to); ?></td>
                <td><a href="?delete_redirect=<?php echo urlencode($from); ?>" class="button">Delete</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}
