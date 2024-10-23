<?php
/*
Plugin Name: Bible Linker
Description: Automatically finds and hyperlinks Bible references in your posts when published.
Version: 1.0.0
Author: Strong Anchor Tech
*/

if (!defined('ABSPATH')) exit; // Exit if accessed directly

// Add settings menu
add_action('admin_menu', 'ssbl_add_admin_menu');
add_action('admin_init', 'ssbl_settings_init');

function ssbl_add_admin_menu() {
    add_options_page(
        'Server-Side Bible Linker Settings',
        'Bible Linker',
        'manage_options',
        'server_side_bible_linker',
        'ssbl_options_page'
    );
}

function ssbl_settings_init() {
    register_setting('ssblSettings', 'ssbl_settings');

    add_settings_section(
        'ssbl_ssblSettings_section',
        __('Settings', 'wordpress'),
        'ssbl_settings_section_callback',
        'ssblSettings'
    );

    add_settings_field(
        'ssbl_bible_version',
        __('Default Bible Version', 'wordpress'),
        'ssbl_bible_version_render',
        'ssblSettings',
        'ssbl_ssblSettings_section'
    );

    add_settings_field(
        'ssbl_bible_site',
        __('Bible Site', 'wordpress'),
        'ssbl_bible_site_render',
        'ssblSettings',
        'ssbl_ssblSettings_section'
    );
}

function ssbl_bible_version_render() {
    $options = get_option('ssbl_settings');
    ?>
    <input type='text' name='ssbl_settings[ssbl_bible_version]' value='<?php echo esc_attr($options['ssbl_bible_version'] ?? 'NIV'); ?>'>
    <p class="description">Enter the default Bible version (e.g., NIV, ESV).</p>
    <?php
}

function ssbl_bible_site_render() {
    $options = get_option('ssbl_settings');
    ?>
    <select name='ssbl_settings[ssbl_bible_site]'>
        <option value='biblegateway' <?php selected($options['ssbl_bible_site'] ?? '', 'biblegateway'); ?>>BibleGateway.com</option>
        <option value='biblia' <?php selected($options['ssbl_bible_site'] ?? '', 'biblia'); ?>>Biblia.com</option>
        <!-- Add more options as needed -->
    </select>
    <p class="description">Select the Bible site to link to.</p>
    <?php
}

function ssbl_settings_section_callback() {
    echo __('Configure the default settings for the Bible Linker.', 'wordpress');
}

function ssbl_options_page() {
    ?>
    <form action='options.php' method='post'>
        <h2>Server-Side Bible Linker Settings</h2>
        <?php
        settings_fields('ssblSettings');
        do_settings_sections('ssblSettings');
        submit_button();
        ?>
    </form>
    <?php
}

// Function to construct the URL based on the selected Bible site and version
function ssbl_construct_url($reference, $version, $site) {
    switch ($site) {
        case 'biblegateway':
            $url = 'https://www.biblegateway.com/passage/?search=' . urlencode($reference) . '&version=' . urlencode($version);
            break;
        case 'biblia':
            // For biblia.com, format reference by replacing spaces and colons
            $formatted_reference = str_replace([' ', ':'], ['', '.'], $reference);
            $url = 'https://biblia.com/bible/' . urlencode($version) . '/' . urlencode($formatted_reference);
            break;
        // Add more cases for different sites if needed
        default:
            $url = '#';
            break;
    }
    return $url;
}

// Function to parse references in text and replace them with hyperlinks
function ssbl_parse_references($content) {
    // Define the regex pattern for matching Bible references
    $book_regex = '\b(?:Genesis|Gen|Exodus|Exod|Ex|Leviticus|Lev|Numbers|Num|Deuteronomy|Deut|Deu|Joshua|Josh|Judges|Judg|Ruth|Ruth|1 Samuel|1 Sam|2 Samuel|2 Sam|1 Kings|1 Kgs|2 Kings|2 Kgs|1 Chronicles|1 Chron|2 Chronicles|2 Chron|Ezra|Ezra|Nehemiah|Neh|Esther|Esth|Job|Job|Psalms|Ps|Proverbs|Prov|Ecclesiastes|Eccl|Song of Solomon|Song|Isaiah|Isa|Jeremiah|Jer|Lamentations|Lam|Ezekiel|Ezek|Daniel|Dan|Hosea|Hos|Joel|Joel|Amos|Amos|Obadiah|Obad|Jonah|Jonah|Micah|Mic|Nahum|Nah|Habakkuk|Hab|Zephaniah|Zeph|Haggai|Hag|Zechariah|Zech|Malachi|Mal|Matthew|Matt|Mark|Mark|Luke|Luke|John|John|Acts|Acts|Romans|Rom|1 Corinthians|1 Cor|2 Corinthians|2 Cor|Galatians|Gal|Ephesians|Eph|Philippians|Phil|Colossians|Col|1 Thessalonians|1 Thess|2 Thessalonians|2 Thess|1 Timothy|1 Tim|2 Timothy|2 Tim|Titus|Titus|Philemon|Philem|Hebrews|Heb|James|James|1 Peter|1 Pet|2 Peter|2 Pet|1 John|1 John|2 John|2 John|3 John|3 John|Jude|Jude|Revelation|Rev)\b';

    // Regex to match references, e.g., John 3:16, Jn 3:16-17, etc.
    $regex = '/
        (?:(1|2|3)\s*)?                 # Optional prefix for 1, 2, 3 John, etc.
        (' . $book_regex . ')           # Book name
        \s*                             # Optional whitespace
        (\d{1,3})                       # Chapter
        (?::(\d{1,3}(?:-\d{1,3})?))?    # Optional :verse or :verse-verse
    /ix';

    // Use callback to replace matches
    return preg_replace_callback($regex, 'ssbl_replace_reference', $content);
}

function ssbl_replace_reference($matches) {
    $book_number = isset($matches[1]) ? $matches[1] . ' ' : '';
    $book = $matches[2];
    $chapter = $matches[3];
    $verse = isset($matches[4]) ? ':' . $matches[4] : '';

    $reference = $book_number . $book . ' ' . $chapter . $verse;

    // Get options
    $options = get_option('ssbl_settings');
    $bible_version = $options['ssbl_bible_version'] ?? 'NIV';
    $bible_site = $options['ssbl_bible_site'] ?? 'biblegateway';

    // Construct URL based on selected Bible site
    $url = ssbl_construct_url($reference, $bible_version, $bible_site);

    return '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($reference) . '</a>';
}

// Modify post content on save
add_action('save_post', 'ssbl_save_post', 10, 3);

function ssbl_save_post($post_ID, $post, $update) {
    // Only process for 'post' and 'page' post types
    if (!in_array($post->post_type, ['post', 'page']) || wp_is_post_revision($post_ID)) {
        return;
    }

    // Check user capabilities
    if (!current_user_can('edit_post', $post_ID)) {
        return;
    }

    // Get the post content
    $content = $post->post_content;

    // Parse and replace Bible references
    $modified_content = ssbl_parse_references($content);

    // Update the post content if modified
    if ($content !== $modified_content) {
        // Remove the action to prevent infinite loop
        remove_action('save_post', 'ssbl_save_post', 10, 3);

        // Update the post
        wp_update_post(array(
            'ID' => $post_ID,
            'post_content' => $modified_content,
        ));

        // Re-add the action
        add_action('save_post', 'ssbl_save_post', 10, 3);
    }
}

?>
