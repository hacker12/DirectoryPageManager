<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

?>
<div class="wrap">
    <h1>DirectoryPageMapper Settings</h1>
    <form method="post" action="">
        <?php
        settings_fields('directory_mapper_options_group');
        do_settings_sections('directory_mapper_options_group');
        wp_nonce_field('update_directory_mapper_settings');
        ?>
        <table class="form-table">
            <tr valign="top">
                <th scope="row">Root Directory Path</th>
                <td><input type="text" name="directory_mapper_root_directory" value="<?php echo esc_attr(get_option('directory_mapper_root_directory')); ?>" size="50"></td>
            </tr>
            <tr valign="top">
                <th scope="row">Skip Directories Without Documents</th>
                <td><input type="checkbox" name="directory_mapper_skip_empty" <?php checked(get_option('directory_mapper_skip_empty'), '1'); ?> value="1"></td>
            </tr>
            <tr valign="top">
                <th scope="row">Disable Breadcrumbs</th>
                <td><input type="checkbox" name="directory_mapper_disable_breadcrumbs" <?php checked(get_option('directory_mapper_disable_breadcrumbs')); ?> value="1"></td>
            </tr>
            <tr valign="top">
                <th scope="row">Font Awesome URL</th>
                <td><input type="text" name="directory_mapper_font_awesome_url" value="<?php echo esc_attr(get_option('directory_mapper_font_awesome_url')); ?>" size="90"></td>
            </tr>
            <tr valign="top">
                <th scope="row">Custom Folder Icons (JSON format)</th>
                <td>
                    <textarea name="directory_mapper_custom_folder_icons" rows="5" cols="50"><?php echo stripcslashes(get_option('directory_mapper_custom_folder_icons')); ?></textarea>
                    <p class="description">
                    Define custom folder icons in JSON format. Define the name of the directory and associate the class to be used.</p>
                    <p>Example: <pre>
{
"Archive":"fas fa-box-archive", 
"Export":"fas fa-up-right-from-square", 
"Import":"fas fa-file-import"
}</pre>
            </p>
                </td>
            </tr>
        </table>
        <?php   if (!current_user_can('manage_options')) {?>
            <input type="submit" name="submit" class="button-primary" value="Save Changes and Regenerate Pages" disabled="disabled" />
            <?php } else { ?>
            <input type="submit" name="submit" class="button-primary" value="Save Changes and Regenerate Pages" />
            <?php } ?>
        <!-- Additional button for regenerating documentation only -->
        <input type="submit" name="regenerate" class="button-secondary" value="Regenerate Pages Only" />
        <!-- Addditional button for deleting documentation pages -->
        <input type="submit" name="delete" class="button-link" value="Delete Pages" />
    </form>
</div>
<?php