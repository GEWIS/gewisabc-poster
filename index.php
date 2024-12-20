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

function humanTiming ($time)
{

    $time = time() - $time; // to get the time since that moment
    $time = ($time<1)? 1 : $time;
    $tokens = array (
        31536000 => 'year',
        2592000 => 'month',
        604800 => 'week',
        86400 => 'day',
        3600 => 'hour',
        60 => 'minute',
        1 => 'second'
    );

    foreach ($tokens as $unit => $text) {
        if ($time < $unit) continue;
        $numberOfUnits = floor($time / $unit);
        return $numberOfUnits.' '.$text.(($numberOfUnits>1)?'s':'');
    }

}

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

        $contributors[$author]['count'] = $count + 1;
        $contributors[$author]['image'] = $commit['author']['avatar_url'];
    }
}

function compareCounts($a, $b)
{
    return $b['count'] - $a['count'];
}
uasort($contributors, "compareCounts");

$contributors = array_slice($contributors, 0, 5, true);

$recentPrs = array();
foreach ($repos as $repo) {
    $repo_name = $repo['name'];
    $prs = sendRequest($ch, "https://api.github.com/repos/GEWIS/$repo_name/pulls?per_page=3&state=closed&sort=updated&direction=desc");

    foreach ($prs as $pr) {
        if (!empty($pr['merged_at'])){
            $time = strtotime($pr['merged_at']);
            if ($time > end($recentPrs) && $recentPrs) {
                continue;
            }

            $recentPrs[$time]['title'] = $pr['title'];
            $recentPrs[$time]['author'] = $pr['user']['login'];
            $recentPrs[$time]['number'] = $pr['number'];
            $recentPrs[$time]['repo'] = $pr['head']['repo']['name'];
            $recentPrs[$time]['merged_at'] = $pr['merged_at'];

            krsort($recentPrs);

            $recentPrs = array_slice($recentPrs, 0, 5, true);
        }
    }
}

curl_close($ch);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ABC GEWIS Poster</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<?php

$checkmark = "
<summary>
    <svg class='check' aria-label='8 / 8 checks OK' role='img' viewBox='0 0 16 16' width='32' height='32' data-view-component='true'>
        <path d='M13.78 4.22a.75.75 0 0 1 0 1.06l-7.25 7.25a.75.75 0 0 1-1.06 0L2.22 9.28a.751.751 0 0 1 .018-1.042.751.751 0 0 1 1.042-.018L6 10.94l6.72-6.72a.75.75 0 0 1 1.06 0Z'></path>
    </svg>
</summary>";

foreach ($recentPrs as $time => $pr) {
    echo "
<div class='pr'>
    <img src='assets/pr-merged.png' alt='PR merged icon'>
    <div class='info'>
        <h2 class='pr-title'>" . $pr['title'] . " $checkmark</h2>
        
        <p class='pr-info'>#" . $pr['number'] . " by " . $pr['author'] . " was merged into " . $pr['repo'] . " " . humanTiming($time) . " ago  •  Approved" . "</p>
    </div>
</div>";
}

foreach ($contributors as $author => $contributor) {
    $imageUrl = $contributor['image'];
    $repoList = implode(', ', $contributor['repos']);
    echo "
<div class='author'>
    <img src='$imageUrl' alt='Avatar of $author' class='avatar'>
    <h2 class='author-name'>$author</h2>
    <div class='info'>
        <p class='commit-count'><strong>" . $contributor['count'] . "</strong> Contributions</p>
        <p class='contributed-repos'>Contributed to: </><i>" . $repoList . "</i></p>
    </div>
</div>";
}
?>

</body>
</html>