<?php
function loadEnv($filePath = '.env')
{
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
$url = "https://api.github.com/orgs/GEWIS/repos?per_page=100";
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
$repos = json_decode($response, true);
$activities = array();
foreach ($repos as $repo) {
    echo $repo['name'] . "<br>";
    $name = $repo['name'];
    curl_setopt($ch, CURLOPT_URL, "https://api.github.com/repos/GEWIS/$name/activity");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Accept: application/vnd.github+json",
        "Authorization: Bearer $accessToken",
        "X-GitHub-Api-Version: 2022-11-28",
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:133.0) Gecko/20100101 Firefox/133.0"
    ]);
    $activity = json_decode(curl_exec($ch), true);
    foreach ($activity as $activityItem) {
        $simplifiedActivity = array();
        $simplifiedActivity['id'] = $activityItem['id'];
        $simplifiedActivity['repo'] = $repo['name'];
        $simplifiedActivity['timestamp'] = $activityItem['timestamp'];
        $simplifiedActivity['actor'] = $activityItem['actor']['login'];
        $simplifiedActivity['gravatar'] = $activityItem['actor']['avatar_url'];
        $activities[] = $simplifiedActivity;
    }
}
// Sort activities by timestamp (newest to oldest)
usort($activities, function ($a, $b) {
    // Compare the 'timestamp' fields, sorting from newest to oldest
    return strtotime($b['timestamp']) - strtotime($a['timestamp']);
});

//foreach ($activities as $activity) {
//    $id = $activity['id'];
//    $repo = $activity['repo'];
//    curl_setopt($ch, CURLOPT_URL, "https://api.github.com/repos/GEWIS/$repo/issues/events/$id");
//    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
//    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
//    curl_setopt($ch, CURLOPT_HTTPHEADER, [
//        "Accept: application/vnd.github+json",
//        "Authorization: Bearer $accessToken",
//        "X-GitHub-Api-Version: 2022-11-28",
//        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:133.0) Gecko/20100101 Firefox/133.0"
//    ]);
//    $response = curl_exec($ch);
//    echo $response;
//    curl_close($ch);
//    foreach ($repos as $repo) {
//        echo $repo['full_name'] . "<br>";
//    }
//}

$commit_count = array();
$since = date('Y-m-d\TH:i:s\Z', strtotime('-30 days'));
foreach ($repos as $repo) {
    $repo_name = $repo['full_name'];
    $url = "https://api.github.com/repos/$repo_name/commits?since=$since";
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
    $commits = json_decode($response, true);
    curl_close($ch);

    foreach ($commits as $commit) {
        $author = isset($commit['author']['login']) ? $commit['author']['login'] : '';
        if ($author == 'dependabot[bot]') {
            continue;
        }
        $count = isset($commit_count[$author]) ? $commit_count[$author] : 0;
        $count += 1;
        $commit_count[$author] = $count;
    }
}

arsort($commit_count);

//$top_five = array_slice($commit_count, 0, 5);

foreach ($commit_count as $author => $commitCount) {
    echo "Author: $author, Commits: $commitCount<br>";
}
?>