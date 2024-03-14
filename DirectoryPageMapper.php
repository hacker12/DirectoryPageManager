<?php
/*
Plugin Name: Directory Page Mapper
Description: Manage documentation organized in directories and create hierarchical WordPress pages accordingly.
Version: 2.1
Author: Morgan ATTIAS
Text Domain: directory-page-mapper
*/

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
            'Directory Pape Mapper',
            'Directory Mapper',
            'read', // This means any user can access it (change to manage_options if you want to restrict it to admins only)
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
        // Check if the 'Save Changes and Regenerate Documentation' button was submitted
        if (isset($_POST['submit']) && check_admin_referer('update_directory_mapper_settings')) {

            // Only allow admin to save settings
            if (!current_user_can('manage_options')) {
                wp_die('You are not allowed to manage these options.');
            }

            update_option('directory_mapper_root_directory', sanitize_text_field($_POST['directory_mapper_root_directory']));
            update_option('directory_mapper_skip_empty', isset($_POST['directory_mapper_skip_empty']) ? '1' : '0');
            update_option('directory_mapper_font_awesome_url', sanitize_text_field($_POST['directory_mapper_font_awesome_url']));
            update_option('directory_mapper_disable_breadcrumbs', isset($_POST['directory_mapper_disable_breadcrumbs']) ? '1' : '0');

            // check if the JSON is valid and if it is then save it
            $jsonString = stripslashes($_POST['directory_mapper_custom_folder_icons']);

            if (json_decode($jsonString) !== null) {
                update_option('directory_mapper_custom_folder_icons', $_POST['directory_mapper_custom_folder_icons']);
            } elseif ($jsonString === '') {
                update_option('directory_mapper_custom_folder_icons', '{}');
            } else {
                echo '<div class="error"><p>Custom Folder Icons is invalid and was ignored.</p></div>';
                error_log('JSON is invalid');
            }

            // check if the JSON is valid and if it is then save it
            $jsonString = stripslashes($_POST['directory_mapper_exclusion']);

            if (json_decode($jsonString) !== null) {
                update_option('directory_mapper_exclusion', $_POST['directory_mapper_exclusion']);
            } elseif ($jsonString === '') {
                update_option('directory_mapper_exclusion', '{}');
            } else {
                echo '<div class="error"><p>Exclusion list is invalid and was ignored.</p></div>';
                error_log('JSON is invalid');
            }

            // Trigger documentation regeneration
            $this->createDocumentationPages();
            echo '<div class="updated"><p>Pages updated and settings saved.</p></div>';
        }

        // Check if the 'Regenerate Documentation Only' button was submitted
        if (isset($_POST['regenerate']) && check_admin_referer('update_directory_mapper_settings')) {

            // This basically allows an "Author" to regenerate the pages (change this if you want to restrict it to a different role)
            if (!((current_user_can('publish_posts')))) {
                wp_die('You do not have sufficient permissions to regenerate pages.');
            }

            // Trigger documentation regeneration without updating settings
            $this->createDocumentationPages();
            echo '<div class="updated"><p>Pages regenerated.</p></div>';
        }

        // Check if the 'Delete Pages' button was submitted
        if (isset($_POST['delete']) && check_admin_referer('update_directory_mapper_settings')) {
            // This basically allows an "Editor" to delete the pages (change this if you want to restrict it to a different role)
            if (!((current_user_can('delete_others_pages') || current_user_can('delete_pages')))) {
                wp_die('You do not have sufficient permissions to delete pages.');
            }
            // Trigger documentation regeneration without updating settings
            $this->deleteDocumentationPages();
            echo '<div class="updated"><p>Pages deleted.</p></div>';
        }

       include plugin_dir_path(__FILE__) . 'admin/settings-page.php';
    }

    // Get the data from the exclusion list
    public function getExclusions() {
        $exclusions = json_decode(stripslashes(get_option('directory_mapper_exclusion')), true);
        $exclusions['directories'] = is_array($exclusions['directories']) ? $exclusions['directories'] : [];
        $exclusions['files'] = is_array($exclusions['files']) ? $exclusions['files'] : [];
        $exclusions['regex'] = is_array($exclusions['regex']) ? $exclusions['regex'] : [];
        return $exclusions;
    }


    public function createDocumentationPages() {
        $root_directory = get_option('directory_mapper_root_directory');
        if (empty($root_directory) || !is_dir($root_directory)) {
            error_log('Invalid root directory path for pages: ' . $root_directory);
            return;
        }
        $skip_empty = get_option('directory_mapper_skip_empty') === '1';
        $parent_id = 0; // Top level pages have no parent
        $this->create_pages_recursive($root_directory, $parent_id, $skip_empty);
    }

    // Recursive function to create pages
    private function create_pages_recursive($dir_path, $parent_id, $skip_empty) {
        $directories = array_filter(glob($dir_path . '/*'), 'is_dir');
        foreach ($directories as $directory) {
            $dir_name = basename($directory);
            $page_title = trim(str_replace('/', ' ', str_replace($dir_path, '', $directory)));

            // Check if page already exists
            $existing_page_id = $this->get_page_by_directory_name($page_title, $parent_id);

            // Get directory exclusion lists
            $exclusions = $this->getexclusions();
            $exclude_dir = isset($exclusions['directories']) ? in_array($dir_name, $exclusions['directories']) : false;
            // check if the regex exclusion matches the directory name
            if (isset($exclusions['regex'])) {
                foreach ($exclusions['regex'] as $regex) {
                    if (preg_match($regex, $dir_name)) {
                        $exclude_dir = true;
                        break;
                    }
                }
            }

            // Delete Page if directory is empty and setting is enabled
            if (($skip_empty && !count(glob("$directory/*")) || $exclude_dir) && $existing_page_id !== 0)
            {
                wp_delete_post($existing_page_id, true);
                continue;
            }
            // Skip directory if it's empty and setting is enabled
            if ($skip_empty && count(glob("$directory/*")) === 0) {
                continue;
            }

            // Skip directory if it's in the exclusion list
            if ($exclude_dir) {
                continue;
            }

            // Create new page if not exists
            if (!$existing_page_id) {
                // Create new page if not exists
                $page_id = wp_insert_post([
                    'post_title'   => $page_title,
                    'post_content' => '[directory_listing path="' . $directory . '"]', // Shortcode to display directory contents
                    'post_type'    => 'page',
                    'post_status'  => 'publish',
                    'post_parent'  => $parent_id,
                    'meta_input' => ['directory_path' => $dir_path],
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

    // function to delete the pages created by the plugin
    public function deleteDocumentationPages() {
        $root_directory = get_option('directory_mapper_root_directory');
        if (empty($root_directory) || !is_dir($root_directory)) {
            error_log('Invalid root directory path for pages: ' . $root_directory);
            return;
        }
        $this->delete_pages_recursive($root_directory);
    }

    private function delete_pages_recursive($dir_path) {
        $directories = array_filter(glob($dir_path . '/*'), 'is_dir');
        foreach ($directories as $directory) {
            $dir_name = basename($directory);
            $page_title = trim(str_replace('/', ' ', str_replace($dir_path, '', $directory)));

            // Check if page already exists
            $existing_page_id = $this->get_page_by_directory_name($page_title);

            // Delete Page if directory is empty and setting is enabled
            if ($existing_page_id !== 0)
            {
                wp_delete_post($existing_page_id, true);
            }

            // Recursive call to create pages for subdirectories
            $this->delete_pages_recursive($directory);
        }
    }

    // Function to check if a page with a given title and parent ID already exists
    private function get_page_by_directory_name($page_title, $parent_id="") {
        $args = [
            'post_type'   => 'page',
            'post_status' => 'publish',
            'title'       => $page_title,
            'numberposts' => 1,
        ];

        if ($parent_id != "") {
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
        $this->enqueueScripts(); // Make sure Font Awesome is enqueued

        $custom_icons_json = stripslashes(get_option('directory_mapper_custom_folder_icons', '{}')); // Default to empty JSON object
        $custom_icons = json_decode($custom_icons_json, true); // Decode the JSON into an associative array

        $output = '<div class="directory-listing">';
        $atts = shortcode_atts(['path' => ''], $atts);
        $path = $atts['path'];

        // If breadcrumbs are not disabled => Generate breadcrumb links
        if (get_option('directory_mapper_disable_breadcrumbs') !== '1') {
            $output .= $this->generate_breadcrumbs($path);
        }

        if (file_exists($path) && is_dir($path)) {
            $entries = array_diff(scandir($path), ['.', '..']);

            // Get directory exclusion lists
            $exclusions = $this->getexclusions();
            $exclude_dir = isset($exclusions['directories']) ? in_array($dir_name, $exclusions['directories']) : false;
            // check if the regex exclusion matches the directory name
            if (isset($exclusions['regex'])) {
                foreach ($exclusions['regex'] as $regex) {
                    if (preg_match('/'.$regex.'/', $dir_name)) {
                        $exclude_dir = true;
                        break;
                    }
                }
            }
            // Skip directory if it's in the exclusion list
            if ($exclude_dir) {
                return;
            }

            $output .= "<div class='file-entries'>";
            foreach ($entries as $entry) {
                $entry_path = $path . '/' . $entry;
                // skip the entry if it's in the exclusion list
                if (in_array($entry, $exclusions['files'])) continue;

                // skip the entry if it matches the regex exclusion
                if (isset($exclusions['regex'])) {
                    foreach ($exclusions['regex'] as $regex) {
                        if(!isset($entry)) {
                            continue;
                        }
                        if (preg_match('/'.$regex.'/', $entry)) {
                            unset($entry);
                            continue;
                        }
                    }
                }

                if(!isset($entry)) { continue; }
                $is_dir = is_dir($entry_path);
                $title = $this->titleize_filename($entry);
                $file_ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                if ($is_dir) {
                    $title = $entry;
                    $icon_class = isset($custom_icons[$title]) ? $custom_icons[$title] : 'fas fa-folder';
                }
                else {
                    $icon_class = $this->get_file_icon_class($file_ext);
                }

                if ($is_dir) {
                    // Continue to the next entry if the directory is in the exclusion list
                    if (in_array($entry, $exclusions['directories'])) continue;
                    // Continue to the next entry if the directory matches the regex exclusion
                    if (isset($exclusions['regex'])) {
                        foreach ($exclusions['regex'] as $regex) {
                            if (preg_match($regex, $entry)) {
                                continue;
                            }
                        }
                    }
                    // Link to the page representing the directory
                    $title = $entry;
                    $page_link = $this->get_page_link_by_path($title);
                    // if skip empty is enabled and page_link is false then skip the directory
                    if (get_option('directory_mapper_skip_empty') === '1' && !$page_link) {
                        continue;
                    }
                    $output .= '<div class="file-entry">';
                    $output .= '<div class="directory-icon"><i class="' . esc_attr($icon_class) . ' fa-fw"></i></div>';
                    $output .= $page_link ? '<a href="' . esc_url($page_link) . '">' . esc_html($entry) . '</a>' : esc_html($title);
                    $output .= '</div>';
                } else {
                    // Link to the document
                    $output .= '<div class="file-entry">';
                    $output .= '<div class="file-icon"><i class="' . esc_attr($icon_class) . ' fa-fw"></i></div>';
                    $file_url = str_replace(WP_CONTENT_DIR, WP_CONTENT_URL, $entry_path);
                    $output .= '<a href="' . esc_url($file_url) . '" class="file-download">' . esc_html($title) . '</a>';
                    $output .= '<div class="file-name">' . esc_html($entry) . " ";
                    $filesize = $is_dir ? '' : $this->format_filesize(filesize($entry_path)); // Get filesize if it's a file
                    $output .= '<span class="file-size">(' . esc_html($filesize) . ')</span>'; // Add filesize to output
                    $output .= '</div>';
                    foreach ($this->getPDFInfo($entry_path) as $key => $value) {
                        // if the $value is an ISO date or ISO datetime string then use the broswer locale to format it
                        $value = (bool)strtotime($value) ? date_i18n(get_option('date_format'), strtotime($value)) : $value;
                        $output .= ($value != '')? '<div class="pdf-info">' . $this->titleize_keys($key) . ':<b> ' . $value . '</b></div>' : '';
                    }
                    #$output .= '<div>'.print_r(getPDFInfo($entry_path), true).'</div>';
                    $output .= '</div>';
                }
            }
        }
        $output .= '</div><br stylr="clear:all;" />';
        return $output;
    }


    // Utilties for PDF metadata extraction
    private function getPDFInfo($filename) {
        $pdfInfo = [
            'title' => '',
            'author' => '',
            'description' => '',
            'pages' => '',
            'creationDate' => ''
        ];

        $string = file_get_contents($filename);

        $start = strpos($string, "<dc:title>") + 10;
        $length = strpos(substr($string, $start), '</dc:title>');

        if ($length) {
            $pdfInfo['title'] = strip_tags(substr($string, $start, $length));
            $pdfInfo['title'] = $this->pdfDecTxt($pdfInfo['title']);
        }

        $start = strpos($string, "<dc:creator>") + 12;
        $length = strpos(substr($string, $start), '</dc:creator>');

        if ($length) {
            $pdfInfo['author'] = strip_tags(substr($string, $start, $length));
            $pdfInfo['author'] = $this->pdfDecTxt($pdfInfo['author']);
        }

        $start = strpos($string, "<dc:description>") + 16;
        $length = strpos(substr($string, $start), '</dc:description>');

        if ($length) {
            $pdfInfo['description'] = strip_tags(substr($string, $start, $length));
            $pdfInfo['description'] = $this->pdfDecTxt($pdfInfo['description']);
        }

        if (preg_match("/\/N\s+([0-9]+)/", $string, $found)) {
            $pdfInfo['pages'] = $found[1]; 
        } else {
            $pos = strpos($string, '/Type /Pages ');
            if ($pos !== false) {
                $pos2 = strpos($string, '>>', $pos);
                $string = substr($string, $pos, $pos2 - $pos);
                $pos = strpos($string, '/Count ');
                $pdfInfo['pages'] = (int) substr($string, $pos+7);
            }
        }
        
        $pdfInfo['creationDate'] = $this->getCreationDate($filename);

        return $pdfInfo;
    }

    private function pdfDecTxt($txt) {
        $len = strlen($txt);
        $out = '';
        $i = 0;
        while ($i<$len) {
            if ($txt[$i] == '\\') {
                $out .= chr(octdec(substr($txt, $i+1, 3)));
                $i += 4;            
            } else {
                $out .= $txt[$i];
                $i++;
            }
        }

        if ($out[0] == chr(254)) {
            $enc = 'UTF-16';
        } else {
            $enc = mb_detect_encoding($out);
        }
        return iconv($enc, 'UTF-8', $out);
    }

    public static function getCreationDate($filename) {
        $fileInfo = stat($filename);
        $creationDate = date('Y-m-d H:i:s', $fileInfo['ctime']);
        return $creationDate;
    }

    // Utility function to format filesize
    public static function format_filesize($bytes, $decimals = 2) {
        $size = ['B', 'kB', 'MB', 'GB', 'TB'];
        $factor = floor((strlen($bytes) - 1) / 3);
        return sprintf("%.{$decimals}f", $bytes / (1024 ** $factor)) . @$size[$factor];
    }

    // Utility function to get Font Awesome icon class based on file extension
    private function get_file_icon_class($extension) {
        // Define Font Awesome icon classes for different file types
        $icons = [
            'pdf' => 'fa-file-pdf',
            'doc' => 'fa-file-word',
            'docx' => 'fa-file-word',
            'jpg' => 'fa-file-image',
            'jpeg' => 'fa-file-image',
            'png' => 'fa-file-image',
            'txt' => 'fa-file-text',
            // Add more file types as needed
        ];
        return isset($icons[$extension]) ? 'fas ' . $icons[$extension] : 'fas fa-file'; // Default icon if type not recognized
    }

    private function generate_breadcrumbs($path) {
        $root_path = get_option('directory_mapper_root_directory');
        $base_directory = str_replace($root_path, '', $path);
        $breadcrumbs = '<div class="breadcrumbs">';
        $directories = explode('/', trim($base_directory, '/'));
        $current_path = $root_path;

        foreach ($directories as $directory) {
            $current_path .= $directory.'/';
            $title = $this->titleize_directory($directory);
            #$breadcrumbs .= get_page_link_by_path($title);
            $breadcrumbs .= ' / <a href="' . $this->get_page_link_by_path($title) . '">' . $title . '</a>';
            $breadcrumbs .= "<script>console.log('$root_path, $current_path, $title')</script>";
        }

        $breadcrumbs .= '</div>';
        return $breadcrumbs;
    }

    private function get_page_link_by_path($title) {
        // Get the page ID by directory path
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
        // check if $key has camelCase, if it does split the words with space
        if (preg_match('/[a-z][A-Z]/', $key)) {
            return ucwords(preg_replace('/([a-z])([A-Z])/', '$1 $2', $key));
        }
        return ucwords(str_replace('_', ' ', $key));
    }


    // Utility function to titleize and format filename
    private function titleize_filename($filename) {
        // check if the $filename is actually a directory
        if (is_dir($filename)) {
            return titleize_directory($filename);
        }
        $name_without_ext = pathinfo($filename, PATHINFO_FILENAME);
        $titleized = str_replace('_', ' ', $name_without_ext);
        return ucwords($titleized);
    }

    private function titleize_directory($directory) {
        return ucwords(str_replace('_', ' ', $directory));
    }

}

new DirectoryPageManager();
