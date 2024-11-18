<?php
/*
Plugin Name: Directory Page Mapper
Description: Manage documentation organized in directories and create hierarchical WordPress pages accordingly.
Version: 2.2
Author: Morgan ATTIAS
Text Domain: directory-page-mapper
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class DirectoryPageManager {
    public function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        add_action('admin_menu', [$this, 'addAdminMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueScripts']);
        add_shortcode('directory_listing', [$this, 'directoryListingShortcode']);
    }

    public function activate() {
        add_option('directory_mapper_skip_empty', '0');
        add_option('directory_mapper_font_awesome_url', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css');
        add_option('directory_mapper_custom_folder_icons', '{}');
        add_option('directory_mapper_disable_breadcrumbs', '0');
        add_option('directory_mapper_exclusion', '{}');
    }

    public function deactivate() {
        // Cleanup actions, like removing options or scheduled events
        delete_option('directory_mapper_root_directory');
        delete_option('directory_mapper_skip_empty');
        delete_option('directory_mapper_font_awesome_url');
        delete_option('directory_mapper_custom_folder_icons');
        delete_option('directory_mapper_disable_breadcrumbs');
        delete_option('directory_mapper_exclusion');
    }

    public function addAdminMenu() {
        add_menu_page(
            __('Directory Page Mapper', 'directory-page-mapper'),
            __('Directory Mapper', 'directory-page-mapper'),
            'manage_options', // Restrict access to administrators
            'directory-mapper',
            [$this, 'settingsPage'],
            'dashicons-media-text'
        );
    }

    public function registerSettings() {
        register_setting('directory_mapper_options_group', 'directory_mapper_root_directory');
        register_setting('directory_mapper_options_group', 'directory_mapper_skip_empty');
        register_setting('directory_mapper_options_group', 'directory_mapper_font_awesome_url');
        register_setting('directory_mapper_options_group', 'directory_mapper_custom_folder_icons');
        register_setting('directory_mapper_options_group', 'directory_mapper_disable_breadcrumbs');
        register_setting('directory_mapper_options_group', 'directory_mapper_exclusion');
    }

    public function settingsPage() {
        // Check if the 'Save Changes and Regenerate Pages' button was submitted
        if (isset($_POST['submit']) && check_admin_referer('update_directory_mapper_settings')) {

            // Only allow administrators to save settings
            if (!current_user_can('manage_options')) {
                wp_die(__('You are not allowed to manage these options.', 'directory-page-mapper'));
            }

            update_option('directory_mapper_root_directory', sanitize_text_field($_POST['directory_mapper_root_directory']));
            update_option('directory_mapper_skip_empty', isset($_POST['directory_mapper_skip_empty']) ? '1' : '0');
            update_option('directory_mapper_font_awesome_url', sanitize_text_field($_POST['directory_mapper_font_awesome_url']));
            update_option('directory_mapper_disable_breadcrumbs', isset($_POST['directory_mapper_disable_breadcrumbs']) ? '1' : '0');

            // Validate and save custom folder icons
            $jsonString = stripslashes($_POST['directory_mapper_custom_folder_icons']);
            $decodedJson = json_decode($jsonString, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                update_option('directory_mapper_custom_folder_icons', $jsonString);
            } elseif ($jsonString === '') {
                update_option('directory_mapper_custom_folder_icons', '{}');
            } else {
                echo '<div class="error"><p>' . __('Custom Folder Icons is invalid and was ignored.', 'directory-page-mapper') . ' ' . json_last_error_msg() . '</p></div>';
                error_log('Custom Folder Icons JSON is invalid: ' . json_last_error_msg());
            }

            // Validate and save exclusion list
            $jsonString = stripslashes($_POST['directory_mapper_exclusion']);
            $decodedJson = json_decode($jsonString, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                update_option('directory_mapper_exclusion', $jsonString);
            } elseif ($jsonString === '') {
                update_option('directory_mapper_exclusion', '{}');
            } else {
                echo '<div class="error"><p>' . __('Exclusion list is invalid and was ignored.', 'directory-page-mapper') . ' ' . json_last_error_msg() . '</p></div>';
                error_log('Exclusion list JSON is invalid: ' . json_last_error_msg());
            }

            // Trigger documentation regeneration
            $this->createDocumentationPages();
            echo '<div class="updated"><p>' . __('Pages updated and settings saved.', 'directory-page-mapper') . '</p></div>';
        }

        // Check if the 'Regenerate Pages Only' button was submitted
        if (isset($_POST['regenerate']) && check_admin_referer('update_directory_mapper_settings')) {

            // Allow users with 'publish_pages' capability to regenerate pages
            if (!current_user_can('publish_pages')) {
                wp_die(__('You do not have sufficient permissions to regenerate pages.', 'directory-page-mapper'));
            }

            // Trigger documentation regeneration without updating settings
            $this->createDocumentationPages();
            echo '<div class="updated"><p>' . __('Pages regenerated.', 'directory-page-mapper') . '</p></div>';
        }

        // Check if the 'Delete Pages' button was submitted
        if (isset($_POST['delete']) && check_admin_referer('update_directory_mapper_settings')) {

            // Allow users with 'delete_pages' capability to delete pages
            if (!current_user_can('delete_pages')) {
                wp_die(__('You do not have sufficient permissions to delete pages.', 'directory-page-mapper'));
            }

            // Trigger documentation deletion
            $this->deleteDocumentationPages();
            echo '<div class="updated"><p>' . __('Pages deleted.', 'directory-page-mapper') . '</p></div>';
        }

        include plugin_dir_path(__FILE__) . 'admin/settings-page.php';
    }

    // Get the data from the exclusion list
    public function getExclusions() {
        $exclusions = json_decode(stripslashes(get_option('directory_mapper_exclusion', '{}')), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $exclusions = [];
        }
        $exclusions['directories'] = isset($exclusions['directories']) && is_array($exclusions['directories']) ? $exclusions['directories'] : [];
        $exclusions['files'] = isset($exclusions['files']) && is_array($exclusions['files']) ? $exclusions['files'] : [];
        $exclusions['regex'] = isset($exclusions['regex']) && is_array($exclusions['regex']) ? $exclusions['regex'] : [];
        return $exclusions;
    }

    public function createDocumentationPages() {
        $root_directory = get_option('directory_mapper_root_directory');
        if (empty($root_directory) || !is_dir($root_directory)) {
            error_log('Invalid root directory path for pages: ' . $root_directory);
            return;
        }
        $root_directory = realpath($root_directory);
        $skip_empty = get_option('directory_mapper_skip_empty') === '1';
        $parent_id = 0; // Top level pages have no parent
        $this->create_pages_recursive($root_directory, $parent_id, $skip_empty);
    }

    // Recursive function to create pages
    private function create_pages_recursive($dir_path, $parent_id, $skip_empty) {
        $directories = array_filter(glob($dir_path . '/*'), 'is_dir');
        $exclusions = $this->getExclusions();
        foreach ($directories as $directory) {
            $dir_name = basename($directory);
            $page_title = $this->titleize_directory($dir_name);

            // Check if directory is in exclusion list
            $exclude_dir = in_array($dir_name, $exclusions['directories']);

            // Check if the regex exclusion matches the directory name
            if (isset($exclusions['regex'])) {
                foreach ($exclusions['regex'] as $regex) {
                    if (@preg_match($regex, $dir_name)) {
                        $exclude_dir = true;
                        break;
                    }
                }
            }

            // Skip directory if it's in the exclusion list
            if ($exclude_dir) {
                continue;
            }

            // Check if directory is empty
            $is_empty = (count(glob("$directory/*")) === 0);

            // Check if page already exists
            $existing_page_id = $this->get_page_by_directory_name($page_title, $parent_id);

            // Delete Page if directory is empty and setting is enabled
            if ($skip_empty && $is_empty && $existing_page_id !== false) {
                wp_delete_post($existing_page_id, true);
                continue;
            }

            // Skip directory if it's empty and setting is enabled
            if ($skip_empty && $is_empty) {
                continue;
            }

            // Create new page if not exists
            if (!$existing_page_id) {
                $page_id = wp_insert_post([
                    'post_title'   => $page_title,
                    'post_content' => '[directory_listing path="' . esc_attr($directory) . '"]', // Shortcode to display directory contents
                    'post_type'    => 'page',
                    'post_status'  => 'publish',
                    'post_parent'  => $parent_id,
                    'meta_input'   => ['directory_path' => $directory],
                ]);
                if (is_wp_error($page_id)) {
                    error_log('Error creating page for directory: ' . $dir_name);
                    continue;
                }
            } else {
                $page_id = $existing_page_id; // Use existing page ID
            }

            // Recursive call to create pages for subdirectories
            $this->create_pages_recursive($directory, $page_id, $skip_empty);
        }
    }

    // Function to delete the pages created by the plugin
    public function deleteDocumentationPages() {
        if (!current_user_can('delete_pages')) {
            wp_die(__('You do not have sufficient permissions to delete pages.', 'directory-page-mapper'));
        }
        $root_directory = get_option('directory_mapper_root_directory');
        if (empty($root_directory) || !is_dir($root_directory)) {
            error_log('Invalid root directory path for pages: ' . $root_directory);
            return;
        }
        $root_directory = realpath($root_directory);
        $this->delete_pages_recursive($root_directory);
    }

    private function delete_pages_recursive($dir_path) {
        $directories = array_filter(glob($dir_path . '/*'), 'is_dir');
        foreach ($directories as $directory) {
            $dir_name = basename($directory);
            $page_title = $this->titleize_directory($dir_name);

            // Check if page exists
            $existing_page_id = $this->get_page_by_directory_name($page_title);

            if ($existing_page_id !== false) {
                wp_delete_post($existing_page_id, true);
            }

            // Recursive call to delete pages for subdirectories
            $this->delete_pages_recursive($directory);
        }
    }

    // Function to check if a page with a given title and parent ID already exists
    private function get_page_by_directory_name($page_title, $parent_id = null) {
        $args = [
            'post_type'   => 'page',
            'post_status' => 'publish',
            'title'       => $page_title,
            'numberposts' => 1,
        ];

        if ($parent_id !== null) {
            $args['post_parent'] = $parent_id;
        }
        $pages = get_posts($args);
        return $pages ? $pages[0]->ID : false;
    }

    public function enqueueScripts() {
        // Enqueue necessary scripts and styles
        $fontAwesomeUrl = get_option('directory_mapper_font_awesome_url', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css');
        wp_enqueue_style('font-awesome', esc_url($fontAwesomeUrl));

        // Enqueue custom CSS if exists
        $customCssUrl = plugin_dir_url(__FILE__) . 'public/css/directory-mapper.css';
        wp_enqueue_style('directory-mapper-custom-css', esc_url($customCssUrl));
    }

    public function directoryListingShortcode($atts) {
        $this->enqueueScripts(); // Ensure Font Awesome is enqueued

        $custom_icons_json = stripslashes(get_option('directory_mapper_custom_folder_icons', '{}')); // Default to empty JSON object
        $custom_icons = json_decode($custom_icons_json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $custom_icons = [];
        }

        $output = '<div class="directory-listing">';
        $atts = shortcode_atts(['path' => ''], $atts);
        $path = $atts['path'];
        $root_directory = get_option('directory_mapper_root_directory');

        // Validate that root directory is set
        if (empty($root_directory) || !is_dir($root_directory)) {
            return '<div class="error">' . __('Invalid root directory path.', 'directory-page-mapper') . '</div>';
        }

        $path = realpath($path);
        $root_directory = realpath($root_directory);

        // Validate that the path is within the root directory
        if (strpos($path, $root_directory) !== 0) {
            return '<div class="error">' . __('Invalid directory path.', 'directory-page-mapper') . '</div>';
        }

        // If breadcrumbs are not disabled, generate breadcrumb links
        if (get_option('directory_mapper_disable_breadcrumbs') !== '1') {
            $output .= $this->generate_breadcrumbs($path);
        }

        if (file_exists($path) && is_dir($path)) {
            $entries = array_diff(scandir($path), ['.', '..']);

            // Get directory exclusion lists
            $exclusions = $this->getExclusions();

            $output .= "<div class='file-entries'>";
            foreach ($entries as $entry) {
                $entry_path = $path . '/' . $entry;
                // Skip the entry if it's in the exclusion list
                if (in_array($entry, $exclusions['files'])) {
                    continue;
                }

                // Skip the entry if it matches the regex exclusion
                if (isset($exclusions['regex'])) {
                    foreach ($exclusions['regex'] as $regex) {
                        if (@preg_match($regex, $entry)) {
                            continue 2; // Skip to next entry
                        }
                    }
                }

                $is_dir = is_dir($entry_path);
                $title = $this->titleize_filename($entry);
                $file_ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));

                if ($is_dir) {
                    $icon_class = isset($custom_icons[$entry]) ? $custom_icons[$entry] : 'fas fa-folder';
                } else {
                    $icon_class = $this->get_file_icon_class($file_ext);
                }

                if ($is_dir) {
                    // Skip directory if it's in the exclusion list
                    if (in_array($entry, $exclusions['directories'])) {
                        continue;
                    }
                    // Check regex exclusion for directories
                    if (isset($exclusions['regex'])) {
                        foreach ($exclusions['regex'] as $regex) {
                            if (@preg_match($regex, $entry)) {
                                continue 2; // Skip to next entry
                            }
                        }
                    }

                    // Link to the page representing the directory
                    $page_link = $this->get_page_link_by_path($entry, $path);

                    // If skip empty is enabled and page_link is false, skip the directory
                    if (get_option('directory_mapper_skip_empty') === '1' && !$page_link) {
                        continue;
                    }

                    $output .= '<div class="file-entry">';
                    $output .= '<div class="directory-icon"><i class="' . esc_attr($icon_class) . ' fa-fw"></i></div>';
                    $output .= $page_link ? '<a href="' . esc_url($page_link) . '">' . esc_html($entry) . '</a>' : esc_html($entry);
                    $output .= '</div>';
                } else {
                    // Link to the document
                    $output .= '<div class="file-entry">';
                    $output .= '<div class="file-icon"><i class="' . esc_attr($icon_class) . ' fa-fw"></i></div>';
                    $file_url = str_replace(WP_CONTENT_DIR, WP_CONTENT_URL, $entry_path);
                    $output .= '<a href="' . esc_url($file_url) . '" class="file-download">' . esc_html($title) . '</a>';
                    $output .= '<div class="file-name">' . esc_html($entry) . ' ';
                    $filesize = $this->format_filesize(filesize($entry_path)); // Get filesize
                    $output .= '<span class="file-size">(' . esc_html($filesize) . ')</span>'; // Add filesize to output
                    $output .= '</div>';
                    $pdfInfo = $this->getPDFInfo($entry_path);
                    foreach ($pdfInfo as $key => $value) {
                        if (!empty($value)) {
                            $value = strtotime($value) ? date_i18n(get_option('date_format'), strtotime($value)) : $value;
                            $output .= '<div class="pdf-info">' . esc_html($this->titleize_keys($key)) . ': <b>' . esc_html($value) . '</b></div>';
                        }
                    }
                    $output .= '</div>';
                }
            }
            $output .= '</div><br style="clear:all;" />';
        } else {
            $output .= '<div class="error">' . __('Directory not found.', 'directory-page-mapper') . '</div>';
        }
        $output .= '</div>';
        return $output;
    }

    // Utilities for PDF metadata extraction
    private function getPDFInfo($filename) {
        $pdfInfo = [
            'title'        => '',
            'author'       => '',
            'description'  => '',
            'pages'        => '',
            'creationDate' => ''
        ];

        $fp = @fopen($filename, 'rb');
        if (!$fp) {
            return $pdfInfo;
        }

        // Read first 1KB of the file
        $content = fread($fp, 1024);
        fclose($fp);

        // Use regular expressions to extract metadata
        if (preg_match('/\/Title\s*\((.*?)\)/', $content, $matches)) {
            $pdfInfo['title'] = $matches[1];
        }
        if (preg_match('/\/Author\s*\((.*?)\)/', $content, $matches)) {
            $pdfInfo['author'] = $matches[1];
        }
        if (preg_match('/\/Subject\s*\((.*?)\)/', $content, $matches)) {
            $pdfInfo['description'] = $matches[1];
        }
        if (preg_match('/\/CreationDate\s*\((.*?)\)/', $content, $matches)) {
            $pdfInfo['creationDate'] = $matches[1];
        }
        if (preg_match('/\/N\s+([0-9]+)/', $content, $matches)) {
            $pdfInfo['pages'] = $matches[1];
        }

        return $pdfInfo;
    }

    // Utility function to format filesize
    public static function format_filesize($bytes, $decimals = 2) {
        $size = ['B', 'kB', 'MB', 'GB', 'TB'];
        $factor = floor((strlen($bytes) - 1) / 3);
        return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$size[$factor];
    }

    // Utility function to get Font Awesome icon class based on file extension
    private function get_file_icon_class($extension) {
        // Define Font Awesome icon classes for different file types
        $icons = [
            'pdf'  => 'fa-file-pdf',
            'doc'  => 'fa-file-word',
            'docx' => 'fa-file-word',
            'jpg'  => 'fa-file-image',
            'jpeg' => 'fa-file-image',
            'png'  => 'fa-file-image',
            'txt'  => 'fa-file-alt',
            // Add more file types as needed
        ];
        return isset($icons[$extension]) ? 'fas ' . $icons[$extension] : 'fas fa-file'; // Default icon if type not recognized
    }

    private function generate_breadcrumbs($path) {
        $root_path = realpath(get_option('directory_mapper_root_directory'));
        $base_directory = str_replace($root_path, '', realpath($path));
        $breadcrumbs = '<div class="breadcrumbs">';
        $directories = explode(DIRECTORY_SEPARATOR, trim($base_directory, DIRECTORY_SEPARATOR));
        $current_path = $root_path;

        $breadcrumbs .= '<a href="' . esc_url(get_permalink()) . '">' . __('Home', 'directory-page-mapper') . '</a>';

        foreach ($directories as $directory) {
            $current_path .= DIRECTORY_SEPARATOR . $directory;
            $title = $this->titleize_directory($directory);
            $page_link = $this->get_page_link_by_path($title, $current_path);
            if ($page_link) {
                $breadcrumbs .= ' / <a href="' . esc_url($page_link) . '">' . esc_html($title) . '</a>';
            } else {
                $breadcrumbs .= ' / ' . esc_html($title);
            }
        }

        $breadcrumbs .= '</div>';
        return $breadcrumbs;
    }

    private function get_page_link_by_path($title, $path = '') {
        // Get the page ID by directory name and path
        $args = [
            'post_type'   => 'page',
            'post_status' => 'publish',
            'title'       => $title,
            'numberposts' => 1,
        ];
        $pages = get_posts($args);
        return $pages ? get_permalink($pages[0]->ID) : false;
    }

    // Utility function to titleize and format keys and values
    private function titleize_keys($key) {
        // Convert camelCase to words
        $key = preg_replace('/([a-z])([A-Z])/', '$1 $2', $key);
        return ucwords(str_replace('_', ' ', $key));
    }

    // Utility function to titleize and format filename
    private function titleize_filename($filename) {
        $name_without_ext = pathinfo($filename, PATHINFO_FILENAME);
        $titleized = str_replace(['_', '-'], ' ', $name_without_ext);
        return ucwords($titleized);
    }

    private function titleize_directory($directory) {
        return ucwords(str_replace(['_', '-'], ' ', $directory));
    }

}

new DirectoryPageManager();
