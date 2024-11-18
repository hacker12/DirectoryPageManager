<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

?>
<div class="wrap">
    <h1><?php _e('Directory Page Mapper Settings', 'directory-page-mapper'); ?></h1>
    <form method="post" action="">
        <?php
        settings_fields('directory_mapper_options_group');
        do_settings_sections('directory_mapper_options_group');
        wp_nonce_field('update_directory_mapper_settings');
        ?>
        <table class="form-table">
            <tr valign="top">
                <th scope="row"><?php _e('Root Directory Path', 'directory-page-mapper'); ?></th>
                <td><input type="text" name="directory_mapper_root_directory" value="<?php echo esc_attr(get_option('directory_mapper_root_directory')); ?>" size="50"></td>
            </tr>
            <tr valign="top">
                <th scope="row"><?php _e('Skip Directories Without Documents', 'directory-page-mapper'); ?></th>
                <td><input type="checkbox" name="directory_mapper_skip_empty" <?php checked(get_option('directory_mapper_skip_empty'), '1'); ?> value="1"></td>
            </tr>
            <tr valign="top">
                <th scope="row"><?php _e('Disable Breadcrumbs', 'directory-page-mapper'); ?></th>
                <td><input type="checkbox" name="directory_mapper_disable_breadcrumbs" <?php checked(get_option('directory_mapper_disable_breadcrumbs'), '1'); ?> value="1"></td>
            </tr>
            <tr valign="top">
                <th scope="row"><?php _e('Font Awesome URL', 'directory-page-mapper'); ?></th>
                <td><input type="text" name="directory_mapper_font_awesome_url" value="<?php echo esc_attr(get_option('directory_mapper_font_awesome_url')); ?>" size="90"></td>
            </tr>
            <tr valign="top">
                <th scope="row"><?php _e('Custom Folder Icons (JSON format)', 'directory-page-mapper'); ?></th>
                <td>
                    <textarea name="directory_mapper_custom_folder_icons" rows="5" cols="50"><?php echo esc_textarea(get_option('directory_mapper_custom_folder_icons')); ?></textarea>
                    <p class="description">
                        <?php _e('Define custom folder icons in JSON format. Define the name of the directory and associate the class to be used.', 'directory-page-mapper'); ?></p>
                    <p><?php _e('Example:', 'directory-page-mapper'); ?> <pre>
{
    "Archive": "fas fa-box-archive",
    "Export": "fas fa-up-right-from-square",
    "Import": "fas fa-file-import"
}</pre>
                    </p>
                </td>
            </tr>
            <tr valign="top">
                <th scope="row"><?php _e('Exclusion List (JSON format)', 'directory-page-mapper'); ?></th>
                <td>
                    <textarea name="directory_mapper_exclusion" rows="5" cols="50"><?php echo esc_textarea(get_option('directory_mapper_exclusion')); ?></textarea>
                    <p class="description">
                        <?php _e('Define exclusion list in JSON format. Define the name of the directory, file, or regex to be excluded from the generation.', 'directory-page-mapper'); ?></p>
                    <p><?php _e('Example:', 'directory-page-mapper'); ?> <pre>
{
    "directories": ["hidden"],
    "files": ["thumbs.db", "desktop.ini"],
    "regex": ["/.*\\.json$/", "/.*\\.php$/"]
}</pre>
                    </p>
                </td>
            </tr>
        </table>
        <?php if (!current_user_can('manage_options')) { ?>
            <input type="submit" name="submit" class="button-primary" value="<?php esc_attr_e('Save Changes and Regenerate Pages', 'directory-page-mapper'); ?>" disabled="disabled" />
        <?php } else { ?>
            <input type="submit" name="submit" class="button-primary" value="<?php esc_attr_e('Save Changes and Regenerate Pages', 'directory-page-mapper'); ?>" />
        <?php } ?>
        <!-- Additional button for regenerating documentation only -->
        <input type="submit" name="regenerate" class="button-secondary" value="<?php esc_attr_e('Regenerate Pages Only', 'directory-page-mapper'); ?>" />
        <!-- Additional button for deleting documentation pages -->
        <input type="submit" name="delete" class="button-link-delete" value="<?php esc_attr_e('Delete Pages', 'directory-page-mapper'); ?>" onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete the generated pages?', 'directory-page-mapper'); ?>');" />
    </form>
</div>
