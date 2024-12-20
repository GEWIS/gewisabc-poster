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

function sendRequest($ch, $url)
{
    curl_setopt($ch, CURLOPT_URL, $url);

    $response = curl_exec($ch);
    return json_decode($response, true);
}

// Load the .env file
loadEnv();
error_reporting(E_ALL);
ini_set('display_errors', 1);

$ch = curl_init();
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Accept: application/vnd.github+json",
    "Authorization: Bearer " . getenv('ACCESS_TOKEN'),
    "X-GitHub-Api-Version: 2022-11-28",
    "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:133.0) Gecko/20100101 Firefox/133.0"
]);

$repos = sendRequest($ch, "https://api.github.com/orgs/GEWIS/repos?per_page=25&sort=pushed");

//$activities = array();
//foreach ($repos as $repo) {
//    echo $repo['name'] . "<br>";
//    $name = $repo['name'];
//    $activity = sendRequest($ch, "https://api.github.com/repos/GEWIS/$name/activity");
//    foreach ($activity as $activityItem) {
//        $simplifiedActivity = array();
//        $simplifiedActivity['id'] = $activityItem['id'];
//        $simplifiedActivity['repo'] = $repo['name'];
//        $simplifiedActivity['timestamp'] = $activityItem['timestamp'];
//        $simplifiedActivity['actor'] = $activityItem['actor']['login'];
//        $simplifiedActivity['gravatar'] = $activityItem['actor']['avatar_url'];
//        $activities[] = $simplifiedActivity;
//    }
//}
//// Sort activities by timestamp (newest to oldest)
//usort($activities, function ($a, $b) {
//    // Compare the 'timestamp' fields, sorting from newest to oldest
//    return strtotime($b['timestamp']) - strtotime($a['timestamp']);
//});
//
//foreach ($activities as $activity) {
//    $id = $activity['id'];
//    $repo = $activity['repo'];
//    $response = sendRequest($ch, "https://api.github.com/repos/GEWIS/$repo/issues/events/$id");
//    echo $response;
//    foreach ($repos as $repo) {
//        echo $repo['full_name'] . "<br>";
//    }
//}

$contributors = array();
$since = date('Y-m-d\TH:i:s\Z', strtotime('-14 days'));
foreach ($repos as $repo) {
    $repo_name = $repo['name'];
    $commits = sendRequest($ch, "https://api.github.com/repos/GEWIS/$repo_name/commits?since=$since");

    foreach ($commits as $commit) {
        $author = $commit['author']['login'] ?? '';
        if ($author == 'dependabot[bot]') {
            continue;
        }
        $count = $contributors[$author]['count'] ?? 0;
        if (!in_array($repo_name, $contributors[$author]['repos'] ?? [])) {
            $contributors[$author]['repos'][] = $repo_name;
        }
        $count += 1;
        $contributors[$author]['count'] = $count;
        $contributors[$author]['image'] = $commit['author']['avatar_url'];
    }
}

uasort($contributors, function ($a, $b) {
    return $b['count'] <=> $a['count'];
});

curl_close($ch);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GitHub Commit Report</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<?php
foreach ($contributors as $author => $contributor) {
    $imageUrl = $contributor['image'];
    $repoList = implode(', ', $contributor['repos']);
    echo "
<div class='author'>
    <img src='$imageUrl' alt='Avatar of $author' class='avatar'>
    <h2 class='author-name'><span class='highlight'>$author</span></h2>
    <div class='info'>
        <p class='commit-count'><strong>" . $contributor['count'] . "</strong> Contributions</p>
        <p class='contributed-repos'>Contributed to: </><i>" . $repoList . "</i></p>
    </div>
</div>";
}
?>

</body>
</html>