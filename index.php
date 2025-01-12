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
function setupCh($ch, $headers = false)
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

    if ($headers) {
        curl_setopt($ch, CURLOPT_HEADER, true);
    }
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
$prCount = 10;

$TESTING = true; // 237 core tokens per non-test page load (max 5000 p/h)

// Load the .env file
if (!$TESTING) {
    try {
        loadEnv();
    } catch (Exception $e) {
        // Make .env pls :)
    }
    error_reporting(E_ALL);
    ini_set('display_errors', 1);

    // Get all repos
    $ch = curl_init("https://api.github.com/orgs/GEWIS/repos?per_page=100&sort=pushed");
    setupCh($ch);
    $repos = json_decode(curl_exec($ch), true);
    curl_close($ch);

    // Used to get commits since a certain date
    $since = date('Y-m-d\TH:i:s\Z', strtotime('-14 days'));

    // Create multi curl handle
    $mh = curl_multi_init();

    // Create list to store curl handles
    $commitChs = [];
    $prChs = [];
    $commitCountChs = [];
    $contributorsChs = [];
    foreach ($repos as $repo) {
        $repoName = $repo['name'];

        // Initiate curl handles for this repo
        $commitCh = curl_init("https://api.github.com/repos/GEWIS/$repoName/commits?since=$since");
        $prCh = curl_init("https://api.github.com/repos/GEWIS/$repoName/pulls?per_page=$prCount&state=closed&sort=updated&direction=desc");
        $commitCountCh = curl_init("https://api.github.com/repos/GEWIS/$repoName/commits?per_page=1");
        $contributorCh = curl_init("https://api.github.com/repos/GEWIS/$repoName/contributors");

        // Set options for curl handles
        setupCh($commitCh);
        setupCh($prCh);
        setupCh($commitCountCh, true);
        setupCh($contributorCh);

        // Add handles to multi handle
        curl_multi_add_handle($mh, $commitCh);
        curl_multi_add_handle($mh, $prCh);
        curl_multi_add_handle($mh, $commitCountCh);
        curl_multi_add_handle($mh, $contributorCh);

        // Add handles to lists
        $commitChs[] = $commitCh;
        $prChs[] = $prCh;
        $commitCountChs[] = $commitCountCh;
        $contributorsChs[] = $contributorCh;
    }

    // Execute all queries at the same time, continue when all are complete
    $running = null;
    do {
        curl_multi_exec($mh, $running);
    } while ($running);

    // Close all handles and parse responses
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

    $commitCount = 0;
    foreach ($commitCountChs as $commitCountCh) {
        curl_multi_remove_handle($mh, $commitCountCh);

        // Get headers using standard separator
        list($headers, $content) = explode("\r\n\r\n", curl_multi_getcontent($commitCountCh), 2);

        // For each header
        foreach (explode("\r\n", $headers) as $header => $line) {
            // If it is the link header
            if (strpos($line, 'link') !== false) {
                // Split into header key and value
                list ($key, $value) = explode(': ', $line);

                // Get the amount of pages left
                // Because page=1 in the parameters we know that this is the amount of commits in this repo
                preg_match('/page=(\d+); rel="last"/', str_replace(['<', '>'], '', $value), $matches);
                $commitCount += intval($matches[1]);
            }
        }
    }

    $uniqueContributors = [];
    foreach ($contributorsChs as $contributorsCh) {
        curl_multi_remove_handle($mh, $contributorsCh);
        $contributorList = json_decode(curl_multi_getcontent($contributorsCh), true);
        foreach ($contributorList as $contributor) {
            // Only add contributors that are not in the list yet to only get unique contributors
            $login = $contributor['login'];
            if (!in_array($login, $uniqueContributors)) {
                $uniqueContributors[] = $login;
            }
        }
    }

    $uniqueContributorCount = count($uniqueContributors);

    // Close multi handle
    curl_multi_close($mh);

    foreach ($commits as $commit) {
        $author = $commit['author']['login'];

        // Ignore commits from dependabot
        if ($author == 'dependabot[bot]') {
            continue;
        }

        $count = $contributors[$author]['count'] ?? 0;

        // Commits have no repo property, but we can get it from the html_url using a regex filter
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

    $repo_count = count($repos);
} else {
    $commitCount = 120471;
    $uniqueContributorCount = 201;
    $repo_count = 40;

    $recentPrs = array(
        1736514338 => array('title' => 'Test PR 1', 'author' => 'Ik', 'number' => 1234, 'repo' => 'test_repo', 'merged_at' => 1736514338),
        1736514339 => array('title' => 'Feature Update', 'author' => 'Alice', 'number' => 5678, 'repo' => 'new_feature_repo', 'merged_at' => 1736514339),
        1736514340 => array('title' => 'Bug Fix', 'author' => 'Bob', 'number' => 9101, 'repo' => 'bug_fixes', 'merged_at' => 1736514340),
        1736514341 => array('title' => 'Refactor Code', 'author' => 'Charlie', 'number' => 1121, 'repo' => 'refactor_repo', 'merged_at' => 1736514341),
        1736514342 => array('title' => 'Add New Tests', 'author' => 'Diane', 'number' => 3141, 'repo' => 'test_suite', 'merged_at' => 1736514342),
        1736514343 => array('title' => 'Optimize Queries', 'author' => 'Eve', 'number' => 5161, 'repo' => 'db_opt', 'merged_at' => 1736514343),
        1736514344 => array('title' => 'Update Docs', 'author' => 'Frank', 'number' => 7181, 'repo' => 'docs_repo', 'merged_at' => 1736514344),
        1736514345 => array('title' => 'Fix CSS', 'author' => 'Grace', 'number' => 9202, 'repo' => 'frontend', 'merged_at' => 1736514345),
        1736514346 => array('title' => 'API Enhancement', 'author' => 'Hank', 'number' => 1223, 'repo' => 'api_repo', 'merged_at' => 1736514346),
        1736514347 => array('title' => 'Fix Memory Leak', 'author' => 'Ivy', 'number' => 3445, 'repo' => 'backend', 'merged_at' => 1736514347),
        1736514348 => array('title' => 'Upgrade Dependencies', 'author' => 'Jack', 'number' => 5667, 'repo' => 'dependency_repo', 'merged_at' => 1736514348),
        1736514349 => array('title' => 'Resolve Merge Conflict', 'author' => 'Kira', 'number' => 7889, 'repo' => 'conflict_resolver', 'merged_at' => 1736514349),
        1736514350 => array('title' => 'Enhance UI', 'author' => 'Liam', 'number' => 9001, 'repo' => 'ui_repo', 'merged_at' => 1736514350),
        1736514351 => array('title' => 'Update CI/CD', 'author' => 'Mona', 'number' => 1232, 'repo' => 'ci_cd', 'merged_at' => 1736514351),
        1736514352 => array('title' => 'Add Logging', 'author' => 'Nate', 'number' => 3453, 'repo' => 'log_repo', 'merged_at' => 1736514352),
        1736514353 => array('title' => 'Improve Performance', 'author' => 'Oscar', 'number' => 5674, 'repo' => 'perf_repo', 'merged_at' => 1736514353),
        1736514354 => array('title' => 'Fix Broken Build', 'author' => 'Paula', 'number' => 7895, 'repo' => 'build_repo', 'merged_at' => 1736514354),
        1736514355 => array('title' => 'Enhance Accessibility', 'author' => 'Quinn', 'number' => 9016, 'repo' => 'access_repo', 'merged_at' => 1736514355),
        1736514356 => array('title' => 'Add Dark Mode', 'author' => 'Ryan', 'number' => 1237, 'repo' => 'theme_repo', 'merged_at' => 1736514356),
        1736514357 => array('title' => 'Improve Logging', 'author' => 'Sophia', 'number' => 3458, 'repo' => 'log_updates', 'merged_at' => 1736514357),
        1736514358 => array('title' => 'Clean Up Code', 'author' => 'Tom', 'number' => 5679, 'repo' => 'cleanup_repo', 'merged_at' => 1736514358),
        1736514359 => array('title' => 'Add CI Test', 'author' => 'Uma', 'number' => 7890, 'repo' => 'ci_repo', 'merged_at' => 1736514359),
    );

    $contributors = array(
        'RubenLWF' => array('count' => 25, 'image' => 'https://avatars.githubusercontent.com/u/98549214?v=4'),
        'Ruben1' => array('count' => 20, 'image' => 'https://avatars.githubusercontent.com/u/98549214?v=4'),
        'Ruben2' => array('count' => 15, 'image' => 'https://avatars.githubusercontent.com/u/98549214?v=4'),
        'Ruben3' => array('count' => 10, 'image' => 'https://avatars.githubusercontent.com/u/98549214?v=4'),
        'Ruben4' => array('count' => 5, 'image' => 'https://avatars.githubusercontent.com/u/98549214?v=4')
    );
}


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
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
</head>
<body>
<div class="container">
    <div class="left">
        <div class="abc-info">
            <img class="abc-logo" src='assets/abc-logo.png' alt='ABC Logo'>
            <div class="abc-text">
                <h1>Like writing software? <br> Join the ABC!</h1>
                <h2>Find us at <span class="highlight">github.com/GEWIS</span></h2>
            </div>
        </div>
        <div class="contributors">
            <h2>Top contributors across all GEWIS repositories (Last 2 weeks)</h2>
            <div class="chart-container">
                <canvas id="contributionsChart"></canvas>
            </div>
        </div>
        <div class="stats">
            <div class="stats-title">
                <h2>All-time stats:</h2>
            </div>
            <div class="stat">
                <h3>There have been</h3>
                <h1 class="highlight"><?php echo $commitCount ?></h1>
                <h3>Contributions</h3>
            </div>
            <div class="stat">
                <h3>Made by</h3>
                <h1 class="highlight"><?php echo $uniqueContributorCount ?></h1>
                <h3>Contributors</h3>
            </div>
            <div class="stat">
                <h3>Across</h3>
                <h1 class="highlight"><?php echo $repo_count ?></h1>
                <h3>Repositories</h3>
            </div>
        </div>
    </div>
    <div class="right">
        <div class="prs">
            <h2 class="pr-list-title">Most recent merged pull requests across all GEWIS repositories</h2>
            <div class="pr-list" id="pr-list">
                <?php
                foreach ($recentPrs as $time => $pr) {
                    echo "
                <div class='pr'>
                    <img src='assets/pr-merged.png' alt='PR merged icon'>
                    <div>
                        <h2 class='pr-title'>" . $pr['title'] . " $checkmark</h2>
                        <p class='pr-info'>#" . $pr['number'] . " by " . $pr['author'] . " was merged into " . $pr['repo'] . " " . toTimeAgo($time) . " ago  •  Approved" . "</p>
                    </div>
                </div>";
                }
                ?>
            </div>
        </div>
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
                        bottom: 70, // Add extra space for the images
                        top: 20
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
            plugins: [
                {
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
                },
                {
                    id: 'customBarLabels',
                    afterDatasetsDraw: function (chart) {
                        const ctx = chart.ctx;
                        const dataset = chart.data.datasets[0];
                        const meta = chart.getDatasetMeta(0);

                        meta.data.forEach((bar, index) => {
                            const value = dataset.data[index];
                            const x = bar.x;
                            const y = bar.y - 5;

                            ctx.save();
                            ctx.font = 'bold 14px Segoe UI';
                            ctx.fillStyle = 'white';
                            ctx.textAlign = 'center';
                            ctx.fillText(value, x, y);
                            ctx.restore();
                        });
                    }
                }]
        });
    });

    const scrollSmoothlyToBottom = (id) => {
        const element = $(`#${id}`);
        element.animate({
            scrollTop: element.prop("scrollHeight")
        }, 45000, "linear");
    }

    scrollSmoothlyToBottom('pr-list')
</script>
</body>
</html>