<?php
/*
Template Name: Service Queue App
*/

// Ensure WordPress is loaded
if (!defined('ABSPATH')) exit;

// Remove admin bar for this page
add_filter('show_admin_bar', '__return_false');

// Dequeue Elementor scripts and styles
add_action('wp_enqueue_scripts', function () {
    // Common scripts
    wp_dequeue_script('elementor-common');
    wp_deregister_script('elementor-common');
    wp_dequeue_script('elementor-app-loader');
    wp_deregister_script('elementor-app-loader');

    // Frontend scripts
    wp_dequeue_script('elementor-frontend');
    wp_deregister_script('elementor-frontend');

    // Styles
    wp_dequeue_style('elementor-frontend');
    wp_deregister_style('elementor-frontend');
    wp_dequeue_style('elementor-common');
    wp_deregister_style('elementor-common');

    // Remove potentially conflicting scripts
    wp_dequeue_script('jquery-lazyload');
    wp_deregister_script('jquery-lazyload');
}, 20);

// Remove Elementor template redirect
remove_action('template_redirect', 'elementor_template_redirect');

// Clean output - remove unnecessary WordPress headers
remove_action('wp_head', 'print_emoji_detection_script', 7);
remove_action('wp_print_styles', 'print_emoji_styles');
remove_action('wp_head', 'wp_generator');
remove_action('wp_head', 'wlwmanifest_link');
remove_action('wp_head', 'rsd_link');
remove_action('wp_head', 'wp_shortlink_wp_head');

// Remove all actions that might add content to wp_footer
remove_all_actions('wp_footer');
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>

<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Service Queue</title>
    <?php wp_head(); ?>
</head>

<body class="service-queue-page">
    <div id="service-queue-app"></div>
    <?php
    // Re-add only our script
    do_action('wp_print_footer_scripts');
    ?>
</body>

</html>
