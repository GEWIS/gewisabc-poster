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

// Function used to set all functions for curl instance
function setupCh($ch)
{
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Accept: application/vnd.github+json",
        "Authorization: Bearer " . getenv('ACCESS_TOKEN'),
        "X-GitHub-Api-Version: 2022-11-28",
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:133.0) Gecko/20100101 Firefox/133.0"
    ]);
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    curl_setopt($ch, CURLOPT_TCP_FASTOPEN, true);
    curl_setopt($ch, CURLOPT_ENCODING, 'gzip,deflate');
}

// Convert integer time display to how long ago it was
function toTimeAgo($time): string
{
    $time = time() - $time;
    $time = ($time < 1) ? 1 : $time;
    $tokens = array(
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
        return $numberOfUnits . ' ' . $text . (($numberOfUnits > 1) ? 's' : '');
    }

    return "could not convert time";
}

$contributorCount = 5;
$prCount = 3;

// Load the .env file
loadEnv();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Get all repos
$ch = curl_init("https://api.github.com/orgs/GEWIS/repos?per_page=100&sort=pushed");

setupCh($ch);

$repos = json_decode(curl_exec($ch), true);

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

// Used to get commits since a certain date
$since = date('Y-m-d\TH:i:s\Z', strtotime('-14 days'));

// Create multi curl handle
$mh = curl_multi_init();

// Create list to store curl handles
$commitChs = [];
$prChs = [];
foreach ($repos as $repo) {
    $repo_name = $repo['name'];

    // Initiate curl handles for this repo
    $commitCh = curl_init("https://api.github.com/repos/GEWIS/$repo_name/commits?since=$since");
    $prCh = curl_init("https://api.github.com/repos/GEWIS/$repo_name/pulls?per_page=$prCount&state=closed&sort=updated&direction=desc");

    // Set options for curl handles
    setupCh($commitCh);
    setupCh($prCh);

    // Add handles to multi handle
    curl_multi_add_handle($mh, $commitCh);
    curl_multi_add_handle($mh, $prCh);

    // Add handles to lists
    $commitChs[] = $commitCh;
    $prChs[] = $prCh;
}

// Execute all queries at the same time, continue when all are complete
$running = null;
do {
    curl_multi_exec($mh, $running);
} while ($running);

// Close all handles and add response to list
$commits = [];
foreach ($commitChs as $commitCh) {
    curl_multi_remove_handle($mh, $commitCh);
    $commits = array_merge($commits, json_decode(curl_multi_getcontent($commitCh), true));
}

$prs = [];
foreach ($prChs as $prCh) {
    curl_multi_remove_handle($mh, $prCh);
    $prs = array_merge($prs, json_decode(curl_multi_getcontent($prCh), true));
}

// Close multi handle
curl_multi_close($mh);

foreach ($commits as $commit) {
    $author = $commit['author']['login'];

    // Ignore commits from dependabot
    if ($author == 'dependabot[bot]') {
        continue;
    }

    $count = $contributors[$author]['count'] ?? 0;

    // Commits have no repo property but we can get it from the html_url using a regex filter
    preg_match('/github\.com\/[^\/]+\/([^\/]+)/', $commit['html_url'], $matches);
    $repo = $matches[1];

    // Only a repo to the list if it is not in the list yet
    if (!in_array($repo, $contributors[$author]['repos'] ?? [])) {
        $contributors[$author]['repos'][] = $repo;
    }

    // Only set image the first time
    if (empty($contributors[$author]['image'])) {
        $contributors[$author]['image'] = $commit['author']['avatar_url'];
    }

    $contributors[$author]['count'] = $count + 1;
}

$recentPrs = [];
foreach ($prs as $pr) {
    // If the PR has been merged
    if (!empty($pr['merged_at'])) {
        $time = strtotime($pr['merged_at']);

        // Index on time to sort on later
        $recentPrs[$time]['title'] = $pr['title'];
        $recentPrs[$time]['author'] = $pr['user']['login'];
        $recentPrs[$time]['number'] = $pr['number'];
        $recentPrs[$time]['repo'] = $pr['head']['repo']['name'] ?? '';
        $recentPrs[$time]['merged_at'] = $pr['merged_at'];
    }
}

function compareCounts($a, $b)
{
    return $b['count'] - $a['count'];
}

// Sort on the count values descending and take highest 4
uasort($contributors, "compareCounts");
$contributors = array_slice($contributors, 0, $contributorCount, true);

// Sort on key (time) values descending and take highest (most recent) 3
krsort($recentPrs);
$recentPrs = array_slice($recentPrs, 0, $prCount, true);

curl_close($ch);

// Save checkmark svg element used for PRs
$checkmark = "
<summary>
    <svg class='check' aria-label='8 / 8 checks OK' role='img' viewBox='0 0 16 16' width='32' height='32' data-view-component='true'>
        <path d='M13.78 4.22a.75.75 0 0 1 0 1.06l-7.25 7.25a.75.75 0 0 1-1.06 0L2.22 9.28a.751.751 0 0 1 .018-1.042.751.751 0 0 1 1.042-.018L6 10.94l6.72-6.72a.75.75 0 0 1 1.06 0Z'></path>
    </svg>
</summary>";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ABC GEWIS Poster</title>
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
</head>
<body>
<div class="container">
    <div class="left">
        <div class="abc-info">
            <img class="abc-logo" src='assets/abc-logo.png' alt='ABC Logo'>
            <div class="abc-text">
                <h1 class="quarter-title">Like writing software? <br> Join the ABC!</h1>
                <h2>Find us at <span class="highlight">github.com/GEWIS</span></h2>
            </div>
        </div>
        <div class="contributors">
            <h2 class="quarter-title">Top contributors across all GEWIS repositories (Last 2 weeks)</h2>
            <!--            --><?php
            //            foreach ($contributors as $author => $contributor) {
            //                $imageUrl = $contributor['image'];
            //                $repoList = implode(', ', $contributor['repos']);
            //                echo "
            //            <div class='author'>
            //                <img src='$imageUrl' alt='Avatar of $author' class='avatar'>
            //                <h2 class='author-name'>$author</h2>
            //                <div class='info'>
            //                    <p class='commit-count'><strong>" . $contributor['count'] . "</strong> Contributions</p>
            //                    <p class='contributed-repos'>Contributed to: </><i>" . $repoList . "</i></p>
            //                </div>
            //            </div>";
            //            }
            //            ?>
            <div class="chart-container">
                <canvas id="contributionsChart"></canvas>
            </div>
        </div>
    </div>
    <div class="right">
        <div class="prs">
            <h2 class="quarter-title">Most recent merged pull requests across all GEWIS repositories</h2>
            <?php
            foreach ($recentPrs as $time => $pr) {
                echo "
            <div class='pr'>
                <img src='assets/pr-merged.png' alt='PR merged icon'>
                <div class='info'>
                    <h2 class='pr-title'>" . $pr['title'] . " $checkmark</h2>
                    
                    <p class='pr-info'>#" . $pr['number'] . " by " . $pr['author'] . " was merged into " . $pr['repo'] . " " . toTimeAgo($time) . " ago  •  Approved" . "</p>
                </div>
            </div>";
            }
            ?>
        </div>
        <div class="dummy">Dummy div 2 (Recent activity?)</div>
    </div>
</div>
<script>
    const contributorsData = <?php echo json_encode(array_map(function ($author, $data) {
        return [
            'name' => $author,
            'count' => $data['count'],
            'image' => $data['image'] // Add the image URL
        ];
    }, array_keys($contributors), $contributors)); ?>;
    document.addEventListener('DOMContentLoaded', () => {
        const ctx = document.getElementById('contributionsChart').getContext('2d');

        const contributorNames = contributorsData.map(contributor => contributor.name);
        const contributionCounts = contributorsData.map(contributor => contributor.count);
        const contributorImages = contributorsData.map(contributor => contributor.image);

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: contributorNames,
                datasets: [{
                    label: 'Contributions',
                    data: contributionCounts,
                    backgroundColor: 'rgba(75, 192, 192, 0.2)',
                    borderColor: 'rgba(75, 192, 192, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                layout: {
                    padding: {
                        bottom: 70 // Add extra space for the images
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    x: {
                        ticks: {
                            callback: function (value, index) {
                                return contributorNames[index]; // Show contributor names
                            },
                            font: {
                                size: 14,
                                weight: 'bold',
                                family: 'Segoe UI'
                            },
                            color: 'white'
                        }
                    },
                    y: {
                        beginAtZero: true
                    }
                }
            },
            plugins: [{
                id: 'customXAxisImages',
                afterDraw: function (chart) {
                    const xAxis = chart.scales.x;
                    const ctx = chart.ctx;

                    xAxis.ticks.forEach((tick, index) => {
                        const image = new Image();
                        image.src = contributorImages[index];
                        const x = xAxis.getPixelForTick(index);
                        const y = chart.height - 60; // Adjust to position images under names

                        // Draw rounded image
                        const imageSize = 60; // Image size
                        ctx.save();
                        ctx.beginPath();
                        ctx.arc(x, y + imageSize / 2, imageSize / 2, 0, Math.PI * 2); // Circle mask
                        ctx.closePath();
                        ctx.clip();
                        ctx.drawImage(image, x - imageSize / 2, y, imageSize, imageSize);
                        ctx.restore();
                    });
                }
            }]
        });
    });
</script>
</body>
</html>