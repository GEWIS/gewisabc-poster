<?php
declare(strict_types=1);

require __DIR__ . "/repos.php";
require __DIR__ . "/pat.php";

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
 * @param array<int, string> $urls
 * @return array<string, array<int, mixed>>
 */
function githubGetJsonMulti(array $urls, string $pat): array
{
    $mh = curl_multi_init();
    $handles = [];

    foreach ($urls as $url) {
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
        curl_multi_add_handle($mh, $ch);
        $handles[$url] = $ch;
    }

    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) {
            curl_multi_select($mh, 1.0);
        }
    } while ($active && $status === CURLM_OK);

    $out = [];
    foreach ($handles as $url => $ch) {
        $response = curl_multi_getcontent($ch);
        $decoded = json_decode(is_string($response) ? $response : "", true);
        $out[$url] = is_array($decoded) ? $decoded : [];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);

    return $out;
}

/**
 * @param array<int, array{owner: string, repo: string}> $reposToWatch
 * @return array<int, array<string, mixed>>
 */
function collectSlides(array $reposToWatch, string $pat, DateTimeInterface $now): array
{
    $since = (new DateTimeImmutable($now->format(DateTimeInterface::ATOM)))->sub(new DateInterval("P7D"));
    $slides = [];

    $prUrls = [];
    $relUrls = [];
    foreach ($reposToWatch as $r) {
        $owner = $r["owner"];
        $repo = $r["repo"];

        $prUrls["$owner/$repo"] = "https://api.github.com/repos/$owner/$repo/pulls?state=closed&per_page=50&sort=updated&direction=desc";
        $relUrls["$owner/$repo"] = "https://api.github.com/repos/$owner/$repo/releases?per_page=10";
    }

    $responses = githubGetJsonMulti(array_values(array_merge($prUrls, $relUrls)), $pat);

    foreach ($reposToWatch as $r) {
        $owner = $r["owner"];
        $repo = $r["repo"];
        $key = "$owner/$repo";

        $prs = $responses[$prUrls[$key]] ?? [];

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

        $releases = $responses[$relUrls[$key]] ?? [];

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
$cacheDir = __DIR__ . "/cache";
$cacheFile = $cacheDir . "/slides.json";
$cacheTtlSeconds = 300;

$slides = null;
if (is_file($cacheFile) && filemtime($cacheFile) !== false && filemtime($cacheFile) >= (time() - $cacheTtlSeconds)) {
    $cache = json_decode((string)file_get_contents($cacheFile), true);
    if (is_array($cache) && isset($cache["slides"]) && is_array($cache["slides"])) {
        $slides = $cache["slides"];
    }
}

if (!is_array($slides)) {
    $slides = collectSlides($reposToWatch, $pat, $now);
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0777, true);
    }
    @file_put_contents(
        $cacheFile,
        json_encode(["generated_at" => time(), "slides" => $slides], JSON_UNESCAPED_SLASHES)
    );
}
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
    let switchToken = 0;
    const preloaded = new Set();

    function preloadSlide(i) {
        if (slides.length === 0) return Promise.resolve();

        const idx = ((i % slides.length) + slides.length) % slides.length;
        const img = slides[idx].querySelector("img");
        const src = img?.getAttribute("src");
        if (!img || !src) return Promise.resolve();
        if (img.complete || preloaded.has(src)) {
            preloaded.add(src);
            return Promise.resolve();
        }

        return new Promise(resolve => {
            const pre = new Image();
            pre.onload = () => {
                preloaded.add(src);
                resolve();
            };
            pre.onerror = () => resolve();
            pre.src = src;
        });
    }

    function showSlide(i) {
        const myToken = ++switchToken;
        const idx = ((i % slides.length) + slides.length) % slides.length;
        preloadSlide(idx).then(() => {
            if (myToken !== switchToken) return;

            slides.forEach(s => {
                s.style.display = "none";
                removeConfetti(s);
            });

            const slide = slides[idx];
            slide.style.display = "block";

            if (slide.dataset.type === "release") {
                launchConfetti(slide);
            }

            preloadSlide(idx + 1);
        });
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
