<?php
// PHP Configuration
// The script handles both the front-end HTML/JS (GET request) 
// and the back-end processing logic (POST request via AJAX).

// --- Debug Logging Functionality ---

// --- API Configuration ---
// Hardcover API requires a Bearer token for authorized requests
// NOTE: This token is only used for cover image lookups based on title/author.

// Load environment variables from .env file
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value, '"\''); // Remove surrounding quotes
        if (!defined($name)) {
            define($name, $value);
        }
    }
}

// Fallback if not defined in .env
if (!defined('HARDCOVER_BEARER_TOKEN')) {
    define('HARDCOVER_BEARER_TOKEN', ''); // Define as empty if not found
}
if (!defined('DEBUG_LOG_FILE')) {
    define('DEBUG_LOG_FILE', 'debug.log');
}
if (!defined('EPUB_DIR')) {
    define('EPUB_DIR', 'epubs/');
}
if (!defined('ISBN_LIST_FILE')) {
    define('ISBN_LIST_FILE', 'processed_isbns.txt');
}
if (!defined('GEMINI_API_KEY')) {
    define('GEMINI_API_KEY', ''); // Define as empty if not found
}

define('HARDCOVER_GRAPHQL_ENDPOINT', 'https://api.hardcover.app/v1/graphql');


// --- Utility Functions ---

// Utility function for logging messages
function log_message($message, $type = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] [$type] $message\n";
    file_put_contents(DEBUG_LOG_FILE, $log_entry, FILE_APPEND);
}

// Utility function to record successful ISBN processing
function record_processed_isbn($isbn, $title = 'Unknown') {
    $timestamp = date('Y-m-d H:i:s');
    $entry = "[$timestamp] ISBN: $isbn, Title: $title\n";
    file_put_contents(ISBN_LIST_FILE, $entry, FILE_APPEND);
}

/**
 * Sanitizes a string for use in a filename (alphanumeric and hyphen only).
 * @param string $string The input string (title, author, or ISBN).
 * @return string The sanitized string.
 */
function sanitize_filename_part($string) {
    $string = preg_replace('/[^a-zA-Z0-9\s-]/', '', $string);
    $string = preg_replace('/[\s-]+/', '-', $string);
    $string = trim($string, '-');
    return strtolower($string);
}

/**
 * Executes a cURL request to a given URL and returns the response body.
 */
function fetch_url($url, $headers = [], $post_fields = null) {
    log_message("Fetching URL: $url" . ($post_fields ? " (POST)" : ""));
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    
    if ($post_fields) {
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    }
    
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); 
    curl_setopt($ch, CURLOPT_TIMEOUT, 15); // Increased timeout for external APIs
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $http_code >= 400 || !empty($error)) {
        log_message("cURL Error for $url: HTTP $http_code, Error: $error", 'ERROR');
        return null;
    }
    
    log_message("Successfully fetched URL, HTTP code: $http_code");
    return $response;
}


// --- Metadata Retrieval Functions (Hardcover - Priority Source) ---

function get_hardcover_metadata($identifier) {
    if (empty(HARDCOVER_BEARER_TOKEN)) {
        log_message("Hardcover Bearer Token is not configured.", 'WARNING');
        return null;
    }
    log_message("Attempting to get metadata from Hardcover for $identifier");
    $query = 'query GetBookDetails($identifier: String!) { search(query: $identifier, query_type: "books") { results } }';
    $variables = ['identifier' => $identifier];
    $payload = json_encode(['query' => $query, 'variables' => $variables]);
    $headers = ['Content-Type: application/json', 'Authorization: ' . HARDCOVER_BEARER_TOKEN];
    $data = fetch_url(HARDCOVER_GRAPHQL_ENDPOINT, $headers, $payload);

    if ($data) {
        $json = json_decode($data, true);

        if (isset($json['errors'])) {
            log_message("Hardcover GraphQL Error: " . print_r($json['errors'], true), 'WARNING');
            return null;
        }

        $hits = $json['data']['search']['results']['hits'] ?? [];

        if (count($hits) > 0) {
            $results = [];
            foreach (array_slice($hits, 0, 3) as $hit) {
                $book_data = $hit['document'];
                $results[] = [
                    'title' => $book_data['title'] ?? 'Unknown Title',
                    'subtitle' => $book_data['subtitle'] ?? '',
                    'author' => implode(' & ', $book_data['author_names'] ?? ['Unknown Author']),
                    'description' => $book_data['description'] ?? '',
                    'publisher' => $book_data['publisher'] ?? '',
                    'publishedDate' => $book_data['release_date'] ?? '',
                    'cover_url' => $book_data['image']['url'] ?? null,
                    'source' => 'Hardcover'
                ];
            }
            log_message("Hardcover success: Found " . count($results) . " book(s).");
            return $results;
        } else {
            log_message("Hardcover API: Book not found.", 'WARNING');
        }
    }
    return null;
}

// --- Metadata Retrieval Functions (Google Books) ---

function get_google_books_metadata($isbn) {
    log_message("Attempting to get metadata from Google Books for ISBN: $isbn");
    $url = "https://www.googleapis.com/books/v1/volumes?q=isbn:$isbn";
    $data = fetch_url($url);

    if ($data) {
        $json = json_decode($data, true);
        if (isset($json['totalItems']) && $json['totalItems'] > 0) {
            $item = $json['items'][0]['volumeInfo'];
            log_message("Google Books success: Found book titled '{$item['title']}'");

            return [
                'title' => $item['title'] ?? 'Unknown Title',
                'subtitle' => $item['subtitle'] ?? '',
                'author' => implode(' & ', $item['authors'] ?? ['Unknown Author']),
                'description' => $item['description'] ?? '',
                'publisher' => $item['publisher'] ?? '',
                'publishedDate' => $item['publishedDate'] ?? '',
                'cover_url' => $item['imageLinks']['extraLarge'] ?? $item['imageLinks']['large'] ?? $item['imageLinks']['thumbnail'] ?? null,
                'source' => 'Google Books'
            ];
        } else {
            log_message("Google Books API: Book not found.", 'WARNING');
        }
    }
    return null;
}

// --- Metadata Retrieval Functions (Open Library) ---

function get_open_library_metadata_by_title_author($title, $author) {
    log_message("Attempting to get metadata from Open Library for title: $title, author: $author");
    $title_query = urlencode($title);
    $author_query = urlencode($author);
    $url = "https://openlibrary.org/search.json?title={$title_query}&author={$author_query}";
    $data = fetch_url($url);

    if ($data) {
        $json = json_decode($data, true);
        if (isset($json['docs']) && count($json['docs']) > 0) {
            $results = [];
            foreach (array_slice($json['docs'], 0, 3) as $book) {
                $cover_id = $book['cover_i'] ?? null;
                $results[] = [
                    'title' => $book['title'] ?? 'Unknown Title',
                    'subtitle' => $book['subtitle'] ?? '',
                    'author' => implode(' & ', $book['author_name'] ?? ['Unknown Author']),
                    'description' => (isset($book['first_sentence']) && $book['first_sentence']) ? $book['first_sentence'][0] : '',
                    'publisher' => (isset($book['publisher']) && $book['publisher']) ? $book['publisher'][0] : '',
                    'publishedDate' => $book['first_publish_year'] ?? '',
                    'cover_url' => $cover_id ? "https://covers.openlibrary.org/b/id/{$cover_id}-L.jpg" : null,
                    'isbn' => (isset($book['isbn']) && $book['isbn'])  ? $book['isbn'][0] : 'N/A',
                    'source' => 'Open Library'
                ];
            }
            log_message("Open Library success: Found " . count($results) . " book(s).");
            return $results;
        } else {
            log_message("Open Library API: Book not found for title/author.", 'WARNING');
        }
    }
    return null;
}

function get_open_library_metadata($isbn) {
    log_message("Attempting to get metadata from Open Library for ISBN: $isbn");
    $url = "https://openlibrary.org/api/books?bibkeys=ISBN:$isbn&jscmd=data&format=json";
    $data = fetch_url($url);

    if ($data) {
        $json = json_decode($data, true);
        $key = "ISBN:$isbn";
        
        if (isset($json[$key])) {
            $book = $json[$key];
            $title = $book['title'] ?? 'Unknown Title';
            $authors = [];
            if (isset($book['authors'])) {
                foreach ($book['authors'] as $author) {
                    $authors[] = $author['name'];
                }
            }

            $cover_url = null;
            if (isset($book['cover']['large'])) {
                 $cover_url = $book['cover']['large'];
            } elseif (isset($book['cover']['medium'])) {
                 $cover_url = $book['cover']['medium'];
            }

            log_message("Open Library success: Found book titled '$title'");
            return [
                'title' => $title,
                'subtitle' => $book['subtitle'] ?? '',
                'author' => implode(' & ', $authors) ?: 'Unknown Author',
                'description' => $book['excerpts'][0]['text'] ?? '', 
                'publisher' => $book['publishers'][0]['name'] ?? '',
                'publishedDate' => $book['publish_date'] ?? '',
                'cover_url' => $cover_url,
                'source' => 'Open Library'
            ];
        } else {
            log_message("Open Library API: Book not found.", 'WARNING');
        }
    }
    return null;
}

// --- Metadata Retrieval Functions (Gemini Vision) ---

function get_gemini_cover_suggestion($base64_image_data) {
    log_message("Attempting to get book suggestion and cover from Gemini Vision");
    $api_key = GEMINI_API_KEY;
    if (empty($api_key)) {
        log_message("GEMINI_API_KEY is not configured.", 'ERROR');
        return null;
    }

    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . $api_key;
    $image_data = substr($base64_image_data, strpos($base64_image_data, ',') + 1);
    $payload = json_encode([
        'contents' => [
            [
                'parts' => [
                    [
                        'text' => "Analyze the image, which contains a book cover. Identify the book's title and author. If the title or author cannot be determined, return an empty string for that value. Respond with a single, clean JSON object with two keys: 'title' and 'author'. For example: {\"title\": \"The Hobbit\", \"author\": \"J.R.R. Tolkien\"}."
                    ],
                    [
                        'inline_data' => [
                            'mime_type' => 'image/jpeg',
                            'data' => $image_data
                        ]
                    ]
                ]
            ]
        ]
    ]);
    $headers = ['Content-Type: application/json'];
    $response = fetch_url($url, $headers, $payload);

    if ($response) {
        $json = json_decode($response, true);

        if (isset($json['candidates'][0]['content']['parts'][0]['text'])) {
            $text = $json['candidates'][0]['content']['parts'][0]['text'];
            $json_text = substr($text, strpos($text, '{'), strrpos($text, '}') - strpos($text, '{') + 1);
            $book_data = json_decode($json_text, true);

            if (isset($book_data['title']) && isset($book_data['author'])) {
                log_message("Gemini Vision success: Found title '{$book_data['title']}' and author '{$book_data['author']}'");
                // Add the original image data to the response
                $book_data['cover_url'] = $base64_image_data;
                return $book_data;
            }
        }
        log_message("Gemini Vision API: Could not parse title and author from response.", 'WARNING');
    }
    return null;
}


/**
 * Consolidates metadata from Hardcover, Google Books, and Open Library.
 */
function get_book_metadata($identifier, $type = 'ISBN', $author = null) {
    // Determine which API to call based on the search type
    if ($type === 'TITLE_AUTHOR') {
        $title = $identifier;
        // Manual search now uses Hardcover and Open Library
        $hardcover_data = get_hardcover_metadata("$title $author");
        $hardcover_data = !empty($hardcover_data) ? [$hardcover_data[0]] : [];
        $open_library_data = get_open_library_metadata_by_title_author($title, $author);

        $all_results = array_merge(
            !empty($open_library_data) ? $open_library_data : [],
            !empty($hardcover_data) ? $hardcover_data : []
        );

        if (count($all_results) > 1) {
            return ['multiple_options' => $all_results];
        }

        $metadata = $all_results[0] ?? null;
        if ($metadata) {
            $metadata['isbn'] = $metadata['isbn'] ?? 'N/A';
        }

    } else { // ISBN search
        $hardcover_data = get_hardcover_metadata($identifier);
        $hardcover_data = !empty($hardcover_data) ? $hardcover_data[0] : $hardcover_data; // convert array to single element for isbn search
        $google_data = get_google_books_metadata($identifier);
        $open_library_data = get_open_library_metadata($identifier);

        $metadata = [
            'isbn' => $identifier,
            'title' => 'Title Not Found',
            'subtitle' => '',
            'author' => 'Author Not Found',
            'description' => 'No description available.',
            'publisher' => 'Publisher Not Found',
            'publishedDate' => 'N/A',
            'cover_url' => null,
            'sources' => []
        ];
   
        $sources = array_values(array_filter([$hardcover_data, $open_library_data, $google_data], 
            fn($data) => !empty($data)
        ));
   
        // Prioritized consolidation: Open Library > Google
        foreach ($sources as $source) {
            if (!in_array($source['source'], $metadata['sources'])) {
                $metadata['sources'][] = $source['source'];
            }
            // Overwrite if the current value is a placeholder or if the new value is better
            foreach ($source as $key => $value) {
                if ($key === 'source') {
                    continue;
                }
                if ($value && ($metadata[$key] === "Title Not Found" || 
                               $metadata[$key] === "Author Not Found" || 
                               $metadata[$key] === "No description available." ||
                               $metadata[$key] === "Publisher Not Found" ||
                               $metadata[$key] === "N/A" || 
                               ($key === 'cover_url' && !$metadata['cover_url']) ||
                               ($key !== 'description' && $value !== ''))) {
                    $metadata[$key] = $value;
                }
            }
        }

        // Final cover check
        if ($open_library_data && isset($open_library_data['cover_url']) && $open_library_data['cover_url']) {
            $metadata['cover_url'] = $open_library_data['cover_url'];
        } elseif ($google_data && isset($google_data['cover_url']) && $google_data['cover_url']) {
            $metadata['cover_url'] = $google_data['cover_url'];
        } elseif ($hardcover_data && isset($hardcover_data['cover_url']) && $hardcover_data['cover_url']) {
            $metadata['cover_url'] = $hardcover_data['cover_url'];
        }

        $metadata['source'] = implode(', ', $metadata['sources']);
        unset($metadata['sources']);
    }

    log_message("Final Consolidated Metadata: " . print_r($metadata, true));
    return $metadata;
}

// --- EPUB File Generation Functions ---

function create_opf_content($metadata, $cover_mime = 'image/jpeg') { 
    $date = date('Y-m-d\\TH:i:s\\Z');
    $uuid = 'urn:uuid:' . uniqid();
    $is_placeholder_cover = (isset($metadata['cover_url']) && $metadata['cover_url'] === 'placeholder');
    // HARDCODED COVER FILENAME AND MIME TYPE
    $cover_filename = 'cover.jpeg';
    $cover_mime = 'image/jpeg';
    $modified_date = substr($metadata['publishedDate'], 0, 10) ?: '2024-01-01';

    $title = htmlspecialchars($metadata['title'] ?? 'Unknown Title', ENT_XML1, 'UTF-8');
    $subtitle = isset($metadata['subtitle']) && !empty($metadata['subtitle']) ? htmlspecialchars($metadata['subtitle'], ENT_XML1, 'UTF-8') : '';
    $author = htmlspecialchars($metadata['author'] ?? 'Unknown Author', ENT_XML1, 'UTF-8');
    $isbn = htmlspecialchars($metadata['isbn'] ?? 'N/A', ENT_XML1, 'UTF-8');
    $publisher = htmlspecialchars($metadata['publisher'] ?? 'Unknown Publisher', ENT_XML1, 'UTF-8');
    $description = htmlspecialchars($metadata['description'] ?? 'No description available.', ENT_XML1, 'UTF-8');

    $full_title = $title;
    if ($subtitle) {
        $full_title .= ': ' . $subtitle;
    }

    $primary_author = $metadata['author'];
    if (strpos($primary_author, ' & ') !== false) {
        $primary_author = trim(explode(' & ', $primary_author)[0]);
    }
    $author_parts = explode(' ', $primary_author);
    if (count($author_parts) > 1) {
        $file_as_author = end($author_parts) . ', ' . implode(' ', array_slice($author_parts, 0, -1));
    } else {
        $file_as_author = $primary_author;
    }
    $file_as_author = htmlspecialchars($file_as_author, ENT_XML1, 'UTF-8');

    $cover_manifest_item = '';
    $cover_meta = '';
    if (!$is_placeholder_cover) {
        $cover_manifest_item = "<item id=\"cover-image\" href=\"{$cover_filename}\" media-type=\"{$cover_mime}\" properties=\"cover-image\"/>";
        $cover_meta = '<meta name="cover" content="cover-image"/>';
    }

    $subtitle_meta = '';
    if ($subtitle) {
        $subtitle_meta = "<meta property=\"title-type\" refines=\"#title\">subtitle</meta><meta property=\"alternate-title\" refines=\"#title\">{$subtitle}</meta>";
    }

    return <<<EOT
<?xml version="1.0" encoding="UTF-8"?>
<package xmlns="http://www.idpf.org/2007/opf" unique-identifier="BookId" version="3.0">
  <metadata xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:opf="http://www.idpf.org/2007/opf">
    <dc:identifier id="BookId">{$uuid}</dc:identifier>
    <dc:identifier opf:scheme="ISBN">{$isbn}</dc:identifier>
    <dc:identifier>isbn:{$isbn}</dc:identifier>
    <dc:title id="title">{$full_title}</dc:title>
    <dc:creator id="creator" opf:role="aut" opf:file-as="{$file_as_author}">{$author}</dc:creator>
    <dc:publisher>{$publisher}</dc:publisher>
    <dc:language>en</dc:language>
    <dc:description>{$description}</dc:description>
    <dc:date opf:event="publication">{$modified_date}</dc:date>
    <meta property="dcterms:modified">{$date}</meta>
    {$cover_meta}
  </metadata>
  <manifest>
    {$cover_manifest_item}
    <item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/>
    <item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>
    <item id="titlepage" href="titlepage.xhtml" media-type="application/xhtml+xml"/>
  </manifest>
  <spine toc="ncx">
    <itemref idref="titlepage" linear="yes"/>
  </spine>
  <guide>
    <reference type="cover" title="Cover" href="titlepage.xhtml"/>
  </guide>
</package>
EOT;
}

function create_titlepage_content($metadata) { 
    $title = htmlspecialchars($metadata['title'], ENT_XML1, 'UTF-8');
    $subtitle = isset($metadata['subtitle']) && !empty($metadata['subtitle']) ? htmlspecialchars($metadata['subtitle'], ENT_XML1, 'UTF-8') : '';
    $author = htmlspecialchars($metadata['author'], ENT_XML1, 'UTF-8');
    $description = htmlspecialchars(nl2br($metadata['description']), ENT_XML1, 'UTF-8');

    $has_cover = isset($metadata['cover_url']) && $metadata['cover_url'] !== 'placeholder';
    // HARDCODED COVER FILENAME
    $cover_filename = 'cover.jpeg';

    $cover_html = '';
    if ($has_cover) {
        $cover_html = "<img src=\"{$cover_filename}\" alt=\"Cover image for {$title}\" />";
    } else {
        $cover_html = "<div class=\"placeholder-cover\"><h2>NO COVER IMAGE AVAILABLE</h2><p>For: {$title}</p></div>";
    }

    $subtitle_html = '';
    if ($subtitle) {
        $subtitle_html = "<h2 class=\"subtitle\">{$subtitle}</h2>";
    }

    return <<<EOT
<?xml version="1.0" encoding="UTF-8"?>
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" lang="en">
  <head>
    <title>{$title}</title>
    <meta charset="utf-8"/>
    <style>
      body { margin: 0; padding: 0; text-align: center; font-family: sans-serif; background-color: #f7f7f7; }
      .cover-container { padding: 5%; }
      img { max-width: 100%; height: auto; display: block; margin: 0 auto; box-shadow: 0 4px 10px rgba(0,0,0,0.5); }
      .placeholder-cover { 
        background-color: #ccc; 
        color: #333; 
        height: 400px; 
        text-align: center; 
        border: 1px dashed #666; 
        margin: 20px auto; 
        max-width: 80%; 
        display: flex; 
        flex-direction: column; 
        justify-content: center; 
        align-items: center; 
        padding: 20px;
        box-sizing: border-box;
      }
      .placeholder-cover h2 { font-size: 1.5em; margin-bottom: 10px; }
      .placeholder-cover p { font-size: 1em; }
      h1 { margin-top: 20px; font-size: 1.5em; }
      h2 { font-size: 1.1em; color: #555; }
      .subtitle { font-size: 1.2em; color: #777; margin-top: -10px; }
      .description { margin-top: 30px; text-align: left; padding: 0 5%; font-size: 0.9em; line-height: 1.5; }
    </style>
  </head>
  <body>
    <div class="cover-container">
      {$cover_html}
      <h1>{$title}</h1>
      {$subtitle_html}
      <h2>By {$author}</h2>
      <div class="description">
        <h3>Description:</h3>
        <p>{$description}</p>
        <p>ISBN: {$metadata['isbn']}</p>
        <p>Publisher: {$metadata['publisher']}</p>
        <p>Published: {$metadata['publishedDate']}</p>
      </div>
    </div>
  </body>
</html>
EOT;
}

function create_ncx_content($metadata) { 
    $title = htmlspecialchars($metadata['title'], ENT_XML1, 'UTF-8');
    $subtitle = isset($metadata['subtitle']) && !empty($metadata['subtitle']) ? htmlspecialchars($metadata['subtitle'], ENT_XML1, 'UTF-8') : '';
    $full_title = $title;
    if ($subtitle) {
        $full_title .= ': ' . $subtitle;
    }
    return <<<EOT
<?xml version="1.0" encoding="UTF-8"?>
<ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1" xml:lang="en">
  <head>
    <meta name="dtb:uid" content="urn:uuid:{$metadata['isbn']}"/>
    <meta name="dtb:depth" content="1"/>
    <meta name="dtb:totalPageCount" content="0"/>
    <meta name="dtb:maxPageNumber" content="0"/>
  </head>
  <docTitle>
    <text>{$full_title}</text>
  </docTitle>
  <navMap>
    <navPoint id="navpoint-1" playOrder="1">
      <navLabel>
        <text>Title Page</text>
      </navLabel>
      <content src="titlepage.xhtml"/>
    </navPoint>
  </navMap>
</ncx>
EOT;
}

/**
 * Downloads a file, determines its MIME type, and converts image data to JPEG if possible.
 * @param string $url The URL to download.
 * @return array|null An array containing 'content' (the file data, guaranteed JPEG if image) and 'mime' (image/jpeg) or null on failure.
 */
function download_file($url) { 
    log_message("Attempting to download file from: $url");
    $content = fetch_url($url);
    if (!$content) {
        log_message("Failed to download file from $url", 'ERROR');
        return null;
    }

    // Determine MIME type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_buffer($finfo, $content);
    finfo_close($finfo);

    // Check if it's an image and if GD extension is loaded for conversion
    if (preg_match('/^image\/(jpe?g|png|gif|webp)$/i', $mime_type) && extension_loaded('gd')) {
        log_message("Downloaded file is an image ($mime_type). Attempting conversion to JPEG.");

        $image = false;
        // Temporarily suppress errors to handle them manually
        $previous_error_handler = set_error_handler(function($errno, $errstr) {
            log_message("Caught GD Error: $errstr", 'ERROR');
        });

        try {
            $image = imagecreatefromstring($content);
        } catch (Exception $e) {
            log_message("An exception occurred during image creation: " . $e->getMessage(), 'ERROR');
            $image = false;
        } finally {
            restore_error_handler();
        }

        if ($image !== false) {
            ob_start();
            $jpeg_success = imagejpeg($image, null, 85);
            $jpeg_content = ob_get_clean();
            imagedestroy($image);

            if ($jpeg_success && $jpeg_content) {
                log_message("Image successfully converted to JPEG.");
                return ['content' => $jpeg_content, 'mime' => 'image/jpeg'];
            } else {
                log_message("imagejpeg failed to produce content.", 'ERROR');
            }
        } else {
            log_message("Could not create image resource for conversion. The image may be corrupt or in an unsupported format.", 'ERROR');
        }
    } else {
        log_message("Downloaded file is not a supported image format or GD is not available ($mime_type). Using raw content.", 'WARNING');
    }

    // Fallback if not an image, GD is missing, or conversion failed
    return ['content' => $content, 'mime' => $mime_type];
}

/**
 * Creates a zip archive (EPUB file) containing the necessary files.
 * @param array $metadata The book metadata.
 * @return string|null The filepath to the created EPUB file or null on failure.
 */
function create_epub_file($metadata) {
    if (!is_dir(EPUB_DIR)) {
        if (!mkdir(EPUB_DIR, 0777, true)) {
            log_message("Failed to create EPUB directory: " . EPUB_DIR, 'FATAL');
            return null;
        }
        log_message("Created EPUB directory: " . EPUB_DIR);
    }

    // Generate the formatted filename: author-title-subtitle-isbn.epub
    $isbn_part = sanitize_filename_part($metadata['isbn'] ?? 'no-isbn');
    $title_part = sanitize_filename_part($metadata['title'] ?? 'untitled');
    $subtitle_part = isset($metadata['subtitle']) && !empty($metadata['subtitle']) ? sanitize_filename_part($metadata['subtitle']) : '';
    $author_part = sanitize_filename_part($metadata['author'] ?? 'unknown-author');

    if ($subtitle_part) {
        $title_part .= '-' . $subtitle_part;
    }

    $title_part = substr($title_part, 0, 40);
    $author_part = substr($author_part, 0, 30);

    $filename_base = "{$author_part}-{$title_part}-{$isbn_part}";
    $epub_filename = EPUB_DIR . $filename_base . ".epub";

    log_message("Starting EPUB creation for {$metadata['title']} into $epub_filename (Formatted)");

    // 1. Download Cover Image and convert to JPEG
    $cover_data = null;
    // HARDCODE MIME TYPE AND FILENAME
    $cover_mime = 'image/jpeg';
    $cover_filename = 'cover.jpeg'; 

    $has_cover_url = isset($metadata['cover_url']) && !empty($metadata['cover_url']);

    if ($has_cover_url) {
        if (strpos($metadata['cover_url'], 'data:image') === 0) {
            $cover_data = base64_decode(substr($metadata['cover_url'], strpos($metadata['cover_url'], ',') + 1));
            log_message("Using base64 encoded cover image.");
        } else {
            $cover_download = download_file($metadata['cover_url']);
            if ($cover_download) {
                // The download_file function now guarantees conversion to 'image/jpeg' if it's a convertible image.
                if ($cover_download['mime'] === 'image/jpeg') {
                    $cover_data = $cover_download['content'];
                    log_message("Cover image is available as JPEG: $cover_filename");
                } else {
                    // If it's not a JPEG (e.g., failed conversion or non-image), treat as no cover
                    log_message("Downloaded file was not a convertible image or GD is missing. Treating as no cover.", 'WARNING');
                }
            }
        }
    }

    if (!$cover_data) {
        log_message("No valid cover image found or downloaded/converted. Using placeholder metadata flag.", 'WARNING');
        $metadata['cover_url'] = 'placeholder'; 
    }

    // 2. Generate EPUB component files
    // Pass the hardcoded MIME type
    $opf_content = create_opf_content($metadata, $cover_mime); 
    $titlepage_content = create_titlepage_content($metadata);
    $ncx_content = create_ncx_content($metadata);

    // 3. Prepare ZIP Archive
    $zip = new ZipArchive();

    if ($zip->open($epub_filename, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
        log_message("Cannot create ZIP archive at $epub_filename", 'FATAL');
        return null;
    }

    // A. Add mimetype file (MUST be uncompressed and first)
    $zip->addFromString('mimetype', 'application/epub+zip');
    $zip->setCompressionName('mimetype', ZipArchive::CM_STORE);

    // B. Add META-INF/container.xml
    $container_xml = '<?xml version="1.0"?><container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles><rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>';
    $zip->addFromString('META-INF/container.xml', $container_xml);

    // C. Add OEBPS folder contents
    $zip->addFromString('OEBPS/content.opf', $opf_content);
    $zip->addFromString('OEBPS/toc.ncx', $ncx_content);
    $zip->addFromString('OEBPS/titlepage.xhtml', $titlepage_content);

    if ($cover_data) {
        // Use the hardcoded filename
        $zip->addFromString('OEBPS/' . $cover_filename, $cover_data);
    }

    // D. Add nav.xhtml (Minimal EPUB 3 navigation file)
    $nav_xhtml = <<<EOT
<?xml version="1.0" encoding="UTF-8"?>
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops">
  <head>
    <title>Navigation</title>
  </head>
  <body>
    <nav epub:type="toc">
      <h1>Table of Contents</h1>
      <ol>
        <li><a href="titlepage.xhtml">Title Page</a></li>
      </ol>
    </nav>
  </body>
</html>
EOT;
    $zip->addFromString('OEBPS/nav.xhtml', $nav_xhtml);


    $zip->close();

    log_message("EPUB file successfully created at $epub_filename");
    return $epub_filename;
}
// End of EPUB creation functions

// --- Main Server Request Handler (POST/AJAX) ---

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    // Make sure the zip extension is available before processing any EPUB creation requests
    if (!extension_loaded('zip')) {
        $error_msg = "PHP ZIP extension is not loaded. Cannot create EPUB file.";
        log_message($error_msg, 'FATAL');
        echo json_encode(['success' => false, 'message' => $error_msg]);
        exit;
    }

    // Check for GD extension now that image conversion is required
    if (!extension_loaded('gd')) {
        $gd_warning = "PHP GD extension is not loaded. Cover image conversion to JPEG will be skipped, which may cause EPUB validation issues.";
        log_message($gd_warning, 'WARNING');
        // Script can continue, but with a warning.
    }

    // Read JSON payload for cleaner AJAX handling
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? 'search'; 

    // --- ACTION: GEMINI COVER SEARCH ---
    if ($action === 'gemini_cover_search') {
        $image_data = $input['image_data'] ?? null;

        if (empty($image_data)) {
            echo json_encode(['success' => false, 'message' => 'No image data provided for analysis.']);
            exit;
        }

        try {
            $gemini_suggestion = get_gemini_cover_suggestion($image_data);

            if ($gemini_suggestion) {
                $gemini_suggestion['source'] = 'Gemini Vision';

                if (empty($gemini_suggestion['title']) || empty($gemini_suggestion['author'])) {
                    // If title or author is missing, go straight to confirmation for editing.
                    echo json_encode(['success' => true, 'requires_confirmation' => true, 'metadata' => $gemini_suggestion]);
                } else {
                    // If both are present, proceed with the selection logic.
                    $options = [];
                    $options[] = $gemini_suggestion;

                    $metadata = get_book_metadata($gemini_suggestion['title'], 'TITLE_AUTHOR', $gemini_suggestion['author']);
                    if ($metadata) {
                        if (isset($metadata['multiple_options'])) {
                            $options = array_merge($options, $metadata['multiple_options']);
                        } else {
                            $options[] = $metadata;
                        }
                    }
                    echo json_encode(['success' => true, 'requires_selection' => true, 'options' => $options]);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Could not identify book from cover. Please try manual entry.']);
            }
        } catch (Exception $e) {
            $error_msg = "An unexpected error occurred during Gemini search: " . $e->getMessage();
            log_message($error_msg, 'FATAL');
            echo json_encode(['success' => false, 'message' => $error_msg]);
        }
        exit;
    }

    // --- ACTION 3: MANUAL SEARCH (Fallback) ---
    if ($action === 'manual_search') {
        $title = trim(preg_replace('/<[^>]*>/', '', $input['title'] ?? ''));
        $author = trim(preg_replace('/<[^>]*>/', '', $input['author'] ?? ''));        

        log_message("Received Manual Search request for: title='$title', author='$author'");

        if (empty($title) || empty($author)) {
             echo json_encode(['success' => false, 'message' => 'Title and Author are required for manual search.']);
             exit;
        }

        try {
            $metadata = get_book_metadata($title, 'TITLE_AUTHOR', $author);

            if (isset($metadata['multiple_options'])) {
                echo json_encode(['success' => true, 'requires_selection' => true, 'options' => $metadata['multiple_options']]);
                exit;
            }

            if (!$metadata) {
                $message = "Could not find book using manual search (Hardcover API). Try a different combination.";
                log_message($message, 'ERROR');
                echo json_encode(['success' => false, 'message' => $message]);
                exit;
            }

            // Metadata found via manual search - require confirmation
            log_message("Metadata found via manual search. Returning for user confirmation.");
            echo json_encode([
                'success' => true,
                'requires_confirmation' => true,
                'metadata' => $metadata
            ]);

        } catch (Exception $e) {
            $error_msg = "An unexpected error occurred during manual search: " . $e->getMessage();
            log_message($error_msg, 'FATAL');
            echo json_encode(['success' => false, 'message' => $error_msg]);
        }
        exit;
    }

    // --- ACTION 2: EPUB CREATION CONFIRMATION ---
    if ($action === 'confirm_epub_creation') {
        $metadata = $input['metadata'] ?? [];

        if (empty($metadata) || !isset($metadata['title'])) {
             echo json_encode(['success' => false, 'message' => 'Invalid or missing metadata for confirmation.']);
             exit;
        }

        log_message("Confirmation received for '{$metadata['title']}'. Starting EPUB creation.");

        try {
            $epub_file = create_epub_file($metadata);

            if ($epub_file) {
                record_processed_isbn($metadata['isbn'], $metadata['title']);

                echo json_encode([
                    'success' => true, 
                    'message' => "Successfully created EPUB for '{$metadata['title']}'",
                    'filename' => $epub_file,
                    'metadata' => [
                        'title' => $metadata['title'],
                        'author' => $metadata['author'],
                        'publisher' => $metadata['publisher'],
                        'isbn' => $metadata['isbn']
                    ]
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => "EPUB creation failed for book: {$metadata['title']}"]);
            }
        } catch (Exception $e) {
            $error_msg = "An unexpected error occurred during creation: " . $e->getMessage();
            log_message($error_msg, 'FATAL');
            echo json_encode(['success' => false, 'message' => $error_msg]);
        }
        exit;
    }

    // --- ACTION 1 (Default): INITIAL ISBN SEARCH ---

    $isbn = trim(htmlspecialchars($input['isbn'] ?? '', ENT_QUOTES, 'UTF-8'));

    try {
        if (!empty($isbn)) {
            log_message("Received POST request for ISBN lookup: $isbn");
            $metadata = get_book_metadata($isbn, 'ISBN');

            if (isset($metadata['multiple_options'])) {
                echo json_encode(['success' => true, 'requires_selection' => true, 'options' => $metadata['multiple_options']]);
                exit;
            }

        } else {
            log_message("Invalid POST request: Missing ISBN.", 'ERROR');
            echo json_encode(['success' => false, 'message' => 'An ISBN is required for searching.']);
            exit;
        }

        // Check if metadata was successfully retrieved
        if (!$metadata || $metadata['title'] === 'Title Not Found') {
            $message = "Could not find sufficient metadata for ISBN: $isbn. Trying manual search...";

            log_message("Metadata lookup failed for ISBN: $isbn. Suggesting manual search.", 'WARNING');

            // Suggest manual search fallback
            echo json_encode([
                'success' => false, 
                'message' => $message,
                'requires_manual_search' => true,
                'isbn_fallback' => $isbn
            ]);
            exit;
        }

        // METADATA FOUND - REQUIRE CONFIRMATION
        log_message("Metadata found. Returning for user confirmation.");
        echo json_encode([
            'success' => true,
            'requires_confirmation' => true,
            'metadata' => $metadata
        ]);

    } catch (Exception $e) {
        $error_msg = "An unexpected error occurred during processing: " . $e->getMessage();
        log_message($error_msg, 'FATAL');
        echo json_encode(['success' => false, 'message' => $error_msg]);
    }
    
    exit;
}

// --- Front-end HTML/JS (GET Request) ---

?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Book Barcode Scanner & EPUB Generator</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/@zxing/library@0.20.0/umd/index.min.js"></script>

  <style>
    /* Custom styling for the video feed and feedback elements */
    #scanner-container {
      position: relative;
      width: 100%;
      max-width: 500px;
      margin: 0 auto;
      border-radius: 12px;
      overflow: hidden;
      box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 0 15px -5px rgba(0, 0, 0, 0.04);
    }

    #video {
      width: 100%;
      height: auto;
      display: block;
    }

    /* Overlay for the scanning target */
    #scan-overlay {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        box-sizing: border-box;
        pointer-events: none;
    }

    #scan-overlay:before {
        content: '';
        position: absolute;
        top: 25%;
        left: 10%;
        right: 10%;
        bottom: 25%;
        border: 2px solid #3b82f6; /* Blue border */
        box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.5); /* Dark background outside the target */
        border-radius: 4px;
    }

    @keyframes pulse {
      0% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.7); }
      70% { box-shadow: 0 0 0 10px rgba(59, 130, 246, 0); }
      100% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0); }
    }

    .pulsing {
        animation: pulse 1.5s infinite;
    }

    .cover-image {
        width: 150px;
        height: 225px;
        object-fit: cover;
        margin: 0 auto 16px;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        background-color: #e5e7eb; /* Light gray background for loading */
    }

  </style>

</head>

<body class="bg-gray-50 min-h-screen p-4 sm:p-8 font-sans">
  
  <div class="max-w-4xl mx-auto bg-white p-6 md:p-10 rounded-xl shadow-2xl">

    <h1 class="text-3xl font-extrabold text-gray-900 mb-6 text-center">
      📚 Book Barcode Scanner & EPUB Generator
    </h1>
    <p class="text-center text-gray-600 mb-8">
      Scan a book's barcode (ISBN) to automatically fetch metadata from **Hardcover**, **Google Books**, and **Open Library**, and generate a starter EPUB file.
    </p>

    <div id="scanner-container">
      <video id="video" class="rounded-xl"></video>
      <div id="scan-overlay"></div>
    </div>

    <div id="status" class="mt-4 p-3 rounded-lg text-center font-medium bg-blue-100 text-blue-800 transition duration-300 ease-in-out">
      Initializing Scanner...
    </div>

    <div class="mt-6 grid grid-cols-1 sm:grid-cols-3 gap-4">
        <button id="toggle-scan-button" 
                class="bg-blue-600 text-white font-semibold py-3 px-6 rounded-lg hover:bg-blue-700 transition duration-150 ease-in-out shadow-md shadow-blue-300 disabled:opacity-50" 
                disabled>
            Start Barcode Scan
        </button>
        <button id="main-manual-lookup-button" 
                class="bg-yellow-500 text-white font-semibold py-3 px-6 rounded-lg hover:bg-yellow-600 transition duration-150 ease-in-out shadow-md shadow-yellow-300">
            Manual Lookup
        </button>
        <button id="main-manual-entry-button" 
                class="bg-green-600 text-white font-semibold py-3 px-6 rounded-lg hover:bg-green-700 transition duration-150 ease-in-out shadow-md shadow-green-300">
            Manual Entry
        </button>
    </div>

    <div class="mt-4 grid grid-cols-1">
        <button id="main-scan-cover-button" 
                class="bg-purple-600 text-white font-semibold py-3 px-6 rounded-lg hover:bg-purple-700 transition duration-150 ease-in-out shadow-md shadow-purple-300">
            Scan Cover
        </button>
    </div>

    <div class="mt-4">
        <label for="camera-select" class="sr-only">Select Camera</label>
        <select id="camera-select" 
            class="w-full p-3 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500 transition duration-150">
        </select>
    </div>

    <div class="mt-8 border-t pt-6">
      <h2 class="text-xl font-semibold text-gray-800 mb-4">
        Processing Log
      </h2>
      <pre id="result" class="bg-gray-100 p-4 rounded-lg text-sm text-gray-700 whitespace-pre-wrap break-words overflow-x-auto h-48 max-h-48">Awaiting first scan...</pre>
    </div>
  </div>

  <div id="confirmation-modal" 
       class="fixed inset-0 bg-gray-900 bg-opacity-75 z-50 hidden items-center justify-center p-4">
      <div class="bg-white p-8 rounded-xl shadow-2xl max-w-sm w-full text-center transform transition-all duration-300 scale-95 opacity-0"
           id="confirmation-modal-content">
          <h3 class="text-2xl font-bold text-gray-800 mb-4">
              Confirm Book Details
          </h3>
          <img id="modal-cover" class="cover-image mx-auto mb-4" alt="Book Cover">
          <div class="text-left">
            <label for="modal-title-input" class="font-semibold">Title:</label>
            <input type="text" id="modal-title-input" class="w-full p-2 border border-gray-300 rounded-lg">
          </div>
          <div class="text-left mt-2">
            <label for="modal-author-input" class="font-semibold">Author:</label>
            <input type="text" id="modal-author-input" class="w-full p-2 border border-gray-300 rounded-lg">
          </div>
          <div class="text-left mt-2" id="modal-subtitle-container" style="display: none;">
            <label for="modal-subtitle-input" class="font-semibold">Subtitle:</label>
            <input type="text" id="modal-subtitle-input" class="w-full p-2 border border-gray-300 rounded-lg">
          </div>
          <div class="text-left mt-2">
            <label for="modal-isbn-input" class="font-semibold">ISBN:</label>
            <input type="text" id="modal-isbn-input" class="w-full p-2 border border-gray-300 rounded-lg">
          </div>
          <p class="text-xs text-gray-500 mt-4">
              Source: <span id="modal-source"></span>
          </p>
          <div class="flex flex-wrap justify-center gap-4">
              <button id="confirm-button" 
                      class="bg-green-600 text-white font-semibold py-3 px-6 rounded-lg hover:bg-green-700 transition duration-150 ease-in-out shadow-md shadow-green-300">
                  Generate EPUB
              </button>
              <button id="confirmation-manual-entry-button"
                      class="bg-yellow-500 text-white font-semibold py-3 px-6 rounded-lg hover:bg-yellow-600 transition duration-150 ease-in-out shadow-md shadow-yellow-300">
                  Not right? Enter manually
              </button>
              <button id="search-again-button"
                      class="bg-blue-500 text-white font-semibold py-3 px-6 rounded-lg hover:bg-blue-600 transition duration-150 ease-in-out shadow-md shadow-blue-300">
                  Search Again
              </button>
              <button id="cancel-button" 
                      class="bg-gray-300 text-gray-800 font-semibold py-3 px-6 rounded-lg hover:bg-gray-400 transition duration-150 ease-in-out">
                  Cancel
              </button>
          </div>
      </div>
  </div>

  <div id="selection-modal" 
       class="fixed inset-0 bg-gray-900 bg-opacity-75 z-50 hidden items-center justify-center p-4">
      <div class="bg-white p-8 rounded-xl shadow-2xl max-w-4xl w-full flex flex-col transform transition-all duration-300 scale-95 opacity-0"
           id="selection-modal-content" style="max-height: 90vh;">
          <h3 class="text-2xl font-bold text-gray-800 mb-4 flex-shrink-0">
              Multiple Books Found
          </h3>
          <p class="text-gray-700 mb-6 flex-shrink-0">
              We found a few potential matches. Please select the correct one.
          </p>
          <div id="selection-options" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-6 overflow-y-auto py-4">
              <!-- Options will be dynamically inserted here -->
          </div>
          <div class="mt-6 text-center flex-shrink-0">
              <button id="selection-cancel-button" 
                      class="bg-gray-300 text-gray-800 font-semibold py-3 px-6 rounded-lg hover:bg-gray-400 transition duration-150 ease-in-out">
                  Cancel
              </button>
          </div>
      </div>
  </div>

  <div id="manual-search-modal" 
       class="fixed inset-0 bg-gray-900 bg-opacity-75 z-50 hidden items-center justify-center p-4">
      <div class="bg-white p-8 rounded-xl shadow-2xl max-w-md w-full text-center transform transition-all duration-300 scale-95 opacity-0"
           id="manual-search-modal-content">
          <h3 class="text-2xl font-bold text-gray-800 mb-4">
              ISBN Not Found
          </h3>
          <p class="text-gray-700 mb-6">
              We couldn't find metadata for ISBN <span id="fallback-isbn" class="font-mono font-semibold"></span>. Try searching manually by title and author.
          </p>

          <div class="border-t border-b border-gray-200 my-6 py-4">
              <p class="text-gray-600 mb-4">Or, use your camera to identify the book from its cover:</p>
              <button type="button" id="show-cover-scan-button"
                      class="w-full bg-purple-600 text-white font-semibold py-3 px-6 rounded-lg hover:bg-purple-700 transition duration-150 ease-in-out shadow-md shadow-purple-300">
                  📷 Scan Book Cover with Camera
              </button>
          </div>

          <form id="manual-search-form" class="space-y-4">
              <div>
                  <label for="search-title" class="block text-left text-sm font-medium text-gray-700 mb-1">Title</label>
                  <input type="text" id="search-title" 
                         class="w-full p-3 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
              </div>
              <div>
                  <label for="search-author" class="block text-left text-sm font-medium text-gray-700 mb-1">Author</label>
                  <input type="text" id="search-author" 
                         class="w-full p-3 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
              </div>
              <div>
                  <label for="search-isbn" class="block text-left text-sm font-medium text-gray-700 mb-1">ISBN (Optional)</label>
                  <input type="text" id="search-isbn"
                         class="w-full p-3 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
              </div>
              <button type="submit" id="manual-search-button"
                      class="w-full bg-blue-600 text-white font-semibold py-3 px-6 rounded-lg hover:bg-blue-700 transition duration-150 ease-in-out shadow-md shadow-blue-300">
                  Search Manually
              </button>
              <button type="button" id="manual-cancel-button" 
                      class="w-full bg-gray-300 text-gray-800 font-semibold py-3 px-6 rounded-lg hover:bg-gray-400 transition duration-150 ease-in-out">
                  Cancel & Restart Scanner
              </button>
          </form>
          <div id="manual-search-status" class="mt-4 text-sm text-red-600 font-medium"></div>
      </div>
  </div>

  <div id="cover-scan-modal" 
       class="fixed inset-0 bg-gray-900 bg-opacity-75 z-50 hidden items-center justify-center p-4">
      <div class="bg-white p-8 rounded-xl shadow-2xl max-w-2xl w-full text-center transform transition-all duration-300 scale-95 opacity-0"
           id="cover-scan-modal-content">
          <h3 class="text-2xl font-bold text-gray-800 mb-4">
              Scan Book Cover
          </h3>
          <p class="text-gray-700 mb-6">
              Position the book's cover in the frame and click "Capture & Analyze".
          </p>
          <div id="cover-scanner-container" class="relative w-full max-w-lg mx-auto rounded-lg overflow-hidden shadow-lg mb-4">
              <video id="cover-video" class="w-full h-auto display-block"></video>
          </div>
          <div id="cover-scan-status" class="mt-4 text-sm text-blue-600 font-medium"></div>
          <div class="flex flex-wrap justify-center gap-4 mt-4">
              <button id="capture-cover-button"
                      class="bg-green-600 text-white font-semibold py-3 px-6 rounded-lg hover:bg-green-700 transition duration-150 ease-in-out shadow-md shadow-green-300">
                  Capture & Analyze
              </button>
              <button id="cover-scan-cancel-button" 
                      class="bg-gray-300 text-gray-800 font-semibold py-3 px-6 rounded-lg hover:bg-gray-400 transition duration-150 ease-in-out">
                  Cancel
              </button>
          </div>
      </div>
  </div>

  <div id="manual-entry-modal" 
       class="fixed inset-0 bg-gray-900 bg-opacity-75 z-50 hidden items-center justify-center p-4">
      <div class="bg-white p-8 rounded-xl shadow-2xl max-w-md w-full text-center transform transition-all duration-300 scale-95 opacity-0"
           id="manual-entry-modal-content">
          <h3 class="text-2xl font-bold text-gray-800 mb-4">
              Manual Entry & EPUB Generation
          </h3>
          <p class="text-gray-700 mb-6">
              Enter the book details below to generate an EPUB file.
          </p>

          <form id="manual-entry-form" class="space-y-4">
              <div>
                  <label for="entry-title" class="block text-left text-sm font-medium text-gray-700 mb-1">Title *</label>
                  <input type="text" id="entry-title" required
                         class="w-full p-3 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
              </div>
              <div>
                  <label for="entry-author" class="block text-left text-sm font-medium text-gray-700 mb-1">Author *</label>
                  <input type="text" id="entry-author" required
                         class="w-full p-3 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
              </div>
              <div>
                  <label for="entry-isbn" class="block text-left text-sm font-medium text-gray-700 mb-1">ISBN (Optional)</label>
                  <input type="text" id="entry-isbn"
                         class="w-full p-3 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
              </div>
              <div>
                  <label for="entry-publisher" class="block text-left text-sm font-medium text-gray-700 mb-1">Publisher (Optional)</label>
                  <input type="text" id="entry-publisher"
                         class="w-full p-3 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
              </div>
              <div>
                  <label for="entry-published-date" class="block text-left text-sm font-medium text-gray-700 mb-1">Publication Year (Optional)</label>
                  <input type="text" id="entry-published-date"
                         class="w-full p-3 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500" placeholder="e.g., 2023">
              </div>
              <button type="submit" id="entry-generate-button"
                      class="w-full bg-green-600 text-white font-semibold py-3 px-6 rounded-lg hover:bg-green-700 transition duration-150 ease-in-out shadow-md shadow-green-300">
                  Generate EPUB
              </button>
              <button type="button" id="entry-cancel-button" 
                      class="w-full bg-gray-300 text-gray-800 font-semibold py-3 px-6 rounded-lg hover:bg-gray-400 transition duration-150 ease-in-out">
                  Cancel
              </button>
          </form>
          <div id="manual-entry-status" class="mt-4 text-sm text-red-600 font-medium"></div>
      </div>
  </div>

  <script>
    const ZXing = window.ZXing;
    const codeReader = new ZXing.BrowserMultiFormatReader();
    let selectedDeviceId;

    let currentMetadata = {};

    const videoElement = document.getElementById('video');
    const statusElement = document.getElementById('status');
    const resultElement = document.getElementById('result');
    const cameraSelect = document.getElementById('camera-select');
    const scanOverlay = document.getElementById('scan-overlay');

    // Main control buttons
    const toggleScanButton = document.getElementById('toggle-scan-button');
    const mainManualLookupButton = document.getElementById('main-manual-lookup-button');
    const mainScanCoverButton = document.getElementById('main-scan-cover-button');
    const mainManualEntryButton = document.getElementById('main-manual-entry-button');
    
    // Modals and form elements
    const confirmationModal = document.getElementById('confirmation-modal');
    const confirmationModalContent = document.getElementById('confirmation-modal-content');
    const manualSearchModal = document.getElementById('manual-search-modal');
    const manualSearchModalContent = document.getElementById('manual-search-modal-content');
    const selectionModal = document.getElementById('selection-modal');
    const selectionModalContent = document.getElementById('selection-modal-content');
    const selectionOptions = document.getElementById('selection-options');
    const selectionCancelButton = document.getElementById('selection-cancel-button');
    const confirmButton = document.getElementById('confirm-button');
    const cancelButton = document.getElementById('cancel-button');
    const confirmationManualEntryButton = document.getElementById('confirmation-manual-entry-button');
    const searchAgainButton = document.getElementById('search-again-button');
    const manualSearchForm = document.getElementById('manual-search-form');
    const manualCancelButton = document.getElementById('manual-cancel-button');
    const manualSearchButton = document.getElementById('manual-search-button');
    const manualSearchStatus = document.getElementById('manual-search-status');

    // Cover Scan Modal Elements
    const coverScanModal = document.getElementById('cover-scan-modal');
    const coverScanModalContent = document.getElementById('cover-scan-modal-content');
    const showCoverScanButton = document.getElementById('show-cover-scan-button');
    const coverVideoElement = document.getElementById('cover-video');
    const captureCoverButton = document.getElementById('capture-cover-button');
    const coverScanCancelButton = document.getElementById('cover-scan-cancel-button');
    const coverScanStatus = document.getElementById('cover-scan-status');

    // Manual Entry Modal Elements
    const manualEntryModal = document.getElementById('manual-entry-modal');
    const manualEntryModalContent = document.getElementById('manual-entry-modal-content');
    const manualEntryForm = document.getElementById('manual-entry-form');
    const entryGenerateButton = document.getElementById('entry-generate-button');
    const entryCancelButton = document.getElementById('entry-cancel-button');
    const manualEntryStatus = document.getElementById('manual-entry-status');

    let currentFallbackISBN = ''; // Stores the ISBN that failed initial lookup
    let coverStream = null; // To hold the stream for the cover scanner
    let isScanning = false; // To track barcode scanner state
    let isBarcodeScanFlow = false; // To track if the flow started with a barcode scan

    // --- Utility Functions ---

    function stopScanning() {
        codeReader.reset();
        isScanning = false;
        toggleScanButton.textContent = 'Start Barcode Scan';
        toggleScanButton.classList.remove('bg-red-600', 'hover:bg-red-700');
        toggleScanButton.classList.add('bg-blue-600', 'hover:bg-blue-700');
        updateStatus('Scanner stopped.', false);
    }


    function stopCoverScanStream() {
        if (coverStream) {
            coverStream.getTracks().forEach(track => track.stop());
            coverStream = null;
            coverVideoElement.srcObject = null;
        }
    }



    function updateStatus(message, isScanning = true) {
      statusElement.textContent = message;
      statusElement.className = `mt-4 p-3 rounded-lg text-center font-medium transition duration-300 ease-in-out ${
        isScanning ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800'
      }`;
      if (isScanning) {
          scanOverlay.classList.add('pulsing');
      } else {
          scanOverlay.classList.remove('pulsing');
      }
    }

    function logResult(message) {
        const timestamp = new Date().toLocaleTimeString();
        resultElement.textContent = `[${timestamp}] ${message}\n` + resultElement.textContent;
    }

    function showModal(modal, content) {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        // Trigger transition for scale/opacity
        setTimeout(() => {
            content.classList.remove('opacity-0', 'scale-95');
            content.classList.add('opacity-100', 'scale-100');
        }, 10);
    }

    function hideModal(modal, content, onHidden) {
        content.classList.remove('opacity-100', 'scale-100');
        content.classList.add('opacity-0', 'scale-95');
        // Hide completely after transition
        setTimeout(() => {
            modal.classList.remove('flex');
            modal.classList.add('hidden');
            if (onHidden) onHidden();
        }, 300);
    }
    
    function restartScannerAfterDelay(delay = 300) {
        if (isBarcodeScanFlow) {
            logResult('Processing complete. Restarting scan shortly...');
            updateStatus(`Restarting scan in ${delay / 1000} seconds...`, false);
            setTimeout(startScanning, delay);
        } else {
            logResult('Process complete. Ready for next action.');
            updateStatus('Ready for next action.', false);
        }
    }

    function showSelectionModal(options) {
        selectionOptions.innerHTML = ''; // Clear previous options
        options.forEach(option => {
            const optionDiv = document.createElement('div');
            optionDiv.className = 'p-4 border rounded-lg text-center';
            if (option.source === 'Gemini Vision') {
                optionDiv.classList.add('border-purple-500', 'border-2');
            }

            const subtitleHTML = option.subtitle ? `<p class="text-sm text-gray-500">${option.subtitle}</p>` : '';
            optionDiv.innerHTML = `
                <img src="${option.cover_url || 'https://placehold.co/150x225/e5e7eb/333?text=NO+COVER'}" alt="Cover for ${option.title}" class="cover-image mx-auto">
                <h4 class="font-semibold">${option.title}</h4>
                ${subtitleHTML}
                <p class="text-sm text-gray-600">${option.author}</p>
                <p class="text-xs text-gray-500 mt-2">Source: ${option.source}</p>
                <button class="mt-4 bg-blue-600 text-white font-semibold py-2 px-4 rounded-lg hover:bg-blue-700 transition duration-150 ease-in-out">Select</button>
            `;
            optionDiv.querySelector('button').addEventListener('click', () => {
                hideModal(selectionModal, selectionModalContent, null);
                showConfirmationModal(option);
            });
            selectionOptions.appendChild(optionDiv);
        });
        showModal(selectionModal, selectionModalContent);
    }

    // --- Cover Scan Handlers ---

    function showCoverScanModal() {
        const capturedImage = document.getElementById('captured-cover-image');
        if (capturedImage) capturedImage.style.display = 'none';
        coverVideoElement.style.display = 'block';

        hideModal(manualSearchModal, manualSearchModalContent, null);
        showModal(coverScanModal, coverScanModalContent);
        coverScanStatus.textContent = 'Starting camera...';

        const selectedDeviceId = cameraSelect.value;
        if (!selectedDeviceId) {
            coverScanStatus.textContent = 'No camera selected.';
            return;
        }

        const constraints = { video: { deviceId: selectedDeviceId } };

        navigator.mediaDevices.getUserMedia(constraints).then(stream => {
            coverStream = stream;
            coverVideoElement.srcObject = stream;
            coverVideoElement.play();
            coverScanStatus.textContent = 'Position the cover and capture.';
        }).catch(err => {
            console.error('Cover scan camera error:', err);
            coverScanStatus.textContent = `Error starting camera: ${err.message}`;
        });
    }

    async function handleCoverCapture() {
        captureCoverButton.disabled = true;
        captureCoverButton.textContent = 'Analyzing...';
        coverScanStatus.textContent = 'Capturing image and sending to server...';

        const canvas = document.createElement('canvas');
        canvas.width = coverVideoElement.videoWidth;
        canvas.height = coverVideoElement.videoHeight;
        const context = canvas.getContext('2d');
        context.drawImage(coverVideoElement, 0, 0, canvas.width, canvas.height);

        const imageDataUrl = canvas.toDataURL('image/jpeg', 0.9);

        // Hide video and show captured image
        coverVideoElement.style.display = 'none';
        let capturedImage = document.getElementById('captured-cover-image');
        if (!capturedImage) {
            capturedImage = document.createElement('img');
            capturedImage.id = 'captured-cover-image';
            capturedImage.className = 'w-full h-auto display-block';
            document.getElementById('cover-scanner-container').appendChild(capturedImage);
        }
        capturedImage.src = imageDataUrl;
        capturedImage.style.display = 'block';

        // Stop the camera stream now that we have the image
        stopCoverScanStream();

        try {
            logResult('Attempting cover analysis with Gemini...');
            const response = await fetch('index.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'gemini_cover_search',
                    image_data: imageDataUrl,
                    isbn_fallback: currentFallbackISBN
                })
            });

            const data = await response.json();

            if (data.success && data.requires_selection) {
                hideModal(coverScanModal, coverScanModalContent, () => {
                    showSelectionModal(data.options);
                });
            } else if (data.success && data.requires_confirmation) {
                hideModal(coverScanModal, coverScanModalContent, () => {
                    showConfirmationModal(data.metadata);
                });
            } else {
                coverScanStatus.textContent = data.message || 'Analysis failed. Please try again or enter manually.';
            }

        } catch (error) {
            console.error('Cover scan analysis error:', error);
            coverScanStatus.textContent = 'A network error occurred during analysis.';
        } finally {
            captureCoverButton.disabled = false;
            captureCoverButton.textContent = 'Capture & Analyze';
            // Stream is already stopped, no need to call it again here.
        }
    }


        // --- Manual Search Handlers ---
        function showManualSearchModal(isbn = null) {
            const fallbackContainer = document.getElementById('manual-search-modal-content').querySelector('p');
            if (isbn) {
                currentFallbackISBN = isbn;
                document.getElementById('fallback-isbn').textContent = isbn;
                fallbackContainer.style.display = 'block';
            } else {
                currentFallbackISBN = '';
                fallbackContainer.style.display = 'none';
            }

            manualSearchStatus.textContent = '';
            document.getElementById('search-title').value = '';
            document.getElementById('search-author').value = '';
            document.getElementById('search-isbn').value = isbn || '';

            showModal(manualSearchModal, manualSearchModalContent);
        }

    async function handleManualSearch(event) {
        event.preventDefault();
        manualSearchStatus.textContent = '';
        manualSearchButton.disabled = true;
        manualSearchButton.textContent = 'Searching...';

        const title = document.getElementById('search-title').value.trim();
        const author = document.getElementById('search-author').value.trim();
        const isbn = document.getElementById('search-isbn').value.trim();

        try {
            if (isbn) {
                logResult(`Attempting manual search for ISBN: ${isbn}`);
                hideModal(manualSearchModal, manualSearchModalContent, null);
                await processISBNOnServer(isbn);
            } else if (title && author) {
                logResult(`Attempting manual search for: ${title} by ${author}`);
                const response = await fetch('index.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'manual_search',
                        title: title,
                        author: author,
                        isbn_fallback: currentFallbackISBN
                    })
                });

                const data = await response.json();

                if (data.success && data.requires_selection) {
                    hideModal(manualSearchModal, manualSearchModalContent, null);
                    showSelectionModal(data.options);
                } else if (data.success && data.requires_confirmation) {
                    hideModal(manualSearchModal, manualSearchModalContent, null);
                    showConfirmationModal(data.metadata);
                } else {
                    manualSearchStatus.textContent = data.message || 'Manual search failed. Try different keywords.';
                }
            } else {
                manualSearchStatus.textContent = 'Please enter an ISBN or both a Title and an Author.';
            }
        } catch (error) {
            console.error('Manual search error:', error);
            manualSearchStatus.textContent = 'A network error occurred during manual search.';
        } finally {
            manualSearchButton.disabled = false;
            manualSearchButton.textContent = 'Search Manually';
        }
    }

    // Manual Entry Functions
    function showManualEntryModal() {
        // Clear form fields
        document.getElementById('entry-title').value = '';
        document.getElementById('entry-author').value = '';
        document.getElementById('entry-isbn').value = '';
        document.getElementById('entry-publisher').value = '';
        document.getElementById('entry-published-date').value = '';
        manualEntryStatus.textContent = '';
        
        // Show modal
        showModal(manualEntryModal, manualEntryModalContent);
    }

    async function handleManualEntry(event) {
        event.preventDefault();
        
        const title = document.getElementById('entry-title').value.trim();
        const author = document.getElementById('entry-author').value.trim();
        const isbn = document.getElementById('entry-isbn').value.trim();
        const publisher = document.getElementById('entry-publisher').value.trim();
        const publishedDate = document.getElementById('entry-published-date').value.trim();

        // Validate required fields
        if (!title || !author) {
            manualEntryStatus.textContent = 'Title and Author are required.';
            return;
        }

        try {
            entryGenerateButton.disabled = true;
            entryGenerateButton.textContent = 'Generating...';
            manualEntryStatus.textContent = '';

            // Create metadata object from manual entry
            const metadata = {
                title: title,
                author: author,
                isbn: isbn || null,
                publisher: publisher || null,
                publishedDate: publishedDate || null,
                description: '',
                coverUrl: null,
                source: 'Manual Entry'
            };

            // Send to server for EPUB generation
            const response = await fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    action: 'confirm_epub_creation',
                    metadata: metadata
                })
            });

            const data = await response.json();

            if (data.success) {
                logResult(`SUCCESS: ${data.message}. File: ${data.filename}`);
                // Close modal and restart appropriate flow
                hideModal(manualEntryModal, manualEntryModalContent, null);
                restartScannerAfterDelay(1500);
            } else {
                manualEntryStatus.textContent = data.message || 'Failed to generate EPUB. Please try again.';
            }
        } catch (error) {
            console.error('Manual entry error:', error);
            manualEntryStatus.textContent = 'A network error occurred. Please try again.';
        } finally {
            entryGenerateButton.disabled = false;
            entryGenerateButton.textContent = 'Generate EPUB';
        }
    }

    // --- Server Communication ---

    async function processISBNOnServer(isbn) {
      updateStatus('Processing ISBN. Please wait...', false);
      logResult(`Attempting API lookup for ISBN: ${isbn}`);

      try {
        const response = await fetch('index.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ isbn: isbn, action: 'search' })
        });

        const data = await response.json();

        if (data.success && data.requires_selection) {
            logResult('SUCCESS: Multiple options found from API.');
            showSelectionModal(data.options);
        } else if (data.success && data.requires_confirmation) {
            // Success: Show confirmation modal
            logResult('SUCCESS: Metadata found from API.');
            showConfirmationModal(data.metadata);
        } else if (data.requires_manual_search) {
            // Failure: Fallback to manual search
            logResult(`FAILURE: ${data.message}`);
            showManualSearchModal(isbn);
        } else {
            // Complete failure / error
            logResult(`ERROR: ${data.message || 'Unknown server error.'}`);
            restartScannerAfterDelay();
        }

      } catch (error) {
        logResult(`NETWORK ERROR: ${error.message}. Restarting scan.`);
        console.error('Network Error:', error);
        restartScannerAfterDelay();
      }
    }

    async function confirmCreation(metadata) {
        const updatedMetadata = { ...metadata };
        updatedMetadata.title = document.getElementById('modal-title-input').value.trim();
        updatedMetadata.author = document.getElementById('modal-author-input').value.trim();
        updatedMetadata.subtitle = document.getElementById('modal-subtitle-input').value.trim();
        updatedMetadata.isbn = document.getElementById('modal-isbn-input').value.trim();

        confirmButton.disabled = true;
        confirmButton.textContent = 'Generating...';
        logResult(`Confirmation received. Generating EPUB for ${updatedMetadata.title}...`);

        try {
            const response = await fetch('index.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'confirm_epub_creation', metadata: updatedMetadata })
            });

            const data = await response.json();

            if (data.success) {
                logResult(`SUCCESS: ${data.message}. File: ${data.filename}`);
                // In a real environment, you'd trigger a download here.
                // For this embedded environment, we just show the final log.
            } else {
                logResult(`EPUB GENERATION FAILED: ${data.message}`);
            }

        } catch (error) {
            logResult(`NETWORK ERROR during generation: ${error.message}`);
            console.error('Generation Error:', error);
        } finally {
            hideModal(confirmationModal, confirmationModalContent, null);
            confirmButton.disabled = false;
            confirmButton.textContent = 'Generate EPUB';
            restartScannerAfterDelay();
        }
    }

    async function handleSearchAgain() {
        const title = document.getElementById('modal-title-input').value.trim();
        const author = document.getElementById('modal-author-input').value.trim();
        const isbn = document.getElementById('modal-isbn-input').value.trim();

        hideModal(confirmationModal, confirmationModalContent, null);

        if (isbn) {
            await processISBNOnServer(isbn);
        } else if (title && author) {
            // We need to manually trigger the manual search logic
            // This is a simplified version of handleManualSearch
            logResult(`Attempting manual search for: ${title} by ${author}`);
            try {
                const response = await fetch('index.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'manual_search',
                        title: title,
                        author: author,
                        isbn_fallback: currentFallbackISBN
                    })
                });
                const data = await response.json();
                if (data.success && data.requires_selection) {
                    showSelectionModal(data.options);
                } else if (data.success && data.requires_confirmation) {
                    showConfirmationModal(data.metadata);
                } else {
                    logResult(`ERROR: ${data.message || 'Unknown server error.'}`);
                    restartScannerAfterDelay();
                }
            } catch (error) {
                logResult(`NETWORK ERROR: ${error.message}. Restarting scan.`);
                console.error('Network Error:', error);
                restartScannerAfterDelay();
            }
        } else {
            logResult('Not enough information to search again. Please provide an ISBN or both a title and an author.');
            restartScannerAfterDelay();
        }
    }

    function showConfirmationModal(metadata) {
      currentMetadata = metadata;

      document.getElementById('modal-title-input').value = metadata.title || '';
      document.getElementById('modal-author-input').value = metadata.author || '';
      document.getElementById('modal-isbn-input').value = metadata.isbn || '';
      document.getElementById('modal-source').textContent = metadata.source;

      const subtitleContainer = document.getElementById('modal-subtitle-container');
      const subtitleInput = document.getElementById('modal-subtitle-input');
      if (metadata.subtitle) {
          subtitleInput.value = metadata.subtitle;
          subtitleContainer.style.display = 'block';
      } else {
          subtitleInput.value = '';
          subtitleContainer.style.display = 'none';
      }
      
      const coverImg = document.getElementById('modal-cover');
      if (metadata.cover_url) {
          coverImg.src = metadata.cover_url;
          coverImg.onerror = () => coverImg.src = 'https://placehold.co/150x225/e5e7eb/333?text=NO+COVER';
      } else {
          coverImg.src = 'https://placehold.co/150x225/e5e7eb/333?text=NO+COVER';
      }

      showModal(confirmationModal, confirmationModalContent);
    }

    // --- Scanning Logic ---

    function toggleScanning() {
        if (isScanning) {
            stopScanning();
        } else {
            startScanning();
        }
    }

    function startScanning() {
      isBarcodeScanFlow = true;
      const selectedDeviceId = cameraSelect.value;
      if (!selectedDeviceId) {
          updateStatus('No camera selected.', false);
          return;
      }

      isScanning = true;
      toggleScanButton.textContent = 'Stop Barcode Scan';
      toggleScanButton.classList.remove('bg-blue-600', 'hover:bg-blue-700');
      toggleScanButton.classList.add('bg-red-600', 'hover:bg-red-700');

      const constraints = { video: { deviceId: selectedDeviceId } };
      codeReader.decodeFromConstraints(constraints, 'video', async (result, err) => {
        if (result) {
          // **STEP 1: Stop scanning on successful read**
          stopScanning();
          const isbn = result.text;
          logResult(`Barcode found: ${isbn}`);

          // **STEP 2: Send ISBN to server for processing**
          await processISBNOnServer(isbn); 
              
          // NOTE: Restarting is handled inside processISBNOnServer or the modal handlers
          
        } else if (err && !(err instanceof ZXing.NotFoundException)) {
          console.error(err)
          updateStatus('Error during scanning.');
          logResult(`Scanning Error: ${err.message || 'Unknown error'}`);
          stopScanning(); // Stop on error
        }
      });
      updateStatus('Scanning for ISBN barcode...', true);
      logResult(`Started continuous decode from camera with id ${selectedDeviceId}`);
    }


    // --- Initialization ---

    window.addEventListener('load', () => {
      // 1. Initialize camera devices
      codeReader.getVideoInputDevices()
        .then((videoInputDevices) => {

          let preferredDeviceId = null;

          // **PRIORITY LOGIC: Check for 'back' or 'environment' to select rear camera**
          const rearCamera = videoInputDevices.find(device => {
              const label = device.label.toLowerCase();
              return label.includes('back') || label.includes('environment');
          });

          if (rearCamera) {
              preferredDeviceId = rearCamera.deviceId;
          } else if (videoInputDevices.length > 0) {
              // Fallback to the first device found
              preferredDeviceId = videoInputDevices[0].deviceId;
          }

          // Populate the camera selection dropdown
          videoInputDevices.forEach(device => {
            const option = document.createElement('option');
            option.value = device.deviceId;
            option.text = device.label || `Camera ${cameraSelect.length + 1}`;
            cameraSelect.appendChild(option);
          });

          if (videoInputDevices.length > 0) {
            selectedDeviceId = preferredDeviceId;
            cameraSelect.value = selectedDeviceId; // Set the preferred device in the dropdown
            updateStatus('Ready for action. Select a function below.', false);
            toggleScanButton.disabled = false;
          } else {
            updateStatus('No video input devices found.', false);
          }

          // 2. Set up event listeners
          toggleScanButton.addEventListener('click', toggleScanning);
          mainManualLookupButton.addEventListener('click', () => {
              isBarcodeScanFlow = false;
              showManualSearchModal();
          });
          mainManualEntryButton.addEventListener('click', () => {
              isBarcodeScanFlow = false;
              showManualEntryModal();
          });
          mainScanCoverButton.addEventListener('click', () => {
              isBarcodeScanFlow = false;
              showCoverScanModal();
          });

          cameraSelect.addEventListener('change', (e) => {
              selectedDeviceId = e.target.value;
              if (isScanning) {
                  stopScanning();
                  startScanning();
              }
          });

          // Confirmation Modal Listeners
          confirmButton.addEventListener('click', () => confirmCreation(currentMetadata));
          cancelButton.addEventListener('click', () => {
              hideModal(confirmationModal, confirmationModalContent, null);
              restartScannerAfterDelay(100);
          });

          confirmationManualEntryButton.addEventListener('click', () => {
              hideModal(confirmationModal, confirmationModalContent, null);
              showManualEntryModal();
          });

          searchAgainButton.addEventListener('click', handleSearchAgain);

          // Manual Search Modal Listeners
          manualSearchForm.addEventListener('submit', handleManualSearch);
          manualCancelButton.addEventListener('click', () => {
              hideModal(manualSearchModal, manualSearchModalContent, null);
              restartScannerAfterDelay(100);
          });

          // Manual Entry Modal Listeners
          manualEntryForm.addEventListener('submit', handleManualEntry);
          entryCancelButton.addEventListener('click', () => {
              hideModal(manualEntryModal, manualEntryModalContent, null);
              restartScannerAfterDelay(100);
          });

          // Cover Scan Modal Listeners
          showCoverScanButton.addEventListener('click', showCoverScanModal);
          captureCoverButton.addEventListener('click', handleCoverCapture);
          coverScanCancelButton.addEventListener('click', () => {
              hideModal(coverScanModal, coverScanModalContent, () => {
                  stopCoverScanStream();
                  const capturedImage = document.getElementById('captured-cover-image');
                  if (capturedImage) capturedImage.style.display = 'none';
                  coverVideoElement.style.display = 'block';
                  restartScannerAfterDelay(100);
              });
          });

          // Selection Modal Listener
          selectionCancelButton.addEventListener('click', () => {
              hideModal(selectionModal, selectionModalContent, null);
              restartScannerAfterDelay(100);
          });

        })
        .catch((err) => {
          console.error(err)
          updateStatus(`Initialization Error: ${err.message || 'Check camera permissions.'}`);
        })
    });
  </script>

</body>

</html>
