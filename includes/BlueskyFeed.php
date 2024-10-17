<?php

class BlueskyFeed {
    /**
     * Register the 'blueskyfeed' parser function.
     */
    public static function onParserFirstCallInit( $parser ) {
        // Register the parser function hook for the 'blueskyfeed' magic word
        $parser->setFunctionHook( 'blueskyfeed', [ self::class, 'fetchBlueskyFeed' ] );
    }

    /**
     * Main function to handle the fetching of the Bluesky feed.
     */
    public static function fetchBlueskyFeed( $parser ) {
        // Get all the arguments passed to the parser function
        $params = func_get_args();
        array_shift($params);  // Remove the first argument ($parser)

        // Define default values for all parameters
        $defaults = [
            'handle' => '',
            'limit' => 5,
            'textSize' => '14px',
            'height' => '500px'
        ];

        // Parse the parameters (handle=..., limit=..., textSize=..., height=...)
        $args = [];
        foreach ( $params as $index => $param ) {
            // Split the parameter into key-value pairs (key=value)
            if ( strpos( $param, '=' ) !== false ) {
                list( $key, $value ) = explode( '=', $param, 2 );
                $args[ trim( $key ) ] = trim( $value );
            } else {
                // If no '=' is found, treat as positional parameter
                if ($index === 0) {
                    $args['handle'] = $param;  // First positional argument is the handle
                } elseif ($index === 1) {
                    $args['limit'] = $param;  // Second positional argument is the limit
                } elseif ($index === 2) {
                    $args['textSize'] = $param;  // Third positional argument is the text size
                } elseif ($index === 3) {
                    $args['height'] = $param;  // Fourth positional argument is the height
                }
            }
        }

        // Merge with defaults (allows partial named arguments)
        $args = array_merge( $defaults, $args );

        // Extract individual parameters from the $args array
        $handle = $args['handle'];
        $limit = (int)$args['limit'];
        $textSize = $args['textSize'];
        $height = $args['height'];
        
        // Load the App password from Localsettings.php
        global $egBlueskyAppKey;
        $config = $egBlueskyAppKey;
                
        // Check if the necessary parameters are provided
        if (empty($handle) || empty($config)) {
            return wfMessage('blueskyfeed-error-missing-handle-password')->text();
        }

        // Step 1: Resolve DID for the handle
        $did = self::resolveDID($handle);
        if (!$did) {
            return wfMessage('blueskyfeed-error-did-resolve', $handle)->text();
        }

        // Step 2: Generate the API key (access token) using the app password and DID
        $api_key = self::generateAPIKey($did, $config);
        if (!$api_key) {
            return wfMessage('blueskyfeed-error-api-key', $handle)->text();
        }

        // Step 3: Fetch the Bluesky feed using the generated API key and limit
        $feed = self::getBlueskyFeed($did, (int)$limit, $api_key, $textSize, $height);

        if (!$feed) {
            return wfMessage('blueskyfeed-error-fetch-feed', $handle)->text();
        }

        // Return the feed content
        return [
            $feed, // Content to be rendered
            'isHTML' => true // Indicates the content contains HTML
        ];
    }

    /**
     * Resolve DID for the given handle using Bluesky's API.
     */
    private static function resolveDID($handle) {
        $api_url = "https://bsky.social/xrpc/com.atproto.identity.resolveHandle";
        $params = ['handle' => $handle];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url . '?' . http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);

        if (!$response) {
            return false;
        }

        // Log the DID response for debugging
        error_log("DID Response: " . print_r($response, true));
        
        $data = json_decode($response, true);
        return $data['did'] ?? false;
    }

    /**
     * Generate an API key (JWT) using the DID and app password.
     */
    private static function generateAPIKey($did, $app_password) {
        $api_url = 'https://bsky.social/xrpc/com.atproto.server.createSession';
        $post_data = [
            'identifier' => $did,
            'password' => $app_password
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);
        //echo $response;

        // Log the API Key (JWT) response for debugging
        error_log("API Key Response: " . print_r($response, true));

        if (!$response) {
            return false;
        }

        $data = json_decode($response, true);
        return $data['accessJwt'] ?? false;  // Return the JWT (API key)
    }

    /**
     * Fetch the Bluesky feed using the resolved DID, limit, and API key.
     */
    private static function getBlueskyFeed($did, $limit, $api_key, $textSize, $height) {
        $api_url = "https://bsky.social/xrpc/app.bsky.feed.getAuthorFeed";
        $params = [
            'actor' => $did,
            'limit' => $limit
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url . '?' . http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $api_key"
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        if (!$response) {
            return wfMessage('blueskyfeed-error-fetch-data')->text();
       }
        

        // Decode the JSON response
        $data = json_decode($response, true);

        // Check if the feed exists and is valid
        if (!isset($data['feed']) || !is_array($data['feed'])) {
            return wfMessage('blueskyfeed-error-invalid-feed')->text();
        }
        
        // Start a scrollable container for the feed
        $output .= "<div style='max-height: $height; overflow-y: auto; padding-right: 10px;'>";
        $counter = 0;

        // Iterate over each post in the feed
        foreach ($data['feed'] as $item) {
            // Extract the text, createdAt
            $text = isset($item['post']['record']['text']) ? $item['post']['record']['text'] : 'No text available';
            $createdAt = isset($item['post']['record']['createdAt']) ? $item['post']['record']['createdAt'] : 'Unknown date';
             
             // Extract only the date from createdAt (YYYY-MM-DD)
             $dateOnly = substr($createdAt, 0, 10);

            // Extract avatar and handle
            $avatar = isset($item['post']['author']['avatar']) ? $item['post']['author']['avatar'] : null;
            $handle = isset($item['post']['author']['handle']) ? $item['post']['author']['handle'] : 'Unknown user';
            $displayName = isset($item['post']['author']['displayName']) ? $item['post']['author']['displayName'] : $handle;

            // Alternate background colors
            $bgColor = ($counter % 2 == 0) ? '#ffffff' : '#f0f0f0';
            $counter++;

            // Start post container with alternating background color
            $output .= "<div style='background-color: $bgColor; padding: 10px; margin-bottom: 10px; font-size: $textSize;'>";
            
            // Display avatar and handle
            if ($avatar) {
                $output .= "<img src='" . htmlspecialchars($avatar) . "' alt='Avatar' style='width: 30px; height: 30px; border-radius: 50%; float: left; margin-right: 5px;'>";
            }
            $output .= "<strong>" . htmlspecialchars($displayName) . "</strong> <span style='color: #888; font-size: 10px;'>@" . htmlspecialchars($handle) . "</span><br>";
            
            // Clear float for better layout
            $output .= "<div style='clear: both;'></div>";

            // Process and replace any links in the post text
             $facets = isset($item['post']['record']['facets']) ? $item['post']['record']['facets'] : [];
             foreach ($facets as $facet) {
                 if (isset($facet['features'][0]['$type']) && $facet['features'][0]['$type'] === 'app.bsky.richtext.facet#link') {
                     $linkUri = $facet['features'][0]['uri'];
                     $byteStart = $facet['index']['byteStart'];
                     $byteEnd = $facet['index']['byteEnd'];

                     // Replace the relevant part of the text with a clickable HTML link
                     $linkedText = substr($text, $byteStart, $byteEnd - $byteStart);
                     $text = substr_replace($text, "<a href='" . htmlspecialchars($linkUri) . "' target='_blank'>" . htmlspecialchars($linkedText) . "</a>", $byteStart, $byteEnd - $byteStart);
                }
            }
            
            // Display post text
            $output .= "<p>" . /*htmlspecialchars*/$text . "<br>";

            // Extract a possible thumb image that is posted in the skeet
            if (isset($item['post']['embed']['images'][0]['fullsize'])) {
                $fullsize = $item['post']['embed']['images'][0]['fullsize'];
                $alt = $item['post']['embed']['images'][0]['alt'];
                
                // Add the full-size image to the output
                $output .= "<img src='" . htmlspecialchars($fullsize) . "' alt='" . htmlspecialchars($alt) . "' width='200' height='auto'>";
            }

            // Display the created date (formatted to show only the date)
            $output .= "<span style='font-size: 12px; color: #888; font-style: italic; float:right;'>Posted on: " . htmlspecialchars($dateOnly) . "</span></p>";
            $output .= "</div>";
        }
        // Close the scrollable container
        $output .= "</div>";

        // Return the formatted output
        return $output;
    }
}
