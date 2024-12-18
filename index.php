<?php
function loadEnv($filePath = '.env') {
    if (!file_exists($filePath)) {
        throw new Exception("The .env file does not exist at: $filePath");
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments and empty lines
        if (strpos(trim($line), '#') === 0 || empty(trim($line))) {
            continue;
        }

        // Split the line into a key and value
        [$key, $value] = explode('=', $line, 2);

        // Remove quotes if the value is wrapped in them
        $value = trim($value);
        if (preg_match('/^["\'](.*)["\']$/', $value, $matches)) {
            $value = $matches[1];
        }

        // Store the key-value pair in $_ENV and putenv
        $_ENV[$key] = $value;
        putenv("$key=$value");
    }
}

// Load the .env file
loadEnv();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Replace with your GitHub organization and repository
$organization = "GEWIS";
$repo = "sudosos-frontend";
$accessToken = getenv('ACCESS_TOKEN');
// GitHub API URL to fetch pull requests
$url = "https://api.github.com/repos/CodeNamedRobin/git-training/commits";
// Use cURL to fetch data
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Accept: application/vnd.github+json",
    "Authorization: Bearer $accessToken",
    "X-GitHub-Api-Version: 2022-11-28",
    "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:133.0) Gecko/20100101 Firefox/133.0"
]);

$response = curl_exec($ch);
curl_close($ch);

// Decode and display the response
$pullRequests = json_decode($response, true);
if ($pullRequests) {
    print_r($pullRequests);
//    foreach ($pullRequests as $pr) {
//        if (!empty($pr['merged_at'])) { // Only show merged PRs
//            echo "{$pr['title']} by {$pr['user']['login']} (Merged on {$pr['merged_at']})<br>";
//        }
//    }
} else {
    echo "No pull requests found or an error occurred.";
}
?>
