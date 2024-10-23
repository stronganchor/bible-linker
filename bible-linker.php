<?php
/*
Plugin Name: Bible Linker
Description: Automatically finds and hyperlinks Bible references in your posts when published.
Version: 1.0.2
Author: Strong Anchor Tech
*/

if (!defined('ABSPATH')) exit; // Exit if accessed directly

// Add settings menu
add_action('admin_menu', 'bible_linker_add_admin_menu');
add_action('admin_init', 'bible_linker_settings_init');

function bible_linker_add_admin_menu() {
    add_options_page(
        'Bible Linker Settings',
        'Bible Linker',
        'manage_options',
        'bible_linker',
        'bible_linker_options_page'
    );
}

function bible_linker_settings_init() {
    register_setting('bible_linkerSettings', 'bible_linker_settings');

    add_settings_section(
        'bible_linker_bible_linkerSettings_section',
        __('Settings', 'wordpress'),
        'bible_linker_settings_section_callback',
        'bible_linkerSettings'
    );

    add_settings_field(
        'bible_linker_bible_version',
        __('Default Bible Version', 'wordpress'),
        'bible_linker_bible_version_render',
        'bible_linkerSettings',
        'bible_linker_bible_linkerSettings_section'
    );

    add_settings_field(
        'bible_linker_bible_site',
        __('Bible Site', 'wordpress'),
        'bible_linker_bible_site_render',
        'bible_linkerSettings',
        'bible_linker_bible_linkerSettings_section'
    );
}

function bible_linker_bible_version_render() {
    $options = get_option('bible_linker_settings');
    ?>
    <input type='text' name='bible_linker_settings[bible_linker_bible_version]' value='<?php echo esc_attr($options['bible_linker_bible_version'] ?? 'NIV'); ?>'>
    <p class="description">Enter the default Bible version (e.g., NIV, ESV).</p>
    <?php
}

function bible_linker_bible_site_render() {
    $options = get_option('bible_linker_settings');
    ?>
    <select name='bible_linker_settings[bible_linker_bible_site]'>
        <option value='biblegateway' <?php selected($options['bible_linker_bible_site'] ?? '', 'biblegateway'); ?>>BibleGateway.com</option>
        <option value='biblia' <?php selected($options['bible_linker_bible_site'] ?? '', 'biblia'); ?>>Biblia.com</option>
        <!-- Add more options as needed -->
    </select>
    <p class="description">Select the Bible site to link to.</p>
    <?php
}

function bible_linker_settings_section_callback() {
    echo __('Configure the default settings for the Bible Linker.', 'wordpress');
}

function bible_linker_options_page() {
    ?>
    <form action='options.php' method='post'>
        <h2>Bible Linker Settings</h2>
        <?php
        settings_fields('bible_linkerSettings');
        do_settings_sections('bible_linkerSettings');
        submit_button();
        ?>
    </form>
    <?php
}

// Function to construct the URL based on the selected Bible site and version
function bible_linker_construct_url($reference, $version, $site) {
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
function bible_linker_parse_references($content) {
    // Use DOMDocument to parse and modify the content
    $dom = new DOMDocument;
    libxml_use_internal_errors(true); // Suppress errors due to malformed HTML

    // Ensure UTF-8 encoding
    $content = mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8');

    $dom->loadHTML('<div>' . $content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    // Define the tags we want to exclude (a, h1, h2, h3, h4, h5, h6)
    $excluded_tags = ['a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    // Build XPath query to select text nodes not within the excluded tags
    $excluded_tags_query = implode(' and not(ancestor::', array_map(function($tag) {
        return $tag . ')';
    }, $excluded_tags));
    $query = '//text()[not(ancestor::' . $excluded_tags_query . ')]';

    // Process text nodes that are not within the excluded tags
    foreach ($xpath->query($query) as $textNode) {
        $parentNode = $textNode->parentNode;
        $newHTML = bible_linker_replace_references_in_text($textNode->nodeValue);
        if ($newHTML !== $textNode->nodeValue) {
            // Create a new fragment and replace the text node
            $fragment = $dom->createDocumentFragment();
            // Use innerHTML to preserve encoding and avoid empty nodes
            $tmpDom = new DOMDocument();
            $tmpDom->loadHTML('<?xml encoding="UTF-8">' . $newHTML, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            foreach ($tmpDom->getElementsByTagName('body')->item(0)->childNodes as $child) {
                $importedNode = $dom->importNode($child, true);
                $fragment->appendChild($importedNode);
            }
            $parentNode->replaceChild($fragment, $textNode);
        }
    }

    // Remove the wrapping div added during loadHTML
    $content = '';
    foreach ($dom->documentElement->childNodes as $child) {
        $content .= $dom->saveHTML($child);
    }

    return $content;
}

function bible_linker_replace_references_in_text($text) {
    // Define the regex pattern for matching Bible references
    $book_regex = '\b(?:'
        . 'Genesis|Gen|Exodus|Exod|Ex|Leviticus|Lev|Numbers|Num|Deuteronomy|Deut|Deu|'
        . 'Joshua|Josh|Judges|Judg|Ruth|Ruth|'
        . '1 Samuel|1 Sam|2 Samuel|2 Sam|'
        . '1 Kings|1 Kgs|2 Kings|2 Kgs|'
        . '1 Chronicles|1 Chron|2 Chronicles|2 Chron|'
        . 'Ezra|Ezra|Nehemiah|Neh|Esther|Esth|Job|Job|Psalms|Ps|Psalm|Proverbs|Prov|'
        . 'Ecclesiastes|Eccl|Song of Solomon|Song|'
        . 'Isaiah|Isa|Jeremiah|Jer|Lamentations|Lam|Ezekiel|Ezek|Daniel|Dan|'
        . 'Hosea|Hos|Joel|Joel|Amos|Amos|Obadiah|Obad|Jonah|Jonah|Micah|Mic|'
        . 'Nahum|Nah|Habakkuk|Hab|Zephaniah|Zeph|Haggai|Hag|Zechariah|Zech|Malachi|Mal|'
        . 'Matthew|Matt|Mark|Mark|Luke|Luke|John|John|Acts|Acts|Romans|Rom|'
        . '1 Corinthians|1 Cor|2 Corinthians|2 Cor|'
        . 'Galatians|Gal|Ephesians|Eph|Philippians|Phil|Colossians|Col|'
        . '1 Thessalonians|1 Thess|2 Thessalonians|2 Thess|'
        . '1 Timothy|1 Tim|2 Timothy|2 Tim|Titus|Titus|Philemon|Philem|Hebrews|Heb|'
        . 'James|James|1 Peter|1 Pet|2 Peter|2 Pet|'
        . '1 John|1 John|2 John|2 John|3 John|3 John|Jude|Jude|Revelation|Rev'
        . ')\b';

    // Regex to match references, e.g., 1 Thessalonians 3:1-13
    $regex = '/\b(' . $book_regex . ')\s+(\d{1,3})(?::\s*(\d{1,3}(?:[-–—]\d{1,3})?(?:,\s*\d{1,3}(?:[-–—]\d{1,3})?)*))?\b/i';

    // Use callback to replace matches
    return preg_replace_callback($regex, 'bible_linker_replace_reference', $text);
}

function bible_linker_replace_reference($matches) {
    $book = $matches[1];
    $chapter = $matches[2];
    $verse = isset($matches[3]) ? ':' . preg_replace('/\s+/', '', $matches[3]) : '';

    $reference = $book . ' ' . $chapter . $verse;

    // Get options
    $options = get_option('bible_linker_settings');
    $bible_version = $options['bible_linker_bible_version'] ?? 'NIV';
    $bible_site = $options['bible_linker_bible_site'] ?? 'biblegateway';

    // Construct URL based on selected Bible site
    $url = bible_linker_construct_url($reference, $bible_version, $bible_site);

    return '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($reference) . '</a>';
}

// Modify post content on save
add_action('save_post', 'bible_linker_save_post', 10, 3);

function bible_linker_save_post($post_ID, $post, $update) {
    // Only process for specified post types
    if (!in_array($post->post_type, ['post', 'page', 'sermon', 'sermons', 'podcast', 'podcasts']) || wp_is_post_revision($post_ID)) {
        return;
    }

    // Check user capabilities
    if (!current_user_can('edit_post', $post_ID)) {
        return;
    }

    // Get the post content
    $content = $post->post_content;

    // Parse and replace Bible references
    $modified_content = bible_linker_parse_references($content);

    // Update the post content if modified
    if ($content !== $modified_content) {
        // Remove the action to prevent infinite loop
        remove_action('save_post', 'bible_linker_save_post', 10, 3);

        // Update the post
        wp_update_post(array(
            'ID' => $post_ID,
            'post_content' => $modified_content,
        ));

        // Re-add the action
        add_action('save_post', 'bible_linker_save_post', 10, 3);
    }
}

?>
