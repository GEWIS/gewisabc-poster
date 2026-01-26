<?php
declare(strict_types=1);

require __DIR__ . "/repos.php";

if (is_file(__DIR__ . "/pat.php")) {
    require __DIR__ . "/pat.php";
} elseif (is_file(__DIR__ . "/pat.example.php")) {
    require __DIR__ . "/pat.example.php";
}

if (!isset($pat) || !is_string($pat) || $pat === "") {
    http_response_code(500);
    echo "Missing \$pat. Create pat.php (see pat.example.php).";
    exit;
}

/**
 * @return array<int, mixed>
 */
function githubGetJson(string $url, string $pat): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "User-Agent: Narrowcasting-Screen",
            "Authorization: Bearer $pat",
            "Accept: application/vnd.github+json",
            "X-GitHub-Api-Version: 2022-11-28",
        ],
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    $decoded = json_decode(is_string($response) ? $response : "", true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * @param array<int, array{owner: string, repo: string}> $reposToWatch
 * @return array<int, array<string, mixed>>
 */
function collectSlides(array $reposToWatch, string $pat, DateTimeInterface $now): array
{
    $since = (new DateTimeImmutable($now->format(DateTimeInterface::ATOM)))->sub(new DateInterval("P7D"));
    $slides = [];

    foreach ($reposToWatch as $r) {
        $owner = $r["owner"];
        $repo = $r["repo"];

        $prs = githubGetJson(
            "https://api.github.com/repos/$owner/$repo/pulls?state=closed&per_page=50&sort=updated&direction=desc",
            $pat
        );

        foreach ($prs as $pr) {
            if (!is_array($pr)) {
                continue;
            }
            if (empty($pr["merged_at"])) {
                continue;
            }
            $login = $pr["user"]["login"] ?? null;
            if ($login === "github-actions[bot]" || $login === "dependabot[bot]") {
                continue;
            }

            $mergedAt = new DateTimeImmutable((string)$pr["merged_at"]);
            if ($mergedAt >= $since) {
                $slides[] = [
                    "type" => "pr",
                    "owner" => $owner,
                    "repo" => $repo,
                    "number" => $pr["number"],
                    "title" => $pr["title"],
                    "merged_at" => $pr["merged_at"],
                ];
            }
        }

        $releases = githubGetJson("https://api.github.com/repos/$owner/$repo/releases?per_page=10", $pat);

        foreach ($releases as $rel) {
            if (!is_array($rel)) {
                continue;
            }
            if (empty($rel["published_at"]) || !empty($rel["draft"]) || !empty($rel["prerelease"])) {
                continue;
            }

            $publishedAt = new DateTimeImmutable((string)$rel["published_at"]);
            if ($publishedAt >= $since) {
                $slides[] = [
                    "type" => "release",
                    "owner" => $owner,
                    "repo" => $repo,
                    "tag" => $rel["tag_name"],
                    "title" => ($rel["name"] ?? "") ?: $rel["tag_name"],
                    "published_at" => $rel["published_at"],
                ];
            }
        }
    }

    usort($slides, function (array $a, array $b): int {
        $dateA = $a["type"] === "pr" ? $a["merged_at"] : $a["published_at"];
        $dateB = $b["type"] === "pr" ? $b["merged_at"] : $b["published_at"];
        return strtotime((string)$dateB) <=> strtotime((string)$dateA);
    });

    return $slides;
}

function prImage(string $owner, string $repo, int $prNumber): string
{
    return "https://opengraph.githubassets.com/static/$owner/$repo/pull/$prNumber?size=1600";
}

function relImage(string $owner, string $repo, string $tag): string
{
    return "https://opengraph.githubassets.com/static/$owner/$repo/releases/tag/$tag?size=1600";
}

$now = new DateTimeImmutable("now", new DateTimeZone("UTC"));
$slides = collectSlides($reposToWatch, $pat, $now);
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta http-equiv="refresh" content="300">
    <title>Engineering Activity</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<?php if (count($slides) === 0): ?>
    <div class="empty">No activity last week.</div>
<?php else: ?>
    <?php foreach ($slides as $i => $s): ?>
        <div class="slide" id="slide-<?= $i ?>" data-type="<?= $s["type"] ?>">
            <img src="<?=
            $s["type"] === "pr"
                ? prImage($s["owner"], $s["repo"], $s["number"])
                : relImage($s["owner"], $s["repo"], $s["tag"])
            ?>">
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<script>
    const slides = document.querySelectorAll(".slide");
    let current = 0;

    function showSlide(i) {
        slides.forEach(s => {
            s.style.display = "none";
            removeConfetti(s);
        });

        const slide = slides[i];
        slide.style.display = "block";

        if (slide.dataset.type === "release") {
            launchConfetti(slide);
        }
    }

    function nextSlide() {
        current = (current + 1) % slides.length;
        showSlide(current);
    }

    function launchConfetti(parent) {
        for (let i = 0; i < 80; i++) {
            const c = document.createElement("div");
            c.className = "confetti";
            c.style.left = Math.random() * 100 + "vw";
            c.style.background = `hsl(${Math.random()*360}, 80%, 60%)`;
            c.style.animationDuration = (2 + Math.random() * 3) + "s";
            parent.appendChild(c);
        }
    }

    function removeConfetti(parent) {
        parent.querySelectorAll(".confetti").forEach(c => c.remove());
    }

    if (slides.length > 0) {
        showSlide(0);
        setInterval(nextSlide, 10000); // 10 sec per slide
    }
</script>

</body>
</html>
